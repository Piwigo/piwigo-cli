<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('backup', 'cli_backup',
  array(
    'description' => 'Dump the database into a dated .sql.gz file',
    'boot' => 'minimal',
    'details' => [
      'Writes every table of this gallery, the ones carrying its prefix, into <path>/piwigo-<date>.sql.gz. The photos are not in it: upload/ weighs what it weighs, back it up with rsync or the tool of your host.',
      'Uses mysqldump or mariadb-dump when one is on the PATH, the password handed over in a private file, never on the command line. Without them it dumps through the database connection it already has, in plain SQL a mysql client restores the same way.',
      'To restore: gunzip < piwigo-<date>.sql.gz | mysql -h <host> -u <user> -p <database>',
    ],
    'examples' => [
      'pwg backup --path /var/backups/piwigo',
      'pwg backup --path /var/backups/piwigo --dry-run',
    ],
    'args' => [
      'path' => [
        'short' => 'p',
        'info' => 'Directory to write the file into, must exist',
        'default' => null,
      ],
    ],
  )
);
function cli_backup(array $args)
{
  global $conf, $prefixeTable;

  if (null === $args['path'])
  {
    PwgCommand::error('Where to? --path is needed, and keep it off the disk you are protecting');
    return PwgCommand::INVALID;
  }

  $directory = rtrim($args['path'], '/');

  if (!is_dir($directory))
  {
    PwgCommand::error('"'.$directory.'" is not a directory, create it first');
    return PwgCommand::INVALID;
  }

  if (!is_writable($directory))
  {
    PwgCommand::error('"'.$directory.'" is not writable');
    return PwgCommand::ERROR;
  }

  $file = $directory.'/piwigo-'.date('Y-m-d-Hi').'.sql.gz';

  if (file_exists($file))
  {
    PwgCommand::error($file.' exists already, wait a minute');
    return PwgCommand::ERROR;
  }

  $tables = cli_backup_tables($prefixeTable);

  if (0 === count($tables))
  {
    PwgCommand::error('no table starts with "'.$prefixeTable.'" in '.$conf['db_base']);
    return PwgCommand::ERROR;
  }

  $tool = null;
  foreach (['mariadb-dump', 'mysqldump'] as $candidate)
  {
    if (cli_has_program($candidate))
    {
      $tool = $candidate;
      break;
    }
  }

  PwgCommand::writeln(count($tables).' tables of '.$conf['db_base'].', about '.cli_backup_size($tables).' in the database, with '.($tool ?? 'the php connection, no mysqldump on the PATH'));

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would write '.$file);
    return PwgCommand::SUCCESS;
  }

  $out = gzopen($file, 'wb6');

  if (false === $out)
  {
    PwgCommand::error('cannot write '.$file);
    return PwgCommand::ERROR;
  }

  gzwrite($out, '-- Piwigo '.PHPWG_VERSION.' backup of '.$conf['db_base'].' on '.date('c').', by pwg backup with '.($tool ?? 'php')."\n");
  $ok = null === $tool ? cli_backup_with_php($out, $tables) : cli_backup_with_tool($out, $tool, $tables);
  gzclose($out);

  if (!$ok)
  {
    @unlink($file);
    return PwgCommand::ERROR;
  }

  PwgCommand::success($file.' written, '.cli_backup_human(filesize($file)));
  return PwgCommand::SUCCESS;
}

// the tables of this gallery: the prefix keeps the other applications of the same database out
function cli_backup_tables(string $prefix): array
{
  // in a LIKE, "_" is a wildcard for one character: escape it, then escape the escape for the string
  $query = '
SHOW TABLES LIKE \''.pwg_db_real_escape_string(str_replace('_', '\_', $prefix)).'%\'
;';
  $result = pwg_query($query);
  $tables = [];

  // SHOW TABLES names its column after the pattern, so read by position
  while ($row = pwg_db_fetch_row($result))
  {
    $tables[] = $row[0];
  }

  return $tables;
}

