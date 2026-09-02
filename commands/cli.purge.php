<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('purge.user_cache', 'cli_purge_user_cache',
  array(
    'description' => 'Purge user cache',
    'boot' => 'full',
  )
);
function cli_purge_user_cache()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  [$nb_users] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.USER_CACHE_TABLE));

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would drop the cache of '.$nb_users.' users and the persistent cache');
    return PwgCommand::SUCCESS;
  }

  invalidate_user_cache();
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'user_cache'));

  PwgCommand::success('User cache purged');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.orphan_tags', 'cli_purge_orphan_tags',
  array(
    'description' => 'Delete orphan tags',
    'boot' => 'full',
  )
);
function cli_purge_orphan_tags()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  $orphans = get_orphan_tags();

  if (0 === count($orphans))
  {
    PwgCommand::success('No orphan tag');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.count($orphans).' orphan tags: '.implode(', ', array_column($orphans, 'name')));
    return PwgCommand::SUCCESS;
  }

  delete_orphan_tags();
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'delete_orphan_tags'));

  PwgCommand::success(count($orphans).' orphan tags deleted');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.history_details', 'cli_purge_history_details',
  array(
    'description' => 'Purge history details',
    'boot' => 'full',
  )
);
function cli_purge_history_details()
{
  [$nb_rows] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.HISTORY_TABLE));

  if (0 == $nb_rows)
  {
    PwgCommand::success('History details already empty');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.$nb_rows.' history lines');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete '.$nb_rows.' history lines?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  pwg_query('DELETE FROM '.HISTORY_TABLE);
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'history_detail'));

  PwgCommand::success('History details purged');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.history_summary', 'cli_purge_history_summary',
  array(
    'description' => 'Purge history summary',
    'boot' => 'full',
  )
);
function cli_purge_history_summary()
{
  [$nb_rows] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.HISTORY_SUMMARY_TABLE));

  if (0 == $nb_rows)
  {
    PwgCommand::success('History summary already empty');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.$nb_rows.' summary lines');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete '.$nb_rows.' summary lines?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  pwg_query('DELETE FROM '.HISTORY_SUMMARY_TABLE);
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'history_summary'));

  PwgCommand::success('History summary purged');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.sessions', 'cli_purge_sessions',
  array(
    'description' => 'Purge sessions',
    'boot' => 'full',
  )
);
function cli_purge_sessions()
{
  global $conf;

  // same clause as pwg_session_gc(), the core has no function to count them
  $expired = pwg_db_date_to_ts('NOW()').' - '.pwg_db_date_to_ts('expiration').' > '.$conf['session_length'];
  [$nb_expired] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.SESSIONS_TABLE.' WHERE '.$expired));
  $orphans = cli_orphan_sessions();

  if (0 == $nb_expired and 0 === count($orphans))
  {
    PwgCommand::success('No session to purge');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.$nb_expired.' expired sessions and '.count($orphans).' sessions of deleted users');
    return PwgCommand::SUCCESS;
  }

  pwg_session_gc();

  if (count($orphans) > 0)
  {
    pwg_query('DELETE FROM '.SESSIONS_TABLE.' WHERE id IN (\''.implode("','", $orphans).'\')');
  }

  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'sessions'));

  PwgCommand::success(($nb_expired + count($orphans)).' sessions purged');
  return PwgCommand::SUCCESS;
}

// sessions whose user no longer exists, the web page does this inline
function cli_orphan_sessions()
{
  global $conf;

  $sessions = query2array('SELECT id, data FROM '.SESSIONS_TABLE);
  $user_ids = query2array('SELECT '.$conf['user_fields']['id'].' AS id FROM '.USERS_TABLE, 'id', null);

  $orphans = array();
  foreach ($sessions as $session)
  {
    if (preg_match('/pwg_uid\|i:(\d+);/', $session['data'], $matches) and !isset($user_ids[$matches[1]]))
    {
      $orphans[] = $session['id'];
    }
  }

  return $orphans;
}

$cli->add_command('purge.feeds', 'cli_purge_feeds',
  array(
    'description' => 'Purge never used notification feeds',
    'boot' => 'full',
  )
);
function cli_purge_feeds()
{
  [$nb_feeds] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.USER_FEED_TABLE.' WHERE last_check IS NULL'));

  if (0 == $nb_feeds)
  {
    PwgCommand::success('No unused feed');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.$nb_feeds.' never used feeds');
    return PwgCommand::SUCCESS;
  }

  pwg_query('DELETE FROM '.USER_FEED_TABLE.' WHERE last_check IS NULL');
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'feeds'));

  PwgCommand::success($nb_feeds.' feeds purged');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.search', 'cli_purge_search',
  array(
    'description' => 'Purge search history',
    'boot' => 'full',
  )
);
function cli_purge_search()
{
  [$nb_rows] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.SEARCH_TABLE));

  if (0 == $nb_rows)
  {
    PwgCommand::success('Search history already empty');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.$nb_rows.' saved searches');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete '.$nb_rows.' saved searches?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  pwg_query('DELETE FROM '.SEARCH_TABLE);
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'search'));

  PwgCommand::success('Search history purged');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.compiled_templates', 'cli_purge_compiled_templates',
  array(
    'description' => 'Purge compiled templates',
    'boot' => 'full',
  )
);
function cli_purge_compiled_templates()
{
  global $conf, $template, $persistent_cache;

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would empty '.$conf['data_location'].'templates_c/, the combined files and the persistent cache');
    return PwgCommand::SUCCESS;
  }

  $template->delete_compiled_templates();
  FileCombiner::clear_combined_files();
  $persistent_cache->purge(true);
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'compiled-templates'));

  PwgCommand::success('Compiled templates purged');
  return PwgCommand::SUCCESS;
}

$cli->add_command('purge.derivatives', 'cli_purge_derivatives',
  array(
    'description' => 'Delete every generated photo size',
    'boot' => 'full',
  )
);
function cli_purge_derivatives()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete every generated size in '.PWG_DERIVATIVE_DIR.', they are rebuilt on demand');
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete every generated size in '.PWG_DERIVATIVE_DIR.'?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  clear_derivative_cache('all');
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'derivatives'));

  PwgCommand::success('Generated sizes deleted');
  return PwgCommand::SUCCESS;
}
