<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('plugin.list', 'cli_plugin_list',
  array(
    'description' => 'List the plugins present in plugins/ and their state',
    'boot' => 'full',
    'pagination' => true,
    'args' => [
      'search' => [
        'short' => 's',
        'info' => 'Only the plugins whose id or name contains this text',
        'default' => null,
      ],
    ],
  )
);
function cli_plugin_list(array $args)
{
  $plugins = cli_plugin_manager();
  $needle = null === $args['search'] ? null : mb_strtolower($args['search']);

  $rows = [];
  foreach ($plugins->fs_plugins as $plugin_id => $plugin)
  {
    if (null !== $needle
        and false === mb_strpos(mb_strtolower($plugin_id), $needle)
        and false === mb_strpos(mb_strtolower($plugin['name']), $needle))
    {
      continue;
    }

    $rows[$plugin_id] = [
      'id' => $plugin_id,
      'name' => $plugin['name'],
      'version' => $plugin['version'],
      'state' => cli_plugin_state($plugins, $plugin_id),
    ];
  }

  if (0 === count($rows))
  {
    PwgCommand::writeln(null === $needle ? 'no plugin in plugins/' : 'no plugin matching "'.$args['search'].'" in plugins/');
    return PwgCommand::SUCCESS;
  }

  ksort($rows);
  PwgCommand::table(PwgCommand::paginate(array_values($rows), $args));
  PwgCommand::pagination_footer('plugin');

  return PwgCommand::SUCCESS;
}

$cli->add_command('plugin.search', 'cli_plugin_search',
  array(
    'description' => 'Search the plugins available on piwigo.org, the ones you do not have yet',
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
        'info' => 'Every available plugin, no text to match',
        'flag' => true,
      ],
      'beta' => [
        'short' => 'b',
        'info' => 'Show plugins compatible with previous version of Piwigo',
        'flag' => true,
      ],
      'sort' => [
        'info' => 'downloads, date, name, author or revision',
        'default' => 'downloads',
      ],
    ],
  )
);
function cli_plugin_search(array $args)
{
  if (null === $args['term'] and !$args['all'])
  {
    PwgCommand::error('Give a text to search for, or --all to list everything');
    return PwgCommand::INVALID;
  }

  $plugins = cli_plugin_manager();

  // this asks piwigo.org, so it needs the server to reach the outside
  if (!$plugins->get_server_plugins(true, $args['beta']))
  {
    PwgCommand::error('Could not reach '.PEM_URL.', check the network access of this server');
    return PwgCommand::ERROR;
  }

  $plugins->sort_server_plugins(in_array($args['sort'], ['downloads', 'date', 'name', 'author', 'revision']) ? $args['sort'] : 'downloads');

  $needle = null === $args['term'] ? null : mb_strtolower($args['term']);
  $found = [];
  foreach ($plugins->server_plugins as $plugin)
  {
    if (null !== $needle
        and false === mb_strpos(mb_strtolower($plugin['extension_name']), $needle)
        and false === mb_strpos(mb_strtolower($plugin['extension_description'] ?? ''), $needle))
    {
      continue;
    }

    $found[] = [
      'name' => $plugin['extension_name'],
      'version' => $plugin['revision_name'],
      'author' => $plugin['author_name'],
      'downloads' => $plugin['extension_nb_downloads'],
    ];
  }

  if (0 === count($found))
  {
    PwgCommand::writeln('nothing found'.(null === $needle ? '' : ' for "'.$args['term'].'"').'. Plugins already in plugins/ are not listed, see "pwg plugin list"');

    // piwigo.org lists extensions per Piwigo version and knows nothing about an unreleased one
    if (!$args['beta'])
    {
      PwgCommand::writeln('piwigo.org may have published nothing for Piwigo '.PHPWG_VERSION.' yet, try again with --beta (-b)');
    }

    return PwgCommand::SUCCESS;
  }

  PwgCommand::table(PwgCommand::paginate($found, $args));
  PwgCommand::pagination_footer('plugin');

  return PwgCommand::SUCCESS;
}

