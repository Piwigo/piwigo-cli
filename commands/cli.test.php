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
