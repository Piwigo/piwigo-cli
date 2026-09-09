<?php
// Bench for cli.album.php: a small tree, Vacances #12 holding two photos, one of which
// also lives in Divers #7
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

include __DIR__.'/bench.inc.php';

define('CATEGORIES_TABLE', 'cats');
define('IMAGE_CATEGORY_TABLE', 'image_category');

$conf = [];

// id => [name, parent, status]
$albums = [
  7 => ['Divers', null, 'public'],
  12 => ['Vacances', null, 'public'],
  45 => ['2024', 12, 'public'],
];
// album => photos
$links = [7 => [900], 12 => [900, 901], 45 => []];

function pwg_query($query) { return $query; }
function pwg_db_real_escape_string($string) { return addslashes($string); }
function pwg_db_fetch_row($query)
{
  global $albums;

  if (preg_match('/SELECT id\s+FROM cats\s+WHERE id = (\d+)/', $query, $m))
  {
    return isset($albums[$m[1]]) ? [$m[1]] : false;
  }

  if (preg_match('/SELECT name\s+FROM cats\s+WHERE id = (\d+)/', $query, $m))
  {
    return isset($albums[$m[1]]) ? [$albums[$m[1]][0]] : false;
  }

  return false;
}
function pwg_db_fetch_assoc($query)
{
  global $albums;
  preg_match('/WHERE id = (\d+)/', $query, $m);

  return [
    'id' => $m[1],
    'name' => $albums[$m[1]][0],
    'comment' => '',
    'status' => $albums[$m[1]][2],
    'visible' => 'true',
    'commentable' => 'true',
  ];
}
function query2array($query, $key = null, $value = null)
{
  global $albums, $links;

  if (false !== strpos($query, 'FROM image_category'))
  {
    preg_match('/category_id (NOT )?IN \(([\d,]+)\)/', $query, $m);
    $ids = array_map('intval', explode(',', $m[2]));
    $wanted = [];

    foreach ($links as $album => $photos)
    {
      $inside = in_array($album, $ids);
      if (('' === ($m[1] ?? '') and $inside) or ('NOT ' === ($m[1] ?? '') and !$inside))
      {
        $wanted = array_merge($wanted, $photos);
      }
    }

    // the second query also filters on the images found by the first
    if (preg_match('/image_id IN \(([\d,]+)\)/', $query, $only))
    {
      $wanted = array_intersect($wanted, array_map('intval', explode(',', $only[1])));
    }

    return array_values(array_unique($wanted));
  }

  $rows = [];
  foreach ($albums as $id => $album)
  {
    $rows[] = ['id' => $id, 'name' => $album[0], 'parent' => $album[1], 'status' => $album[2], 'photos' => count($links[$id])];
  }

  return $rows;
}
function get_subcat_ids($ids)
{
  global $albums;
  $tree = $ids;

  foreach ($albums as $id => $album)
  {
    if (in_array($album[1], $ids) and !in_array($id, $tree))
    {
      $tree[] = $id;
    }
  }

  return $tree;
}
$next_album = 100;
function create_virtual_category($name, $parent, $options = [])
{
  global $next_album;
  bench_core("create_virtual_category('$name', ".var_export($parent, true).', '.json_encode($options).')');

  return '' === trim($name) ? ['error' => 'The name of an album must not be empty'] : ['id' => ++$next_album];
}
function set_cat_status($ids, $status) { bench_core('set_cat_status('.implode(',', $ids).", '$status')"); }
function set_cat_visible($ids, $visible) { bench_core('set_cat_visible('.implode(',', $ids).", '$visible')"); }
function single_update($table, $columns, $where) { bench_core('single_update('.json_encode($columns).')'); }
function move_categories($ids, $parent)
{
  global $page;
  bench_core('move_categories('.implode(',', $ids).", $parent)");

  if (in_array(12, $ids) and 45 === $parent)
  {
    $page['errors'][] = 'You cannot move an album in its own sub album';
  }
}
function delete_categories($ids, $mode) { bench_core('delete_categories('.implode(',', $ids).", '$mode')"); }
function update_global_rank() {}
function invalidate_user_cache() {}
function pwg_activity() {}

include CLI_ROOT_PATH.'commands/cli.album.php';

$page = [];
$list = ['search' => null, 'parent' => null, 'page' => 1, 'limit' => 20];
$edit = ['name' => null, 'comment' => null, 'status' => null, 'visible' => null, 'commentable' => null];

bench_run([
  'list' => function () use ($list) { return cli_album_list($list); },
  'add' => function () { return cli_album_add(['name' => 'Noel', 'parent' => null, 'private' => false, 'comment' => null]); },
  'add-under' => function () { return cli_album_add(['name' => 'Plage', 'parent' => '12', 'private' => true, 'comment' => 'la mer']); },
  'add-dry' => function () { PwgCommand::set_dry_run(); return cli_album_add(['name' => 'Noel', 'parent' => '12', 'private' => true, 'comment' => null]); },
  'add-unknown-parent' => function () { return cli_album_add(['name' => 'Noel', 'parent' => '999', 'private' => false, 'comment' => null]); },
  'edit' => function () use ($edit) { return cli_album_edit(['album_id' => '12', 'name' => 'Vacances 2024', 'status' => 'private'] + $edit); },
  'edit-dry' => function () use ($edit) { PwgCommand::set_dry_run(); return cli_album_edit(['album_id' => '12', 'name' => 'Vacances 2024'] + $edit); },
  'edit-nothing' => function () use ($edit) { return cli_album_edit(['album_id' => '12', 'name' => 'Vacances'] + $edit); },
  'edit-bad-status' => function () use ($edit) { return cli_album_edit(['album_id' => '12', 'status' => 'secret'] + $edit); },
  'edit-bad-visible' => function () use ($edit) { return cli_album_edit(['album_id' => '12', 'visible' => 'oui'] + $edit); },
  'edit-unknown' => function () use ($edit) { return cli_album_edit(['album_id' => '999', 'name' => 'x'] + $edit); },
  'move' => function () { return cli_album_move(['album_id' => ['45'], 'parent' => '7']); },
  'move-root' => function () { return cli_album_move(['album_id' => ['45'], 'parent' => 'root']); },
  'move-dry' => function () { PwgCommand::set_dry_run(); return cli_album_move(['album_id' => ['45'], 'parent' => '7']); },
  'move-into-itself' => function () { return cli_album_move(['album_id' => ['12'], 'parent' => '45']); },
  'move-nothing' => function () { return cli_album_move(['album_id' => [], 'parent' => 'root']); },
  'delete-dry' => function () { PwgCommand::set_dry_run(); return cli_album_delete(['album_id' => ['12'], 'photos' => null]); },
  'delete-keep' => function () { PwgCommand::assume_yes(); return cli_album_delete(['album_id' => ['12'], 'photos' => 'keep']); },
  'delete-orphans' => function () { PwgCommand::assume_yes(); return cli_album_delete(['album_id' => ['12'], 'photos' => 'orphans']); },
  'delete-all' => function () { PwgCommand::assume_yes(); return cli_album_delete(['album_id' => ['12'], 'photos' => 'all']); },
  'delete-asks' => function () { return cli_album_delete(['album_id' => ['12'], 'photos' => null]); },
  'delete-bad-mode' => function () { return cli_album_delete(['album_id' => ['12'], 'photos' => 'burn']); },
  'delete-empty-album' => function () { PwgCommand::assume_yes(); return cli_album_delete(['album_id' => ['45'], 'photos' => null]); },
  'delete-nothing' => function () { return cli_album_delete(['album_id' => [], 'photos' => null]); },
]);
