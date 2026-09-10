<?php
// Smoke tests for the pwg CLI. No framework: each case runs bin/pwg.php as a
// subprocess and checks the exit code and output, exactly like a user would.
//
//   php cli/tests/run.php
//
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

$passed = 0;
$failed = 0;

/**
* @param string $label What the case proves
* @param array $args argv for pwg.php
* @param int $expected_exit
* @param string|null $expected_in_output Substring that must appear (stdout+stderr)
* @param string|null $forbidden_in_output Substring that must NOT appear
* @param string|null $stdin What the command reads on STDIN, closed when null
* @param string|null $command Prepended to $args, to keep a long argument list readable
*/
function pwg_case(string $label, array $args, int $expected_exit, ?string $expected_in_output = null, ?string $forbidden_in_output = null, ?string $stdin = null, ?string $command = null)
{
  global $passed, $failed;

  $bin = dirname(__DIR__).'/bin/pwg.php';

  if (null !== $command)
  {
    array_unshift($args, $command);
  }

  $parts = array_map('escapeshellarg', array_merge([PHP_BINARY, $bin], $args));
  $command = 'PWG_CLI_TESTS=1 '.implode(' ', $parts).' 2>&1'; // the fixtures are gated off outside the suite

  // never inherit the runner's terminal: a confirm() would hang the suite
  $command = null === $stdin
    ? $command.' < /dev/null'
    : 'printf %s '.escapeshellarg($stdin).' | '.$command;

  exec($command, $output_lines, $exit);
  $output = implode("\n", $output_lines);

  $errors = [];
  if ($exit !== $expected_exit)
  {
    $errors[] = 'exit '.$exit.', expected '.$expected_exit;
  }
  if (null !== $expected_in_output && strpos($output, $expected_in_output) === false)
  {
    $errors[] = 'output misses "'.$expected_in_output.'"';
  }
  if (null !== $forbidden_in_output && strpos($output, $forbidden_in_output) !== false)
  {
    $errors[] = 'output contains "'.$forbidden_in_output.'"';
  }

  if (empty($errors))
  {
    $passed++;
    echo '  ok   '.$label."\n";
    return;
  }

  $failed++;
  echo '  FAIL '.$label.' ('.implode(', ', $errors).')'."\n";
  echo '       $ pwg '.implode(' ', $args)."\n";
  foreach ($output_lines as $line)
  {
    echo '       > '.$line."\n";
  }
}

/**
* Registration-time guards fire while loading commands: a broken fixture would
* kill every pwg run, so exercise add_command in a bare process instead
*/
function reg_case(string $label, string $php, string $expected_in_output)
{
  global $passed, $failed;

  $bootstrap = 'define("PHPWG_ROOT_PATH", "'.dirname(__DIR__, 3).'/");'
    .'define("CLI_ROOT_PATH", "'.dirname(__DIR__).'/");'
    .'include CLI_ROOT_PATH."cli_command.php";'
    .'include CLI_ROOT_PATH."cli_core.php";'
    .'$cli = new PwgCli();';

  exec(PHP_BINARY.' -r '.escapeshellarg($bootstrap.$php).' 2>&1 < /dev/null', $output_lines, $exit);
  $output = implode("\n", $output_lines);

  if (1 === $exit && strpos($output, $expected_in_output) !== false)
  {
    $passed++;
    echo '  ok   '.$label."\n";
    return;
  }

  $failed++;
  echo '  FAIL '.$label.' (exit '.$exit.', expected "'.$expected_in_output.'")'."\n";
  foreach ($output_lines as $line)
  {
    echo '       > '.$line."\n";
  }
}

echo "resolution\n";
pwg_case('dot form finds the command', ['test.ok'], 0, '"name":null');
pwg_case('space form finds the same command', ['test', 'ok'], 0, '"name":null');
pwg_case('longest name wins, rest becomes operands', ['test', 'operands', 'Vacances'], 0, '"album":"Vacances"');
pwg_case('unknown command fails and points to list', ['machin', 'truc'], 2, 'run "pwg list"');
pwg_case('bare pwg shows help but is an error', [], 2, 'Usage:');

