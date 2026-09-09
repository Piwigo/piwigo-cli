<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

// themes/ holds two folders that are not themes to pick from: "default" is the base every
// theme inherits, "standard_pages" is the shared login, profile and password pages
const CLI_THEME_BUILTIN = ['default', 'standard_pages'];

$cli->add_command('theme.list', 'cli_theme_list',
  array(
    'description' => 'List the themes present in themes/ and their state',
    'boot' => 'full',
    'pagination' => true,
    'args' => [
      'search' => [
        'short' => 's',
        'info' => 'Only the themes whose id or name contains this text',
        'default' => null,
      ],
    ],
  )
);
function cli_theme_list(array $args)
{
  $themes = cli_theme_manager();
  $default = get_default_theme();
  $needle = null === $args['search'] ? null : mb_strtolower($args['search']);

  $rows = [];
  foreach ($themes->fs_themes as $theme_id => $theme)
  {
    if (in_array($theme_id, CLI_THEME_BUILTIN))
    {
      continue;
    }

    if (null !== $needle
        and false === mb_strpos(mb_strtolower($theme_id), $needle)
        and false === mb_strpos(mb_strtolower($theme['name']), $needle))
    {
      continue;
    }

    $rows[$theme_id] = [
      'id' => $theme_id,
      'name' => $theme['name'],
      'version' => $theme['version'],
      'state' => cli_theme_state($themes, $theme_id).($theme_id === $default ? ', default' : ''),
    ];
  }

  if (0 === count($rows))
  {
    PwgCommand::writeln(null === $needle ? 'no theme in themes/' : 'no theme matching "'.$args['search'].'" in themes/');
    return PwgCommand::SUCCESS;
  }

  ksort($rows);
  PwgCommand::table(PwgCommand::paginate(array_values($rows), $args));
  PwgCommand::pagination_footer('theme');

  return PwgCommand::SUCCESS;
}

