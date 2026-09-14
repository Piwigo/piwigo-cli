<?php
// Bench for cli.update.php: a fake core, a throwaway root with two migration scripts, no database
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

require_once __DIR__.'/bench_root.php';
define('PHPWG_ROOT_PATH', bench_fake_root(
  'pwg_cli_bench_update_root',
  [
    'admin/include/functions.php',
    'admin/include/functions_upgrade.php',
    'admin/include/updates.class.php',
    'admin/include/pclzip.lib.php',
    'include/cache.class.php',
    'install/db/index.php',
  ],
  []
));
require_once __DIR__.'/bench.inc.php';
register_shutdown_function('bench_rmtree', rtrim(PHPWG_ROOT_PATH, '/'));

define('UPGRADE_TABLE', 'pwg_upgrade');
define('PHPWG_URL', 'https://piwigo.org');
$prefixeTable = 'pwg_';

// two migrations: 190 goes through, 191 fails on its query
file_put_contents(PHPWG_ROOT_PATH.'install/db/190-database.php', '<?php
$upgrade_description = \'add a column\';
pwg_query(\'ALTER TABLE pwg_images ADD COLUMN bench int\');
echo "noise the core prints";
');
file_put_contents(PHPWG_ROOT_PATH.'install/db/191-database.php', '<?php
$upgrade_description = \'break something\';
trigger_error(\'[mysql error 1060] Duplicate column name\', E_USER_WARNING);
');
// the files on disk still say 17.0.0 after a fake download, so no migration subprocess runs
@mkdir(PHPWG_ROOT_PATH.'include', 0777, true);
file_put_contents(PHPWG_ROOT_PATH.'include/constants.php', "<?php define('PHPWG_VERSION', '17.0.0');");

// what the scenarios steer: the database branch, the applied ids, piwigo.org, the container
$db_branch = '17';
$applied = ['190', '191'];
$piwigo_org = ['piwigo.org-checked' => true, 'is_dev' => false];
$container = ['none', null];
$missing = [];
$conf = ['piwigo_db_version' => '17', 'data_location' => '_data/', 'enable_core_update' => true];

function load_conf_from_db() { global $conf, $db_branch; $conf['piwigo_db_version'] = $db_branch; }
function get_branch_from_version($version) { return implode('.', array_slice(explode('.', $version), 0, 1)); }
function query2array($query, $key = null, $value = null) { global $applied; return $applied; }
function get_available_upgrade_ids()
{
  $ids = [];
  foreach (glob(PHPWG_ROOT_PATH.'install/db/*-database.php') as $file) { $ids[] = basename($file, '-database.php'); }
  return $ids;
}
function pwg_db_fetch_row($result) { return ['2026-09-10 12:00:00']; }
function pwg_query($query)
{
  global $ran;
  $flat = trim(preg_replace('/\s+/', ' ', $query));
  if (0 !== strpos($flat, 'SELECT')) { bench_core('query: '.$flat); }
  // what a migration script ran, kept aside since its output is swallowed on purpose
  if (0 === strpos($flat, 'ALTER')) { $ran[] = $flat; }
  return true;
}
$ran = [];
function pwg_db_real_escape_string($string) { return addslashes($string); }
function get_moment() { return 1.0; }
function get_elapsed_time($start, $end) { return '0.001 s'; }
function conf_update_param($param, $value) { bench_core("conf_update_param('$param', '$value')"); }
function conf_delete_param($param) { bench_core("conf_delete_param('$param')"); }
function invalidate_user_cache($full = false) { bench_core('invalidate_user_cache('.var_export($full, true).')'); }
function get_container_info() { global $container; return $container; }
function version_compare_php() {}
class PersistentFileCache {}
class updates
{
  public $missing = [];
  function get_piwigo_new_versions() { global $piwigo_org; return $piwigo_org; }
  function get_merged_extensions($version) { bench_core("get_merged_extensions('$version')"); }
  function get_server_extensions($version) { global $missing; bench_core("get_server_extensions('$version')"); $this->missing = $missing; }
  static function upgrade_to($to, &$step, $check = true) { bench_core("upgrade_to('$to', step=$step)"); }
}

include CLI_ROOT_PATH.'commands/cli.update.php';

bench_run([
  'up-nothing' => function () {
    return cli_upgrade();
  },
  'up-stamp' => function () {
    global $db_branch;
    $db_branch = '16';
    PwgCommand::assume_yes();
    return cli_upgrade();
  },
  'up-dry' => function () {
    global $db_branch, $applied;
    $db_branch = '16';
    $applied = [];
    PwgCommand::set_dry_run();
    return cli_upgrade();
  },
  'up-refused' => function () {
    global $applied;
    $applied = ['191'];
    return cli_upgrade();
  },
  'up-ok' => function () {
    global $applied, $ran;
    $applied = ['191'];
    PwgCommand::assume_yes();
    $exit = cli_upgrade();
    PwgCommand::writeln('scripts ran: '.implode(' | ', $ran));
    return $exit;
  },
  'up-failing' => function () {
    global $applied, $ran;
    $applied = [];
    PwgCommand::assume_yes();
    $exit = cli_upgrade();
    PwgCommand::writeln('scripts ran: '.implode(' | ', $ran));
    return $exit;
  },
  'update-disabled' => function () {
    global $conf;
    $conf['enable_core_update'] = false;
    return cli_update(['to' => null]);
  },
  'update-dev' => function () {
    global $piwigo_org;
    $piwigo_org['is_dev'] = true;
    return cli_update(['to' => null]);
  },
  'update-unreachable' => function () {
    global $piwigo_org;
    $piwigo_org['piwigo.org-checked'] = false;
    return cli_update(['to' => null]);
  },
  'update-latest' => function () {
    return cli_update(['to' => null]);
  },
  'update-list' => function () {
    global $piwigo_org;
    $piwigo_org += ['minor' => '17.0.2', 'minor_php' => '7.4', 'major' => '18.0.0', 'major_php' => '99.0'];
    return cli_update(['to' => null]);
  },
  'update-docker' => function () {
    global $piwigo_org, $container;
    $piwigo_org += ['minor' => '17.0.2'];
    $container = ['Official', '17.0.0b'];
    return cli_update(['to' => null]);
  },
  'update-bad-to' => function () {
    global $piwigo_org;
    $piwigo_org += ['minor' => '17.0.2'];
    return cli_update(['to' => '16.9.9']);
  },
  'update-php' => function () {
    global $piwigo_org;
    $piwigo_org += ['major' => '18.0.0', 'major_php' => '99.0'];
    return cli_update(['to' => '18.0.0']);
  },
  'update-dry' => function () {
    global $piwigo_org, $missing;
    $piwigo_org += ['major' => '18.0.0', 'major_php' => '7.4'];
    $missing = ['plugins' => [['name' => 'EditorPlus'], ['name' => 'Bench']], 'themes' => []];
    PwgCommand::set_dry_run();
    return cli_update(['to' => '18.0.0']);
  },
  'update-refused' => function () {
    global $piwigo_org;
    $piwigo_org += ['minor' => '17.0.2'];
    return cli_update(['to' => '17.0.2']);
  },
  'update-go' => function () {
    global $piwigo_org;
    $piwigo_org += ['minor' => '17.0.2'];
    PwgCommand::assume_yes();
    return cli_update(['to' => '17.0.2']);
  },
]);
