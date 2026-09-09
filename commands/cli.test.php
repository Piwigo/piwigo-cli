<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('test.ok', 'cli_test_ok',
  array(
    'description' => 'Returns SUCCESS',
    'args' => [
      'name' => [
        'short' => 'n',
        'info' => 'Name argument',
        'default' => null,
      ],
      'show' => [
        'short' => 's',
        'info' => 'Show argument',
        'flag' => true,
      ],
    ],
    'hidden' => true,
    'boot' => 'none',
  )
);
function cli_test_ok(array $args)
{
  PwgCommand::writeln(json_encode($args));

  return PwgCommand::SUCCESS;
}

// interactive tour of everything PwgCommand offers, run it by hand:
//   pwg test io             the full ride, questions included
//   pwg test io --dry-run   report only, nothing to confirm
//   pwg test io --yes       no question asked
//   pwg test io < /dev/null what a cron sees
$cli->add_command('test.io', 'cli_test_io',
  array(
    'description' => 'Demo of every PwgCommand output and input',
    'boot' => 'none',
    'hidden' => true,
  )
);
function cli_test_io(array $args)
{
  PwgCommand::writeln('--- output ---');
  PwgCommand::writeln('writeln: one full line');
  PwgCommand::writeln(['writeln with an array:', '  one line', '  per entry']);

  PwgCommand::write('write: a line built');
  PwgCommand::write(' piece by piece, ');
  PwgCommand::write(['then an array', 'comma separated']);
  PwgCommand::writeln(''); // write never ends the line, writeln does

  PwgCommand::writeln(['nested arrays flatten:', ['one', 'two'], 'end']);

  PwgCommand::writeln('--- table ---');
  PwgCommand::table([
    // first row's keys become the headers, a DB fetch prints as-is
    ['id' => 1, 'album' => 'Racine', 'photos' => 240],
    ['id' => 12, 'album' => 'Vacances à Nîmes', 'photos' => 3],
  ]);

  PwgCommand::writeln('--- decorated (colors only on a terminal) ---');
  PwgCommand::success('success goes to STDOUT');
  PwgCommand::warning('warning goes to STDERR, command goes on');
  PwgCommand::error('error goes to STDERR too, and prints only: exiting is your job');
  PwgCommand::errln('errln: plain STDERR, for noise that must not pollute "pwg x > file"');

  PwgCommand::writeln('--- input ---');
  $name = PwgCommand::prompt('Your name? (enter skips)');
  if ('' === $name)
  {
    $name = 'anonymous'; // empty answer, EOF included, must have a sane fallback
  }
  PwgCommand::writeln('hello '.$name);

  PwgCommand::writeln('--- the destructive contract ---');
  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete 3 fake albums');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete 3 fake albums?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  PwgCommand::success('3 fake albums deleted (nothing really happened)');
  return PwgCommand::SUCCESS;
}

// the destructive-command contract in miniature: honor dry-run, confirm before
// acting, abort on refusal
$cli->add_command('test.confirm', 'cli_test_confirm',
  array(
    'description' => 'Confirms before pretending to destroy',
    'boot' => 'none',
    'hidden' => true,
  )
);
function cli_test_confirm(array $args)
{
  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would destroy 3 things');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Destroy 3 things?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  PwgCommand::writeln('destroyed');
  return PwgCommand::SUCCESS;
}

$cli->add_command('test.operands', 'cli_test_operands',
  array(
    'description' => 'One named operand then a variadic one',
    'boot' => 'none',
    'hidden' => true,
    'operands' => [
      'album' => [
        'info' => 'Target album',
      ],
      'files' => [
        'info' => 'Files to add',
        'multiple' => true,
      ],
    ],
  )
);
function cli_test_operands(array $args)
{
  PwgCommand::writeln(json_encode($args));

  return PwgCommand::SUCCESS;
}

// crosses the whole init chain: installed check, db, _data gate, common.inc.php
$cli->add_command('test.full', 'cli_test_full',
  array(
    'description' => 'Boots Piwigo entirely then reports',
    'boot' => 'full',
    'hidden' => true,
  )
);
function cli_test_full(array $args)
{
  global $user;

  PwgCommand::writeln('full boot reached, user status: '.($user['status'] ?? 'unknown'));
  return PwgCommand::SUCCESS;
}

$cli->add_command('test.throw', 'cli_test_throw',
  array(
    'description' => 'Throws, the engine must catch and exit ERROR',
    'boot' => 'none',
    'hidden' => true,
  )
);
function cli_test_throw(array $args)
{
  throw new Exception('boom from test.throw');
}

