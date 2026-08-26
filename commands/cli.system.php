<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('doctor', 'cli_doctor',
  array(
    'description' => 'Diagnose the environment and the Piwigo installation',
    'boot' => 'none',
  )
);
function cli_doctor(array $args)
{
  global $cli, $conf;

  // read PHPWG_VERSION and REQUIRED_PHP_VERSION without executing constants.php, which would demand $conf and $prefixeTable
  $constants = file_get_contents(PHPWG_ROOT_PATH.'include/constants.php');
  preg_match("/PHPWG_VERSION', '([^']+)'/", $constants, $phpwg_version);
  preg_match("/REQUIRED_PHP_VERSION', '([^']+)'/", $constants, $required_php_Version);

  $report = [];
  $check = function (string $status, string $label, string $detail = '') use (&$report) {
    $report[] = ['status' => $status, 'label' => $label, 'detail' => $detail]; // ok | warn | fail
  };

  // environment, no dependency
  $valid_version = version_compare(PHP_VERSION, $required_php_Version[1], '>=');
  $check(
    $valid_version ? 'ok' : 'fail',
    'PHP '.PHP_VERSION,
    $valid_version ?  '' : 'required: '.$required_php_Version[1]
  );

  // three lists: required present, recommended present, required missing
  $required = ['mysqli'];
  $image_libs = ['gd', 'imagick'];
  $recommended = ['exif', 'mbstring', 'curl', 'intl'];

  $required_present = [];
  $required_missing = [];
  foreach ($required as $extension)
  {
    extension_loaded($extension) ? $required_present[] = $extension : $required_missing[] = $extension;
  }

  // server binaries, the list of the official docker image: core execs
  // imagemagick and ffmpeg, the others serve popular plugins
  $binaries = [
    'imagemagick' => ['magick', 'convert'],
    'ffmpeg' => ['ffmpeg'],
    'exiftool' => ['exiftool'],
    'mediainfo' => ['mediainfo'],
    'ghostscript' => ['gs'],
  ];

  $binaries_present = [];
  if (function_exists('exec') && DIRECTORY_SEPARATOR === '/')
  {
    foreach ($binaries as $name => $candidates)
    {
      foreach ($candidates as $candidate)
      {
        $out = null;
        @exec('command -v '.$candidate, $out, $status); // shell builtin, silent when absent
        if (0 === $status)
        {
          $binaries_present[] = $name;
          break;
        }
      }
    }
  }

  // one image library is required: gd or imagick extension, or the magick binary
  $image_present = array_filter($image_libs, 'extension_loaded');
  if (empty($image_present) && !in_array('imagemagick', $binaries_present))
  {
    $required_missing[] = 'gd or imagick or the imagemagick binary';
  }
  $required_present = array_merge($required_present, $image_present);

  $check('ok', 'required extensions', implode(', ', $required_present));
  $check('ok', 'recommended extensions', implode(', ', array_filter($recommended, 'extension_loaded')));
  $check('ok', 'server binaries', implode(', ', $binaries_present));
  if (!empty($required_missing))
  {
    $check('fail', 'missing required extensions', implode(', ', $required_missing));
  }

  include_once(PHPWG_ROOT_PATH.'include/functions.inc.php');
  [$container] = get_container_info();
  $is_git = is_dir(PHPWG_ROOT_PATH.'.git');
  $check(
    'ok',
    'environment',
    'none' === $container
      ? ($is_git ? 'git' : 'release build')
      : 'docker ('.$container.')'
  );

  // configuration files
  $check(
    'ok',
    'local config',
    is_file(PHPWG_ROOT_PATH.'local/config/config.inc.php') ? 'local/config/config.inc.php loaded' : 'none, defaults apply'
  );

  $installed = PwgCommand::SUCCESS === $cli->init_minimal(false);
  $check($installed ? 'ok' : 'warn', 'Piwigo installed', $installed ? '' : 'no local/config/database.inc.php, run "pwg install"');

  // database, only reachable when installed
  if ($installed)
  {
    $connected = PwgCommand::SUCCESS === $cli->init_db(false);
    $check($connected ? 'ok' : 'fail', 'database connection', $connected ? $conf['dblayer'].' on '.$conf['db_host'] : 'connection failed');

    if ($connected)
    {
      global $prefixeTable;
      $result = pwg_query('SELECT value FROM '.$prefixeTable.'config WHERE param = \'piwigo_db_version\'');
      $row = pwg_db_fetch_row($result);
      $db_version = $row[0] ?? null;

      // same rule as common.inc.php: the db follows the branch, not the patch
      $code_branch = implode('.', array_slice(explode('.', $phpwg_version[1]), 0, 2));
      $check(
        $db_version === $code_branch || $is_git ? 'ok' : 'warn',
        'version',
        'code '.$phpwg_version[1].' / database '.($db_version ?? 'unknown').($db_version === $code_branch || $is_git ? '' : ', upgrade needed?')
      );
    }
  }

  // piwigo says 777 for every dir it writes into, 777 means writable by anyone: test just that
  $data_dir = PHPWG_ROOT_PATH.$conf['data_location'];

  // dir => [severity when off-norm, consequence, message when missing (null: skip)]
  $writable_dirs = [
    $conf['data_location'] => ['fail', 'run "chmod -R 777 '.$conf['data_location'].'"', 'missing, created at first full boot'],
    $conf['data_location'].'templates_c/' => ['fail', 'run "chmod -R 777 '.$conf['data_location'].'"', null],
    'upload/' => ['warn', 'adding photos may fail', 'missing, created at first upload'],
    'plugins/' => ['warn', 'installing plugins via the ui may fail', 'missing, incomplete piwigo?'],
    'themes/' => ['warn', 'installing themes via the ui may fail', 'missing, incomplete piwigo?'],
    'language/' => ['warn', 'installing languages via the ui may fail', 'missing, incomplete piwigo?'],
  ];

  foreach ($writable_dirs as $dir => [$severity, $consequence, $missing])
  {
    $path = PHPWG_ROOT_PATH.$dir;

    if (!is_dir($path))
    {
      if (null !== $missing)
      {
        $check('warn', $dir, $missing);
      }
      continue;
    }

    $in_777 = (fileperms($path) & 0002) !== 0;
    $check($in_777 ? 'ok' : $severity, $dir, $in_777 ? 'in 777' : 'not in 777, '.$consequence);
  }

  // identity: who runs the CLI vs who owns the data
  if (function_exists('posix_geteuid') && is_dir($data_dir))
  {
    $process_user = posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid();
    $data_owner = posix_getpwuid(fileowner($data_dir))['name'] ?? fileowner($data_dir);

    $check(
      $process_user === $data_owner || is_writable($data_dir) ? 'ok' : 'warn',
      'process user',
      $process_user.' (data owned by '.$data_owner.'), try "sudo -u '.$data_owner.'" if writes fail'
    );
  }

  // render
  $symbols = [
    'ok' => PwgCommand::green('[+]'),
    'warn' => PwgCommand::yellow('[!]'),
    'fail' => PwgCommand::red('[x]'),
  ];
  $lines = [];
  foreach ($report as $probe)
  {
    $lines[] = $symbols[$probe['status']].' '.$probe['label'].('' !== $probe['detail'] ? ' - '.$probe['detail'] : '');
  }

  $fails = count(array_filter($report, function ($probe) { return 'fail' === $probe['status']; }));
  $warns = count(array_filter($report, function ($probe) { return 'warn' === $probe['status']; }));

  $lines[] = '';
  $lines[] = 0 === $fails && 0 === $warns
    ? 'No issues found!'
    : $fails.' error(s), '.$warns.' warning(s)';

  PwgCommand::writeln($lines);

  return $fails > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// doctor says how the install feels, status says what it holds
$cli->add_command('status', 'cli_status',
  array(
    'description' => 'Show the gallery in numbers',
    'boot' => 'minimal',
  )
);
function cli_status(array $args)
{
  $counts = [
    'photos' => 'SELECT COUNT(*) FROM '.IMAGES_TABLE,
    'albums' => 'SELECT COUNT(*) FROM '.CATEGORIES_TABLE,
    'users' => 'SELECT COUNT(*) FROM '.USERS_TABLE,
    'tags' => 'SELECT COUNT(*) FROM '.TAGS_TABLE,
    'comments' => 'SELECT COUNT(*) FROM '.COMMENTS_TABLE,
  ];

  $facts = [
    'version' => PHPWG_VERSION,
  ];
  foreach ($counts as $metric => $query)
  {
    $facts[$metric] = pwg_db_fetch_row(pwg_query($query))[0];
  }

  [$last_photo] = pwg_db_fetch_row(pwg_query('SELECT MAX(date_available) FROM '.IMAGES_TABLE));
  $facts['last photo added'] = $last_photo ?? 'never';

  // images.filesize is stored in KB
  [$weight] = pwg_db_fetch_row(pwg_query('SELECT SUM(filesize) FROM '.IMAGES_TABLE));
  $facts['files total size'] = null === $weight
    ? '0 MB'
    : ($weight >= 1048576 ? round($weight / 1048576, 1).' GB' : round($weight / 1024).' MB');

  $lines = [];
  $width = max(array_map('strlen', array_keys($facts)));
  foreach ($facts as $label => $value)
  {
    $lines[] = '  '.str_pad($label, $width + 3).PwgCommand::green((string) $value);
  }

  PwgCommand::writeln($lines);

  return PwgCommand::SUCCESS;
}

$cli->add_command('list', 'cli_list',
  array(
    'description' => 'List every available command',
    'boot' => 'none',
    'args' => [
      'all' => [
        'short' => 'a',
        'info' => 'Include hidden commands',
        'flag' => true,
      ],
    ],
  )
);
function cli_list(array $args)
{
  global $cli;

  // group by namespace, the no-dot commands under "system"
  $groups = [];
  $width = 0;
  foreach ($cli->all_commands() as $command)
  {
    if (!$args['all'] && !empty($command['spec']['hidden']))
    {
      continue;
    }

    $namespace = strpos($command['name'], '.') !== false ? strstr($command['name'], '.', true) : '';
    $groups[$namespace][str_replace('.', ' ', $command['name'])] = $command['spec']['description'] ?? '';
    $width = max($width, strlen(str_replace('.', ' ', $command['name'])));
  }
  ksort($groups);

  $lines = [];
  foreach ($groups as $namespace => $commands)
  {
    if ([] !== $lines)
    {
      $lines[] = '';
    }
    $lines[] = PwgCommand::yellow('' === $namespace ? 'system' : $namespace);

    ksort($commands);
    foreach ($commands as $display => $description)
    {
      $lines[] = '  '.PwgCommand::green(str_pad($display, $width + 3)).$description;
    }
  }

  PwgCommand::writeln($lines);

  return PwgCommand::SUCCESS;
}

$cli->add_command('shortcut', 'cli_shortcut',
  array(
    'description' => 'Install a global "pwg" command into /usr/local/bin',
    'boot' => 'none',
    'hidden' => DIRECTORY_SEPARATOR !== '/',
    'args' => [
      'revert' => [
        'short' => 'r',
        'info' => 'Remove the global "pwg" command instead',
        'flag' => true,
      ],
    ],
  )
);
function cli_shortcut(array $args)
{
  if (DIRECTORY_SEPARATOR !== '/')
  {
    PwgCommand::error('unix only, on Windows add '.CLI_ROOT_PATH.'bin to your PATH manually');
    return PwgCommand::ERROR;
  }

  $target = '/usr/local/bin/pwg';
  $launcher = CLI_ROOT_PATH.'bin/pwg.php';
  $exists = is_link($target) || file_exists($target); // a broken link still holds the name
  $current = is_link($target) ? readlink($target) : ($exists ? 'not a symlink' : '');

  if ($args['revert'])
  {
    if (!$exists)
    {
      PwgCommand::writeln('nothing to remove, '.$target.' does not exist');
      return PwgCommand::SUCCESS;
    }

    if (PwgCommand::is_dry_run())
    {
      PwgCommand::writeln('would remove '.$target.' -> '.$current);
      return PwgCommand::SUCCESS;
    }

    if (!is_writable(dirname($target)))
    {
      PwgCommand::error('no write access to '.$target);
      PwgCommand::errln('run it as root:  sudo php '.$launcher.' shortcut --revert');
      return PwgCommand::ERROR;
    }

    if (!PwgCommand::confirm('remove '.$target.' -> '.$current.'?'))
    {
      PwgCommand::writeln('aborted');
      return PwgCommand::ERROR;
    }

    if (!unlink($target))
    {
      PwgCommand::error('could not remove '.$target);
      return PwgCommand::ERROR;
    }

    PwgCommand::success('"pwg" removed, only '.$launcher.' remains');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would link '.$target.' -> '.$launcher);
    return PwgCommand::SUCCESS;
  }

  if (!is_executable($launcher) && !@chmod($launcher, 0755))
  {
    PwgCommand::error($launcher.' is not executable, run "chmod +x" on it first');
    return PwgCommand::ERROR;
  }

  if (!is_writable(dirname($target)))
  {
    PwgCommand::error('no write access to '.$target);
    PwgCommand::errln('run it as root:  sudo php '.$launcher.' shortcut');
    return PwgCommand::ERROR;
  }

  if ($exists)
  {
    if (!PwgCommand::confirm($target.' exists ('.$current.'), overwrite?'))
    {
      PwgCommand::writeln('aborted');
      return PwgCommand::ERROR;
    }

    if (!unlink($target))
    {
      PwgCommand::error('could not remove the existing '.$target);
      return PwgCommand::ERROR;
    }
  }

  if (!symlink($launcher, $target))
  {
    PwgCommand::error('could not link '.$target);
    return PwgCommand::ERROR;
  }

  PwgCommand::success('"pwg" is now global ('.$target.' -> '.$launcher.'), try "pwg doctor" from anywhere');
  return PwgCommand::SUCCESS;
}

$cli->add_command('install', 'cli_install_pwg',
  array(
    'description' => 'Install Piwigo (soon)',
    'boot' => 'none',
  )
);
function cli_install_pwg()
{
  PwgCommand::writeln('Not yet available');
  return PwgCommand::SUCCESS;
}
