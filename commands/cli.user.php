<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('user.list', 'cli_user_list', 
  array(
    'description' => 'List users',
    'boot' => 'full',
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

  PwgCommand::table($users);
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

  $username_or_id = pwg_db_real_escape_string($args['username_or_id']);
  $user_id = is_numeric($username_or_id)
  ? (int) $username_or_id
  : get_userid($username_or_id);

  if (false === $user_id)
  {
    PwgCommand::error('User "'.$username_or_id.'" not found');
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
    PwgCommand::error('User "'.$username_or_id.'" not found');
    return PwgCommand::INVALID;
  }

  PwgCommand::writeJson($user);
  return PwgCommand::SUCCESS;
}