// what the tables weigh in the database, an order of magnitude for the dry run
function cli_backup_size(array $tables): string
{
  global $conf;

  $query = '
SELECT SUM(data_length + index_length)
  FROM information_schema.tables
  WHERE table_schema = \''.pwg_db_real_escape_string($conf['db_base']).'\'
    AND table_name IN (\''.implode('\',\'', array_map('pwg_db_real_escape_string', $tables)).'\')
;';
  [$bytes] = pwg_db_fetch_row(pwg_query($query));

  return cli_backup_human((int) $bytes);
}

function cli_backup_human(int $bytes)
{
  foreach (['B', 'KB', 'MB', 'GB'] as $unit)
  {
    if ($bytes < 1024 or 'GB' === $unit)
    {
      return round($bytes, 'B' === $unit ? 0 : 1).' '.$unit;
    }

    $bytes = $bytes / 1024;
  }
}

// mysqldump does the work: the password goes through a private defaults file, and its
// stdout through our gzip, so a failure on its stderr can be told from a success
function cli_backup_with_tool($out, string $tool, array $tables): bool
{
  global $conf;

  $host = $conf['db_host'];
  $target = ['--user='.$conf['db_user']];

  // the same three shapes pwg_db_connect() accepts: a socket, host:port, a host
  if (0 === strpos($host, '/'))
  {
    $target[] = '--socket='.$host;
  }
  elseif (false !== strpos($host, ':'))
  {
    [$name, $port] = explode(':', $host);
    $target[] = '--host='.$name;
    $target[] = '--port='.$port;
  }
  else
  {
    $target[] = '--host='.$host;
  }

  $secrets = tempnam(sys_get_temp_dir(), 'pwg-backup-');
  chmod($secrets, 0600);
  file_put_contents($secrets, "[client]\npassword=\"".str_replace('"', '\"', $conf['db_password'])."\"\n");

  $command = escapeshellarg($tool).' --defaults-extra-file='.escapeshellarg($secrets).' '
    .implode(' ', array_map('escapeshellarg', $target))
    .' --single-transaction --quick --add-drop-table --default-character-set=utf8mb4 '
    .escapeshellarg($conf['db_base']).' '.implode(' ', array_map('escapeshellarg', $tables));

  $pipes = [];
  $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

  if (!is_resource($process))
  {
    unlink($secrets);
    PwgCommand::error('cannot start '.$tool);
    return false;
  }

  PwgCommand::progress_start(null, 'dumping with '.$tool);

  while (!feof($pipes[1]))
  {
    $chunk = fread($pipes[1], 65536);

    if ('' !== $chunk and false !== $chunk)
    {
      gzwrite($out, $chunk);
      PwgCommand::progress_advance();
    }
  }

  $errors = trim(stream_get_contents($pipes[2]));
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit = proc_close($process);
  unlink($secrets);
  PwgCommand::progress_finish();

  if (0 !== $exit)
  {
    PwgCommand::error($tool.' failed'.('' === $errors ? '' : ': '.strtok($errors, "\n")));
    return false;
  }

  return true;
}

// no dump tool: the same SQL, table by table, through the connection the boot opened
function cli_backup_with_php($out, array $tables): bool
{
  global $mysqli;

  gzwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
  PwgCommand::progress_start(count($tables), 'dumping');

  foreach ($tables as $table)
  {
    PwgCommand::progress_label('dumping '.$table);
    [, $create] = pwg_db_fetch_row(pwg_query('SHOW CREATE TABLE `'.$table.'`'));
    gzwrite($out, 'DROP TABLE IF EXISTS `'.$table."`;\n".$create.";\n\n");

    // unbuffered on purpose: history can hold millions of rows, they must not all sit in memory
    $result = $mysqli->query('SELECT * FROM `'.$table.'`', MYSQLI_USE_RESULT);

    if (false === $result)
    {
      PwgCommand::progress_finish();
      PwgCommand::error('cannot read '.$table.': '.$mysqli->error);
      return false;
    }

    $batch = [];
    while ($row = $result->fetch_row())
    {
      $values = [];
      foreach ($row as $value)
      {
        $values[] = null === $value ? 'NULL' : '\''.$mysqli->real_escape_string($value).'\'';
      }
      $batch[] = '('.implode(',', $values).')';

      if (500 === count($batch))
      {
        gzwrite($out, 'INSERT INTO `'.$table.'` VALUES '.implode(",\n", $batch).";\n");
        $batch = [];
      }
    }

    if (count($batch) > 0)
    {
      gzwrite($out, 'INSERT INTO `'.$table.'` VALUES '.implode(",\n", $batch).";\n");
    }

    $result->free();
    gzwrite($out, "\n");
    PwgCommand::progress_advance();
  }

  gzwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
  PwgCommand::progress_finish();

  return true;
}
