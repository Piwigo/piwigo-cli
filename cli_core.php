<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

final class PwgCli {
  private const GLOBAL_ARGS = [
    'help' => [
      'short' => 'h',
      'info' => 'Show this help',
      'flag' => true,
    ],
    'dry-run' => [
      'info' => 'Simulate, do not change anything',
      'flag' => true,
    ],
    'yes' => [
      'short' => 'y',
      'info' => 'Answer yes to every confirmation',
      'flag' => true,
    ],
    'verbose' => [
      'info' => 'Show debug details (stack traces, full errors)',
      'flag' => true,
    ],
  ];

  private array $commands = [];
  private array $current_command = [];
  private bool $show_help = false;
  private string $boot_level = 'full';

  public function boot()
  {
    // load all commands
    $this->load_commands();

    // get the current command
    $this->current_command = $this->get_current_command();

    // --help shortcut everything: no arg validation, no Piwigo boot
    if ($this->help_requested())
    {
      $this->show_help = true;
      $this->boot_level = 'none';
      return;
    }

    // set the boot_level
    $this->boot_level = $this->current_command['command']['spec']['boot'] ?? 'full';

    // replace input args by parsed args
    $this->current_command['args'] = $this->parse_args(
      $this->current_command['args'],
      $this->current_command['command']['spec']
    );

    // global flags feed the engine state, the callback only sees its own args
    if (!empty($this->current_command['args']['yes']))
    {
      PwgCommand::assume_yes();
    }
    if (!empty($this->current_command['args']['verbose']))
    {
      PwgCommand::set_verbose();
    }
    if (!empty($this->current_command['args']['dry-run']))
    {
      PwgCommand::set_dry_run();
    }
    foreach (array_keys(self::GLOBAL_ARGS) as $global_arg)
    {
      unset($this->current_command['args'][$global_arg]);
    }
  }

  public function init_minimal($verbose = true)
  {
    // define global $conf because
    // config/database.inc.php set new $conf key
    // global put these keys in the global scope
    global $conf, $prefixeTable;
    // define local dir and load database config
    defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');
    @include_once(PHPWG_ROOT_PATH.PWG_LOCAL_DIR.'config/database.inc.php');

    // if piwigo isn't installed, abort
    if (!defined('PHPWG_INSTALLED'))
    {
      if ($verbose)
      {
        PwgCommand::error('Piwigo is not installed, this command needs a database');
      }
      return PwgCommand::ERROR;
    }

    // include constants only in minimal init mode
    if ('full' !== $this->boot_level)
    {
      include_once(PHPWG_ROOT_PATH.'include/constants.php');
    }

    return PwgCommand::SUCCESS;
  }

  public function init_db($verbose = true)
  {
    global $conf;
    include_once(PHPWG_ROOT_PATH.'include/dblayer/functions_'.$conf['dblayer'].'.inc.php');

    try
    {
      pwg_db_connect($conf['db_host'], $conf['db_user'], $conf['db_password'], $conf['db_base']);
      return PwgCommand::SUCCESS;
    }
    catch (Exception $e)
    {
      if ($verbose)
      {
        PwgCommand::error('Unable to start a database connection:');
        PwgCommand::error($e->getMessage());
      }
      return PwgCommand::ERROR;
    }
  }

  public function init_piwigo($verbose = true)
  {
    global $conf;

    // we need to fake this variable because we're not longer
    // in HTTP context but in CLI context
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

    // _data is the only directory the full boot itself writes to (Smarty
    // compiles into _data/templates_c), probe it ignoring data_dir_checked
    $data_dir = PHPWG_ROOT_PATH.$conf['data_location'];

    $blocking = null;
    if (!is_dir($data_dir) || !is_writable($data_dir))
    {
      $blocking = $conf['data_location'];
    }
    elseif (is_dir($data_dir.'templates_c') && !is_writable($data_dir.'templates_c'))
    {
      // created 755 by the web server user, the classic CLI trap
      // (my first issue when i create thisd cli)
      $blocking = $conf['data_location'].'templates_c';
    }

    if (null !== $blocking)
    {
      if ($verbose)
      {
        PwgCommand::error('"'.$blocking.'" is missing or not writable, this command needs to write in it');
        PwgCommand::errln('give write access ("chmod -R 777 '.$conf['data_location'].'") or run as the web server user ("sudo -u www-data php '.CLI_ROOT_PATH.'bin/pwg.php ...")');
      }
      return PwgCommand::ERROR;
    }

    return PwgCommand::SUCCESS;
  }

  public function prepare_cli_user()
  {
    include_once(PHPWG_ROOT_PATH.'include/functions_plugins.inc.php');
    add_event_handler('user_init', 'cli_promote_user');
    return PwgCommand::SUCCESS;
  }

