<?php
// Bench for cli.plugin.php: three plugins on disk, a fake piwigo.org catalogue
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

include __DIR__.'/bench.inc.php';

$conf = ['enable_extensions_install' => true, 'pem_plugins_category' => 12];
define('ACTIVITY_SYSTEM_PLUGIN', 'plugin');

function pwg_activity() {}
function safe_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }

// piwigo.org, reachable unless a scenario says otherwise, and knowing nothing about our version
$bench_reachable = true;
function fetchRemote($url, &$dest, $get_data = [], $post_data = [], $user_agent = '', $step = 0)
{
  global $bench_reachable;

  if (!$bench_reachable)
  {
    return false;
  }

  bench_core('fetchRemote('.basename(parse_url($url, PHP_URL_PATH)).', ext='.($get_data['extension_include'] ?? '-').', versions='.($get_data['version'] ?? '-').')');

  if (false !== strpos($url, 'get_version_list'))
  {
    $dest = serialize([['id' => 160, 'name' => '16'], ['id' => 150, 'name' => '15']]);
    return true;
  }

  // every revision of extension 3, for --version
  $dest = serialize([
    ['extension_id' => 3, 'revision_id' => 5003, 'revision_name' => '1.3'],
    ['extension_id' => 3, 'revision_id' => 3003, 'revision_name' => '15.a'],
  ]);

  return true;
}

class plugins
{
  public $fs_plugins = [
    'AdminTools' => ['name' => 'Admin Tools', 'version' => 'auto', 'extension' => 900],
    'oAuth' => ['name' => 'oAuth', 'version' => '1.2', 'extension' => 901],
    'community' => ['name' => 'Community', 'version' => '16.f', 'extension' => 902],
    'piwigo-cli' => ['name' => 'Piwigo CLI', 'version' => 'auto'],
  ];
  public $db_plugins_by_id = [
    'AdminTools' => ['id' => 'AdminTools', 'state' => 'active', 'version' => '2.9'],
    'oAuth' => ['id' => 'oAuth', 'state' => 'inactive', 'version' => '1.2'],
  ];
  public $server_plugins = [];
  public $extract_status = 'ok';
  public $version_unknown = false;

  function get_server_plugins($new = false, $beta = false)
  {
    global $bench_reachable;

    if (!$bench_reachable)
    {
      return false;
    }

    // piwigo.org publishes nothing for our Piwigo unless the caller asks for the older one
    if ($this->version_unknown and !$beta)
    {
      $this->server_plugins = [];
      return true;
    }

    if (!$new)
    {
      // the revisions of what is installed: oAuth is behind
      $this->server_plugins = [
        900 => ['extension_id' => 900, 'extension_name' => 'Admin Tools', 'revision_id' => 9001, 'revision_name' => '2.9'],
        901 => ['extension_id' => 901, 'extension_name' => 'oAuth', 'revision_id' => 9011, 'revision_name' => '1.5'],
        902 => ['extension_id' => 902, 'extension_name' => 'Community', 'revision_id' => 9021, 'revision_name' => '16.f'],
      ];
      return true;
    }

    for ($i = 1; $i <= 25; $i++)
    {
      $this->server_plugins[$i] = [
        'extension_id' => $i,
        'revision_id' => 5000 + $i,
        'extension_name' => 0 === $i % 8 ? 'Admin Helper '.$i : 'Plugin '.$i,
        'extension_description' => 3 === $i ? 'an admin toolbox' : 'does thing '.$i,
        'revision_name' => '1.'.$i,
        'author_name' => 'author'.$i,
        'extension_nb_downloads' => 1000 - $i * 10,
      ];
    }

    return true;
  }
  function sort_server_plugins($order) { bench_core("sort_server_plugins('$order')"); }
  function get_fs_plugin($id) { $this->fs_plugins[$id] = ['name' => $id, 'version' => '1.0']; }
  function extract_plugin_files($action, $revision, $dest, &$plugin_id = null)
  {
    bench_core("extract_plugin_files('$action', rev=$revision, dest=$dest)");
    $plugin_id = 'NewPlugin';
    return $this->extract_status;
  }
  function perform_action($action, $id, $options = [])
  {
    bench_core("perform_action('$action', '$id')");
    return 'oAuth' === $id && 'activate' === $action ? ['missing php extension curl'] : [];
  }
}

include CLI_ROOT_PATH.'commands/cli.plugin.php';

$page = ['page' => 1, 'limit' => 20];
$install = ['activate' => false, 'beta' => false, 'version' => null];

