<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

// photos go through add_uploaded_file() like a web upload: moved into upload/,
// deduplicated by md5, metadata read. The directory becomes an album named after
// it, its sub-folders sub-albums, every photo lands in the album of its folder
$cli->add_command('sync', 'cli_sync',
  array(
    'description' => 'Import a directory into the gallery, through the same path as a web upload. The directory becomes an album, its sub-folders sub-albums, every photo lands in the album of its folder (underscores become spaces). Photos are moved into upload/, a photo already in the gallery is only linked to the album. Albums are found by name before being created: running it again is safe. Symbolic links are skipped. The account running it must write into upload/ and read the directory. Start with --dry-run.',
    'boot' => 'full',
    'operands' => [
      'directory' => [
        'info' => 'Directory to scan, its photos are moved into the gallery (see --keep)',
      ],
    ],
    'args' => [
      'album' => [
        'short' => 'p',
        'info' => 'Parent album id, the folder tree is created under it (default: root)',
        'default' => null,
      ],
      'unwrap' => [
        'info' => 'No album for the directory itself, its content maps directly onto --album (photos at the top then need --album)',
        'flag' => true,
      ],
      'flat' => [
        'info' => 'Ignore the sub-folders, every photo lands in the same album',
        'flag' => true,
      ],
      'keep' => [
        'short' => 'k',
        'info' => 'Copy the photos instead of moving them, the directory stays untouched',
        'flag' => true,
      ],
      'privacy' => [
        'info' => 'Privacy level given to the new photos (0 to 8)',
        'default' => 0,
      ],
      'caddie' => [
        'short' => 'c',
        'info' => 'Add the new photos to the caddie',
        'flag' => true,
      ],
      'dirs-only' => [
        'short' => 'd',
        'info' => 'Only create the albums from the folders, import no photo',
        'flag' => true,
      ],
    ],
  )
);
function cli_sync(array $args)
{
  global $conf;

  $directory = realpath($args['directory']);
  if (false === $directory or !is_dir($directory))
  {
    PwgCommand::error('"'.$args['directory'].'" is not a directory');
    return PwgCommand::ERROR;
  }

  // the gallery's own files must never be scanned: a stored photo is a duplicate of itself,
  // and the core deletes the "source" of a duplicate
  $upload_dir = realpath(PHPWG_ROOT_PATH.$conf['upload_dir']);
  if (false !== $upload_dir and (0 === strpos($directory.'/', $upload_dir.'/') or 0 === strpos($upload_dir.'/', $directory.'/')))
  {
    PwgCommand::error('"'.$directory.'" contains or sits inside the upload directory, nothing to import from there');
    return PwgCommand::ERROR;
  }

  // the parent album: null is the root
  $parent_id = null;
  if (null !== $args['album'])
  {
    $parent_id = filter_var($args['album'], FILTER_VALIDATE_INT);
    if (false === $parent_id)
    {
      PwgCommand::error('--album (-p) must be an album id');
      return PwgCommand::ERROR;
    }

    $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$parent_id.'
;';
    if (!pwg_db_fetch_row(pwg_query($query)))
    {
      PwgCommand::error('album #'.$parent_id.' does not exist');
      return PwgCommand::ERROR;
    }
  }

  $privacy = filter_var($args['privacy'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8]]);
  if (false === $privacy)
  {
    PwgCommand::error('--privacy must be a level between 0 and 8');
    return PwgCommand::ERROR;
  }

  include_once(PHPWG_ROOT_PATH.'admin/site_reader_local.php');
  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');
  $site_reader = new LocalSiteReader($directory);

  // the core deletes a file of a type it refuses, then dies: only hand it what it accepts
  $accepted = array_flip(!empty($conf['upload_form_all_types']) ? $conf['file_ext'] : $conf['picture_ext']);

  // the photo scan does not know the excluded folders (thumbnail, pwg_high, sync_exclude_folders...),
  // the folder scan does: only keep the photos of folders the walk will visit. A folder whose
  // real path is not itself was reached through a link, so are all its children: drop them all
  $folders = array_flip(array_filter(get_fs_directories($directory, true), function ($folder) { return realpath($folder) === $folder; }));
  $folders[$directory] = true;

  // one disk scan, grouped by folder: each level of the recursion picks its own photos
  $photos = [];
  $refused = [];
  foreach (array_keys($site_reader->get_elements($directory)) as $path)
  {
    if (!isset($folders[dirname($path)]))
    {
      continue;
    }

    $extension = strtolower(get_extension($path));
    if (!isset($accepted[$extension]))
    {
      $refused[] = $path;
      continue;
    }

    // the core deletes an svg it finds unsafe, then dies: check it first with its own validator
    if ('svg' === $extension)
    {
      include_once(PHPWG_ROOT_PATH.'include/svg-sanitizer.php');
      if ('' !== validate_svg(file_get_contents($path)))
      {
        $refused[] = $path;
        continue;
      }
    }

    // a link moved into the gallery would end up dangling once its target is consumed,
    // and one leading outside the directory could reach the gallery's own files
    $real = realpath($path);
    if (is_link($path) or false === $real or 0 !== strpos($real, $directory.'/'))
    {
      continue;
    }

    $photos[dirname($path)][] = $path;
  }

  if (count($refused) > 0)
  {
    PwgCommand::warning(count($refused).' file'.(1 === count($refused) ? '' : 's').' skipped, type not accepted for upload ($conf[\'upload_form_all_types\']) or unsafe svg');
    if (PwgCommand::is_verbose())
    {
      PwgCommand::errln(array_map(function ($path) { return '  '.$path; }, $refused));
    }
  }

  // --flat: every photo counts as sitting at the top, the recursion will not go down
  if ($args['flat'])
  {
    $photos = [$directory => array_merge(...array_values($photos))];
  }

  // --dirs-only: no photo anywhere, the albums are all that remains
  if ($args['dirs-only'])
  {
    $photos = [];
  }

  // --unwrap gives the top of the directory no album of its own: its photos need --album
  if ($args['unwrap'] and null === $parent_id and !empty($photos[$directory]))
  {
    PwgCommand::error(count($photos[$directory]).' photos at the top of the directory need an album: drop --unwrap or give --album');
    return PwgCommand::ERROR;
  }

  $total = array_sum(array_map('count', $photos));

  if (!PwgCommand::is_dry_run() and $total > 0)
  {
    $upload = PHPWG_ROOT_PATH.$conf['upload_dir'];
    $target = is_dir($upload) ? $upload : PHPWG_ROOT_PATH;

    // add_uploaded_file() dies when it cannot write there, fail cleanly before it does
    if (!is_writable($target))
    {
      PwgCommand::error('"'.$conf['upload_dir'].'" is not writable, the photos cannot be imported');
      PwgCommand::errln('give write access or run as the web server user ("sudo -u www-data ...")');
      return PwgCommand::ERROR;
    }

    // the core stores every photo as 0644 owned by whoever runs this: another owner than
    // the one of upload/ can still serve them, not rewrite them (rotation from the admin)
    if (function_exists('posix_geteuid') and posix_geteuid() !== fileowner($target))
    {
      $me = posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid();
      $owner = posix_getpwuid(fileowner($target))['name'] ?? fileowner($target);
      PwgCommand::warning('the photos will belong to "'.$me.'", not to "'.$owner.'" who owns "'.$conf['upload_dir'].'": the web server will read them, not rewrite them');
    }

    // the photos leave the directory and the duplicates are deleted: ask first
    if (!$args['keep'] and !PwgCommand::confirm('Move '.$total.' photos out of "'.$directory.'" into the gallery (duplicates are deleted)?'))
    {
      PwgCommand::writeln('aborted');
      return PwgCommand::ERROR;
    }

    // an import without duplicate detection makes no sense, whatever the admin setting says
    $conf['upload_detect_duplicate'] = true;

    // --keep stages its copies here, once is enough
    if ($args['keep'])
    {
      prepare_directory(PHPWG_ROOT_PATH.$conf['upload_dir'].'/buffer');
    }
  }

  $ctx = [
    'root' => $directory,
    'keep' => $args['keep'],
    'level' => $privacy > 0 ? $privacy : null,
    'buffer' => PHPWG_ROOT_PATH.$conf['upload_dir'].'/buffer',
    'ids' => [],
    'seen' => [], // md5 => id of the photos handled by this run
    'albums_created' => 0,
    'albums_found' => 0,
    'new' => 0,
    'dup' => 0,
    'err' => 0,
  ];

  if ($total > 0)
  {
    PwgCommand::progress_start($total, PwgCommand::is_dry_run() ? 'checking' : 'importing');
  }
  cli_sync_folder($directory, $parent_id, 0, $photos, $args['flat'], !$args['unwrap'], $ctx);
  PwgCommand::progress_finish();

  // like the web upload form at the end of a batch: the lounge holds the new photos,
  // and the album links of the duplicates, until someone pushes them into their albums
  if ($ctx['new'] + $ctx['dup'] > 0 and !PwgCommand::is_dry_run())
  {
    empty_lounge();
  }

  if ($args['caddie'] and count($ctx['ids']) > 0)
  {
    fill_caddie($ctx['ids']);
  }

  // albums and photos are two different stories, keep them on two lines
  $dry = PwgCommand::is_dry_run();
  $photos_line = 'photos: '.$ctx['new'].($dry ? ' to import' : ' imported').', '.$ctx['dup'].' already there';
  if ($ctx['err'] > 0)
  {
    $photos_line .= ', '.PwgCommand::red($ctx['err'].' failed');
  }

  PwgCommand::writeln([
    '',
    'albums: '.$ctx['albums_created'].($dry ? ' to create' : ' created').', '.$ctx['albums_found'].' existing',
    $photos_line,
  ]);

  return $ctx['err'] > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