echo "options\n";
pwg_case('long option takes the next token', ['test.ok', '--name', 'coucou'], 0, '"name":"coucou"');
pwg_case('short option maps to its long name', ['test.ok', '-n', 'coucou'], 0, '"name":"coucou"');
pwg_case('flag set', ['test.ok', '-s'], 0, '"show":true');
pwg_case('flag unset defaults to false', ['test.ok'], 0, '"show":false');
pwg_case('unknown option fails', ['test.ok', '--nope'], 2, 'Unknown option');
pwg_case('option without its value fails', ['test.ok', '--name'], 2, 'needs a value');

echo "inline values\n";
pwg_case('--name=value works', ['test.ok', '--name=coucou'], 0, '"name":"coucou"');
pwg_case('short form -n=value works too', ['test.ok', '-n=coucou'], 0, '"name":"coucou"');
pwg_case('inline value may start with a dash', ['test.ok', '--name=-1'], 0, '"name":"-1"');
pwg_case('inline value may be empty', ['test.ok', '--name='], 0, '"name":""');
pwg_case('a flag refuses an inline value', ['test.ok', '--show=1'], 2, 'is a flag and takes no value');
pwg_case('unknown inline option shows a clean name', ['test.ok', '--nope=x'], 2, 'Unknown option "--nope"');

echo "-- separator\n";
pwg_case('dashed operands pass after --', ['test.operands', 'Vac', '--', '-weird.jpg'], 0, '"files":["-weird.jpg"]');
pwg_case('options before -- still parse', ['test.operands', '--dry-run', 'Vac', '--', '--strange'], 0, '"album":"Vac","files":["--strange"]');
pwg_case('--help after -- is an operand, not help', ['test.operands', 'Vac', '--', '--help'], 0, '"files":["--help"]');

echo "operands\n";
pwg_case('named then variadic', ['test.operands', 'Vac', 'a.jpg', 'b.jpg'], 0, '"files":["a.jpg","b.jpg"]');
pwg_case('variadic may be empty', ['test.operands', 'Vac'], 0, '"files":[]');
pwg_case('missing mandatory operand fails', ['test.operands', '--dry-run'], 2, 'Missing argument "album"');
pwg_case('extra operand fails when nothing is variadic', ['test.ok', 'intrus'], 2, 'Unexpected argument');

echo "global args\n";
pwg_case('global flags never reach the callback args', ['test.ok', '--dry-run', '-y', '--verbose'], 0, null, '"dry-run"');
pwg_case('args stay pure without global flags too', ['test.ok'], 0, null, '"yes"');
pwg_case('dry-run state drives the destructive contract', ['test.confirm', '--dry-run'], 0, 'would destroy 3 things', 'Destroy');
pwg_case('global shorts stay global: -h is always help', ['test.operands', '-h'], 0, 'Usage:');

echo "confirm\n";
pwg_case('the question is asked, refusing aborts with ERROR', ['test.confirm'], 1, 'Destroy 3 things? [y/N]', null, "n\n");
pwg_case('answering y confirms', ['test.confirm'], 0, 'destroyed', null, "y\n");
pwg_case('--yes bypasses the question', ['test.confirm', '--yes'], 0, 'destroyed', 'Destroy');
pwg_case('-y works too', ['test.confirm', '-y'], 0, 'destroyed');
pwg_case('closed stdin aborts, a cron cannot destroy by accident', ['test.confirm'], 1, 'aborted');
pwg_case('dry-run reports without confirming nor destroying', ['test.confirm', '--dry-run'], 0, 'would destroy 3 things', 'destroyed');

echo "io demo\n";
pwg_case('piped answers feed prompt then confirm', ['test.io'], 0, 'hello Linty', null, "Linty\ny\n");
pwg_case('closed stdin: prompt falls back, confirm aborts', ['test.io'], 1, 'hello anonymous');
pwg_case('table aligns despite accents', ['test.io', '--dry-run'], 0, '| 12 | Vacances à Nîmes | 3      |');
pwg_case('writeln flattens nested arrays with the separator', ['test.io', '--dry-run'], 0, "nested arrays flatten:\none\ntwo\nend");