bench_run([
  'list' => function () use ($page) { return cli_plugin_list(['search' => null] + $page); },
  'list-search' => function () use ($page) { return cli_plugin_list(['search' => 'admin'] + $page); },
  'list-search-none' => function () use ($page) { return cli_plugin_list(['search' => 'zzz'] + $page); },
  'activate-failing' => function () { return cli_plugin_activate(['plugin_id' => ['oAuth']]); },
  'activate-already' => function () { return cli_plugin_activate(['plugin_id' => ['AdminTools']]); },
  'activate-unknown' => function () { return cli_plugin_activate(['plugin_id' => ['ghost']]); },
  'deactivate-dry' => function () { PwgCommand::set_dry_run(); return cli_plugin_deactivate(['plugin_id' => ['AdminTools']]); },
  'uninstall' => function () { PwgCommand::assume_yes(); return cli_plugin_uninstall(['plugin_id' => ['AdminTools']]); },
  'uninstall-refused' => function () { return cli_plugin_uninstall(['plugin_id' => ['AdminTools']]); },
  'delete-itself' => function () { PwgCommand::assume_yes(); return cli_plugin_delete(['plugin_id' => ['piwigo-cli']]); },
  'nothing-named' => function () { return cli_plugin_activate(['plugin_id' => []]); },
  'search-all' => function () use ($page) { return cli_plugin_search(['term' => null, 'all' => true, 'sort' => 'downloads', 'beta' => false, 'limit' => 10] + $page); },
  'search-page-3' => function () use ($page) { return cli_plugin_search(['term' => null, 'all' => true, 'sort' => 'name', 'beta' => false, 'page' => 3, 'limit' => 10] + $page); },
  'search-beyond' => function () use ($page) { return cli_plugin_search(['term' => null, 'all' => true, 'sort' => 'downloads', 'beta' => false, 'page' => 9, 'limit' => 10] + $page); },
  'search-term' => function () use ($page) { return cli_plugin_search(['term' => 'admin', 'all' => false, 'sort' => 'downloads', 'beta' => false] + $page); },
  'search-nothing-asked' => function () use ($page) { return cli_plugin_search(['term' => null, 'all' => false, 'sort' => 'downloads', 'beta' => false] + $page); },
  'search-unknown-version' => function () use ($page) {
    cli_plugin_manager()->version_unknown = true;
    return cli_plugin_search(['term' => 'editor', 'all' => false, 'sort' => 'downloads', 'beta' => false] + $page);
  },
  'search-offline' => function () use ($page) {
    $GLOBALS['bench_reachable'] = false;
    return cli_plugin_search(['term' => null, 'all' => true, 'sort' => 'downloads', 'beta' => false] + $page);
  },
  'install-dry' => function () use ($install) { PwgCommand::set_dry_run(); return cli_plugin_install(['name' => ['Plugin 3']] + $install); },
  'install' => function () use ($install) { return cli_plugin_install(['name' => ['Plugin 3'], 'activate' => true] + $install); },
  'install-version' => function () use ($install) { return cli_plugin_install(['name' => ['Plugin 3'], 'version' => '15.a'] + $install); },
  'install-version-missing' => function () use ($install) { return cli_plugin_install(['name' => ['Plugin 3'], 'version' => '9.9'] + $install); },
  'install-version-two' => function () use ($install) { return cli_plugin_install(['name' => ['Plugin 3', 'Plugin 4'], 'version' => '1.1'] + $install); },
  'install-ambiguous' => function () use ($install) { return cli_plugin_install(['name' => ['Admin']] + $install); },
  'install-unknown' => function () use ($install) { return cli_plugin_install(['name' => ['nope']] + $install); },
  'install-failing' => function () use ($install) {
    cli_plugin_manager()->extract_status = 'dl_archive_error';
    return cli_plugin_install(['name' => ['Plugin 3']] + $install);
  },
  'update-list' => function () { return cli_plugin_update(['plugin_id' => [], 'all' => false]); },
  'update-all' => function () { PwgCommand::assume_yes(); return cli_plugin_update(['plugin_id' => [], 'all' => true]); },
  'update-dry' => function () { PwgCommand::set_dry_run(); return cli_plugin_update(['plugin_id' => ['oAuth'], 'all' => false]); },
  'update-up-to-date' => function () { return cli_plugin_update(['plugin_id' => ['community'], 'all' => false]); },
  'update-dev-mode' => function () { return cli_plugin_update(['plugin_id' => ['AdminTools'], 'all' => false]); },
]);
