<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('user.list', 'cli_user_list', 
  array(
    'description' => 'List users',
    'boot' => 'full',
    'pagination' => true,
  )
);
function cli_user_list(array $args) 
{
  global $conf;

  $query = '
SELECT
    ui.user_id,
    u.'.$conf['user_fields']['username'].' AS username,
    u.'.$conf['user_fields']['email'].' AS email,
    ui.status,
    ui.language
  FROM '.USER_INFOS_TABLE.' AS ui
    JOIN '.USERS_TABLE.' AS u ON u.'.$conf['user_fields']['id'].' = ui.user_id
;';
  $users = query2array($query);

  PwgCommand::table(PwgCommand::paginate($users, $args));
  PwgCommand::pagination_footer('user');

  return PwgCommand::SUCCESS;
}

$cli->add_command('user.info', 'cli_user_info', 
  array(
    'description' => 'Show user info',
    'boot' => 'full',
    'operands' => [
      'username_or_id' => ['info' => 'Username or Id'],
    ],
  )
);
function cli_user_info(array $args)
{
  global $conf;

  $user_id = cli_user_id($args['username_or_id']);

  if (null === $user_id)
  {
    PwgCommand::error('User "'.$args['username_or_id'].'" not found');
    return PwgCommand::INVALID;
  }

  $query = '
SELECT 
  *,
  u.'.$conf['user_fields']['email'].' AS email,
  u.'.$conf['user_fields']['username'].' AS username
FROM '.USER_INFOS_TABLE.' AS ui
JOIN '.USERS_TABLE.' AS u ON u.'.$conf['user_fields']['id'].' = ui.user_id
WHERE 
  ui.user_id = '.$user_id.'
;';

  $user = pwg_db_fetch_assoc(pwg_query($query));

  if (empty($user))
  {
    PwgCommand::error('User "'.$args['username_or_id'].'" not found');
    return PwgCommand::INVALID;
  }

  // a field/value table by default, the object itself with --format=json
  PwgCommand::record($user);

  return PwgCommand::SUCCESS;
}

$cli->add_command('user.edit', 'cli_user_edit',
  array(
    'description' => 'Change a user account, only the options you pass',
    'boot' => 'full',
    'operands' => [
      'username_or_id' => ['info' => 'User to edit, by username or id'],
    ],
    'args' => [
      'username' => [
        'info' => 'New username',
        'default' => null,
      ],
      'email' => [
        'short' => 'e',
        'info' => 'New email address',
        'default' => null,
      ],
      'password' => [
        'short' => 'p',
        'info' => 'New password (it lands in your shell history, prefer a throwaway one the user changes)',
        'default' => null,
      ],
      'status' => [
        'short' => 's',
        'info' => 'guest, generic, normal, admin or webmaster',
        'default' => null,
      ],
      'level' => [
        'info' => 'Privacy level, the highest level of photos the user may see',
        'default' => null,
      ],
      'language' => [
        'info' => 'Language code, e.g. fr_FR',
        'default' => null,
      ],
      'theme' => [
        'info' => 'Theme name, e.g. modus',
        'default' => null,
      ],
    ],
  )
);
function cli_user_edit(array $args)
{
  $user_id = cli_user_id($args['username_or_id']);
  if (null === $user_id)
  {
    PwgCommand::error('User "'.$args['username_or_id'].'" not found');
    return PwgCommand::INVALID;
  }

  // only what was actually typed, the core updates nothing else
  $changes = [];
  foreach (['username', 'email', 'password', 'status', 'level', 'language', 'theme'] as $field)
  {
    if (null !== $args[$field])
    {
      $changes[$field] = $args[$field];
    }
  }

  if (0 === count($changes))
  {
    PwgCommand::error('Nothing to change, give at least one option (see "pwg user edit --help")');
    return PwgCommand::INVALID;
  }

  // the core wants a real int, level 0 is a valid value
  if (isset($changes['level']))
  {
    $changes['level'] = (int) $changes['level'];
  }

  if (PwgCommand::is_dry_run())
  {
    cli_user_report_changes($user_id, $changes);
    return PwgCommand::SUCCESS;
  }

  // same worker as pwg.users.setInfo: it validates, updates and traces the activity
  $result = check_and_save_user_infos(['user_id' => [$user_id]] + $changes);

  if (isset($result['error']))
  {
    PwgCommand::error($result['error']['message']);
    return PwgCommand::ERROR;
  }

  PwgCommand::success('User #'.$user_id.' updated: '.implode(', ', array_keys($changes)));
  return PwgCommand::SUCCESS;
}

$cli->add_command('user.delete', 'cli_user_delete',
  array(
    'description' => 'Delete users and what belongs to them',
    'boot' => 'full',
    'operands' => [
      'username_or_id' => [
        'info' => 'Users to delete, by username or id',
        'multiple' => true,
      ],
    ],
  )
);
function cli_user_delete(array $args)
{
  global $conf, $user;

  // a "multiple" operand may legally be empty, but doing nothing quietly is no answer
  if (0 === count($args['username_or_id']))
  {
    PwgCommand::error('Which user? Give at least one username or id (see "pwg user list")');
    return PwgCommand::INVALID;
  }

  // the accounts the gallery needs, same list as the web service
  $protected = [$user['id'], $conf['guest_id'], $conf['default_user_id'], $conf['webmaster_id']];

  $to_delete = [];
  $unknown = [];
  foreach ($args['username_or_id'] as $wanted)
  {
    $user_id = cli_user_id($wanted);

    if (null === $user_id)
    {
      $unknown[] = $wanted;
      continue;
    }

    if (in_array($user_id, $protected))
    {
      PwgCommand::warning('"'.$wanted.'" (#'.$user_id.') is a protected account, skipped');
      continue;
    }

    $to_delete[$user_id] = get_username($user_id).' (#'.$user_id.')';
  }

  if (count($unknown) > 0)
  {
    PwgCommand::error('User'.(1 === count($unknown) ? '' : 's').' not found: '.implode(', ', $unknown));
    return PwgCommand::INVALID;
  }

  if (0 === count($to_delete))
  {
    PwgCommand::success('No user to delete');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.count($to_delete).' user'.(1 === count($to_delete) ? '' : 's').': '.implode(', ', $to_delete));
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete '.implode(', ', $to_delete).'?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  foreach (array_keys($to_delete) as $user_id)
  {
    delete_user($user_id);
  }

  PwgCommand::success(count($to_delete).' user'.(1 === count($to_delete) ? '' : 's').' deleted');
  return PwgCommand::SUCCESS;
}