echo "engine\n";
pwg_case('a throwing command exits ERROR with a clean message', ['test.throw'], 1, '[ERROR]', 'Uncaught');
pwg_case('no stack trace without --verbose', ['test.throw'], 1, null, '#0 ');
pwg_case('--verbose adds file, line and trace', ['test.throw', '--verbose'], 1, 'cli.test.php');
pwg_case('-v inside a command is not verbose anymore', ['test.throw', '-v'], 2, 'Unknown option');

echo "launcher\n";
pwg_case('the cwd is the piwigo root whatever the shell cwd', ['test.env'], 0, 'cwd is the piwigo root');
pwg_case('umask is 0, files stay writable by the web server', ['test.env'], 0, 'umask 0000');

echo "php errors\n";
pwg_case('a warning fails the command instead of hiding under [OK]', ['test.php_error'], 1, 'Undefined array key', '[OK]');
pwg_case('--verbose locates the warning', ['test.php_error', '--verbose'], 1, 'cli.test.php:');
pwg_case('the @ operator is honored, the command goes on', ['test.php_error', '--level', 'silenced'], 0, 'survived');
pwg_case('a deprecation is silent by default', ['test.php_error', '--level', 'deprecated'], 0, 'survived', 'old way');
pwg_case('a deprecation shows with --verbose', ['test.php_error', '--level', 'deprecated', '--verbose'], 0, '[NOTICE] old way');

echo "pagination\n";
pwg_case('a paginated command gets --page and --limit', ['test.pages', '--help'], 0, '-l, --limit');
pwg_case('the first page is the default', ['test.pages'], 0, 'row 20', 'row 21');
pwg_case('--page moves to the next slice', ['test.pages', '--page', '2'], 0, 'row 21', 'row 20');
pwg_case('--limit changes the slice size', ['test.pages', '-l', '5'], 0, 'row 5', 'row 6');
pwg_case('the footer says where you are and how to go on', ['test.pages', '-l', '10'], 0, 'page 1/3, 25 rows, --page 2 for the next');
pwg_case('the last page has no next hint', ['test.pages', '-l', '10', '--page', '3'], 0, 'page 3/3', 'for the next');
pwg_case('a page beyond the end says so', ['test.pages', '--page', '3'], 0, 'there is no page 3, 25 rows fit in 2 pages');
pwg_case('it says so even when everything fits on one page', ['test.pages', '-l', '50', '--page', '2'], 0, 'there is no page 2, 25 rows fit in 1 page');
pwg_case('a single page prints no footer', ['test.pages', '-l', '50'], 0, 'row 25', 'page 1/1');

echo "long help\n";
pwg_case('details paragraphs only show in the help', ['test.pages', '--help'], 0, 'only show in the help');
pwg_case('examples are listed at the end', ['test.pages', '--help'], 0, "Examples:\n  pwg test pages --page 2");
pwg_case('the list keeps the one-line description', ['list', '-a'], 0, 'Demo of the shared pagination', 'only show in the help');

echo "output format\n";
pwg_case('a table is a grid by default', ['test.format'], 0, '| id | name');
pwg_case('a record is a field/value grid by default', ['test.format'], 0, "| field   | value");
pwg_case('--format=json turns the table into rows', ['test.format', '--format=json'], 0, '"name": "Vacances"', '+----');
pwg_case('--format=json keeps a record an object', ['test.format', '--format=json'], 0, '"private": false');
pwg_case('a record flattens what json would nest', ['test.format'], 0, '| tags    | ["mer","été"] |');
pwg_case('an unknown format is refused before the command runs', ['test.format', '--format=yaml'], 2, '--format takes "table" or "json"');
pwg_case('format never reaches the callback args', ['test.ok', '--format=json'], 0, null, '"format"');

echo "registration guards\n";
reg_case('duplicate name is rejected',
  '$cli->add_command("x.a", "cb"); $cli->add_command("x.a", "cb");',
  'already registered');
