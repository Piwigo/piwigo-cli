<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('photo.list', 'cli_photo_list',
  array(
    'description' => 'List the photos, newest first',
    'boot' => 'full',
    'pagination' => true,
    'args' => [
      'album' => [
        'short' => 'p',
        'info' => 'Only the photos of this album id',
        'default' => null,
      ],
      'search' => [
        'short' => 's',
        'info' => 'Only the photos whose name or file name contains this text',
        'default' => null,
      ],
    ],
  )
);
function cli_photo_list(array $args)
{
  $where = [];
  $join = '';

  if (null !== $args['album'])
  {
    if (null === cli_photo_album_id($args['album']))
    {
      PwgCommand::error('album #'.$args['album'].' does not exist');
      return PwgCommand::INVALID;
    }

    $join = 'JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id';
    $where[] = 'ic.category_id = '.(int) $args['album'];
  }

  if (null !== $args['search'])
  {
    $needle = pwg_db_real_escape_string($args['search']);
    $where[] = '(i.name LIKE \'%'.$needle.'%\' OR i.file LIKE \'%'.$needle.'%\')';
  }

  $query = '
SELECT
    i.id,
    i.name,
    i.file,
    i.date_available AS added,
    i.filesize AS size_kb
  FROM '.IMAGES_TABLE.' AS i
    '.$join.'
  '.(0 === count($where) ? '' : 'WHERE '.implode(' AND ', $where)).'
  ORDER BY i.id DESC
;';
  $photos = query2array($query);

  if (0 === count($photos))
  {
    PwgCommand::writeln('no photo'.(null === $args['search'] ? '' : ' matching "'.$args['search'].'"'));
    return PwgCommand::SUCCESS;
  }

  PwgCommand::table(PwgCommand::paginate($photos, $args));
  PwgCommand::pagination_footer('photo');

  return PwgCommand::SUCCESS;
}

$cli->add_command('photo.info', 'cli_photo_info',
  array(
    'description' => 'Show everything the gallery knows about one photo',
    'boot' => 'full',
    'operands' => [
      'photo_id' => ['info' => 'Photo id, as shown by "pwg photo list"'],
    ],
  )
);
function cli_photo_info(array $args)
{
  $photo_id = cli_photo_id($args['photo_id']);

  if (null === $photo_id)
  {
    PwgCommand::error('photo #'.$args['photo_id'].' does not exist');
    return PwgCommand::INVALID;
  }

  $query = '
SELECT *
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$photo_id.'
;';
  $photo = pwg_db_fetch_assoc(pwg_query($query));

  // the albums it belongs to, and its tags: neither lives in the images table
  $query = '
SELECT c.id, c.name
  FROM '.IMAGE_CATEGORY_TABLE.' AS ic
    JOIN '.CATEGORIES_TABLE.' AS c ON c.id = ic.category_id
  WHERE ic.image_id = '.$photo_id.'
;';
  $albums = query2array($query, 'id', 'name');

  $query = '
SELECT t.name
  FROM '.IMAGE_TAG_TABLE.' AS it
    JOIN '.TAGS_TABLE.' AS t ON t.id = it.tag_id
  WHERE it.image_id = '.$photo_id.'
;';
  $tags = query2array($query, null, 'name');

  $photo['albums'] = $albums;
  $photo['tags'] = $tags;

  PwgCommand::record($photo);

  return PwgCommand::SUCCESS;
}