  public function prepare_cli_logger()
  {
    include_once(PHPWG_ROOT_PATH.'include/functions_plugins.inc.php');
    // "plugins_loaded" is the last event before the core writes its first log line
    add_event_handler('plugins_loaded', 'cli_swap_logger');

    return PwgCommand::SUCCESS;
  }

  public function run()
  {
    $command = $this->current_command['command'];

    if ($this->show_help)
    {
      $this->command_help($command);
      return PwgCommand::SUCCESS;
    }

    if (!is_callable($command['callback']) && !empty($command['file_to_include']))
    {
      include_once($command['file_to_include']);
    }

    if (!is_callable($command['callback']))
    {
      PwgCommand::error('Command "'.$command['name'].'" has no callable callback');
      return PwgCommand::ERROR;
    }

    try
    {
      $exit = call_user_func($command['callback'], $this->current_command['args']);
    }
    catch (\Throwable $th)
    {
      // catch all error to handle in CLI context
      PwgCommand::error('An error occurred');
      PwgCommand::errln($th->getMessage());
      if (PwgCommand::is_verbose())
      {
        PwgCommand::errln([$th->getFile().':'.$th->getLine(), $th->getTraceAsString()]);
      }
      return PwgCommand::ERROR;
    }

    return is_int($exit) ? $exit : PwgCommand::SUCCESS;
  }

  private function load_commands()
  {
    global $conf;

    $command_files = [
      'system',
      'user',
      'maintenance',
      'purge',
    ];

    // the test suite sets the env var, so fixtures stay invisible everywhere else
    if (!empty($conf['cli_allow_test_commands']) || false !== getenv('PWG_CLI_TESTS'))
    {
      array_push($command_files, 'test');
    }

    foreach ($command_files as $file)
    {
      include_once(CLI_ROOT_PATH.'commands/cli.'.$file.'.php');
    }
  }

  private function get_current_command()
  {
    $argv = $_SERVER['argv'];

    $words = [];
    for ($i = 1; $i < count($argv); $i++)
    {
      if (substr($argv[$i], 0, 1) === '-')
      {
        break; // an option closes the command name
      }
      $words[] = $argv[$i];
    }

    if (empty($words))
    {
      if (array_intersect(['--version', '-v'], $argv))
      {
        // read PHPWG_VERSION without executing constants.php, which would demand $conf and $prefixeTable
        preg_match("/PHPWG_VERSION', '([^']+)'/", file_get_contents(PHPWG_ROOT_PATH.'include/constants.php'), $matches);
        PwgCommand::writeln('Piwigo '.($matches[1] ?? 'unknown').', PHP '.PHP_VERSION);
        exit(PwgCommand::SUCCESS);
      }

      // "pwg" and "pwg --help" both land here, only the former is a mistake
      $this->general_help();
      exit(in_array('--help', $argv) || in_array('-h', $argv) ? PwgCommand::SUCCESS : PwgCommand::INVALID);
    }

    for ($length = count($words); $length > 0; $length--)
    {
      $name = implode('.', array_slice($words, 0, $length));

      if (isset($this->commands[$name]))
      {
        return [
          'command' => $this->commands[$name],
          'args' => array_slice($argv, $length + 1),
        ];
      }
    }

    PwgCommand::error('Command "'.implode(' ', $words).'" not found, run "pwg list" to see available commands');
    exit(PwgCommand::INVALID);
  }

  private function help_requested()
  {
    // -h and --help always mean help, global shorts cannot be claimed by a command
    foreach ($this->current_command['args'] as $arg)
    {
      if ('--' === $arg)
      {
        break; // past the separator, "--help" is an operand like any other
      }

      if ('--help' === $arg || '-h' === $arg)
      {
        return true;
      }
    }

    return false;
  }

