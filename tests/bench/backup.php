<?php
// Bench for cli.backup.php: a fake connection with two tables, a fake mysqldump on a PATH of our own
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

require_once __DIR__.'/bench_root.php';
include __DIR__.'/bench.inc.php';

$prefixeTable = 'pwg_';
$conf = ['db_base' => 'gallery', 'db_host' => 'db.local:3307', 'db_user' => 'pwg', 'db_password' => 'se"cret'];

// the working directories: a destination, and a bin holding the fake dump tools
$work = sys_get_temp_dir().'/pwg_cli_bench_backup';
bench_rmtree($work);
@mkdir($work.'/out', 0777, true);
@mkdir($work.'/bin', 0777, true);
register_shutdown_function('bench_rmtree', $work);

// a mysqldump that prints what it was asked, then a canned dump
file_put_contents($work.'/bin/mysqldump', "#!/bin/sh\necho \"-- fake mysqldump: \$*\"\necho 'CREATE TABLE `pwg_config` (x int);'\n");
chmod($work.'/bin/mysqldump', 0755);
// and one that fails the way a wrong password does
@mkdir($work.'/broken', 0777, true);
file_put_contents($work.'/broken/mysqldump', "#!/bin/sh\necho 'mysqldump: Got error: 1045: Access denied' >&2\nexit 2\n");
chmod($work.'/broken/mysqldump', 0755);

function pwg_db_real_escape_string($s) { return addslashes($s); }
$show_tables = [];
function pwg_query($query)
{
  global $show_tables;
  $flat = trim(preg_replace('/\s+/', ' ', $query));
  if (0 === strpos($flat, 'SHOW TABLES')) { bench_core('query: '.$flat); $show_tables = [['pwg_config'], ['pwg_images']]; }
  return $flat;
}
function pwg_db_fetch_row($query)
{
  global $show_tables;
  if (0 === strpos($query, 'SHOW TABLES')) { return array_shift($show_tables) ?? false; }
  if (0 === strpos($query, 'SELECT SUM')) { return ['1572864']; }
  if (preg_match('/SHOW CREATE TABLE `(\w+)`/', $query, $m)) { return [$m[1], 'CREATE TABLE `'.$m[1].'` (id int, name text)']; }
  return false;
}

// the unbuffered read of a table: two rows for images, none for config
class BenchResult
{
  private $rows;
  function __construct(array $rows) { $this->rows = $rows; }
  function fetch_row() { return array_shift($this->rows) ?? false; }
  function free() {}
}
class BenchMysqli
{
  public $error = '';
  function query($query, $mode = 0)
  {
    bench_core('mysqli->query('.$query.', unbuffered='.var_export(MYSQLI_USE_RESULT === $mode, true).')');
    return false !== strpos($query, 'pwg_images') ? new BenchResult([[900, "Sunset o'clock"], [901, null]]) : new BenchResult([]);
  }
  function real_escape_string($s) { return addslashes($s); }
}
$mysqli = new BenchMysqli();

include CLI_ROOT_PATH.'commands/cli.backup.php';

// what the command wrote, read back through gzip
function bench_dump(string $dir): string
{
  $files = glob($dir.'/piwigo-*.sql.gz');
  return 1 === count($files) ? implode('', gzfile($files[0])) : count($files).' files';
}

bench_run([
  'backup-no-path' => function () {
    return cli_backup(['path' => null]);
  },
  'backup-no-dir' => function () use ($work) {
    return cli_backup(['path' => $work.'/nowhere']);
  },
  'backup-dry' => function () use ($work) {
    putenv('PATH='.$work.'/bin');
    PwgCommand::set_dry_run();
    $exit = cli_backup(['path' => $work.'/out/']);
    PwgCommand::writeln('files written: '.count(glob($work.'/out/*')));
    return $exit;
  },
  'backup-php' => function () use ($work) {
    putenv('PATH=/pwg-cli-bench-nowhere');
    $exit = cli_backup(['path' => $work.'/out']);
    PwgCommand::writeln('--- dump');
    PwgCommand::writeln(bench_dump($work.'/out'));
    return $exit;
  },
  'backup-tool' => function () use ($work) {
    putenv('PATH='.$work.'/bin');
    $exit = cli_backup(['path' => $work.'/out']);
    PwgCommand::writeln('--- dump');
    PwgCommand::writeln(bench_dump($work.'/out'));
    PwgCommand::writeln('secrets left behind: '.count(glob(sys_get_temp_dir().'/pwg-backup-*')));
    return $exit;
  },
  'backup-tool-fails' => function () use ($work) {
    putenv('PATH='.$work.'/broken');
    $exit = cli_backup(['path' => $work.'/out']);
    PwgCommand::writeln('files left: '.count(glob($work.'/out/*')));
    return $exit;
  },
]);