$cli->add_command('theme.activate', 'cli_theme_activate',
  array(
    'description' => 'Activate themes, making them available to the users',
    'boot' => 'full',
    'operands' => [
      'theme_id' => [
        'info' => 'Theme folder names, as shown by "pwg theme list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_theme_activate(array $args)
{
  return cli_theme_apply('activate', $args['theme_id'], 'active', 'activated');
}

$cli->add_command('theme.deactivate', 'cli_theme_deactivate',
  array(
    'description' => 'Deactivate themes, the gallery keeps at least one',
    'boot' => 'full',
    'operands' => [
      'theme_id' => [
        'info' => 'Theme folder names, as shown by "pwg theme list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_theme_deactivate(array $args)
{
  return cli_theme_apply('deactivate', $args['theme_id'], 'inactive', 'deactivated');
}

$cli->add_command('theme.default', 'cli_theme_default',
  array(
    'description' => 'Set the theme every user gets by default',
    'boot' => 'full',
    'operands' => [
      'theme_id' => ['info' => 'Theme folder name, as shown by "pwg theme list"'],
    ],
  )
);
function cli_theme_default(array $args)
{
  $themes = cli_theme_manager();
  $theme_id = $args['theme_id'];

  if (!isset($themes->fs_themes[$theme_id]))
  {
    PwgCommand::error('Theme "'.$theme_id.'" not found in themes/');
    return PwgCommand::INVALID;
  }

  if ('active' !== cli_theme_state($themes, $theme_id))
  {
    PwgCommand::error('"'.$theme_id.'" is not active, activate it first: pwg theme activate '.$theme_id);
    return PwgCommand::INVALID;
  }

  $current = get_default_theme();

  if ($theme_id === $current)
  {
    PwgCommand::success('"'.$theme_id.'" is already the default theme');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would make "'.$theme_id.'" the default theme, instead of "'.$current.'"');
    return PwgCommand::SUCCESS;
  }

  // this moves every user still on the old default, hence the question
  if (!PwgCommand::confirm('Make "'.$theme_id.'" the default theme, moving the users still on "'.$current.'"?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  $errors = array_filter((array) $themes->perform_action('set_default', $theme_id));

  if (count($errors) > 0)
  {
    PwgCommand::error('"'.$theme_id.'": '.implode(', ', $errors));
    return PwgCommand::ERROR;
  }

  PwgCommand::success('"'.$theme_id.'" is now the default theme');
  return PwgCommand::SUCCESS;
}

$cli->add_command('theme.delete', 'cli_theme_delete',
  array(
    'description' => 'Move the folder of inactive themes to themes/trash',
    'boot' => 'full',
    'operands' => [
      'theme_id' => [
        'info' => 'Theme folder names, as shown by "pwg theme list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_theme_delete(array $args)
{
  global $conf;

  // the core would die() on this one, say it properly instead
  if (!$conf['enable_extensions_install'])
  {
    PwgCommand::error('the extension install/update/delete system is disabled ($conf[\'enable_extensions_install\'])');
    return PwgCommand::ERROR;
  }

  return cli_theme_apply('delete', $args['theme_id'], 'deleted', 'deleted',
    'Delete %s? Their folder moves to themes/trash.');
}

$cli->add_command('theme.standard_pages', 'cli_theme_standard_pages',
  array(
    'description' => 'Show or change the shared login, profile and password pages',
    'boot' => 'full',
    'operands' => [
      'state' => [
        'info' => 'on or off, nothing to only show the current state',
        'default' => null,
      ],
    ],
  )
);
function cli_theme_standard_pages(array $args)
{
  $used = (bool) conf_get_param('use_standard_pages', false);

  if (null === $args['state'])
  {
    PwgCommand::writeln('standard pages are '.($used ? 'on' : 'off'));
    return PwgCommand::SUCCESS;
  }

  if (!in_array($args['state'], ['on', 'off']))
  {
    PwgCommand::error('say "on" or "off", not "'.$args['state'].'"');
    return PwgCommand::INVALID;
  }

  $wanted = 'on' === $args['state'];

  if ($wanted === $used)
  {
    PwgCommand::success('standard pages are already '.$args['state']);
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would turn the standard pages '.$args['state']);
    return PwgCommand::SUCCESS;
  }

  conf_update_param('use_standard_pages', $wanted, true);

  PwgCommand::success('standard pages turned '.$args['state']);
  return PwgCommand::SUCCESS;
}

$cli->add_command('theme.search', 'cli_theme_search',
  array(
    'description' => 'Search the themes available on piwigo.org, the ones you do not have yet',
    'boot' => 'full',
    'pagination' => true,
    'operands' => [
      'term' => [
        'info' => 'Text to look for in the name and the description',
        'default' => null,
      ],
    ],
    'args' => [
      'all' => [
        'short' => 'a',
        'info' => 'Every available theme, no text to match',
        'flag' => true,
      ],
      'sort' => [
        'info' => 'downloads, date, name, author or revision',
        'default' => 'downloads',
      ],
    ],
  )
);
function cli_theme_search(array $args)
{
  if (null === $args['term'] and !$args['all'])
  {
    PwgCommand::error('Give a text to search for, or --all to list everything');
    return PwgCommand::INVALID;
  }

  $themes = cli_theme_manager();
  $catalogue = cli_theme_catalogue(true);

  if (null === $catalogue)
  {
    PwgCommand::error('Could not reach '.PEM_URL.', check the network access of this server');
    return PwgCommand::ERROR;
  }

  $themes->server_themes = $catalogue;
  $themes->sort_server_themes(in_array($args['sort'], ['downloads', 'date', 'name', 'author', 'revision']) ? $args['sort'] : 'downloads');

  $needle = null === $args['term'] ? null : mb_strtolower($args['term']);
  $found = [];
  foreach ($themes->server_themes as $theme)
  {
    if (null !== $needle
        and false === mb_strpos(mb_strtolower($theme['extension_name']), $needle)
        and false === mb_strpos(mb_strtolower($theme['extension_description'] ?? ''), $needle))
    {
      continue;
    }

    $found[] = [
      'name' => $theme['extension_name'],
      'version' => $theme['revision_name'],
      'author' => $theme['author_name'],
      'downloads' => $theme['extension_nb_downloads'],
    ];
  }

  if (0 === count($found))
  {
    PwgCommand::writeln('nothing found'.(null === $needle ? '' : ' for "'.$args['term'].'"').'. Themes already in themes/ are not listed, see "pwg theme list"');
    return PwgCommand::SUCCESS;
  }

  PwgCommand::table(PwgCommand::paginate($found, $args));
  PwgCommand::pagination_footer('theme');

  return PwgCommand::SUCCESS;
}

$cli->add_command('theme.install', 'cli_theme_install',
  array(
    'description' => 'Download a theme from piwigo.org into themes/',
    'boot' => 'full',
    'operands' => [
      'name' => [
        'info' => 'Theme name as shown by "pwg theme search"',
        'multiple' => true,
      ],
    ],
    'args' => [
      'activate' => [
        'short' => 'a',
        'info' => 'Activate each theme right after installing it',
        'flag' => true,
      ],
      'version' => [
        'info' => 'Install this exact revision instead of the latest one, compatibility unchecked',
        'default' => null,
      ],
    ],
  )
);
function cli_theme_install(array $args)
{
  global $conf;

  if (0 === count($args['name']))
  {
    PwgCommand::error('Which theme? Give at least one name (see "pwg theme search")');
    return PwgCommand::INVALID;
  }

  if (null !== $args['version'] and count($args['name']) > 1)
  {
    PwgCommand::error('--version applies to a single theme, you named '.count($args['name']));
    return PwgCommand::INVALID;
  }

  if (!$conf['enable_extensions_install'])
  {
    PwgCommand::error('the extension install/update/delete system is disabled ($conf[\'enable_extensions_install\'])');
    return PwgCommand::ERROR;
  }

  $themes = cli_theme_manager();
  $catalogue = cli_theme_catalogue(true);

  if (null === $catalogue)
  {
    PwgCommand::error('Could not reach '.PEM_URL.', check the network access of this server');
    return PwgCommand::ERROR;
  }

  $targets = [];
  foreach ($args['name'] as $name)
  {
    $matches = cli_theme_match($catalogue, $name);

    if (0 === count($matches))
    {
      PwgCommand::error('"'.$name.'" is not on '.PEM_URL.', or you already have it (see "pwg theme list")');
      return PwgCommand::INVALID;
    }

    if (count($matches) > 1)
    {
      PwgCommand::error('"'.$name.'" matches '.count($matches).' themes, be more precise:');
      PwgCommand::errln(array_map(function ($theme) { return '  '.$theme['extension_name']; }, $matches));
      return PwgCommand::INVALID;
    }

    $theme = reset($matches);

    // the catalogue only carries the latest revision, an older one is another lookup
    if (null !== $args['version'])
    {
      $revision = cli_theme_revision($theme['extension_id'], $args['version']);

      if (null === $revision)
      {
        PwgCommand::error('"'.$theme['extension_name'].'" has no revision "'.$args['version'].'" on '.PEM_URL);
        PwgCommand::errln('its latest one is '.$theme['revision_name'].', see the theme page for the others');
        return PwgCommand::INVALID;
      }

      // nothing checked its compatibility, that is the point of naming a revision
      PwgCommand::warning('installing "'.$theme['extension_name'].'" '.$revision['revision_name'].' as asked, without checking it supports Piwigo '.PHPWG_VERSION);

      $theme['revision_id'] = $revision['revision_id'];
      $theme['revision_name'] = $revision['revision_name'];
    }

    $targets[] = $theme;
  }

  if (PwgCommand::is_dry_run())
  {
    foreach ($targets as $theme)
    {
      PwgCommand::writeln('would install "'.$theme['extension_name'].'" '.$theme['revision_name'].($args['activate'] ? ', then activate it' : ''));
    }
    return PwgCommand::SUCCESS;
  }

  $failed = 0;
  // one step per theme: the download itself gives no progress, fetchRemote hides curl
  PwgCommand::progress_start(count($targets), 'downloading');

  foreach ($targets as $theme)
  {
    PwgCommand::progress_label($theme['extension_name']);

    $theme_id = null;
    $status = $themes->extract_theme_files('install', $theme['revision_id'], $theme['extension_id'], $theme_id);

    if ('ok' !== $status)
    {
      $failed++;
      PwgCommand::error('"'.$theme['extension_name'].'": '.cli_theme_extract_error($status));
      PwgCommand::progress_advance();
      continue;
    }

    pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'install', ['theme_id' => $theme_id, 'version' => $theme['revision_name']]);
    PwgCommand::success('"'.$theme['extension_name'].'" '.$theme['revision_name'].' installed as "'.$theme_id.'"');

    if ($args['activate'])
    {
      // the freshly extracted folder is unknown to the instance we are holding
      $themes->get_fs_themes();
      $errors = array_filter((array) $themes->perform_action('activate', $theme_id));

      if (count($errors) > 0)
      {
        $failed++;
        PwgCommand::error('"'.$theme_id.'" installed but not activated: '.implode(', ', $errors));
        PwgCommand::progress_advance();
        continue;
      }

      PwgCommand::success('"'.$theme_id.'" activated');
    }

    PwgCommand::progress_advance();
  }

  PwgCommand::progress_finish();

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

$cli->add_command('theme.update', 'cli_theme_update',
  array(
    'description' => 'Update installed themes from piwigo.org, or list what is behind',
    'boot' => 'full',
    'operands' => [
      'theme_id' => [
        'info' => 'Theme folder names, none of them to only list what could be updated',
        'multiple' => true,
      ],
    ],
    'args' => [
      'all' => [
        'short' => 'a',
        'info' => 'Update every theme that has a newer revision',
        'flag' => true,
      ],
    ],
  )
);
function cli_theme_update(array $args)
{
  global $conf;

  // no theme named and no --all: the only sensible question is "what is behind?"
  $list_only = (0 === count($args['theme_id']) and !$args['all']);

  if (!$list_only and !$conf['enable_extensions_install'])
  {
    PwgCommand::error('the extension install/update/delete system is disabled ($conf[\'enable_extensions_install\'])');
    return PwgCommand::ERROR;
  }

  $themes = cli_theme_manager();
  $catalogue = cli_theme_catalogue(false);

  if (null === $catalogue)
  {
    PwgCommand::error('Could not reach '.PEM_URL.', check the network access of this server');
    return PwgCommand::ERROR;
  }

  $wanted = ($args['all'] or $list_only) ? array_keys($themes->fs_themes) : $args['theme_id'];
  $targets = [];
  $unknown = [];
  $dev = [];

  foreach ($wanted as $theme_id)
  {
    if (in_array($theme_id, CLI_THEME_BUILTIN))
    {
      continue;
    }

    if (!isset($themes->fs_themes[$theme_id]))
    {
      $unknown[] = $theme_id;
      continue;
    }

    $fs_theme = $themes->fs_themes[$theme_id];

    // "Version: auto" means a dev checkout: the files are managed by hand or by git
    if ('auto' === $fs_theme['version'])
    {
      $dev[] = $theme_id;
      continue;
    }

    $extension_id = $fs_theme['extension'] ?? null;

    if (null === $extension_id or !isset($catalogue[$extension_id]))
    {
      if (!$args['all'] and !$list_only)
      {
        PwgCommand::writeln('"'.$theme_id.'" does not come from '.PEM_URL.', nothing to update');
      }
      continue;
    }

    $server = $catalogue[$extension_id];

    // same test as the core update checker
    if (safe_version_compare($fs_theme['version'], $server['revision_name'], '>='))
    {
      if (!$args['all'] and !$list_only)
      {
        PwgCommand::writeln('"'.$theme_id.'" is already up to date ('.$fs_theme['version'].')');
      }
      continue;
    }

    $targets[$theme_id] = $server;
  }

  if (count($unknown) > 0)
  {
    PwgCommand::error('Theme'.(1 === count($unknown) ? '' : 's').' not found in themes/: '.implode(', ', $unknown));
    return PwgCommand::INVALID;
  }

  if (0 === count($targets))
  {
    PwgCommand::success('Everything is up to date');
    cli_theme_report_dev($dev);
    return PwgCommand::SUCCESS;
  }

  $rows = [];
  foreach ($targets as $theme_id => $server)
  {
    $rows[] = [
      'theme' => $theme_id,
      'current' => $themes->fs_themes[$theme_id]['version'],
      'latest' => $server['revision_name'],
    ];
  }
  PwgCommand::table($rows);

  if ($list_only)
  {
    PwgCommand::writeln(count($targets).' theme'.(1 === count($targets) ? '' : 's').' can be updated, with "pwg theme update --all"');
    cli_theme_report_dev($dev);
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would update '.count($targets).' theme'.(1 === count($targets) ? '' : 's'));
    cli_theme_report_dev($dev);
    return PwgCommand::SUCCESS;
  }

  // the files are replaced on disk, a broken release cannot be undone from here
  if (!PwgCommand::confirm('Update '.count($targets).' theme'.(1 === count($targets) ? '' : 's').'?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  $failed = 0;
  PwgCommand::progress_start(count($targets), 'updating');

  foreach ($targets as $theme_id => $server)
  {
    PwgCommand::progress_label($theme_id);

    $status = $themes->extract_theme_files('upgrade', $server['revision_id'], $theme_id);

    if ('ok' !== $status)
    {
      $failed++;
      PwgCommand::error('"'.$theme_id.'": '.cli_theme_extract_error($status));
    }
    else
    {
      pwg_activity('system', ACTIVITY_SYSTEM_THEME, 'update', ['theme_id' => $theme_id, 'to_version' => $server['revision_name']]);
      PwgCommand::success('"'.$theme_id.'" updated to '.$server['revision_name']);
    }

    PwgCommand::progress_advance();
  }

  PwgCommand::progress_finish();

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// themes kept out of the update on purpose
function cli_theme_report_dev(array $dev)
{
  if (0 === count($dev))
  {
    return;
  }

  PwgCommand::writeln(count($dev).' theme'.(1 === count($dev) ? '' : 's').' in dev mode (version "auto") left alone'
    .(PwgCommand::is_verbose() ? ':' : ', --verbose to list them'));

  if (PwgCommand::is_verbose())
  {
    PwgCommand::writeln(array_map(function ($theme_id) { return '  '.$theme_id; }, $dev));
  }
}

// the piwigo.org catalogue, keyed by extension id. The core method refuses to answer when
// piwigo.org knows nothing about our Piwigo version, so ask it ourselves and fall back to
// the most recent published version instead of returning nothing
function cli_theme_catalogue(bool $new): ?array
{
  global $conf, $user;

  $result = '';
  if (!fetchRemote(PEM_URL.'/api/get_version_list.php?format=php&category_id='.$conf['pem_themes_category'], $result))
  {
    return null;
  }

  $pem_versions = @unserialize($result);

  if (!is_array($pem_versions) or 0 === count($pem_versions))
  {
    return null;
  }

  $branch = get_branch_from_version(PHPWG_VERSION);
  $versions = [];
  foreach ($pem_versions as $pem_version)
  {
    if (0 === strpos($pem_version['name'], $branch))
    {
      $versions[] = $pem_version['id'];
    }
  }

  // our Piwigo is newer than anything published: take the latest known version
  if (0 === count($versions))
  {
    $versions[] = $pem_versions[0]['id'];
  }

  $themes = cli_theme_manager();
  $installed = [];
  foreach ($themes->fs_themes as $fs_theme)
  {
    if (isset($fs_theme['extension']))
    {
      $installed[] = $fs_theme['extension'];
    }
  }

  $get_data = [
    'category_id' => $conf['pem_themes_category'],
    'format' => 'php',
    'last_revision_only' => 'true',
    'version' => implode(',', $versions),
    'lang' => substr($user['language'], 0, 2),
    'get_nb_downloads' => 'true',
  ];

  if (count($installed) > 0)
  {
    $get_data[$new ? 'extension_exclude' : 'extension_include'] = implode(',', $installed);
  }

  $result = '';
  if (!fetchRemote(PEM_URL.'/api/get_revision_list-next.php', $result, $get_data))
  {
    return null;
  }

  $pem_themes = @unserialize($result);

  if (!is_array($pem_themes))
  {
    return null;
  }

  $catalogue = [];
  foreach ($pem_themes as $theme)
  {
    $catalogue[$theme['extension_id']] = $theme;
  }

  return $catalogue;
}

// one precise revision of one extension. The catalogue only gives the latest one, so this
// asks for them all, across every Piwigo version: a named revision is wanted as named,
// whichever Piwigo it was published for
function cli_theme_revision(int $extension_id, string $version): ?array
{
  global $conf;

  $result = '';
  if (!fetchRemote(PEM_URL.'/api/get_version_list.php?format=php&category_id='.$conf['pem_themes_category'], $result))
  {
    return null;
  }

  $pem_versions = @unserialize($result);

  if (!is_array($pem_versions) or 0 === count($pem_versions))
  {
    return null;
  }

  $get_data = [
    'category_id' => $conf['pem_themes_category'],
    'format' => 'php',
    'version' => implode(',', array_column($pem_versions, 'id')),
    'extension_include' => $extension_id,
  ];

  $result = '';
  if (!fetchRemote(PEM_URL.'/api/get_revision_list.php', $result, $get_data))
  {
    return null;
  }

  $revisions = @unserialize($result);

  if (!is_array($revisions))
  {
    return null;
  }

  foreach ($revisions as $revision)
  {
    if (isset($revision['revision_name'], $revision['revision_id']) and $version === $revision['revision_name'])
    {
      return $revision;
    }
  }

  return null;
}

// the catalogue entries whose name matches, exactly first
function cli_theme_match(array $catalogue, string $name): array
{
  $needle = mb_strtolower($name);
  $matches = [];

  foreach ($catalogue as $extension_id => $theme)
  {
    if (mb_strtolower($theme['extension_name']) === $needle)
    {
      return [$extension_id => $theme];
    }

    if (false !== mb_strpos(mb_strtolower($theme['extension_name']), $needle))
    {
      $matches[$extension_id] = $theme;
    }
  }

  return $matches;
}

// the statuses extract_theme_files() returns, in plain words
function cli_theme_extract_error(string $status): string
{
  $messages = [
    'temp_path_error' => 'cannot create a temporary file in themes/',
    'dl_archive_error' => 'cannot download the archive',
    'archive_error' => 'cannot read the archive',
    'extract_error' => 'cannot extract the archive, check the permissions of themes/',
  ];

  return $messages[$status] ?? $status;
}

// activate, deactivate and delete share this: resolve, report, ask, then hand to the core
function cli_theme_apply(string $action, array $wanted, string $final_state, string $done, ?string $question = null)
{
  // a "multiple" operand may legally be empty, but doing nothing quietly is no answer
  if (0 === count($wanted))
  {
    PwgCommand::error('Which theme? Give at least one id (see "pwg theme list")');
    return PwgCommand::INVALID;
  }

  $themes = cli_theme_manager();

  $targets = [];
  $unknown = [];
  foreach ($wanted as $theme_id)
  {
    if (in_array($theme_id, CLI_THEME_BUILTIN))
    {
      PwgCommand::warning('"'.$theme_id.'" belongs to the core, it is not a theme to '.$action.', skipped');
      continue;
    }

    if (!isset($themes->fs_themes[$theme_id]))
    {
      $unknown[] = $theme_id;
      continue;
    }

    $state = cli_theme_state($themes, $theme_id);

    if ($state === $final_state)
    {
      PwgCommand::writeln('"'.$theme_id.'" is already '.$state.', nothing to do');
      continue;
    }

    // the core refuses this one anyway, say why before it does
    if ('delete' === $action and 'active' === $state)
    {
      PwgCommand::warning('"'.$theme_id.'" is active, deactivate it first, skipped');
      continue;
    }

    $targets[] = $theme_id;
  }

  if (count($unknown) > 0)
  {
    PwgCommand::error('Theme'.(1 === count($unknown) ? '' : 's').' not found in themes/: '.implode(', ', $unknown));
    return PwgCommand::INVALID;
  }

  if (0 === count($targets))
  {
    return PwgCommand::SUCCESS;
  }

  $list = '"'.implode('", "', $targets).'"';

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would '.$action.' '.$list);
    return PwgCommand::SUCCESS;
  }

  if (null !== $question and !PwgCommand::confirm(sprintf($question, $list)))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  $failed = 0;
  foreach ($targets as $theme_id)
  {
    // the core traces the activity and returns what went wrong, empty when fine
    $errors = array_filter((array) $themes->perform_action($action, $theme_id));

    if (count($errors) > 0)
    {
      $failed++;
      PwgCommand::error('"'.$theme_id.'": '.implode(', ', $errors));
      continue;
    }

    PwgCommand::success('"'.$theme_id.'" '.$done);
  }

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// the core class, built once per command
function cli_theme_manager(): themes
{
  static $themes = null;

  if (null === $themes)
  {
    if (!class_exists('themes'))
    {
      include_once(PHPWG_ROOT_PATH.'admin/include/themes.class.php');
    }

    $themes = new themes();
  }

  return $themes;
}

// a theme is active when it has a row in the themes table, there is no other state
function cli_theme_state(themes $themes, string $theme_id): string
{
  return isset($themes->db_themes_by_id[$theme_id]) ? 'active' : 'inactive';
}