reg_case('a multiple operand must be the last one',
  '$cli->add_command("x.a", "cb", ["operands" => ["files" => ["multiple" => true], "name" => []]]);',
  'must be the last one');
reg_case('mandatory operand cannot follow an optional one',
  '$cli->add_command("x.a", "cb", ["operands" => ["a" => ["default" => null], "b" => []]]);',
  'cannot follow an optional one');
reg_case('a global option name cannot be redeclared',
  '$cli->add_command("x.a", "cb", ["args" => ["dry-run" => ["flag" => true]]]);',
  'reserved, it is a global option');
reg_case('a global short cannot be taken',
  '$cli->add_command("x.a", "cb", ["args" => ["host" => ["short" => "h"]]]);',
  'reserved by the global option "help"');
reg_case('two args of one command cannot share a short',
  '$cli->add_command("x.a", "cb", ["args" => ["size" => ["short" => "s"], "sort" => ["short" => "s"]]]);',
  'used by both "size" and "sort"');
reg_case('a paginated command cannot redeclare page',
  '$cli->add_command("x.a", "cb", ["pagination" => true, "args" => ["page" => ["default" => 1]]]);',
  '"page" is reserved, the command declares "pagination"');
reg_case('a paginated command cannot take the -l short',
  '$cli->add_command("x.a", "cb", ["pagination" => true, "args" => ["level" => ["short" => "l"]]]);',
  'used by both "level" and "limit"');
reg_case('a short must be a single letter',
  '$cli->add_command("x.a", "cb", ["args" => ["dirs-only" => ["short" => "do", "flag" => true]]]);',
  'short "do" of "dirs-only" must be a single letter');

echo "help\n";
pwg_case('pwg --help succeeds', ['--help'], 0, 'Available commands:');
pwg_case('pwg --version prints versions and exits SUCCESS', ['--version'], 0, 'Piwigo ', 'Warning');
pwg_case('bare -v is version too', ['-v'], 0, 'Piwigo ');
pwg_case('command --help shows its usage', ['test.operands', '--help'], 0, 'pwg test operands [options] <album> [<files>...]');
pwg_case('help wins over a missing mandatory operand', ['test.operands', '--help'], 0, null, 'Missing argument');
pwg_case('help lists the global options too', ['install', '--help'], 0, '--dry-run');

echo "boot\n";
if (!is_file(dirname(__DIR__, 3).'/local/config/database.inc.php'))
{
  // dev checkout: only the not-installed path is reachable
  pwg_case('a db-needing command fails cleanly when not installed', ['user', 'list'], 1, 'not installed');
  pwg_case('status needs a database too', ['status'], 1, 'not installed');
  echo "  skip full boot, needs an installed Piwigo\n";
}
else
{
  // installed (e.g. the VPS): the whole init chain, _data gate included
  echo "  skip installed here, not-installed path not testable\n";
  pwg_case('full boot crosses the _data gate', ['test.full'], 0, 'full boot reached');
  pwg_case('full boot acts as the webmaster, not guest', ['test.full'], 0, 'user status: webmaster', 'guest');
  pwg_case('status shows the gallery numbers on boot minimal', ['status'], 0, 'photos');
}

echo "progress\n";
pwg_case('off a terminal, progress prints a line every 10%', ['test.progress'], 0, 'progress  50% (500/1000)');
pwg_case('progress closes on a 100% line, with the current label', ['test.progress'], 0, 'second half 100% (1000/1000)');
pwg_case('progress never emits carriage returns off a terminal', ['test.progress'], 0, null, "\r");
pwg_case('a warning during progress keeps its own line', ['test.progress'], 0, '[WARNING] halfway');
pwg_case('unknown total counts by thousands', ['test.progress'], 0, 'counting... 2000');
pwg_case('unknown total closes with the final count', ['test.progress'], 0, 'counting done (2500)');
pwg_case('the label can change while the bar runs', ['test.progress'], 0, 'second half  60% (600/1000)');
pwg_case('starting a second bar is refused loudly', ['test.progress_twice'], 1, 'already running ("first")');

