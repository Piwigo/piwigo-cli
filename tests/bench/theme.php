<?php
// Bench for cli.theme.php: three themes on disk plus the two core folders, and a
// piwigo.org that publishes nothing for our Piwigo version
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

include __DIR__.'/bench.inc.php';

$conf = ['enable_extensions_install' => true, 'pem_themes_category' => 10];
$user = ['language' => 'fr_FR'];
define('ACTIVITY_SYSTEM_THEME', 'theme');

function pwg_activity() {}
function get_default_theme() { return 'modus'; }
function get_branch_from_version($version) { return substr($version, 0, strpos($version, '.')); }
function safe_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }

$bench_stored_standard_pages = false;
function conf_get_param($param, $default = null)
{
  global $bench_stored_standard_pages;
  return 'use_standard_pages' === $param ? $bench_stored_standard_pages : $default;
}
function conf_update_param($param, $value, $global = false)
{
  global $bench_stored_standard_pages;
  $bench_stored_standard_pages = $value;
  bench_core("conf_update_param('$param', ".var_export($value, true).')');
}

$bench_reachable = true;
function fetchRemote($url, &$dest, $get_data = [], $post_data = [], $user_agent = '', $step = 0)
{
  global $bench_reachable;

  if (!$bench_reachable)
  {
    return false;
  }

  bench_core('fetchRemote('.basename(parse_url($url, PHP_URL_PATH)).', versions='.($get_data['version'] ?? '-').')');

  if (false !== strpos($url, 'get_version_list'))
  {
    // piwigo.org knows 16 and 15, nothing about our 17
    $dest = serialize([['id' => 160, 'name' => '16'], ['id' => 150, 'name' => '15']]);
    return true;
  }

  if (false !== strpos($url, 'get_revision_list.php'))
  {
    $dest = serialize([
      ['extension_id' => 800, 'revision_id' => 8001, 'revision_name' => '1.0'],
      ['extension_id' => 800, 'revision_id' => 7900, 'revision_name' => '0.9'],
    ]);
    return true;
  }

  $dest = serialize(isset($get_data['extension_include'])
    ? [['extension_id' => 700, 'extension_name' => 'Elegant', 'revision_id' => 7001, 'revision_name' => '2.5', 'author_name' => 'a', 'extension_nb_downloads' => 10]]
    : [
      ['extension_id' => 800, 'extension_name' => 'Grum', 'extension_description' => 'a dark theme', 'revision_id' => 8001, 'revision_name' => '1.0', 'author_name' => 'grum', 'extension_nb_downloads' => 500],
      ['extension_id' => 801, 'extension_name' => 'Clear', 'extension_description' => 'a light theme', 'revision_id' => 8011, 'revision_name' => '2.0', 'author_name' => 'pwg', 'extension_nb_downloads' => 900],
    ]);

  return true;
}

class themes
{
  public $fs_themes = [
    'modus' => ['name' => 'Modus', 'version' => '16.a'],
    'bootstrap_darkroom' => ['name' => 'Bootstrap Darkroom', 'version' => '3.2'],
    'elegant' => ['name' => 'Elegant', 'version' => '2.1', 'extension' => 700],
    'default' => ['name' => 'default', 'version' => 'auto'],
    'standard_pages' => ['name' => 'standard_pages', 'version' => 'auto'],
  ];
  public $db_themes_by_id = [
    'modus' => ['id' => 'modus'],
    'bootstrap_darkroom' => ['id' => 'bootstrap_darkroom'],
  ];
  public $server_themes = [];

  function sort_server_themes($order) { bench_core("sort_server_themes('$order')"); }
  function get_fs_themes() {}
  function extract_theme_files($action, $revision, $dest, &$theme_id = null)
  {
    bench_core("extract_theme_files('$action', rev=$revision, dest=$dest)");
    $theme_id = 'NewTheme';
    return 'ok';
  }
  function perform_action($action, $id)
  {
    bench_core("perform_action('$action', '$id')");
    return 'elegant' === $id && 'activate' === $action
      ? ['Impossible to activate this theme, the parent theme is missing: foo']
      : [];
  }
}

