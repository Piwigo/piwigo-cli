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
*/
function pwg_case(string $label, array $args, int $expected_exit, ?string $expected_in_output = null, ?string $forbidden_in_output = null, ?string $stdin = null)
{
  global $passed, $failed;

  $bin = dirname(__DIR__).'/bin/pwg.php';

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
  pwg_case('status shows the gallery numbers on boot minimal', ['status'], 0, 'Piwigo ');
}

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

echo "\n".$passed.' passed, '.$failed.' failed'."\n";
exit($failed > 0 ? 1 : 0);