echo "system commands\n";
pwg_case('list hides hidden commands', ['list'], 0, 'install', 'test ok');
pwg_case('list -a shows them', ['list', '-a'], 0, 'test ok');
pwg_case('shortcut --dry-run shows the link, writes nothing', ['shortcut', '--dry-run'], 0, 'would link');
if (DIRECTORY_SEPARATOR === '/')
{
  pwg_case('shortcut shows on unix', ['list'], 0, 'shortcut');
}
else
{
  pwg_case('shortcut is hidden on Windows', ['list'], 0, null, 'shortcut');
}
pwg_case('shortcut --revert --dry-run never touches anything', ['shortcut', '--revert', '--dry-run'], 0, 'remove');
pwg_case('-r is the short form of revert', ['shortcut', '-r', '--dry-run'], 0, 'remove');

echo "doctor\n";
if (!is_file(dirname(__DIR__, 3).'/local/config/database.inc.php'))
{
  pwg_case('doctor reports not installed as a warning, no red', ['doctor'], 0, 'Piwigo installed - no local/config/database.inc.php');
  pwg_case('doctor summarizes and stays SUCCESS on warnings only', ['doctor'], 0, '0 error(s),');
}
else
{
  pwg_case('doctor sees the install and the database', ['doctor'], 0, 'database connection');
  pwg_case('doctor checks code vs database versions', ['doctor'], 0, 'version - code');
}