  // to do:
  // - parsing group args like "-ds" is in fact -d -s
  // - type ( cast + validate to int or string)
  // - choices (parse enum like this args accepts only: 'guest', 'admin', 'normal')
  // - negative int need to be in "--value=-1" for value "-- -1" for operands
  //   we can have a better way: "pwg command -1"
  private function parse_args(array $args, ?array $infos)
  {
    // the command's own declaration wins, so it can give dry-run a short letter
    $spec = ($infos['args'] ?? []) + self::GLOBAL_ARGS;
    $spec_operands = $infos['operands'] ?? [];

    $options = [];
    $operands = []; // positional, e.g. the files in "image add *.jpg"

    // short letter => long name, e.g. n => name
    $shorts = [];
    foreach ($spec as $name => $arg_infos)
    {
      if (isset($arg_infos['short']))
      {
        $shorts[$arg_infos['short']] = $name;
      }
    }

    // retrieves all args
    $count = count($args);
    for ($i = 0; $i < $count; $i++)
    {
      $arg = $args[$i];

      if ('--' === $arg)
      {
        // everything after -- is an operand, even when it starts with a dash
        $operands = array_merge($operands, array_slice($args, $i + 1));
        break;
      }

      if (substr($arg, 0, 1) !== '-')
      {
        $operands[] = $arg;
        continue;
      }

      $name = ltrim($arg, '-'); // -name and --name are the same option

      // --name=value carries its value inline, the only way to pass a value
      // that itself starts with a dash, e.g. --order=-1
      $inline_value = null;
      $equal = strpos($name, '=');
      if (false !== $equal)
      {
        $inline_value = substr($name, $equal + 1);
        $name = substr($name, 0, $equal);
        $arg = explode('=', $arg, 2)[0]; // error messages show --name, not --name=value
      }

      // a long name wins over a short one, so an option named "n" stays reachable
      if (!isset($spec[$name]) && isset($shorts[$name]))
      {
        $name = $shorts[$name];
      }

      if (!isset($spec[$name]))
      {
        PwgCommand::error('Unknown option "'.$arg.'"');
        exit(PwgCommand::INVALID);
      }

      if (!empty($spec[$name]['flag']))
      {
        if (null !== $inline_value)
        {
          PwgCommand::error('Option "'.$arg.'" is a flag and takes no value');
          exit(PwgCommand::INVALID);
        }

        $options[$name] = true; // a flag never takes a value
        continue;
      }

      if (null !== $inline_value)
      {
        $options[$name] = $inline_value;
        continue;
      }

      $value = $args[$i + 1] ?? null;
      if (null === $value || substr($value, 0, 1) === '-')
      {
        PwgCommand::error('Option "'.$arg.'" needs a value');
        exit(PwgCommand::INVALID);
      }

      $options[$name] = $value;

      $i++; // the value has been consumed
    }

    // what the user did not write
    foreach ($spec as $name => $arg_infos)
    {
      if (!array_key_exists($name, $options))
      {
        $options[$name] = !empty($arg_infos['flag'])
          ? false
          : ($arg_infos['default'] ?? null);
      }
    }

    // marries the positional values with their declared name, in order
    $position = 0;
    $variadic = false;
    foreach ($spec_operands as $operand_name => $operand_infos)
    {
      if (!empty($operand_infos['multiple']))
      {
        $options[$operand_name] = array_slice($operands, $position);
        $position = count($operands);
        $variadic = true;
        continue;
      }

      if (array_key_exists($position, $operands))
      {
        $options[$operand_name] = $operands[$position];
      }
      elseif (array_key_exists('default', $operand_infos))
      {
        $options[$operand_name] = $operand_infos['default'];
      }
      else
      {
        PwgCommand::error('Missing argument "'.$operand_name.'"');
        exit(PwgCommand::INVALID);
      }

      $position++;
    }

    if (!$variadic && $position < count($operands))
    {
      PwgCommand::error('Unexpected argument "'.$operands[$position].'"');
      exit(PwgCommand::INVALID);
    }

    return $options;
  }

  private function general_help()
  {
    $lines = [
      'Usage:',
      '  pwg <command> [options] [arguments]',
      '  pwg --version, -v',
      '',
    ];

    $lines = array_merge($lines, ['Global options:'], $this->options_lines(self::GLOBAL_ARGS), ['']);

    $lines[] = 'Available commands:';
    PwgCommand::writeln($lines);

    cli_list(['all' => false]); // one single rendering for the commands list
  }

  private function command_help(array $command)
  {
    $spec = $command['spec'] ?? [];
    $lines = [];

    if (!empty($spec['description']))
    {
      $lines = ['Description:', '  '.$spec['description'], ''];
    }

    $usage = '  pwg '.str_replace('.', ' ', $command['name']).' [options]';
    foreach ($spec['operands'] ?? [] as $operand_name => $operand_infos)
    {
      $token = '<'.$operand_name.'>'.(!empty($operand_infos['multiple']) ? '...' : '');

      // variadic or defaulted operands are optional
      $usage .= !empty($operand_infos['multiple']) || array_key_exists('default', $operand_infos)
        ? ' ['.$token.']'
        : ' '.$token;
    }
    $lines = array_merge($lines, ['Usage:', $usage]);

    if (!empty($spec['operands']))
    {
      $lines[] = '';
      $lines[] = 'Arguments:';

      $width = max(array_map('strlen', array_keys($spec['operands'])));
      foreach ($spec['operands'] as $operand_name => $operand_infos)
      {
        $lines[] = '  '.str_pad($operand_name, $width + 3).($operand_infos['info'] ?? '');
      }
    }

    $lines[] = '';
    $lines[] = 'Options:';
    $lines = array_merge($lines, $this->options_lines(($spec['args'] ?? []) + self::GLOBAL_ARGS));

    PwgCommand::writeln($lines);
  }