// one folder: its album (found, created, or "+" in dry-run), its photos, then its sub-folders.
// $parent_id: null is the root, 0 is an album that only exists in the dry-run so far
function cli_sync_folder(string $dir, ?int $parent_id, int $depth, array $photos, bool $flat, bool $own_album, array &$ctx)
{
  $indent = str_repeat('  ', $depth);

  if ($own_album)
  {
    // same rule as the legacy sync: underscores become spaces in album names
    $name = str_replace('_', ' ', basename($dir));
    $album_id = 0 === $parent_id ? null : cli_sync_find_album($name, $parent_id);

    if (null !== $album_id)
    {
      $ctx['albums_found']++;
      $label = '= '.$name.' #'.$album_id;
    }
    else
    {
      $ctx['albums_created']++;

      if (PwgCommand::is_dry_run())
      {
        $album_id = 0;
        $label = PwgCommand::green('+ '.$name);
      }
      else
      {
        $created = create_virtual_category($name, $parent_id);
        if (isset($created['error']))
        {
          throw new RuntimeException($created['error']);
        }
        $album_id = (int) $created['id'];
        $label = PwgCommand::green('+ '.$name.' #'.$album_id);
      }
    }
  }
  else
  {
    // --unwrap: the directory itself has no album, its content hangs from the parent
    $name = 'album #'.$parent_id;
    $album_id = $parent_id;
    $label = 'at the top of the directory, into album #'.$parent_id;
    $depth--;
  }

  $result = cli_sync_photos($photos[$dir] ?? [], $album_id, $name, $indent, $ctx);

  if ($own_album or $result['total'] > 0)
  {
    PwgCommand::writeln($indent.$label.'  '.cli_sync_photo_summary($result));
  }

  if ($flat)
  {
    return;
  }

  $subs = get_fs_directories($dir, false);
  sort($subs, SORT_STRING);
  foreach ($subs as $sub)
  {
    // links are skipped, and so is anything resolving outside the directory
    $real = realpath($sub);
    if (is_link($sub) or false === $real or 0 !== strpos($real.'/', $ctx['root'].'/'))
    {
      continue;
    }
    cli_sync_folder($sub, $album_id, $depth + 1, $photos, $flat, true, $ctx);
  }
}

