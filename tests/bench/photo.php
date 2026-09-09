<?php
// Bench for cli.photo.php: photos 900 and 901 in album 12, 900 also in album 7
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

require_once __DIR__.'/bench_root.php';
define('PHPWG_ROOT_PATH', bench_fake_root(
  'pwg_cli_bench_photo_root',
  ['admin/include/functions_upload.inc.php', 'admin/include/functions_metadata.php'],
  []
));
require_once __DIR__.'/bench.inc.php';

define('CATEGORIES_TABLE', 'cats');
define('IMAGES_TABLE', 'imgs');
define('IMAGE_CATEGORY_TABLE', 'image_category');
define('IMAGE_TAG_TABLE', 'image_tag');
define('TAGS_TABLE', 'tags');

$conf = [
  'file_ext' => ['jpg', 'pdf'],
  'picture_ext' => ['jpg'],
  'upload_dir' => './upload',
];

// two files to import, made here so nothing binary lives in the repo
$inbox = sys_get_temp_dir().'/pwg_cli_bench_photo';
bench_rmtree($inbox);
@mkdir($inbox, 0777, true);
file_put_contents($inbox.'/one.jpg', 'photo-one');
file_put_contents($inbox.'/two.jpg', 'photo-two');
file_put_contents($inbox.'/notes.pdf', 'not a picture');
register_shutdown_function('bench_rmtree', $inbox);
register_shutdown_function('bench_rmtree', rtrim(PHPWG_ROOT_PATH, '/'));

$photos = [900 => 'Sunset', 901 => 'Plage'];
$albums = [7 => 'Divers', 12 => 'Vacances'];

function pwg_query($query) { return $query; }
function pwg_db_real_escape_string($string) { return addslashes($string); }
function pwg_db_fetch_row($query)
{
  global $photos, $albums;

  if (preg_match('/FROM imgs\s+WHERE id = (\d+)/', $query, $m))
  {
    return isset($photos[$m[1]]) ? [$m[1]] : false;
  }

  if (preg_match('/FROM cats\s+WHERE id = (\d+)/', $query, $m))
  {
    return isset($albums[$m[1]]) ? [$m[1]] : false;
  }

  return false;
}
function pwg_db_fetch_assoc($query)
{
  return ['id' => 900, 'name' => 'Sunset', 'file' => 'sunset.jpg', 'filesize' => 240, 'width' => 1600];
}
function query2array($query, $key = null, $value = null)
{
  global $photos;

  // the listing, the only one that asks for the columns of a table
  if (false !== strpos($query, 'i.date_available'))
  {
    $rows = [];
    foreach ($photos as $id => $name)
    {
      $rows[] = ['id' => $id, 'name' => $name, 'file' => strtolower($name).'.jpg', 'added' => '2026-09-01', 'size_kb' => 240];
    }

    return $rows;
  }

  // the albums one photo belongs to
  if (false !== strpos($query, 'JOIN cats'))
  {
    return [7 => 'Divers', 12 => 'Vacances'];
  }

  // its tags
  if (false !== strpos($query, 'JOIN tags'))
  {
    return ['mer', 'été'];
  }

  // the photos of one album
  if (false !== strpos($query, 'SELECT image_id'))
  {
    return [900, 901];
  }

  // every photo of the gallery
  return array_keys($photos);
}

function move_images_to_categories($images, $categories) { bench_core('move_images_to_categories('.implode(',', $images).', '.implode(',', $categories).')'); }
function associate_images_to_categories($images, $categories) { bench_core('associate_images_to_categories('.implode(',', $images).', '.implode(',', $categories).')'); }
function delete_elements($ids, $physical = false)
{
  bench_core('delete_elements('.implode(',', $ids).', '.var_export($physical, true).')');
  return count($ids);
}
function sync_metadata($ids) { bench_core('sync_metadata('.count($ids).' photos)'); }
function add_uploaded_file($source, $name, $categories, $level, $id, $md5)
{
  bench_core("add_uploaded_file('$name', album=".implode(',', $categories).')');

  if ('two.jpg' === $name)
  {
    throw new RuntimeException('disk full');
  }

  unlink($source);
  return 902;
}
function pwg_check_real_extension($source, $name, $die) { return true; }
function prepare_directory($directory) { @mkdir($directory, 0777, true); }
function get_extension($file) { return pathinfo($file, PATHINFO_EXTENSION); }
function empty_lounge() { bench_core('empty_lounge()'); return []; }
function update_category($ids) {}
function invalidate_user_cache() {}
function pwg_activity() {}