  // "  -d, --dry-run   Simulate only" for each option, aligned
  private function options_lines(array $spec)
  {
    $left = [];
    foreach ($spec as $name => $arg_infos)
    {
      $left[$name] = (isset($arg_infos['short']) ? '-'.$arg_infos['short'].', ' : '    ').'--'.$name;
    }

    $width = max(array_map('strlen', $left));

    $lines = [];
    foreach ($spec as $name => $arg_infos)
    {
      $line = '  '.str_pad($left[$name], $width + 3).($arg_infos['info'] ?? '');

      if (empty($arg_infos['flag']) && isset($arg_infos['default']))
      {
        $line .= ' [default: '.$arg_infos['default'].']';
      }

      $lines[] = $line;
    }

    return $lines;
  }

  /**
  * @param callable|string|null $callback
  */
  function add_command(string $name, $callback = null, array $spec = [], ?string $file_to_include = null)
  {
    // check if this command already exist
    if (isset($this->commands[$name]))
    {
      PwgCommand::error('Command "'.$name.'" is already registered');
      exit(PwgCommand::ERROR);
    }

    // global options are engine territory: neither their names nor their shorts can be taken
    $reserved_shorts = [];
    foreach (self::GLOBAL_ARGS as $global_name => $global_infos)
    {
      if (isset($global_infos['short']))
      {
        $reserved_shorts[$global_infos['short']] = $global_name;
      }
    }
    $taken_shorts = [];
    foreach ($spec['args'] ?? [] as $arg_name => $arg_infos)
    {
      if (isset(self::GLOBAL_ARGS[$arg_name]))
      {
        PwgCommand::error('Command "'.$name.'": arg "'.$arg_name.'" is reserved, it is a global option');
        exit(PwgCommand::ERROR);
      }
      if (isset($arg_infos['short']))
      {
        if (isset($reserved_shorts[$arg_infos['short']]))
        {
          PwgCommand::error('Command "'.$name.'": short "'.$arg_infos['short'].'" is reserved by the global option "'.$reserved_shorts[$arg_infos['short']].'"');
          exit(PwgCommand::ERROR);
        }
        if (isset($taken_shorts[$arg_infos['short']]))
        {
          PwgCommand::error('Command "'.$name.'": short "'.$arg_infos['short'].'" is used by both "'.$taken_shorts[$arg_infos['short']].'" and "'.$arg_name.'"');
          exit(PwgCommand::ERROR);
        }
        $taken_shorts[$arg_infos['short']] = $arg_name;
      }
    }

    $new_command = [
      'name' => $name,
      'spec' => $spec,
      'callback' => $callback,
      'file_to_include' => $file_to_include,
    ];

    if (null === $new_command['callback'])
    {
      PwgCommand::error('Command "'.$name.'" must have a callback');
      exit(PwgCommand::ERROR);
    }

    // impossible operand declarations die here, at parse time the user could do nothing about them
    $seen_optional = false;
    $seen_multiple = false;
    foreach ($spec['operands'] ?? [] as $operand_name => $operand_infos)
    {
      if ($seen_multiple)
      {
        PwgCommand::error('Command "'.$name.'": operand "'.$operand_name.'" is unreachable, the multiple operand must be the last one');
        exit(PwgCommand::ERROR);
      }
      $seen_multiple = !empty($operand_infos['multiple']);

      $optional = $seen_multiple || array_key_exists('default', $operand_infos);
      if ($seen_optional && !$optional)
      {
        PwgCommand::error('Command "'.$name.'": mandatory operand "'.$operand_name.'" cannot follow an optional one');
        exit(PwgCommand::ERROR);
      }
      $seen_optional = $optional;
    }

    $this->commands[$name] = $new_command;
  }

  public function boot_level()
  {
    return $this->boot_level;
  }

  public function all_commands()
  {
    return $this->commands;
  }
}

function cli_promote_user()
{
  global $user, $conf;
  $user = build_user($conf['webmaster_id'] ?? 1, false);
}

// the web server owns its log file, the CLI writes its own
function cli_swap_logger()
{
  global $logger, $conf;

  $logger = new Logger(array(
    'directory' => PHPWG_ROOT_PATH.$conf['data_location'].$conf['log_dir'],
    'severity' => $conf['log_level'],
    // same hashed scheme as the core, the file must stay unreachable over HTTP
    'filename' => 'log_cli_'.date('Y-m-d').'_'.sha1(date('Y-m-d').$conf['db_password']).'.txt',
    // our own pattern, purging must never touch the web server files
    'globPattern' => 'log_cli_*.txt',
    'archiveDays' => $conf['log_archive_days'],
    ));
}