include CLI_ROOT_PATH.'commands/cli.theme.php';

$page = ['page' => 1, 'limit' => 20];
$install = ['activate' => false, 'version' => null];

bench_run([
  'list' => function () use ($page) { return cli_theme_list(['search' => null] + $page); },
  'list-search' => function () use ($page) { return cli_theme_list(['search' => 'boot'] + $page); },
  'activate-failing' => function () { return cli_theme_activate(['theme_id' => ['elegant']]); },
  'activate-already' => function () { return cli_theme_activate(['theme_id' => ['modus']]); },
  'activate-unknown' => function () { return cli_theme_activate(['theme_id' => ['ghost']]); },
  'deactivate-dry' => function () { PwgCommand::set_dry_run(); return cli_theme_deactivate(['theme_id' => ['bootstrap_darkroom']]); },
  'delete-active' => function () { return cli_theme_delete(['theme_id' => ['modus']]); },
  'delete-builtin' => function () { return cli_theme_delete(['theme_id' => ['default', 'standard_pages']]); },
  'delete' => function () { PwgCommand::assume_yes(); return cli_theme_delete(['theme_id' => ['elegant']]); },
  'default-set' => function () { PwgCommand::assume_yes(); return cli_theme_default(['theme_id' => 'bootstrap_darkroom']); },
  'default-same' => function () { return cli_theme_default(['theme_id' => 'modus']); },
  'default-inactive' => function () { return cli_theme_default(['theme_id' => 'elegant']); },
  'nothing-named' => function () { return cli_theme_activate(['theme_id' => []]); },
  'standard-pages-show' => function () { return cli_theme_standard_pages(['state' => null]); },
  'standard-pages-on' => function () { return cli_theme_standard_pages(['state' => 'on']); },
  'standard-pages-already' => function () { return cli_theme_standard_pages(['state' => 'off']); },
  'standard-pages-bad' => function () { return cli_theme_standard_pages(['state' => 'oui']); },
  'standard-pages-dry' => function () { PwgCommand::set_dry_run(); return cli_theme_standard_pages(['state' => 'on']); },
  'search-all' => function () use ($page) { return cli_theme_search(['term' => null, 'all' => true, 'sort' => 'downloads'] + $page); },
  'search-term' => function () use ($page) { return cli_theme_search(['term' => 'dark', 'all' => false, 'sort' => 'downloads'] + $page); },
  'search-nothing-asked' => function () use ($page) { return cli_theme_search(['term' => null, 'all' => false, 'sort' => 'downloads'] + $page); },
  'search-offline' => function () use ($page) {
    $GLOBALS['bench_reachable'] = false;
    return cli_theme_search(['term' => null, 'all' => true, 'sort' => 'downloads'] + $page);
  },
  'install-dry' => function () use ($install) { PwgCommand::set_dry_run(); return cli_theme_install(['name' => ['Grum']] + $install); },
  'install' => function () use ($install) { return cli_theme_install(['name' => ['Grum'], 'activate' => true] + $install); },
  'install-version' => function () use ($install) { return cli_theme_install(['name' => ['Grum'], 'version' => '0.9'] + $install); },
  'install-version-missing' => function () use ($install) { return cli_theme_install(['name' => ['Grum'], 'version' => '9.9'] + $install); },
  'install-version-two' => function () use ($install) { return cli_theme_install(['name' => ['Grum', 'Clear'], 'version' => '0.9'] + $install); },
  'update-list' => function () { return cli_theme_update(['theme_id' => [], 'all' => false]); },
  'update-all' => function () { PwgCommand::assume_yes(); return cli_theme_update(['theme_id' => [], 'all' => true]); },
]);
