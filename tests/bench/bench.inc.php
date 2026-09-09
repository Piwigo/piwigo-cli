<?php
// Shared skeleton for the benches: a fake Piwigo just big enough to load one command
// file and call its callbacks. Nothing here talks to a database, a gallery or the network.
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

// a bench that shadows a core file builds its own root first, see bench_fake_root()
defined('PHPWG_ROOT_PATH') or define('PHPWG_ROOT_PATH', dirname(__DIR__, 4).'/');
define('CLI_ROOT_PATH', dirname(__DIR__, 2).'/');
define('PEM_URL', 'https://piwigo.org/ext');
define('PHPWG_VERSION', '17.0.0');

include CLI_ROOT_PATH.'cli_command.php';

// add_command() is the engine's, a bench only wants the callbacks it declares
class BenchCli
{
  public array $specs = [];
  function add_command($name, $callback, $spec = [], $file = null) { $this->specs[$name] = $spec; }
}

$cli = new BenchCli();

// the scenario asked for on the command line, and what the bench is allowed to do
$bench_scenario = $argv[1] ?? '';

/**
* Declare the scenarios of a bench and run the one that was asked for. Each scenario is
* a callable returning the exit code. With no name, print the list.
*/
function bench_run(array $scenarios)
{
  global $bench_scenario;

  if (!isset($scenarios[$bench_scenario]))
  {
    echo 'scenarios: '.implode(', ', array_keys($scenarios))."\n";
    exit(2);
  }

  $exit = $scenarios[$bench_scenario]();
  echo 'exit='.$exit."\n";
  exit(0);
}

// what a bench prints when the fake core is reached, so a scenario shows the calls made
function bench_core(string $call)
{
  echo '  core: '.$call."\n";
}