include CLI_ROOT_PATH.'commands/cli.photo.php';

$page = ['page' => 1, 'limit' => 20];

bench_run([
  'list' => function () use ($page) { return cli_photo_list(['album' => null, 'search' => null] + $page); },
  'list-album' => function () use ($page) { return cli_photo_list(['album' => '12', 'search' => null] + $page); },
  'list-unknown-album' => function () use ($page) { return cli_photo_list(['album' => '999', 'search' => null] + $page); },
  'info' => function () { return cli_photo_info(['photo_id' => '900']); },
  'info-unknown' => function () { return cli_photo_info(['photo_id' => '999']); },
  'move' => function () { return cli_photo_move(['photo_id' => ['900', '901'], 'album' => '7', 'add' => false]); },
  'move-add' => function () { return cli_photo_move(['photo_id' => ['900'], 'album' => '7', 'add' => true]); },
  'move-dry' => function () { PwgCommand::set_dry_run(); return cli_photo_move(['photo_id' => ['900'], 'album' => '7', 'add' => false]); },
  'move-no-album' => function () { return cli_photo_move(['photo_id' => ['900'], 'album' => null, 'add' => false]); },
  'move-unknown-photo' => function () { return cli_photo_move(['photo_id' => ['999'], 'album' => '7', 'add' => false]); },
  'delete' => function () { PwgCommand::assume_yes(); return cli_photo_delete(['photo_id' => ['900'], 'keep-files' => false]); },
  'delete-keep-files' => function () { PwgCommand::assume_yes(); return cli_photo_delete(['photo_id' => ['900'], 'keep-files' => true]); },
  'delete-dry' => function () { PwgCommand::set_dry_run(); return cli_photo_delete(['photo_id' => ['900', '901'], 'keep-files' => false]); },
  'delete-refused' => function () { return cli_photo_delete(['photo_id' => ['900'], 'keep-files' => false]); },
  'delete-nothing' => function () { return cli_photo_delete(['photo_id' => [], 'keep-files' => false]); },
  'import' => function () use ($inbox) {
    PwgCommand::assume_yes();
    return cli_photo_import(['file' => [$inbox.'/one.jpg'], 'album' => '12', 'keep' => true, 'privacy' => 0]);
  },
  'import-one-fails' => function () use ($inbox) {
    PwgCommand::assume_yes();
    return cli_photo_import(['file' => [$inbox.'/one.jpg', $inbox.'/two.jpg'], 'album' => '12', 'keep' => true, 'privacy' => 0]);
  },
  'import-dry' => function () use ($inbox) {
    PwgCommand::set_dry_run();
    return cli_photo_import(['file' => [$inbox.'/one.jpg'], 'album' => '12', 'keep' => false, 'privacy' => 0]);
  },
  'import-bad-type' => function () use ($inbox) {
    return cli_photo_import(['file' => [$inbox.'/notes.pdf'], 'album' => '12', 'keep' => true, 'privacy' => 0]);
  },
  'import-missing-file' => function () use ($inbox) {
    return cli_photo_import(['file' => [$inbox.'/nope.jpg'], 'album' => '12', 'keep' => true, 'privacy' => 0]);
  },
  'import-no-album' => function () use ($inbox) {
    return cli_photo_import(['file' => [$inbox.'/one.jpg'], 'album' => null, 'keep' => true, 'privacy' => 0]);
  },
  'sync-ids' => function () { return cli_photo_sync_metadata(['photo_id' => ['900', '901'], 'album' => null, 'all' => false]); },
  'sync-album' => function () { return cli_photo_sync_metadata(['photo_id' => [], 'album' => '12', 'all' => false]); },
  'sync-all' => function () { return cli_photo_sync_metadata(['photo_id' => [], 'album' => null, 'all' => true]); },
  'sync-dry' => function () { PwgCommand::set_dry_run(); return cli_photo_sync_metadata(['photo_id' => [], 'album' => null, 'all' => true]); },
  'sync-nothing' => function () { return cli_photo_sync_metadata(['photo_id' => [], 'album' => null, 'all' => false]); },
]);
