<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('maintenance.lock', 'cli_lock_gallery',
  array(
    'description' => 'Lock gallery',
    'boot' => 'full',
  )
);
function cli_lock_gallery()
{
  global $conf;

  if ($conf['gallery_locked'])
  {
    PwgCommand::success('Gallery is already locked');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would lock the gallery');
    return PwgCommand::SUCCESS;
  }

  conf_update_param('gallery_locked', 'true');
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'lock_gallery'));

  PwgCommand::success('Gallery locked');
  return PwgCommand::SUCCESS;
}

$cli->add_command('maintenance.unlock', 'cli_unlock_gallery',
  array(
    'description' => 'Unlock gallery',
    'boot' => 'minimal',
  )
);
function cli_unlock_gallery()
{
  // a locked gallery stops the full boot, this one stays on the light one
  include_once(PHPWG_ROOT_PATH.'include/functions.inc.php');

  $query = "SELECT * FROM ".CONFIG_TABLE." WHERE param = 'gallery_locked'";
  $result = pwg_db_fetch_assoc(pwg_query($query));

  if ('false' === $result['value'])
  {
    PwgCommand::success('Gallery is already unlocked');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would unlock the gallery');
    return PwgCommand::SUCCESS;
  }

  pwg_query("UPDATE ".CONFIG_TABLE." SET value='false' WHERE param='gallery_locked'");
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'unlock_gallery'));

  PwgCommand::success('Gallery unlocked');
  return PwgCommand::SUCCESS;
}

$cli->add_command('maintenance.update.albums_info', 'cli_update_albums_info',
  array(
    'description' => 'Update Album\'s information',
    'boot' => 'full',
  )
);
function cli_update_albums_info()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  if (PwgCommand::is_dry_run())
  {
    [$nb_albums] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.CATEGORIES_TABLE));
    PwgCommand::writeln('would refresh '.$nb_albums.' albums: integrity, uppercats, ranks, user cache');
    return PwgCommand::SUCCESS;
  }

  images_integrity();
  categories_integrity();
  update_uppercats();
  update_category('all');
  update_global_rank();
  invalidate_user_cache(true);
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'categories'));

  PwgCommand::success('Album\'s information updated');
  return PwgCommand::SUCCESS;
}

$cli->add_command('maintenance.update.photos_info', 'cli_update_photos_info',
  array(
    'description' => 'Update Photo\'s information',
    'boot' => 'full',
  )
);
function cli_update_photos_info()
{
  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  if (PwgCommand::is_dry_run())
  {
    [$nb_photos] = pwg_db_fetch_row(pwg_query('SELECT COUNT(*) FROM '.IMAGES_TABLE));
    PwgCommand::writeln('would refresh '.$nb_photos.' photos: integrity, paths, rating scores, user cache');
    return PwgCommand::SUCCESS;
  }

  images_integrity();
  update_path();
  include_once(PHPWG_ROOT_PATH.'include/functions_rate.inc.php');
  update_rating_score();
  invalidate_user_cache();
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'images'));

  PwgCommand::success('Photo\'s information updated');
  return PwgCommand::SUCCESS;
}

$cli->add_command('maintenance.repair_db', 'cli_repair_opti_db',
  array(
    'description' => 'Repair and optimize database',
    'boot' => 'full',
  )
);
function cli_repair_opti_db()
{
  global $prefixeTable, $page;

  if (PwgCommand::is_dry_run())
  {
    $tables = array();
    $result = pwg_query('SHOW TABLES LIKE \''.$prefixeTable.'%\'');
    while ($row = pwg_db_fetch_row($result))
    {
      $tables[] = $row[0];
    }

    PwgCommand::writeln('would repair, reorder and optimize '.count($tables).' tables');
    return PwgCommand::SUCCESS;
  }

  // it reports through $page, nothing is returned
  $errors_before = isset($page['errors']) ? count($page['errors']) : 0;
  do_maintenance_all_tables();

  if (isset($page['errors']) and count($page['errors']) > $errors_before)
  {
    PwgCommand::error('Optimizations have been completed with some errors');
    return PwgCommand::ERROR;
  }

  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'database'));

  PwgCommand::success('Database repaired and optimized');
  return PwgCommand::SUCCESS;
}

$cli->add_command('maintenance.reset_integrity', 'cli_reset_integrity_check',
  array(
    'description' => 'Reinitialize integrity check',
    'boot' => 'full',
  )
);
function cli_reset_integrity_check()
{
  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would forget every ignored integrity check');
    return PwgCommand::SUCCESS;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/check_integrity.class.php');
  $c13y = new check_integrity();
  $c13y->maintenance();
  pwg_activity('system', ACTIVITY_SYSTEM_CORE, 'maintenance', array('maintenance_action' => 'c13y'));

  PwgCommand::success('Integrity reinitialized and checked');
  return PwgCommand::SUCCESS;
}