$cli->add_command('plugin.activate', 'cli_plugin_activate',
  array(
    'description' => 'Activate plugins, installing them first when needed',
    'boot' => 'full',
    'operands' => [
      'plugin_id' => [
        'info' => 'Plugin folder names, as shown by "pwg plugin list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_plugin_activate(array $args)
{
  return cli_plugin_apply('activate', $args['plugin_id'], 'active', 'activated');
}

$cli->add_command('plugin.deactivate', 'cli_plugin_deactivate',
  array(
    'description' => 'Deactivate plugins, keeping their data',
    'boot' => 'full',
    'operands' => [
      'plugin_id' => [
        'info' => 'Plugin folder names, as shown by "pwg plugin list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_plugin_deactivate(array $args)
{
  return cli_plugin_apply('deactivate', $args['plugin_id'], 'inactive', 'deactivated');
}

$cli->add_command('plugin.uninstall', 'cli_plugin_uninstall',
  array(
    'description' => 'Uninstall plugins: their data goes, their files stay',
    'boot' => 'full',
    'operands' => [
      'plugin_id' => [
        'info' => 'Plugin folder names, as shown by "pwg plugin list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_plugin_uninstall(array $args)
{
  // a plugin drops its own tables and settings here, there is no undo
  return cli_plugin_apply('uninstall', $args['plugin_id'], 'uninstalled', 'uninstalled',
    'Uninstall %s? Their tables and settings are dropped, their files stay.');
}

$cli->add_command('plugin.delete', 'cli_plugin_delete',
  array(
    'description' => 'Uninstall plugins and move their folder to plugins/trash',
    'boot' => 'full',
    'operands' => [
      'plugin_id' => [
        'info' => 'Plugin folder names, as shown by "pwg plugin list"',
        'multiple' => true,
      ],
    ],
  )
);
function cli_plugin_delete(array $args)
{
  global $conf;

  // the core would die() on this one, say it properly instead
  if (!$conf['enable_extensions_install'])
  {
    PwgCommand::error('the extension install/update/delete system is disabled ($conf[\'enable_extensions_install\'])');
    return PwgCommand::ERROR;
  }

  return cli_plugin_apply('delete', $args['plugin_id'], 'deleted', 'deleted',
    'Delete %s? Their data is dropped and their folder moves to plugins/trash.');
}

$cli->add_command('plugin.install', 'cli_plugin_install',
  array(
    'description' => 'Download a plugin from piwigo.org into plugins/',
    'boot' => 'full',
    'operands' => [
      'name' => [
        'info' => 'Plugin name as shown by "pwg plugin search"',
        'multiple' => true,
      ],
    ],
    'args' => [
      'activate' => [
        'short' => 'a',
        'info' => 'Activate each plugin right after installing it',
        'flag' => true,
      ],
      'beta' => [
        'short' => 'b',
        'info' => 'Show plugins compatible with previous version of Piwigo',
        'flag' => true,
      ],
      'version' => [
        'info' => 'Install this exact revision instead of the latest one, compatibility unchecked',
        'default' => null,
      ],
    ],
  )
);
function cli_plugin_install(array $args)
{
  global $conf;

  if (0 === count($args['name']))
  {
    PwgCommand::error('Which plugin? Give at least one name (see "pwg plugin search")');
    return PwgCommand::INVALID;
  }

  if (null !== $args['version'] and count($args['name']) > 1)
  {
    PwgCommand::error('--version applies to a single plugin, you named '.count($args['name']));
    return PwgCommand::INVALID;
  }

  if (!$conf['enable_extensions_install'])
  {
    PwgCommand::error('the extension install/update/delete system is disabled ($conf[\'enable_extensions_install\'])');
    return PwgCommand::ERROR;
  }

  $plugins = cli_plugin_manager();

  // the catalogue of what is not installed yet, this is what we can pick from
  if (!$plugins->get_server_plugins(true, $args['beta']))
  {
    PwgCommand::error('Could not reach '.PEM_URL.', check the network access of this server');
    return PwgCommand::ERROR;
  }

  $targets = [];
  foreach ($args['name'] as $name)
  {
    $matches = cli_plugin_match($plugins->server_plugins, $name);

    if (0 === count($matches))
    {
      PwgCommand::error('"'.$name.'" is not on '.PEM_URL.', or you already have it (see "pwg plugin list")');
      if (!$args['beta'])
      {
        PwgCommand::errln('piwigo.org may have published nothing for Piwigo '.PHPWG_VERSION.' yet, try again with --beta (-b)');
      }
      return PwgCommand::INVALID;
    }

    if (count($matches) > 1)
    {
      PwgCommand::error('"'.$name.'" matches '.count($matches).' plugins, be more precise:');
      PwgCommand::errln(array_map(function ($plugin) { return '  '.$plugin['extension_name']; }, $matches));
      return PwgCommand::INVALID;
    }

    $plugin = reset($matches);

    // the catalogue only carries the latest revision, an older one is another lookup
    if (null !== $args['version'])
    {
      $revision = cli_plugin_revision($plugin['extension_id'], $args['version']);

      if (null === $revision)
      {
        PwgCommand::error('"'.$plugin['extension_name'].'" has no revision "'.$args['version'].'" on '.PEM_URL);
        PwgCommand::errln('its latest one is '.$plugin['revision_name'].', see the plugin page for the others');
        return PwgCommand::INVALID;
      }

      // nothing checked its compatibility, that is the point of naming a revision
      PwgCommand::warning('installing "'.$plugin['extension_name'].'" '.$revision['revision_name'].' as asked, without checking it supports Piwigo '.PHPWG_VERSION);

      $plugin['revision_id'] = $revision['revision_id'];
      $plugin['revision_name'] = $revision['revision_name'];
    }

    $targets[] = $plugin;
  }


  if (PwgCommand::is_dry_run())
  {
    foreach ($targets as $plugin)
    {
      PwgCommand::writeln('would install "'.$plugin['extension_name'].'" '.$plugin['revision_name'].($args['activate'] ? ', then activate it' : ''));
    }
    return PwgCommand::SUCCESS;
  }

  $failed = 0;
  // one step per plugin: the download itself gives no progress, fetchRemote hides curl
  PwgCommand::progress_start(count($targets), 'downloading');

  foreach ($targets as $plugin)
  {
    PwgCommand::progress_label($plugin['extension_name']);

    $plugin_id = null;
    $status = $plugins->extract_plugin_files('install', $plugin['revision_id'], $plugin['extension_id'], $plugin_id);

    if ('ok' !== $status)
    {
      $failed++;
      PwgCommand::error('"'.$plugin['extension_name'].'": '.cli_plugin_extract_error($status));
      PwgCommand::progress_advance();
      continue;
    }

    // the class does not trace this one, the admin page does it itself
    pwg_activity('system', ACTIVITY_SYSTEM_PLUGIN, 'install', ['plugin_id' => $plugin_id, 'version' => $plugin['revision_name']]);
    PwgCommand::success('"'.$plugin['extension_name'].'" '.$plugin['revision_name'].' installed as "'.$plugin_id.'"');

    if ($args['activate'])
    {
      // the freshly extracted folder is unknown to the instance we are holding
      $plugins->get_fs_plugin($plugin_id);
      $errors = array_filter((array) $plugins->perform_action('activate', $plugin_id));

      if (count($errors) > 0)
      {
        $failed++;
        PwgCommand::error('"'.$plugin_id.'" installed but not activated: '.implode(', ', $errors));
        PwgCommand::progress_advance();
        continue;
      }

      PwgCommand::success('"'.$plugin_id.'" activated');
    }

    PwgCommand::progress_advance();
  }

  PwgCommand::progress_finish();

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

$cli->add_command('plugin.update', 'cli_plugin_update',
  array(
    'description' => 'Update installed plugins from piwigo.org, or list what is behind',
    'boot' => 'full',
    'operands' => [
      'plugin_id' => [
        'info' => 'Plugin folder names, none of them to only list what could be updated',
        'multiple' => true,
      ],
    ],
    'args' => [
      'all' => [
        'short' => 'a',
        'info' => 'Update every plugin that has a newer revision',
        'flag' => true,
      ],
    ],
  )
);
function cli_plugin_update(array $args)
{
  global $conf;

  // no plugin named and no --all: the only sensible question is "what is behind?"
  // parentheses matter here: "and" binds looser than "="
  $list_only = (0 === count($args['plugin_id']) and !$args['all']);

  if (!$list_only and !$conf['enable_extensions_install'])
  {
    PwgCommand::error('the extension install/update/delete system is disabled ($conf[\'enable_extensions_install\'])');
    return PwgCommand::ERROR;
  }

  $plugins = cli_plugin_manager();

  // the revisions of what we already have, so we can tell what is behind. Always the widest
  // search: an update is an update, whether piwigo.org published it for our version or the one before
  if (!$plugins->get_server_plugins(false, true))
  {
    PwgCommand::error('Could not reach '.PEM_URL.', check the network access of this server');
    return PwgCommand::ERROR;
  }

  $wanted = ($args['all'] or $list_only) ? array_keys($plugins->fs_plugins) : $args['plugin_id'];
  $targets = [];
  $unknown = [];
  $dev = [];

  foreach ($wanted as $plugin_id)
  {
    if (!isset($plugins->fs_plugins[$plugin_id]))
    {
      $unknown[] = $plugin_id;
      continue;
    }

    $fs_plugin = $plugins->fs_plugins[$plugin_id];
    $extension_id = $fs_plugin['extension'] ?? null;

    // "Version: auto" means a dev checkout: the files are managed by hand or by git,
    // and any revision from piwigo.org would overwrite them
    if ('auto' === $fs_plugin['version'])
    {
      $dev[] = $plugin_id;
      continue;
    }

    // a plugin without a piwigo.org id was not installed from there
    if (null === $extension_id or !isset($plugins->server_plugins[$extension_id]))
    {
      if (!$args['all'] and !$list_only)
      {
        PwgCommand::writeln('"'.$plugin_id.'" does not come from '.PEM_URL.', nothing to update');
      }
      continue;
    }

    $server = $plugins->server_plugins[$extension_id];

    // same test as the core update checker
    if (safe_version_compare($fs_plugin['version'], $server['revision_name'], '>='))
    {
      if (!$args['all'] and !$list_only)
      {
        PwgCommand::writeln('"'.$plugin_id.'" is already up to date ('.$fs_plugin['version'].')');
      }
      continue;
    }

    $targets[$plugin_id] = $server;
  }

  if (count($unknown) > 0)
  {
    PwgCommand::error('Plugin'.(1 === count($unknown) ? '' : 's').' not found in plugins/: '.implode(', ', $unknown));
    return PwgCommand::INVALID;
  }

  if (0 === count($targets))
  {
    PwgCommand::success('Everything is up to date');
    cli_plugin_report_dev($dev);

    if (0 === count($plugins->server_plugins))
    {
      PwgCommand::writeln('piwigo.org returned no revision at all, so there was nothing to compare against');
    }

    return PwgCommand::SUCCESS;
  }

  $rows = [];
  foreach ($targets as $plugin_id => $server)
  {
    $rows[] = [
      'plugin' => $plugin_id,
      'current' => $plugins->fs_plugins[$plugin_id]['version'],
      'latest' => $server['revision_name'],
    ];
  }
  PwgCommand::table($rows);

  if ($list_only)
  {
    PwgCommand::writeln(count($targets).' plugin'.(1 === count($targets) ? '' : 's').' can be updated, with "pwg plugin update --all"');
    cli_plugin_report_dev($dev);
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would update '.count($targets).' plugin'.(1 === count($targets) ? '' : 's'));
    cli_plugin_report_dev($dev);
    return PwgCommand::SUCCESS;
  }

  // the files are replaced on disk, a broken release cannot be undone from here
  if (!PwgCommand::confirm('Update '.count($targets).' plugin'.(1 === count($targets) ? '' : 's').'?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  $failed = 0;
  // one step per plugin: the download itself gives no progress, fetchRemote hides curl
  PwgCommand::progress_start(count($targets), 'updating');

  foreach ($targets as $plugin_id => $server)
  {
    PwgCommand::progress_label($plugin_id);

    $errors = array_filter((array) $plugins->perform_action('update', $plugin_id, ['revision' => $server['revision_id']]),
      function ($error) { return !empty($error) and 'ok' !== $error; });

    if (count($errors) > 0)
    {
      $failed++;
      PwgCommand::error('"'.$plugin_id.'": '.implode(', ', array_map('cli_plugin_extract_error', $errors)));
    }
    else
    {
      PwgCommand::success('"'.$plugin_id.'" updated to '.$server['revision_name']);
    }

    PwgCommand::progress_advance();
  }

  PwgCommand::progress_finish();

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// one precise revision of one extension. The catalogue only gives the latest one, so this
// asks for them all, across every Piwigo version: a named revision is wanted as named,
// whichever Piwigo it was published for
function cli_plugin_revision(int $extension_id, string $version): ?array
{
  global $conf;

  $versions = cli_plugin_pem_versions();

  if (empty($versions))
  {
    return null;
  }

  $get_data = [
    'category_id' => $conf['pem_plugins_category'],
    'format' => 'php',
    'version' => implode(',', $versions),
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

// plugins kept out of the update on purpose: a count, and the names only when asked
function cli_plugin_report_dev(array $dev)
{
  if (0 === count($dev))
  {
    return;
  }

  PwgCommand::writeln(count($dev).' plugin'.(1 === count($dev) ? '' : 's').' in dev mode (version "auto") left alone'
    .(PwgCommand::is_verbose() ? ':' : ', --verbose to list them'));

  if (PwgCommand::is_verbose())
  {
    PwgCommand::writeln(array_map(function ($plugin_id) { return '  '.$plugin_id; }, $dev));
  }
}

// every Piwigo version piwigo.org knows about, so a revision search filters nothing out
function cli_plugin_pem_versions(): array
{
  $result = '';
  if (!fetchRemote(PEM_URL.'/api/get_version_list.php?format=php', $result))
  {
    return [];
  }

  $versions = @unserialize($result);

  return is_array($versions) ? array_column($versions, 'id') : [];
}

// the catalogue entries whose name matches, exactly first so "Admin" wins over "Admin Tools 2"
function cli_plugin_match(array $server_plugins, string $name): array
{
  $needle = mb_strtolower($name);
  $matches = [];

  foreach ($server_plugins as $extension_id => $plugin)
  {
    if (mb_strtolower($plugin['extension_name']) === $needle)
    {
      return [$extension_id => $plugin];
    }

    if (false !== mb_strpos(mb_strtolower($plugin['extension_name']), $needle))
    {
      $matches[$extension_id] = $plugin;
    }
  }

  return $matches;
}

// the statuses extract_plugin_files() returns, in plain words
function cli_plugin_extract_error(string $status): string
{
  $messages = [
    'temp_path_error' => 'cannot create a temporary file in plugins/',
    'dl_archive_error' => 'cannot download the archive',
    'archive_error' => 'cannot read the archive',
    'extract_error' => 'cannot extract the archive, check the permissions of plugins/',
  ];

  return $messages[$status] ?? $status;
}

// every command above is the same shape: resolve the ids, say what would happen,
// ask when it destroys something, then hand each one to the core
function cli_plugin_apply(string $action, array $wanted, string $final_state, string $done, ?string $question = null)
{
  // a "multiple" operand may legally be empty, but doing nothing quietly is no answer
  if (0 === count($wanted))
  {
    PwgCommand::error('Which plugin? Give at least one id (see "pwg plugin list")');
    return PwgCommand::INVALID;
  }

  $plugins = cli_plugin_manager();
  $self = basename(rtrim(CLI_ROOT_PATH, '/'));

  $targets = [];
  $unknown = [];
  foreach ($wanted as $plugin_id)
  {
    if (!isset($plugins->fs_plugins[$plugin_id]))
    {
      $unknown[] = $plugin_id;
      continue;
    }

    // deleting the plugin we are running from would remove this very command
    if ('delete' === $action and $plugin_id === $self)
    {
      PwgCommand::warning('"'.$plugin_id.'" is the plugin this command runs from, skipped');
      continue;
    }

    $state = cli_plugin_state($plugins, $plugin_id);
    if ($state === $final_state or ('uninstalled' === $final_state and 'not installed' === $state))
    {
      PwgCommand::writeln('"'.$plugin_id.'" is already '.$state.', nothing to do');
      continue;
    }

    $targets[] = $plugin_id;
  }

  if (count($unknown) > 0)
  {
    PwgCommand::error('Plugin'.(1 === count($unknown) ? '' : 's').' not found in plugins/: '.implode(', ', $unknown));
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
  foreach ($targets as $plugin_id)
  {
    // the core traces the activity and returns what went wrong, empty when fine
    $errors = $plugins->perform_action($action, $plugin_id);
    $errors = array_filter((array) $errors, function ($error) { return !empty($error) and 'ok' !== $error; });

    if (count($errors) > 0)
    {
      $failed++;
      PwgCommand::error('"'.$plugin_id.'": '.implode(', ', $errors));
      continue;
    }

    PwgCommand::success('"'.$plugin_id.'" '.$done);
  }

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// the core class, built once per command
function cli_plugin_manager(): plugins
{
  static $plugins = null;

  if (null === $plugins)
  {
    // the file extends PluginMaintain, which the full boot already loaded
    if (!class_exists('plugins'))
    {
      include_once(PHPWG_ROOT_PATH.'admin/include/plugins.class.php');
    }

    $plugins = new plugins();
  }

  return $plugins;
}

function cli_plugin_state(plugins $plugins, string $plugin_id): string
{
  return $plugins->db_plugins_by_id[$plugin_id]['state'] ?? 'not installed';
}
