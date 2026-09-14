<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('upgrade', 'cli_upgrade',
  array(
    'description' => 'Bring the database up to the version of the code',
    'boot' => 'minimal',
    'details' => [
      'What upgrade.php does in the browser: run the migration scripts of install/db/ the database has not seen yet, then stamp it with the branch of the code. Needed after the files changed, whatever brought them: a zip, git pull, a new docker image.',
      'A gallery whose database lags behind its code refuses every page and redirects to upgrade.php, and so does every "pwg" command that loads the gallery. This one boots without it on purpose.',
      '"pwg update" fetches new files from piwigo.org, then runs this.',
    ],
    'examples' => [
      'pwg upgrade --dry-run',
      'pwg upgrade -y',
    ],
  )
);
function cli_upgrade()
{
  global $conf, $prefixeTable, $page, $persistent_cache;

  // the migration scripts expect what upgrade.php gives them
  defined('PREFIX_TABLE') or define('PREFIX_TABLE', $prefixeTable);
  defined('UPGRADES_PATH') or define('UPGRADES_PATH', PHPWG_ROOT_PATH.'install/db');
  defined('PHPWG_IN_UPGRADE') or define('PHPWG_IN_UPGRADE', true);
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upgrade.php');

  load_conf_from_db();

  $code_branch = get_branch_from_version(PHPWG_VERSION);
  $db_branch = $conf['piwigo_db_version'] ?? 'unknown';
  $pending = cli_upgrade_pending();
  $branch_moves = $db_branch !== $code_branch;

  if (0 === count($pending) and !$branch_moves)
  {
    PwgCommand::success('database and code both on branch '.$code_branch.', nothing to migrate');
    return PwgCommand::SUCCESS;
  }

  PwgCommand::writeln('code '.PHPWG_VERSION.' on branch '.$code_branch.', database on branch '.$db_branch);

  if (count($pending) > 0)
  {
    PwgCommand::writeln(count($pending).' migration'.(1 === count($pending) ? '' : 's').' to run: '.implode(', ', $pending));
  }

  if ($branch_moves)
  {
    PwgCommand::writeln('the database will then be stamped with branch '.$code_branch);
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would do it, nothing done');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Migrate the database? Have a backup first.'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  [$now] = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
  defined('CURRENT_DATE') or define('CURRENT_DATE', $now);
  $page['infos'] = [];
  $page['errors'] = [];
  $failed = [];

  // upgrade.php lets a failing query go on to the next script, keep that
  $conf['die_on_sql_error'] = false;
  PwgCommand::progress_start(count($pending), 'migrating');

  foreach ($pending as $upgrade_id)
  {
    $upgrade_description = '';
    $started = get_moment();
    ob_start();

    try
    {
      include(UPGRADES_PATH.'/'.$upgrade_id.'-database.php');
    }
    catch (Throwable $error)
    {
      $failed[] = $upgrade_id.': '.strtok($error->getMessage(), "\n");
    }

    ob_end_clean();

    // same row as upgrade.php, and marked applied even when a query failed: running
    // the same ALTER twice would only fail again
    $query = '
INSERT INTO '.UPGRADE_TABLE.'
  (id, applied, description)
  VALUES
  (\''.$upgrade_id.'\', NOW(), \'[migration to '.PHPWG_VERSION.', '.get_elapsed_time($started, get_moment()).'] '.pwg_db_real_escape_string($upgrade_description).'\')
;';
    pwg_query($query);
    PwgCommand::progress_advance();
  }

  PwgCommand::progress_finish();
  $conf['die_on_sql_error'] = true;

  conf_update_param('piwigo_db_version', $code_branch);
  conf_delete_param('last_major_update');

  // caches built on the old version, dropped like upgrade.php does
  include_once(PHPWG_ROOT_PATH.'include/cache.class.php');
  $persistent_cache = new PersistentFileCache();
  invalidate_user_cache(true);
  cli_upgrade_clear_compiled_templates();

  foreach ($page['errors'] as $error)
  {
    PwgCommand::warning(strip_tags($error));
  }

  foreach ($failed as $failure)
  {
    PwgCommand::warning('migration '.$failure);
  }

  if (count($failed) > 0)
  {
    PwgCommand::error(count($pending).' migration'.(1 === count($pending) ? '' : 's').' run, '.count($failed).' with errors, database stamped '.$code_branch.' anyway like upgrade.php does');
    return PwgCommand::ERROR;
  }

  PwgCommand::success(count($pending).' migration'.(1 === count($pending) ? '' : 's').' run, database on branch '.$code_branch);
  return PwgCommand::SUCCESS;
}

// the install/db scripts the upgrade table does not list yet, in order
function cli_upgrade_pending(): array
{
  $query = '
SELECT id
  FROM '.UPGRADE_TABLE.'
;';
  $applied = query2array($query, null, 'id');

  return array_values(array_diff(get_available_upgrade_ids(), $applied));
}

// what Template::delete_compiled_templates() does, without loading Smarty for it
function cli_upgrade_clear_compiled_templates()
{
  global $conf;

  $compiled = PHPWG_ROOT_PATH.$conf['data_location'].'templates_c';

  foreach (glob($compiled.'/*') ?: [] as $file)
  {
    if (is_file($file) and 'index.htm' !== basename($file))
    {
      @unlink($file);
    }
  }
}

$cli->add_command('update', 'cli_update',
  array(
    'description' => 'Check piwigo.org for a new Piwigo, fetch it, migrate the database',
    'boot' => 'full',
    'details' => [
      'What the Updates page of the admin does. Without --to it only asks piwigo.org and tells what is available: a minor version on your branch with bug fixes, a major one with new features, or nothing.',
      'With --to it downloads that version from piwigo.org and extracts it over this gallery, exactly like the admin, then runs "pwg upgrade" in a fresh process so the migration uses the files just written, not the ones this process loaded.',
      'In the official docker image the files come with the image: this command names the version and points to the update guide, like the admin does. On development sources there is nothing to compare, and it says so.',
    ],
    'examples' => [
      'pwg update',
      'pwg update --to 17.1.0',
      'pwg update --to 17.1.0 --dry-run',
    ],
    'args' => [
      'to' => [
        'short' => 't',
        'info' => 'Version to update to, one of the two piwigo.org offers',
        'default' => null,
      ],
    ],
  )
);
function cli_update(array $args)
{
  global $conf, $page;

  if (!$conf['enable_core_update'])
  {
    PwgCommand::error('core update is disabled by $conf[\'enable_core_update\']');
    return PwgCommand::ERROR;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/updates.class.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/pclzip.lib.php');

  [$container, $build] = get_container_info();
  $updates = new updates();
  $versions = $updates->get_piwigo_new_versions();

  if ($versions['is_dev'])
  {
    PwgCommand::writeln('Piwigo '.PHPWG_VERSION.' is a development version, nothing to compare with');
    return PwgCommand::SUCCESS;
  }

  if (!$versions['piwigo.org-checked'])
  {
    PwgCommand::error('could not read the version list on piwigo.org');
    return PwgCommand::ERROR;
  }

  $running = 'Official' === $container ? $build.' (docker)' : PHPWG_VERSION;
  $offers = [];

  if (isset($versions['minor']))
  {
    $offers[$versions['minor']] = ['step' => 2, 'label' => 'minor, bug fixes only', 'php' => $versions['minor_php'] ?? null];
  }

  if (isset($versions['major']))
  {
    $offers[$versions['major']] = ['step' => 3, 'label' => 'major, new features, some plugins and themes may not be ready', 'php' => $versions['major_php'] ?? null];
  }

  if (0 === count($offers))
  {
    PwgCommand::success('Piwigo '.$running.', latest version');
    return PwgCommand::SUCCESS;
  }

  PwgCommand::writeln('Piwigo '.$running.', '.(1 === count($offers) ? 'an update is' : 'two updates are').' available:');

  foreach ($offers as $version => $offer)
  {
    $needs = (null !== $offer['php'] && version_compare(PHP_VERSION, $offer['php'], '<')) ? ', needs PHP '.$offer['php'].' and this is '.PHP_VERSION : '';
    PwgCommand::writeln('  '.str_pad($version, 10).$offer['label'].$needs);
  }

  // the image brings the files, the admin only points to the guide there
  if ('Official' === $container)
  {
    PwgCommand::writeln('the files come with the docker image, update the image: '.PHPWG_URL.'/guide-update-docker');
    return null === $args['to'] ? PwgCommand::SUCCESS : PwgCommand::ERROR;
  }

  if (null === $args['to'])
  {
    $best = array_key_last($offers);
    PwgCommand::writeln('run "pwg update --to '.$best.'"'.(2 === count($offers) ? ', piwigo.org recommends going there directly' : ''));
    return PwgCommand::SUCCESS;
  }

  $to = $args['to'];

  if (!isset($offers[$to]))
  {
    PwgCommand::error('"'.$to.'" is not one of the versions piwigo.org offers: '.implode(', ', array_keys($offers)));
    return PwgCommand::INVALID;
  }

  if (null !== $offers[$to]['php'] and version_compare(PHP_VERSION, $offers[$to]['php'], '<'))
  {
    PwgCommand::error('Piwigo '.$to.' needs PHP '.$offers[$to]['php'].', this is PHP '.PHP_VERSION.'. Upgrade PHP first');
    return PwgCommand::ERROR;
  }

  $step = $offers[$to]['step'];

  // on a major, the admin lists what has no release on the new branch yet and asks to go anyway
  if (3 === $step)
  {
    $updates->get_merged_extensions($to);
    $updates->get_server_extensions($to);

    foreach (['plugins', 'themes'] as $type)
    {
      if (!empty($updates->missing[$type]))
      {
        PwgCommand::warning($type.' with no release for Piwigo '.$to.' yet: '.implode(', ', array_column($updates->missing[$type], 'name')));
      }
    }
  }

  PwgCommand::writeln('Always have a backup of the database and the files before this.');

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would download Piwigo '.$to.' from piwigo.org, extract it over '.PHPWG_ROOT_PATH.', then run "pwg upgrade"');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Update Piwigo '.PHPWG_VERSION.' to '.$to.'?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  // on a major the core ends with a redirect to upgrade.php, which is an exit here:
  // the migration then runs from the shutdown, on the files just written
  register_shutdown_function('cli_update_migrate', $to);
  $page['errors'] = [];
  $page['infos'] = [];

  PwgCommand::writeln('downloading and extracting Piwigo '.$to.', this takes a while');
  updates::upgrade_to($to, $step);

  foreach ($page['errors'] as $error)
  {
    PwgCommand::error(strip_tags($error));
  }

  return count($page['errors']) > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// after the files changed: check them, then migrate in a process that loads the new code
function cli_update_migrate(string $to)
{
  // the version the files on disk now declare, read without executing them
  $constants = @file_get_contents(PHPWG_ROOT_PATH.'include/constants.php');

  if (false === $constants or !preg_match("/PHPWG_VERSION', '([^']+)'/", $constants, $written) or $written[1] !== $to)
  {
    return;
  }

  PwgCommand::success('files updated to Piwigo '.$to);
  PwgCommand::writeln('migrating the database');
  passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(CLI_ROOT_PATH.'bin/pwg.php').' upgrade -y', $exit);

  if (0 !== $exit)
  {
    PwgCommand::error('the migration did not go through, run "pwg upgrade" to see');
  }
}