// the photos of one folder into one album, through the same path as a web upload
function cli_sync_photos(array $paths, ?int $album_id, string $label, string $indent, array &$ctx): array
{
  $result = ['total' => count($paths), 'new' => 0, 'dup' => 0, 'err' => 0];
  if (0 === $result['total'])
  {
    return $result;
  }

  PwgCommand::progress_label($label);

  // one lookup for the whole folder: which of these files the gallery already has
  $md5s = [];
  foreach ($paths as $path)
  {
    $md5s[$path] = md5_file($path);
  }
  $known = cli_sync_find_photos(array_values($md5s));
  $referenced = cli_sync_find_referenced($paths);

  foreach ($paths as $path)
  {
    $file = basename($path);

    // this very file is a photo of the gallery (a physical album): moving or deleting it would break it
    if (isset($referenced[$path]))
    {
      $result['dup']++;
      if (PwgCommand::is_verbose())
      {
        PwgCommand::writeln($indent.'  ~ '.$file.' is already photo #'.$referenced[$path].' of the gallery, left alone');
      }
      PwgCommand::progress_advance();
      continue;
    }

    $md5 = $md5s[$path];
    $existing = $known[$md5] ?? $ctx['seen'][$md5] ?? null;
    $kind = null === $existing ? 'new' : 'dup';
    $where = 0 === $existing ? 'earlier in this run' : '#'.$existing;

    if (PwgCommand::is_dry_run())
    {
      $result[$kind]++;
      $ctx['seen'][$md5] = $existing ?? 0;
      if (PwgCommand::is_verbose())
      {
        PwgCommand::writeln($indent.'  '.(null === $existing ? '+ '.$file : '~ '.$file.' already there '.$where));
      }
      PwgCommand::progress_advance();
      continue;
    }

    try
    {
      // the core would die() on a mismatch, ask it politely first
      if (false === pwg_check_real_extension($path, $file, false))
      {
        throw new RuntimeException('content does not match its extension');
      }

      // --keep: the core moves what it is given, so give it a copy
      $source = $path;
      if ($ctx['keep'])
      {
        $source = $ctx['buffer'].'/'.uniqid('cli-', true).'.'.get_extension($file);
        if (!copy($path, $source))
        {
          throw new RuntimeException('cannot copy to '.$source);
        }
      }

      // a duplicate is not stored again, only linked to the album (and the source consumed)
      $id = add_uploaded_file($source, $file, [$album_id], $ctx['level'], null, $md5);
      $result[$kind]++;
      $ctx['seen'][$md5] = $id;

      // the caddie is for what was actually added, like the web sync
      if (null === $existing)
      {
        $ctx['ids'][] = $id;
      }

      if (PwgCommand::is_verbose())
      {
        PwgCommand::writeln($indent.'  '.(null === $existing ? '+ '.$file.' #'.$id : '~ '.$file.' already there #'.$id));
      }
    }
    catch (Throwable $e)
    {
      $result['err']++;
      PwgCommand::warning($file.': '.$e->getMessage());

      // --keep: a copy the core never consumed must not pile up in the buffer
      if (isset($source) and $source !== $path and file_exists($source))
      {
        @unlink($source);
      }
    }

    PwgCommand::progress_advance();
  }

  foreach (['new', 'dup', 'err'] as $kind)
  {
    $ctx[$kind] += $result[$kind];
  }

  return $result;
}