$cli->add_command('user.add', 'cli_user_add',
  array(
    'description' => 'Create a user account',
    'boot' => 'full',
    'operands' => [
      'username' => ['info' => 'Login of the new user'],
    ],
    'args' => [
      'password' => [
        'short' => 'p',
        'info' => 'Password, generated and printed once when you omit it',
        'default' => null,
      ],
      'email' => [
        'short' => 'e',
        'info' => 'Email address',
        'default' => null,
      ],
      'status' => [
        'short' => 's',
        'info' => 'guest, generic, normal, admin or webmaster (default: normal)',
        'default' => null,
      ],
      'level' => [
        'info' => 'Privacy level, the highest level of photos the user may see',
        'default' => null,
      ],
      'language' => [
        'info' => 'Language code, e.g. fr_FR',
        'default' => null,
      ],
      'theme' => [
        'info' => 'Theme name, e.g. modus',
        'default' => null,
      ],
    ],
  )
);
function cli_user_add(array $args)
{
  if (null !== cli_user_id($args['username']))
  {
    PwgCommand::error('User "'.$args['username'].'" already exists');
    return PwgCommand::INVALID;
  }

  // no password given: make one, it is printed once and never again
  $generated = null === $args['password'];
  $password = $generated ? generate_key(rand(15, 20)) : $args['password'];

  // what the account gets after creation, the core sets the rest by default
  $infos = [];
  foreach (['status', 'level', 'language', 'theme'] as $field)
  {
    if (null !== $args[$field])
    {
      $infos[$field] = 'level' === $field ? (int) $args[$field] : $args[$field];
    }
  }

  if (PwgCommand::is_dry_run())
  {
    $line = 'would create user "'.$args['username'].'"';
    $line .= null === $args['email'] ? ' without email' : ' <'.$args['email'].'>';
    if (count($infos) > 0)
    {
      $line .= ', then set '.implode(', ', array_keys($infos));
    }

    PwgCommand::writeln($line);
    return PwgCommand::SUCCESS;
  }

  $errors = [];
  // no mail on a CLI creation, neither to the admins nor to the user
  $user_id = register_user($args['username'], $password, $args['email'], false, $errors, false);

  if (!$user_id)
  {
    PwgCommand::error($errors[0] ?? 'The user could not be created');
    return PwgCommand::ERROR;
  }

  PwgCommand::success('User "'.$args['username'].'" created (#'.$user_id.')');

  if ($generated)
  {
    PwgCommand::writeln('  password: '.PwgCommand::green($password).' (shown once, write it down)');
  }

  if (0 === count($infos))
  {
    return PwgCommand::SUCCESS;
  }

  // same worker as user.edit, so a status or a level is validated the same way
  $result = check_and_save_user_infos(['user_id' => [$user_id]] + $infos);

  if (isset($result['error']))
  {
    PwgCommand::error('the account exists but "'.implode(', ', array_keys($infos)).'" could not be set: '.$result['error']['message']);
    PwgCommand::errln('fix it with: pwg user edit '.$user_id.' ...');
    return PwgCommand::ERROR;
  }

  PwgCommand::writeln('  '.implode(', ', array_keys($infos)).' set');
  return PwgCommand::SUCCESS;
}

// "gorge" or "42" to a user id, null when the gallery has no such user
function cli_user_id(string $username_or_id): ?int
{
  global $conf;

  if (ctype_digit($username_or_id))
  {
    $query = '
SELECT '.$conf['user_fields']['id'].'
  FROM '.USERS_TABLE.'
  WHERE '.$conf['user_fields']['id'].' = '.(int) $username_or_id.'
;';
    $row = pwg_db_fetch_row(pwg_query($query));

    return $row ? (int) $row[0] : null;
  }

  $user_id = get_userid($username_or_id);

  return false === $user_id ? null : (int) $user_id;
}

// what --dry-run shows: the fields that would move, current value on the left
function cli_user_report_changes(int $user_id, array $changes)
{
  global $conf;

  $query = '
SELECT
    u.'.$conf['user_fields']['username'].' AS username,
    u.'.$conf['user_fields']['email'].' AS email,
    ui.status,
    ui.level,
    ui.language,
    ui.theme
  FROM '.USER_INFOS_TABLE.' AS ui
    JOIN '.USERS_TABLE.' AS u ON u.'.$conf['user_fields']['id'].' = ui.user_id
  WHERE ui.user_id = '.$user_id.'
;';
  $current = pwg_db_fetch_assoc(pwg_query($query));

  $rows = [];
  foreach ($changes as $field => $value)
  {
    $rows[] = [
      'field' => $field,
      'current' => 'password' === $field ? '********' : (string) ($current[$field] ?? ''),
      'would become' => 'password' === $field ? '********' : (string) $value,
    ];
  }

  PwgCommand::writeln('user #'.$user_id.' "'.$current['username'].'"');
  PwgCommand::table($rows);
}
