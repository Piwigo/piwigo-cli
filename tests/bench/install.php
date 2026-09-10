<?php
// Bench for the install command of cli.system.php: a fake core, a throwaway root, no database
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

require_once __DIR__.'/bench_root.php';
define('PHPWG_ROOT_PATH', bench_fake_root(
  'pwg_cli_bench_install_root',
  [
    'include/functions.inc.php',
    'include/constants.php',
    'include/dblayer/functions_mysqli.inc.php',
    'admin/include/functions.php',
    'admin/include/functions_install.inc.php',
    'admin/include/functions_upgrade.php',
    'admin/include/languages.class.php',
    'install/piwigo_structure-mysql.sql',
    'install/config.sql',
  ],
  []
));
require_once __DIR__.'/bench.inc.php';
register_shutdown_function('bench_rmtree', rtrim(PHPWG_ROOT_PATH, '/'));

define('REQUIRED_PHP_VERSION', '7.4.0');
define('ACTIVITY_SYSTEM_CORE', 1);
define('SITES_TABLE', 'sites');
define('USERS_TABLE', 'users');
define('UPGRADE_TABLE', 'upgrade');
define('MKGETDIR_DEFAULT', 7);
define('MKGETDIR_DIE_ON_ERROR', 2);

$conf = [];

// how many tables already answer to the prefix, the scenarios change it
$taken_tables = 0;

class languages
{
  public $fs_languages = ['en_UK' => 'English', 'fr_FR' => 'Français'];

  function __construct($charset) {}
  function perform_action($action, $language) { bench_core("languages->$action('$language')"); }
}

function load_language($file, $dirname = '', $options = []) {}
function l10n($string) { return $string; }
function validate_mail_address($user_id, $mail) { return false === strpos($mail, '@') ? 'mail address must be like xxx@yyy.eee' : ''; }
function pwg_db_connect($host, $user, $password, $base) { bench_core("pwg_db_connect('$host', '$user', '$base')"); }
function pwg_db_check_version() {}
function pwg_db_check_charset() {}
function pwg_query($query) { return trim(preg_replace('/\s+/', ' ', $query)); }
function pwg_db_num_rows($query) { global $taken_tables; return $taken_tables; }
function pwg_db_fetch_row($query) { return ['2026-09-10 12:00:00']; }
function pwg_db_real_escape_string($string) { return addslashes($string); }
function execute_sqlfile($file, $replaced, $replacing, $dblayer) { bench_core('execute_sqlfile('.basename($file).", $replaced -> $replacing)"); }
function conf_update_param($param, $value) { bench_core("conf_update_param('$param')"); }
function load_conf_from_db() {}
function get_branch_from_version($version) { return '17'; }
function activate_core_themes() { bench_core('activate_core_themes()'); }
function activate_core_plugins() { bench_core('activate_core_plugins()'); }
function mass_inserts($table, $fields, $rows) { bench_core("mass_inserts($table, ".count($rows).' rows: '.implode(',', $fields).')'); }
function create_user_infos($ids, $values) { bench_core('create_user_infos('.implode(',', $ids).", language=".$values['language'].')'); }
function get_available_upgrade_ids() { return [142, 143]; }
function pwg_activity($type, $id, $action, $details) { bench_core("pwg_activity('$type', '$action')"); }
function pwg_password_hash($password) { return '$P$hashed-'.strlen($password); }
function mkgetdir($dir, $flags = 7) { return @mkdir($dir, 0777, true) || is_dir($dir); }

include CLI_ROOT_PATH.'commands/cli.system.php';

$config_file = PHPWG_ROOT_PATH.'local/config/database.inc.php';
$blank = ['db-host' => null, 'db-name' => null, 'db-user' => null, 'db-password' => null,
          'db-prefix' => null, 'admin-user' => null, 'admin-password' => null,
          'admin-email' => null, 'language' => 'en_UK'];
$good = ['db-host' => 'db.local', 'db-name' => 'piwigo', 'db-user' => 'pwg', 'db-password' => "se'cret",
         'db-prefix' => 'pwg_', 'admin-user' => 'linty', 'admin-password' => 'hunter2',
         'admin-email' => 'me@example.org', 'language' => 'fr_FR'];

bench_run([
  'install-nothing' => function () use ($blank) {
    return cli_install_pwg($blank);
  },
  'install-bad-values' => function () use ($good) {
    return cli_install_pwg(['db-prefix' => '9-nope', 'admin-user' => "o'brien", 'admin-email' => 'nope', 'language' => 'zz_ZZ'] + $good);
  },
  'install-dry' => function () use ($good, $config_file) {
    PwgCommand::set_dry_run();
    $exit = cli_install_pwg($good);
    PwgCommand::writeln('config file written: '.var_export(is_file($config_file), true));
    return $exit;
  },
  'install-refused' => function () use ($good, $config_file) {
    $exit = cli_install_pwg($good);
    PwgCommand::writeln('config file written: '.var_export(is_file($config_file), true));
    return $exit;
  },
  'install-ok' => function () use ($good, $config_file) {
    PwgCommand::assume_yes();
    $exit = cli_install_pwg($good);
    PwgCommand::writeln('--- '.$config_file);
    PwgCommand::writeln(file_get_contents($config_file));
    return $exit;
  },
  'install-already' => function () use ($good, $config_file) {
    @mkdir(dirname($config_file), 0777, true);
    file_put_contents($config_file, '<?php $conf[\'db_base\'] = \'old\'; define(\'PHPWG_INSTALLED\', true);');
    return cli_install_pwg($good);
  },
  'install-halfway' => function () use ($good, $config_file) {
    // a file an earlier install wrote before dying: no PHPWG_INSTALLED, so it is no gallery
    @mkdir(dirname($config_file), 0777, true);
    file_put_contents($config_file, '<?php $conf[\'db_base\'] = \'half\';');
    PwgCommand::assume_yes();
    $exit = cli_install_pwg($good);
    PwgCommand::writeln('--- rewritten');
    PwgCommand::writeln(file_get_contents($config_file));
    return $exit;
  },
  'install-prefix-taken' => function () use ($good) {
    global $taken_tables;
    $taken_tables = 1;
    PwgCommand::assume_yes();
    return cli_install_pwg($good);
  },
]);