// "3 photos: 2 new, 1 already there"
function cli_sync_photo_summary(array $result): string
{
  if (0 === $result['total'])
  {
    return '0 photos';
  }

  $parts = [];
  if ($result['new'] > 0)
  {
    $parts[] = $result['new'].(PwgCommand::is_dry_run() ? ' new' : ' imported');
  }
  if ($result['dup'] > 0)
  {
    $parts[] = $result['dup'].' already there';
  }
  if ($result['err'] > 0)
  {
    $parts[] = PwgCommand::red($result['err'].' failed');
  }

  return $result['total'].' photo'.(1 === $result['total'] ? '' : 's').': '.implode(', ', $parts);
}

function cli_sync_find_album(string $name, ?int $parent_id): ?int
{
  $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE name = \''.pwg_db_real_escape_string($name).'\'
    AND id_uppercat '.(null === $parent_id ? 'IS NULL' : '= '.$parent_id).'
;';
  $row = pwg_db_fetch_row(pwg_query($query));

  return $row ? (int) $row[0] : null;
}

// md5 => id for the photos the gallery already stores, whatever their album
function cli_sync_find_photos(array $md5s): array
{
  $query = '
SELECT md5sum, id
  FROM '.IMAGES_TABLE.'
  WHERE md5sum IN (\''.implode('\',\'', $md5s).'\')
;';

  return query2array($query, 'md5sum', 'id');
}

// absolute path => id for the files the gallery already references by path (physical albums)
function cli_sync_find_referenced(array $paths): array
{
  // the core stores paths relative to its root, "./galleries/2024/img.jpg"
  $root = rtrim(realpath(PHPWG_ROOT_PATH), '/').'/';
  $stored = [];
  foreach ($paths as $path)
  {
    if (0 === strpos($path, $root))
    {
      $stored['./'.substr($path, strlen($root))] = $path;
    }
  }

  if (0 === count($stored))
  {
    return [];
  }

  $query = '
SELECT path, id
  FROM '.IMAGES_TABLE.'
  WHERE path IN (\''.implode('\',\'', array_map('pwg_db_real_escape_string', array_keys($stored))).'\')
;';

  $referenced = [];
  foreach (query2array($query, 'path', 'id') as $stored_path => $id)
  {
    $referenced[$stored[$stored_path]] = (int) $id;
  }

  return $referenced;
}