$cli->add_command('photo.move', 'cli_photo_move',
  array(
    'description' => 'Move photos into an album, out of the ones they were in',
    'details' => [
      'A photo can belong to several albums at once. --add keeps the albums it already has and only adds the new one, which is what the Batch Manager calls associating.',
    ],
    'boot' => 'full',
    'operands' => [
      'photo_id' => [
        'info' => 'Photos to move',
        'multiple' => true,
      ],
    ],
    'args' => [
      'album' => [
        'short' => 'p',
        'info' => 'Album they land in',
        'default' => null,
      ],
      'add' => [
        'short' => 'a',
        'info' => 'Add the album instead of replacing the ones they are in',
        'flag' => true,
      ],
    ],
  )
);
function cli_photo_move(array $args)
{
  if (0 === count($args['photo_id']))
  {
    PwgCommand::error('Which photo? Give at least one id (see "pwg photo list")');
    return PwgCommand::INVALID;
  }

  if (null === $args['album'])
  {
    PwgCommand::error('Into which album? Give --album (see "pwg album list")');
    return PwgCommand::INVALID;
  }

  $album_id = cli_photo_album_id($args['album']);

  if (null === $album_id)
  {
    PwgCommand::error('album #'.$args['album'].' does not exist');
    return PwgCommand::INVALID;
  }

  $photos = cli_photo_ids($args['photo_id']);

  if (null === $photos)
  {
    return PwgCommand::INVALID;
  }

  $verb = $args['add'] ? 'add' : 'move';

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would '.$verb.' '.count($photos).' photo'.(1 === count($photos) ? '' : 's').' '
      .($args['add'] ? 'to' : 'into').' album #'.$album_id);
    return PwgCommand::SUCCESS;
  }

  // move_images_to_categories drops the other links, associate keeps them
  $args['add']
    ? associate_images_to_categories($photos, [$album_id])
    : move_images_to_categories($photos, [$album_id]);

  update_category([$album_id]);
  invalidate_user_cache();
  pwg_activity('photo', $photos, $args['add'] ? 'associate' : 'move', ['album' => $album_id]);

  PwgCommand::success(count($photos).' photo'.(1 === count($photos) ? '' : 's').' now in album #'.$album_id);
  return PwgCommand::SUCCESS;
}