// Everything below needs a gallery. Nothing here writes: only --dry-run and listings,
// so the suite can run against a real Piwigo without leaving a single row behind.
if (is_file(dirname(__DIR__, 3).'/local/config/database.inc.php'))
{
  echo "listings (installed)\n";
  pwg_case('user list prints a table', ['user', 'list'], 0, '| user_id |');
  pwg_case('user list paginates', ['user', 'list', '-l', '1'], 0, '--page 2 for the next');
  pwg_case('an unknown user is rejected before anything', ['user', 'info', 'no_such_user_'.getmypid()], 2, 'not found');
  pwg_case('plugin list prints a table', ['plugin', 'list'], 0, '| state');
  pwg_case('plugin list filters on the name', ['plugin', 'list', '-s', 'piwigo-cli'], 0, 'piwigo-cli');
  pwg_case('a filter matching nothing says so', ['plugin', 'list', '-s', 'zzz_no_plugin'], 0, 'no plugin matching');
  pwg_case('theme list hides the core folders', ['theme', 'list'], 0, null, 'standard_pages');
  pwg_case('theme standard_pages reports its state', ['theme', 'standard_pages'], 0, 'standard pages are');
  pwg_case('status counts the gallery', ['status'], 0, 'photos');
  pwg_case('album list prints a table', ['album', 'list'], 0, '| photos |');
  pwg_case('album list can stay at the top level', ['album', 'list', '-p', 'root'], 0, '| id |');
  pwg_case('an unknown album is rejected', ['album', 'list', '-p', '999999'], 2, 'does not exist');
  pwg_case('photo list prints a table', ['photo', 'list', '-l', '3'], 0, '| file');
  pwg_case('photo list can stay in one album', ['photo', 'list', '-p', '1', '-l', '3'], 0, null, 'does not exist');
  pwg_case('an unknown photo is rejected', ['photo', 'info', '999999'], 2, 'does not exist');

  echo "dry runs (installed)\n";
  pwg_case('purge orphan tags counts without deleting', ['purge', 'orphan_tags', '--dry-run'], 0, null, 'deleted');
  pwg_case('purge orphan photos counts without deleting', ['purge', 'orphan_photos', '--dry-run'], 0, null, 'deleted');
  pwg_case('purge sessions counts without deleting', ['purge', 'sessions', '--dry-run'], 0, null, 'purged');
  pwg_case('purge derivatives reports the directory', ['purge', 'derivatives', '--dry-run'], 0, 'would delete every generated size');
  pwg_case('maintenance repair_db counts the tables', ['maintenance', 'repair_db', '--dry-run'], 0, 'would repair, reorder and optimize');
  pwg_case('user edit shows the fields it would change', ['user', 'edit', '1', '--level', '4', '--dry-run'], 0, 'would become');
  pwg_case('user edit refuses an empty change', ['user', 'edit', '1', '--dry-run'], 2, 'Nothing to change');
  pwg_case('user delete protects the webmaster', ['user', 'delete', '1', '--dry-run'], 0, 'protected account');
  pwg_case('plugin deactivate refuses an unknown plugin', ['plugin', 'deactivate', 'zzz_nope', '--dry-run'], 2, 'not found in plugins/');
  pwg_case('theme delete refuses a core folder', ['theme', 'delete', 'default', '--dry-run'], 0, 'belongs to the core');
  pwg_case('album add reports what it would create', ['album', 'add', 'pwg cli test album', '--dry-run'], 0, 'would create a public album');
  pwg_case('album delete counts the photos and every outcome', ['album', 'delete', '1', '--dry-run'], 0, 'would delete', 'What should happen');
  pwg_case('album edit refuses an unknown status', ['album', 'edit', '1', '-s', 'secret', '--dry-run'], 2, '--status takes');
  pwg_case('photo sync-metadata counts what it would read', ['photo', 'sync-metadata', '--all', '--dry-run'], 0, 'would read the metadata');
  pwg_case('photo move needs a target album', ['photo', 'move', '1', '--dry-run'], 2, 'Into which album?');
  pwg_case('photo import refuses a missing file', ['photo', 'import', '-p', '1', '/nope.jpg', '--dry-run'], 2, 'is not a file');

  echo "import dry runs (installed)\n";
  // a throwaway tree, two real jpeg files made here so nothing binary lives in the repo
  $tree = sys_get_temp_dir().'/pwg_cli_import_'.getmypid();
  @mkdir($tree.'/plage/soir_2', 0777, true);
  @mkdir($tree.'/vide', 0777, true);
  foreach (['a', 'b'] as $i => $name)
  {
    $image = imagecreatetruecolor(60, 40);
    imagefilledrectangle($image, 0, 0, 60, 40, imagecolorallocate($image, $i * 120, 90, 30));
    imagejpeg($image, $tree.($i ? '/plage/' : '/').$name.'.jpg');
  }

  pwg_case('import plans the albums and the photos', [$tree, '--dry-run'], 0, '+ pwg cli import '.getmypid(), null, null, 'import');
  pwg_case('import counts what it would do', [$tree, '--dry-run'], 0, 'photos: 2 to import', null, null, 'import');
  pwg_case('import goes down into the sub-folders', [$tree, '--dry-run'], 0, 'plage', null, null, 'import');
  pwg_case('--flat keeps everything in one album', [$tree, '--dry-run', '--flat'], 0, '2 photos', null, null, 'import');
  pwg_case('--dirs-only imports no photo', [$tree, '--dry-run', '-d'], 0, 'photos: 0 to import', null, null, 'import');
  pwg_case('--unwrap needs an album for the loose photos', [$tree, '--dry-run', '--unwrap'], 1, 'need an album', null, null, 'import');
  pwg_case('a directory that does not exist is refused', [$tree.'/nope', '--dry-run'], 1, 'is not a directory', null, null, 'import');
  pwg_case('the upload directory itself is refused', [dirname(__DIR__, 3).'/upload', '--dry-run'], 1, 'upload directory', null, null, 'import');

  // leave nothing behind, the tree was ours
  array_map('unlink', glob($tree.'/{,*/,*/*/}*.jpg', GLOB_BRACE));
  foreach ([$tree.'/plage/soir_2', $tree.'/plage', $tree.'/vide', $tree] as $dir)
  {
    @rmdir($dir);
  }
}

echo "\n".$passed.' passed, '.$failed.' failed'."\n";

// the benches cover what needs a gallery, with a fake core instead of one
echo "\nbenches\n";
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/bench/run_bench.php'), $bench_failed);

exit($failed > 0 || 0 !== $bench_failed ? 1 : 0);
