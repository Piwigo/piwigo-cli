<?php
// Bench for cli.system.php: the pure helpers, no gallery, no database
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

require_once __DIR__.'/bench_root.php';
// a root of our own, so the web user lookup reads a _data/ the scenarios control
define('PHPWG_ROOT_PATH', bench_fake_root('pwg_cli_bench_system_root', [], []));
@mkdir(PHPWG_ROOT_PATH, 0777, true);
include __DIR__.'/bench.inc.php';
register_shutdown_function('bench_rmtree', rtrim(PHPWG_ROOT_PATH, '/'));

$conf = ['data_location' => '_data/', 'log_dir' => '/logs'];

include CLI_ROOT_PATH.'commands/cli.system.php';

// a directory holding a fake "sudo", to put on the PATH or leave out of it
$fake_bin = sys_get_temp_dir().'/pwg_cli_bench_system_bin';
bench_rmtree($fake_bin);
@mkdir($fake_bin, 0777, true);
file_put_contents($fake_bin.'/sudo', "#!/bin/sh\n");
chmod($fake_bin.'/sudo', 0755);
register_shutdown_function('bench_rmtree', $fake_bin);

bench_run([
  'root-with-sudo' => function () use ($fake_bin) {
    putenv('PATH='.$fake_bin);
    PwgCommand::writeln(cli_shortcut_as_root('php /x/bin/pwg.php shortcut'));
    PwgCommand::writeln('sudo found: '.var_export(cli_has_program('sudo'), true));
    return PwgCommand::SUCCESS;
  },
  'root-without-sudo' => function () {
    // no /.dockerenv on a build machine, so this is the plain unix branch
    putenv('PATH=/pwg-cli-bench-nowhere');
    PwgCommand::writeln(cli_shortcut_as_root('php /x/bin/pwg.php shortcut --revert'));
    PwgCommand::writeln('sudo found: '.var_export(cli_has_program('sudo'), true));
    return PwgCommand::SUCCESS;
  },
  'run-as-with-sudo' => function () use ($fake_bin) {
    putenv('PATH='.$fake_bin);
    PwgCommand::writeln(cli_run_as('nginx', 'php /x/bin/pwg.php import ...'));
    return PwgCommand::SUCCESS;
  },
  'run-as-without-sudo' => function () {
    putenv('PATH=/pwg-cli-bench-nowhere');
    PwgCommand::writeln(cli_run_as('nginx', 'php /x/bin/pwg.php import ...'));
    return PwgCommand::SUCCESS;
  },
  'web-user' => function () {
    $me = cli_process_user();
    PwgCommand::writeln('nothing written yet: '.var_export(cli_web_user(), true));

    // our own log proves nothing, the web server one does
    @mkdir(PHPWG_ROOT_PATH.'_data/logs', 0777, true);
    touch(PHPWG_ROOT_PATH.'_data/logs/log_cli_2026-09-10_abc.txt');
    PwgCommand::writeln('only a cli log: '.var_export(cli_web_user(), true));
    touch(PHPWG_ROOT_PATH.'_data/logs/log_2026-09-10_abc.txt');
    PwgCommand::writeln('a web log, owned by me here: '.var_export(cli_web_user() === $me, true));

    // a compiled template wins over the logs
    @mkdir(PHPWG_ROOT_PATH.'_data/templates_c', 0777, true);
    touch(PHPWG_ROOT_PATH.'_data/templates_c/abc^def.file_index.tpl.php');
    PwgCommand::writeln('a compiled template, owned by me here: '.var_export(cli_web_user() === $me, true));
    return PwgCommand::SUCCESS;
  },
  'can-write' => function () {
    $me = cli_process_user();
    $dir = PHPWG_ROOT_PATH.'probe';
    @mkdir($dir, 0755, true);

    // me: the owner, so the owner bit decides. nobody: neither owner nor group, so only the world bit
    foreach (['0755', '0555', '0775', '0777'] as $mode)
    {
      chmod($dir, octdec($mode));
      PwgCommand::writeln($mode.' owner '.var_export(cli_user_can_write($me, $dir), true).', nobody '.var_export(cli_user_can_write('nobody', $dir), true));
    }

    chmod($dir, 0777);
    PwgCommand::writeln('unknown account: '.var_export(cli_user_can_write('pwg-cli-no-such-user', $dir), true));
    PwgCommand::writeln('missing path: '.var_export(cli_user_can_write($me, $dir.'/nope'), true));
    return PwgCommand::SUCCESS;
  },
  'program-lookup' => function () use ($fake_bin) {
    putenv('PATH='.$fake_bin.PATH_SEPARATOR.'/pwg-cli-bench-nowhere'.PATH_SEPARATOR);
    PwgCommand::writeln('sudo: '.var_export(cli_has_program('sudo'), true));
    PwgCommand::writeln('doas: '.var_export(cli_has_program('doas'), true));
    return PwgCommand::SUCCESS;
  },
]);