$cli->add_command('photo.delete', 'cli_photo_delete',
  array(
    'description' => 'Delete photos and the files that belong to them',
    'boot' => 'full',
    'operands' => [
      'photo_id' => [
        'info' => 'Photos to delete',
        'multiple' => true,
      ],
    ],
    'args' => [
      'keep-files' => [
        'info' => 'Remove them from the gallery but leave their files on disk',
        'flag' => true,
      ],
    ],
  )
);
function cli_photo_delete(array $args)
{
  if (0 === count($args['photo_id']))
  {
    PwgCommand::error('Which photo? Give at least one id (see "pwg photo list")');
    return PwgCommand::INVALID;
  }

  $photos = cli_photo_ids($args['photo_id']);

  if (null === $photos)
  {
    return PwgCommand::INVALID;
  }

  $with_files = !$args['keep-files'];
  $what = count($photos).' photo'.(1 === count($photos) ? '' : 's').($with_files ? ' and their files' : ', keeping their files');

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would delete '.$what);
    return PwgCommand::SUCCESS;
  }

  if (!PwgCommand::confirm('Delete '.$what.'?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  $deleted = delete_elements($photos, $with_files);
  invalidate_user_cache();

  PwgCommand::success($deleted.' photo'.(1 === $deleted ? '' : 's').' deleted');
  return PwgCommand::SUCCESS;
}

$cli->add_command('photo.import', 'cli_photo_import',
  array(
    'description' => 'Import single photo files into an album',
    'details' => [
      'The files go through the same path as a web upload, like "pwg import" does for a whole directory: they are moved into upload/, their metadata is read, and a photo the gallery already stores is only linked to the album.',
      'Use "pwg import" when you have a tree of folders to turn into albums, this one when you have a handful of files and an album in mind.',
    ],
    'examples' => [
      'pwg photo import -p 12 ~/a.jpg ~/b.jpg',
      'pwg photo import -p 12 --keep ~/photos/*.jpg',
    ],
    'boot' => 'full',
    'operands' => [
      'file' => [
        'info' => 'Files to import',
        'multiple' => true,
      ],
    ],
    'args' => [
      'album' => [
        'short' => 'p',
        'info' => 'Album they land in',
        'default' => null,
      ],
      'keep' => [
        'short' => 'k',
        'info' => 'Copy the files instead of moving them, the originals stay untouched',
        'flag' => true,
      ],
      'privacy' => [
        'info' => 'Privacy level given to the new photos (0 to 8)',
        'default' => 0,
      ],
    ],
  )
);
function cli_photo_import(array $args)
{
  global $conf;

  if (0 === count($args['file']))
  {
    PwgCommand::error('Which file? Give at least one path');
    return PwgCommand::INVALID;
  }

  if (null === $args['album'])
  {
    PwgCommand::error('Into which album? Give --album (see "pwg album list")');
    return PwgCommand::INVALID;
  }

  $album_id = cli_photo_album_id($args['album']);

  if (null === $album_id)
  {
    PwgCommand::error('album #'.$args['album'].' does not exist');
    return PwgCommand::INVALID;
  }

  $privacy = filter_var($args['privacy'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 8]]);

  if (false === $privacy)
  {
    PwgCommand::error('--privacy must be a level between 0 and 8');
    return PwgCommand::ERROR;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

  // the core deletes a file of a type it refuses, then dies: only hand it what it accepts
  $accepted = array_flip(!empty($conf['upload_form_all_types']) ? $conf['file_ext'] : $conf['picture_ext']);

  $files = [];
  foreach ($args['file'] as $path)
  {
    $real = realpath($path);

    if (false === $real or !is_file($real))
    {
      PwgCommand::error('"'.$path.'" is not a file');
      return PwgCommand::INVALID;
    }

    if (!isset($accepted[strtolower(get_extension($real))]))
    {
      PwgCommand::error('"'.$path.'" is not a type this gallery accepts for upload');
      return PwgCommand::INVALID;
    }

    $files[] = $real;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would import '.count($files).' file'.(1 === count($files) ? '' : 's').' into album #'.$album_id
      .($args['keep'] ? ', leaving the originals in place' : ', moving them out of their directory'));
    return PwgCommand::SUCCESS;
  }

  if (!$args['keep'] and !PwgCommand::confirm('Move '.count($files).' files into the gallery (duplicates are deleted)?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  // an import without duplicate detection makes no sense, whatever the admin setting says
  $conf['upload_detect_duplicate'] = true;
  $buffer = PHPWG_ROOT_PATH.$conf['upload_dir'].'/buffer';

  if ($args['keep'])
  {
    prepare_directory($buffer);
  }

  $imported = 0;
  $failed = 0;
  PwgCommand::progress_start(count($files), 'importing');

  foreach ($files as $path)
  {
    $name = basename($path);
    PwgCommand::progress_label($name);
    $source = $path;

    try
    {
      // the core would die() on a mismatch, ask it politely first
      if (false === pwg_check_real_extension($path, $name, false))
      {
        throw new RuntimeException('content does not match its extension');
      }

      // --keep: the core moves what it is given, so give it a copy
      if ($args['keep'])
      {
        $source = $buffer.'/'.uniqid('cli-', true).'.'.get_extension($name);

        if (!copy($path, $source))
        {
          throw new RuntimeException('cannot copy to '.$source);
        }
      }

      add_uploaded_file($source, $name, [$album_id], $privacy > 0 ? $privacy : null, null, md5_file($path));
      $imported++;
    }
    catch (Throwable $e)
    {
      $failed++;
      PwgCommand::warning($name.': '.$e->getMessage());

      if ($source !== $path and file_exists($source))
      {
        @unlink($source);
      }
    }

    PwgCommand::progress_advance();
  }

  PwgCommand::progress_finish();

  if ($imported > 0)
  {
    // like the web upload form at the end of a batch
    empty_lounge();
  }

  PwgCommand::success($imported.' photo'.(1 === $imported ? '' : 's').' imported into album #'.$album_id
    .($failed > 0 ? ', '.PwgCommand::red($failed.' failed') : ''));

  return $failed > 0 ? PwgCommand::ERROR : PwgCommand::SUCCESS;
}

$cli->add_command('photo.sync-metadata', 'cli_photo_sync_metadata',
  array(
    'description' => 'Read the metadata of stored photos again: EXIF, IPTC and tags',
    'details' => [
      'Piwigo reads the metadata of a photo once, when it enters the gallery. This reads them again from the files it already stores, which is what you want after changing the metadata mapping in the configuration, or after a plugin started extracting something new.',
      'It reads the files in upload/, not the ones you imported from: editing the EXIF of your originals changes nothing here.',
    ],
    'boot' => 'full',
    'operands' => [
      'photo_id' => [
        'info' => 'Photos to read again, none of them with --album or --all',
        'multiple' => true,
      ],
    ],
    'args' => [
      'album' => [
        'short' => 'p',
        'info' => 'Every photo of this album',
        'default' => null,
      ],
      'all' => [
        'short' => 'a',
        'info' => 'Every photo of the gallery',
        'flag' => true,
      ],
    ],
  )
);
function cli_photo_sync_metadata(array $args)
{
  $named = count($args['photo_id']) > 0;

  if (!$named and null === $args['album'] and !$args['all'])
  {
    PwgCommand::error('Which photos? Give ids, --album or --all');
    return PwgCommand::INVALID;
  }

  if ($named)
  {
    $photos = cli_photo_ids($args['photo_id']);

    if (null === $photos)
    {
      return PwgCommand::INVALID;
    }
  }
  elseif (null !== $args['album'])
  {
    if (null === cli_photo_album_id($args['album']))
    {
      PwgCommand::error('album #'.$args['album'].' does not exist');
      return PwgCommand::INVALID;
    }

    $query = '
SELECT image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.(int) $args['album'].'
;';
    $photos = query2array($query, null, 'image_id');
  }
  else
  {
    $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
;';
    $photos = query2array($query, null, 'id');
  }

  if (0 === count($photos))
  {
    PwgCommand::success('no photo to read');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would read the metadata of '.count($photos).' photo'.(1 === count($photos) ? '' : 's').' again');
    return PwgCommand::SUCCESS;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions_metadata.php');

  // sync_metadata() reads every file, so tell the user where it is
  $chunks = array_chunk($photos, 100);
  PwgCommand::progress_start(count($photos), 'reading');

  foreach ($chunks as $chunk)
  {
    sync_metadata($chunk);
    PwgCommand::progress_advance(count($chunk));
  }

  PwgCommand::progress_finish();

  PwgCommand::success('metadata read again for '.count($photos).' photo'.(1 === count($photos) ? '' : 's'));
  return PwgCommand::SUCCESS;
}

// "12" to a photo id, null when the gallery has no such photo
function cli_photo_id(string $photo_id): ?int
{
  if (!ctype_digit($photo_id))
  {
    return null;
  }

  $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int) $photo_id.'
;';
  $row = pwg_db_fetch_row(pwg_query($query));

  return $row ? (int) $row[0] : null;
}

// every id must exist, or the command stops before touching any of them
function cli_photo_ids(array $wanted): ?array
{
  $photos = [];
  $unknown = [];

  foreach ($wanted as $photo_id)
  {
    $found = cli_photo_id($photo_id);
    null === $found ? $unknown[] = $photo_id : $photos[] = $found;
  }

  if (count($unknown) > 0)
  {
    PwgCommand::error('Photo'.(1 === count($unknown) ? '' : 's').' not found: '.implode(', ', $unknown));
    return null;
  }

  return $photos;
}

function cli_photo_album_id(string $album_id): ?int
{
  if (!ctype_digit($album_id))
  {
    return null;
  }

  $query = '
SELECT id
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int) $album_id.'
;';
  $row = pwg_db_fetch_row(pwg_query($query));

  return $row ? (int) $row[0] : null;
}
