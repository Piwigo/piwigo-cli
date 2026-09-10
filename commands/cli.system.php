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
      : 'docker ('.$container.'), the host mount may ignore the file modes checked below'
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

      // same rule as common.inc.php: the db follows the branch, one digit since Piwigo 11
      $code_branch = get_branch_from_version($phpwg_version[1]);
      $check(
        $db_version === $code_branch || $is_git ? 'ok' : 'warn',
        'version',
        'code '.$phpwg_version[1].' / database '.($db_version ?? 'unknown').($db_version === $code_branch || $is_git ? '' : ', upgrade needed?')
      );
    }
  }

  // two people need to write here: the web server, and whoever runs this
  $me = cli_process_user();
  $web_user = cli_web_user();

  // dir => [severity when the web server cannot write, what then breaks, what the CLI does there (null: nothing), message when missing (null: skip)]
  $writable_dirs = [
    $conf['data_location'] => ['fail', 'the gallery cannot work', 'most commands refuse to start', 'missing, created at first full boot'],
    $conf['data_location'].'templates_c/' => ['fail', 'pages cannot render', 'most commands refuse to start', null],
    'upload/' => ['fail', 'no upload from the admin', 'no import', 'missing, created at first upload'],
    'plugins/' => ['warn', 'no plugin install from the admin', 'no plugin install', 'missing, incomplete piwigo?'],
    'themes/' => ['warn', 'no theme install from the admin', 'no theme install', 'missing, incomplete piwigo?'],
    'language/' => ['warn', 'no language install from the admin', null, 'missing, incomplete piwigo?'],
  ];

  foreach ($writable_dirs as $dir => [$severity, $web_breaks, $cli_breaks, $missing])
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

    $by_me = is_writable($path);
    $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($path))['name'] ?? fileowner($path)) : fileowner($path);
    $mode = substr(sprintf('%o', fileperms($path)), -3);

    if (null === $web_user)
    {
      $check($by_me ? 'ok' : 'warn', $dir, ($by_me ? 'you can write' : 'you cannot write').', web server user unknown ('.$mode.' '.$owner.')');
      continue;
    }

    $by_web = cli_user_can_write($web_user, $path);
    $fix = 'chown '.$web_user.' or chmod 777';

    if ($by_web and $by_me)
    {
      $check('ok', $dir, $web_user.($me === $web_user ? '' : ' and you').' can write');
    }
    elseif ($by_web)
    {
      $check(null === $cli_breaks ? 'ok' : 'warn', $dir, $web_user.' can write, you cannot'.(null === $cli_breaks ? '' : ': '.$cli_breaks.' unless run as '.$web_user));
    }
    elseif ($by_me)
    {
      $check($severity, $dir, 'you can write, '.$web_user.' cannot: '.$web_breaks.'. '.$fix);
    }
    else
    {
      $check($severity, $dir, 'nobody can write ('.$mode.' '.$owner.'): '.$web_breaks.'. '.$fix);
    }
  }

  // identity: what the CLI creates belongs to whoever runs it
  if (null !== $me)
  {
    if (null === $web_user)
    {
      $check('warn', 'process user', $me.', web server user unknown: nothing written in '.$conf['data_location'].' yet');
    }
    elseif ($me === $web_user)
    {
      $check('ok', 'process user', $me.', same as the web server');
    }
    else
    {
      $check(
        'warn',
        'process user',
        $me.', web server is '.$web_user.': what you create, '.$web_user.' can read and delete, not rewrite. Avoid it: '.cli_run_as($web_user, 'php '.CLI_ROOT_PATH.'bin/pwg.php ...')
      );
    }
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
      PwgCommand::errln(cli_shortcut_as_root('php '.$launcher.' shortcut --revert'));
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
    PwgCommand::errln(cli_shortcut_as_root('php '.$launcher.' shortcut'));
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


// "sudo" is not everywhere: an Alpine container has none, and its root does not need one
function cli_shortcut_as_root(string $command): string
{
  foreach (['sudo', 'doas'] as $elevator)
  {
    if (cli_has_program($elevator))
    {
      return 'run it as root:  '.$elevator.' '.$command;
    }
  }

  if (is_file('/.dockerenv'))
  {
    return 'run it as root, this container has no sudo, leave it and come back as root:'."\n"
      .'  docker exec -u root <container> '.$command;
  }

  return 'run it as root, this system has no sudo:  '.$command;
}

$cli->add_command('install', 'cli_install_pwg',
  array(
    'description' => 'Install Piwigo: database, tables and webmaster account',
    'boot' => 'none',
    'details' => [
      'Does what install.php does in the browser, without the browser. Every value can be given as an option, and whatever is missing is asked for, so the command works as well by hand as in a script.',
      'It writes local/config/database.inc.php, creates the tables from install/piwigo_structure-mysql.sql, fills them from install/config.sql, activates the language and the core themes, then creates the webmaster and the guest account.',
      'It refuses to run when local/config/database.inc.php is already there, and when the database already holds tables with the same prefix. Piwigo is never told to send the connection settings by email.',
    ],
    'examples' => [
      'pwg install',
      'pwg install --db-name piwigo --db-user pwg --db-password secret --admin-user linty --admin-password hunter2 --admin-email me@example.org -y',
    ],
    'args' => [
      'db-host' => [
        'info' => 'Database host, asked for if missing, defaults to localhost',
        'default' => null,
      ],
      'db-name' => [
        'info' => 'Database name, asked for if missing',
        'default' => null,
      ],
      'db-user' => [
        'info' => 'Database user, asked for if missing',
        'default' => null,
      ],
      'db-password' => [
        'info' => 'Database password, asked for if missing',
        'default' => null,
      ],
      'db-prefix' => [
        'info' => 'Prefix of the table names, asked for if missing, defaults to piwigo_',
        'default' => null,
      ],
      'admin-user' => [
        'info' => 'Login of the webmaster account, asked for if missing',
        'default' => null,
      ],
      'admin-password' => [
        'info' => 'Password of the webmaster account, asked for if missing',
        'default' => null,
      ],
      'admin-email' => [
        'info' => 'Email of the webmaster account, asked for if missing',
        'default' => null,
      ],
      'language' => [
        'short' => 'l',
        'info' => 'Language of the gallery',
        'default' => 'en_UK',
      ],
    ],
  )
);
function cli_install_pwg(array $args)
{
  global $conf, $prefixeTable;

  $config_file = PHPWG_ROOT_PATH.'local/config/database.inc.php';

  if (is_file($config_file))
  {
    // read, never include: the file defines constants, and a constant cannot be undone
    $written = @file_get_contents($config_file);

    if (false === $written)
    {
      PwgCommand::error('cannot read '.$config_file);
      return PwgCommand::ERROR;
    }

    if (false !== strpos($written, 'PHPWG_INSTALLED'))
    {
      PwgCommand::error('Piwigo is already installed, '.$config_file.' says so');
      return PwgCommand::ERROR;
    }

    PwgCommand::warning($config_file.' is there but does not say the gallery is installed, an install left halfway. It will be overwritten.');
  }

  if (!extension_loaded('mysqli'))
  {
    PwgCommand::error('the mysqli extension is not loaded, Piwigo needs it');
    return PwgCommand::ERROR;
  }

  $answers = [
    'db-host' => cli_install_ask($args['db-host'], 'Database host', 'localhost'),
    'db-name' => cli_install_ask($args['db-name'], 'Database name'),
    'db-user' => cli_install_ask($args['db-user'], 'Database user'),
    'db-password' => cli_install_ask($args['db-password'], 'Database password', '', true),
    'db-prefix' => cli_install_ask($args['db-prefix'], 'Prefix of the table names', 'piwigo_'),
    'admin-user' => cli_install_ask($args['admin-user'], 'Login of the webmaster'),
    'admin-password' => cli_install_ask($args['admin-password'], 'Password of the webmaster', '', true),
    'admin-email' => cli_install_ask($args['admin-email'], 'Email of the webmaster'),
  ];

  // the core needs its own globals in place before constants.php names the tables
  $prefixeTable = $answers['db-prefix'];
  defined('DEFAULT_PREFIX_TABLE') or define('DEFAULT_PREFIX_TABLE', 'piwigo_');
  defined('PWG_LOCAL_DIR') or define('PWG_LOCAL_DIR', 'local/');

  include_once(PHPWG_ROOT_PATH.'include/functions.inc.php');
  include_once(PHPWG_ROOT_PATH.'include/constants.php');
  include_once(PHPWG_ROOT_PATH.'include/dblayer/functions_mysqli.inc.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_install.inc.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upgrade.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/languages.class.php');

  $languages = new languages('utf-8');
  $errors = cli_install_check($answers, $args['language'], $languages);

  if (count($errors) > 0)
  {
    foreach ($errors as $error)
    {
      PwgCommand::error($error);
    }

    return PwgCommand::INVALID;
  }

  load_language('common.lang', '', ['language' => $args['language'], 'target_charset' => 'utf-8']);
  load_language('admin.lang', '', ['language' => $args['language'], 'target_charset' => 'utf-8']);
  load_language('install.lang', '', ['language' => $args['language'], 'target_charset' => 'utf-8']);

  try
  {
    pwg_db_connect($answers['db-host'], $answers['db-user'], $answers['db-password'], $answers['db-name']);
    pwg_db_check_version();
    pwg_db_check_charset();
  }
  catch (Exception $exception)
  {
    PwgCommand::error('cannot reach the database "'.$answers['db-name'].'" as "'.$answers['db-user'].'" on "'.$answers['db-host'].'": '.$exception->getMessage());
    return PwgCommand::ERROR;
  }

  $taken = pwg_db_num_rows(pwg_query('SHOW TABLES LIKE \''.$answers['db-prefix'].'config\''));

  if ($taken > 0)
  {
    PwgCommand::error('"'.$answers['db-name'].'" already holds a Piwigo with the prefix "'.$answers['db-prefix'].'", pick another prefix or another database');
    return PwgCommand::ERROR;
  }

  PwgCommand::record([
    'database' => $answers['db-user'].'@'.$answers['db-host'].' / '.$answers['db-name'],
    'prefix' => $answers['db-prefix'],
    'webmaster' => $answers['admin-user'].' <'.$answers['admin-email'].'>',
    'language' => $args['language'],
    'config file' => $config_file,
  ]);

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would write the config file, create the tables and the webmaster');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Install Piwigo '.PHPWG_VERSION.' with these settings?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  if (!cli_install_write_config($config_file, $answers))
  {
    return PwgCommand::ERROR;
  }

  PwgCommand::writeln('config file written');

  cli_install_fill_database($answers, $args['language'], $languages);

  PwgCommand::success('Piwigo '.PHPWG_VERSION.' installed, log in as "'.$answers['admin-user'].'"');
  return PwgCommand::SUCCESS;
}

// the value given on the command line, or the question when it was not given
function cli_install_ask(?string $given, string $question, string $default = '', bool $hidden = false): string
{
  if (null !== $given)
  {
    return $given;
  }

  // a script has nobody to answer, take the default and let the checks speak
  if (!stream_isatty(STDIN))
  {
    return $default;
  }

  $asked = '' === $default ? $question.':' : $question.' ['.$default.']:';
  $answer = $hidden ? PwgCommand::prompt_hidden($asked) : PwgCommand::prompt($asked);

  return '' === $answer ? $default : $answer;
}

// same rules as install.php, all of them before anything is written
function cli_install_check(array $answers, string $language, languages $languages): array
{
  $errors = [];

  if (version_compare(PHP_VERSION, REQUIRED_PHP_VERSION, '<'))
  {
    $errors[] = 'Piwigo needs PHP '.REQUIRED_PHP_VERSION.', this is PHP '.PHP_VERSION;
  }

  if ('' === $answers['db-name'])
  {
    $errors[] = '--db-name is needed';
  }

  if ('' === $answers['db-user'])
  {
    $errors[] = '--db-user is needed';
  }

  $prefix = $answers['db-prefix'];

  if (strlen($prefix) > 20 or preg_match('/^\d/', $prefix) or !preg_match('/^[a-zA-Z0-9_$]*$/u', $prefix))
  {
    $errors[] = '"'.$prefix.'" is not a valid table prefix: at most 20 letters, digits, _ or $, never starting with a digit';
  }

  if ('' === $answers['admin-user'])
  {
    $errors[] = '--admin-user is needed';
  }
  elseif (preg_match('/[\'"]/', $answers['admin-user']))
  {
    $errors[] = 'the webmaster login cannot hold a quote';
  }

  if ('' === $answers['admin-password'])
  {
    $errors[] = '--admin-password is needed';
  }

  // never with an empty address: validate_mail_address() then reads a config key the
  // database is supposed to hold, and the database does not exist yet
  if ('' === $answers['admin-email'])
  {
    $errors[] = '--admin-email is needed';
  }
  elseif (!empty(validate_mail_address(null, $answers['admin-email'])))
  {
    $errors[] = '"'.$answers['admin-email'].'" is not a valid email address';
  }

  if (!isset($languages->fs_languages[$language]))
  {
    $errors[] = '"'.$language.'" is not one of the languages in language/';
  }

  return $errors;
}

// local/config/database.inc.php, in the format the core reads at every request
function cli_install_write_config(string $config_file, array $answers): bool
{
  $content = '<?php
$conf[\'dblayer\'] = \'mysqli\';
$conf[\'db_base\'] = \''.addslashes($answers['db-name']).'\';
$conf[\'db_user\'] = \''.addslashes($answers['db-user']).'\';
$conf[\'db_password\'] = \''.addslashes($answers['db-password']).'\';
$conf[\'db_host\'] = \''.addslashes($answers['db-host']).'\';

$prefixeTable = \''.addslashes($answers['db-prefix']).'\';

define(\'PHPWG_INSTALLED\', true);
define(\'PWG_CHARSET\', \'utf-8\');
define(\'DB_CHARSET\', \'utf8\');
define(\'DB_COLLATE\', \'\');

?'.'>';

  $directory = dirname($config_file);

  if (!is_dir($directory) and !mkgetdir($directory, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR))
  {
    PwgCommand::error('cannot create '.$directory);
    return false;
  }

  if (false === @file_put_contents($config_file, $content))
  {
    PwgCommand::error('cannot write '.$config_file.', check the permissions of '.$directory);
    return false;
  }

  return true;
}

// the same order install.php follows, it matters: the tables, then the config, then the users
function cli_install_fill_database(array $answers, string $language, languages $languages)
{
  global $conf;

  $prefix = $answers['db-prefix'];

  PwgCommand::progress_start(6, 'creating the tables');
  execute_sqlfile(PHPWG_ROOT_PATH.'install/piwigo_structure-mysql.sql', DEFAULT_PREFIX_TABLE, $prefix, 'mysql');
  PwgCommand::progress_advance();

  PwgCommand::progress_label('filling them');
  execute_sqlfile(PHPWG_ROOT_PATH.'install/config.sql', DEFAULT_PREFIX_TABLE, $prefix, 'mysql');
  PwgCommand::progress_advance();

  $query = '
INSERT INTO '.$prefix.'config (param, value, comment)
  VALUES (\'secret_key\', \''.sha1(random_bytes(1000)).'\', \'a secret key specific to the gallery for internal use\')
;';
  pwg_query($query);

  conf_update_param('piwigo_db_version', get_branch_from_version(PHPWG_VERSION));
  conf_update_param('gallery_title', pwg_db_real_escape_string(l10n('Just another Piwigo gallery')));
  conf_update_param('page_banner', '<h1>%gallery_title%</h1>'."\n\n<p>".pwg_db_real_escape_string(l10n('Welcome to my photo gallery')).'</p>');
  PwgCommand::progress_advance();

  PwgCommand::progress_label('activating the language');
  $languages->perform_action('activate', $language);
  load_conf_from_db();
  PwgCommand::progress_advance();

  PwgCommand::progress_label('activating the themes');
  defined('PWG_CHARSET') or define('PWG_CHARSET', 'utf-8');
  activate_core_themes();
  activate_core_plugins();
  PwgCommand::progress_advance();

  PwgCommand::progress_label('creating the accounts');
  $site = ['id' => 1, 'galleries_url' => PHPWG_ROOT_PATH.'galleries/'];
  mass_inserts(SITES_TABLE, array_keys($site), [$site]);

  // id 1 is the webmaster_id config.sql just wrote, id 2 is the guest
  $users = [
    [
      'id' => 1,
      'username' => pwg_db_real_escape_string($answers['admin-user']),
      'password' => pwg_password_hash($answers['admin-password']),
      'mail_address' => pwg_db_real_escape_string($answers['admin-email']),
    ],
    [
      'id' => 2,
      'username' => 'guest',
    ],
  ];
  mass_inserts(USERS_TABLE, array_keys($users[0]), $users);
  create_user_infos([1, 2], ['language' => $language]);

  // a fresh gallery has nothing to upgrade, mark every migration as already applied
  [$now] = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
  defined('CURRENT_DATE') or define('CURRENT_DATE', $now);
  $upgrades = [];

  foreach (get_available_upgrade_ids() as $upgrade_id)
  {
    $upgrades[] = ['id' => $upgrade_id, 'applied' => CURRENT_DATE, 'description' => 'upgrade included in installation'];
  }

  mass_inserts(UPGRADE_TABLE, array_keys($upgrades[0]), $upgrades);
  PwgCommand::progress_advance();
  PwgCommand::progress_finish();

  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'install', ['version' => PHPWG_VERSION]);
}
