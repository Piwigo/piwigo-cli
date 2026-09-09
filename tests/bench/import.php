<?php
// Bench for cli.import.php: a real folder tree under the system temp dir, a fake gallery
// that already stores one of the photos, and a fake core upload that consumes its input
// the command includes two core files: one we replace with our own functions, one we
// want for real because it holds the scanning rules we are testing against
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

require_once __DIR__.'/bench_root.php';
define('PHPWG_ROOT_PATH', bench_fake_root(
  'pwg_cli_bench_root',
  ['admin/include/functions_upload.inc.php'],
  ['admin/site_reader_local.php']
));
require_once __DIR__.'/bench.inc.php';

define('CATEGORIES_TABLE', 'cats');
define('IMAGES_TABLE', 'imgs');

$conf = [
  'file_ext' => ['jpg', 'pdf'],
  'picture_ext' => ['jpg'],
  'enable_formats' => false,
  'upload_dir' => './upload',
  'sync_exclude_folders' => ['private'],
];

// the tree, rebuilt from scratch at every run so a scenario never sees the last one
$tree = sys_get_temp_dir().'/pwg_cli_bench_import';
bench_import_tree($tree);

// leave the temp dir as we found it, even if a scenario throws
register_shutdown_function('bench_rmtree', $tree);
register_shutdown_function('bench_rmtree', rtrim(PHPWG_ROOT_PATH, '/'));
$known_md5 = md5_file($tree.'/plage/e.jpg');

function bench_import_tree(string $tree)
{
  bench_rmtree($tree);

  @mkdir($tree.'/plage/soir_2', 0777, true);
  @mkdir($tree.'/montagne', 0777, true);
  @mkdir($tree.'/private', 0777, true);
  @mkdir($tree.'/thumbnail', 0777, true);

  foreach (['a', 'b', 'c'] as $name)
  {
    file_put_contents($tree.'/'.$name.'.jpg', 'photo-'.$name);
  }
  file_put_contents($tree.'/plage/d.jpg', 'photo-d');
  file_put_contents($tree.'/plage/e.jpg', 'photo-e');
  file_put_contents($tree.'/montagne/a_again.jpg', 'photo-a'); // same bytes as a.jpg
  file_put_contents($tree.'/notes.pdf', 'not a picture');
  file_put_contents($tree.'/private/hidden.jpg', 'photo-hidden');
  file_put_contents($tree.'/thumbnail/thumb.jpg', 'photo-thumb');
}

function get_extension($file) { return pathinfo($file, PATHINFO_EXTENSION); }
function get_filename_wo_extension($file) { return pathinfo($file, PATHINFO_FILENAME); }
function get_fs_directories($path, $recursive)
{
  global $conf;
  $skip = array_flip(array_merge($conf['sync_exclude_folders'], ['thumbnail', 'pwg_high', 'pwg_representative', 'pwg_format']));
  $found = [];

  foreach (scandir($path) as $node)
  {
    if ('.' === $node[0] || !is_dir($path.'/'.$node) || isset($skip[$node]))
    {
      continue;
    }

    $found[] = $path.'/'.$node;
    if ($recursive)
    {
      $found = array_merge($found, get_fs_directories($path.'/'.$node, true));
    }
  }

  return $found;
}
function pwg_query($query) { return $query; }
function pwg_db_real_escape_string($string) { return addslashes($string); }
function pwg_db_fetch_row($query) { return false; } // no album exists yet
function query2array($query, $key = null, $value = null)
{
  global $known_md5;

  if (false !== strpos($query, 'md5sum IN') && false !== strpos($query, $known_md5))
  {
    return [$known_md5 => 120];
  }

  return [];
}
$next_album = 100;
function create_virtual_category($name, $parent) { global $next_album; return ['id' => ++$next_album]; }
$next_image = 500;
function add_uploaded_file($source, $name, $categories, $level, $id, $md5)
{
  global $next_image, $known_md5;

  if ('c.jpg' === $name)
  {
    throw new RuntimeException('disk full');
  }

  unlink($source); // the core consumes what it is given
  return $md5 === $known_md5 ? 120 : ++$next_image;
}
function pwg_check_real_extension($source, $name, $die) { return true; }
function prepare_directory($directory) { @mkdir($directory, 0777, true); }
function empty_lounge() { bench_core('empty_lounge()'); return []; }
function fill_caddie($ids) { bench_core('fill_caddie('.implode(',', $ids).')'); }

include CLI_ROOT_PATH.'commands/cli.import.php';

$defaults = ['album' => null, 'unwrap' => false, 'flat' => false, 'keep' => false,
             'privacy' => 4, 'caddie' => false, 'dirs-only' => false];

bench_run([
  'dry' => function () use ($tree, $defaults) {
    PwgCommand::set_dry_run();
    PwgCommand::set_verbose();
    return cli_import(['directory' => $tree] + $defaults);
  },
  'dry-flat' => function () use ($tree, $defaults) {
    PwgCommand::set_dry_run();
    return cli_import(['directory' => $tree, 'flat' => true] + $defaults);
  },
  'dry-dirs-only' => function () use ($tree, $defaults) {
    PwgCommand::set_dry_run();
    return cli_import(['directory' => $tree, 'dirs-only' => true] + $defaults);
  },
  'unwrap-needs-album' => function () use ($tree, $defaults) {
    PwgCommand::set_dry_run();
    return cli_import(['directory' => $tree, 'unwrap' => true] + $defaults);
  },
  'not-a-directory' => function () use ($tree, $defaults) {
    return cli_import(['directory' => $tree.'/nope'] + $defaults);
  },
  'import-keep' => function () use ($tree, $defaults) {
    PwgCommand::assume_yes();
    return cli_import(['directory' => $tree, 'keep' => true, 'caddie' => true] + $defaults);
  },
  'import-move' => function () use ($tree, $defaults) {
    PwgCommand::assume_yes();
    return cli_import(['directory' => $tree] + $defaults);
  },
  'refuse-without-yes' => function () use ($tree, $defaults) {
    return cli_import(['directory' => $tree] + $defaults);
  },
]);