// exercises the progress bar, on a terminal and off (cron, redirect):
//   pwg test progress            a 1000-step bar with a warning in the middle
//   pwg test progress 2>&1 | cat the milestone lines a cron would get
$cli->add_command('test.progress', 'cli_test_progress',
  array(
    'description' => 'Demo of the progress bar',
    'hidden' => true,
    'boot' => 'none',
  )
);
function cli_test_progress()
{
  foreach (PwgCommand::iterate(range(1, 1000), 'progress') as $i)
  {
    if (500 === $i)
    {
      PwgCommand::warning('halfway');
    }
    if (600 === $i)
    {
      PwgCommand::progress_label('second half');
    }
    usleep(500);
  }

  // unknown total: a plain counter
  PwgCommand::progress_start(null, 'counting');
  for ($i = 0; $i < 2500; $i++)
  {
    PwgCommand::progress_advance();
  }
  PwgCommand::progress_finish();

  PwgCommand::success('progress done');
  return PwgCommand::SUCCESS;
}

// a second bar while one runs is a bug in the command, start() must refuse it
$cli->add_command('test.progress_twice', 'cli_test_progress_twice',
  array(
    'description' => 'Starts two progress bars at once',
    'hidden' => true,
    'boot' => 'none',
  )
);
function cli_test_progress_twice()
{
  PwgCommand::progress_start(10, 'first');
  PwgCommand::progress_start(10, 'second');

  return PwgCommand::SUCCESS;
}


// what the launcher guarantees before any command runs
$cli->add_command('test.env', 'cli_test_env',
  array(
    'description' => 'Prints the process environment the CLI set up',
    'hidden' => true,
    'boot' => 'none',
  )
);
function cli_test_env()
{
  PwgCommand::writeln([
    getcwd() === realpath(PHPWG_ROOT_PATH) ? 'cwd is the piwigo root' : 'cwd is '.getcwd(),
    sprintf('umask %04o', umask()),
  ]);

  return PwgCommand::SUCCESS;
}

// PHP errors raised inside a command: --level picks which one
$cli->add_command('test.php_error', 'cli_test_php_error',
  array(
    'description' => 'Raises a PHP warning, a silenced one, or a deprecation',
    'hidden' => true,
    'boot' => 'none',
    'args' => [
      'level' => [
        'info' => 'warning | silenced | deprecated',
        'default' => 'warning',
      ],
    ],
  )
);
function cli_test_php_error(array $args)
{
  $empty = [];

  switch ($args['level'])
  {
    case 'silenced':
      $value = @$empty['nope'];
      break;
    case 'deprecated':
      trigger_error('old way', E_USER_DEPRECATED);
      break;
    default:
      $value = $empty['nope'];
  }

  PwgCommand::success('survived');
  return PwgCommand::SUCCESS;
}

// a paginated listing: the engine adds --page and --limit, PwgCommand slices
$cli->add_command('test.pages', 'cli_test_pages',
  array(
    'description' => 'Demo of the shared pagination',
    'details' => [
      'The "details" paragraphs and the "examples" of a spec only show in the help, so the description can stay the single line that "pwg list" prints.',
    ],
    'examples' => [
      'pwg test pages --page 2',
    ],
    'hidden' => true,
    'boot' => 'none',
    'pagination' => true,
  )
);
function cli_test_pages(array $args)
{
  $rows = [];
  foreach (range(1, 25) as $i)
  {
    $rows[] = ['n' => $i, 'name' => 'row '.$i];
  }

  PwgCommand::table(PwgCommand::paginate($rows, $args));
  PwgCommand::pagination_footer('row');

  return PwgCommand::SUCCESS;
}

// the two shapes --format applies to: a list of things, and one thing
$cli->add_command('test.format', 'cli_test_format',
  array(
    'description' => 'Demo of table(), record() and --format',
    'hidden' => true,
    'boot' => 'none',
  )
);
function cli_test_format(array $args)
{
  PwgCommand::table([
    ['id' => 12, 'name' => 'Vacances', 'photos' => 3],
    ['id' => 13, 'name' => 'Noël', 'photos' => 0],
  ]);

  PwgCommand::record(['id' => 12, 'name' => 'Vacances', 'private' => false, 'tags' => ['mer', 'été']]);

  return PwgCommand::SUCCESS;
}
