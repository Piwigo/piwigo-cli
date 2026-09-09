<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

global $cli;

$cli->add_command('album.list', 'cli_album_list',
  array(
    'description' => 'List the albums, with their parent and how many photos they hold',
    'boot' => 'full',
    'pagination' => true,
    'args' => [
      'search' => [
        'short' => 's',
        'info' => 'Only the albums whose name contains this text',
        'default' => null,
      ],
      'parent' => [
        'short' => 'p',
        'info' => 'Only the direct sub-albums of this album id, "root" for the top level',
        'default' => null,
      ],
    ],
  )
);
function cli_album_list(array $args)
{
  $where = [];

  if (null !== $args['parent'])
  {
    if ('root' === $args['parent'])
    {
      $where[] = 'c.id_uppercat IS NULL';
    }
    elseif (null === cli_album_id($args['parent']))
    {
      PwgCommand::error('album #'.$args['parent'].' does not exist');
      return PwgCommand::INVALID;
    }
    else
    {
      $where[] = 'c.id_uppercat = '.(int) $args['parent'];
    }
  }

  if (null !== $args['search'])
  {
    $where[] = 'c.name LIKE \'%'.pwg_db_real_escape_string($args['search']).'%\'';
  }

  $query = '
SELECT
    c.id,
    c.name,
    c.id_uppercat AS parent,
    c.status,
    COUNT(ic.image_id) AS photos
  FROM '.CATEGORIES_TABLE.' AS c
    LEFT JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.category_id = c.id
  '.(0 === count($where) ? '' : 'WHERE '.implode(' AND ', $where)).'
  GROUP BY c.id, c.name, c.id_uppercat, c.status
  ORDER BY c.global_rank
;';
  $albums = query2array($query);

  if (0 === count($albums))
  {
    PwgCommand::writeln('no album'.(null === $args['search'] ? '' : ' matching "'.$args['search'].'"'));
    return PwgCommand::SUCCESS;
  }

  PwgCommand::table(PwgCommand::paginate($albums, $args));
  PwgCommand::pagination_footer('album');

  return PwgCommand::SUCCESS;
}

$cli->add_command('album.add', 'cli_album_add',
  array(
    'description' => 'Create an album, at the top level or under another one',
    'boot' => 'full',
    'operands' => [
      'name' => ['info' => 'Name of the new album'],
    ],
    'args' => [
      'parent' => [
        'short' => 'p',
        'info' => 'Create it under this album id instead of the top level',
        'default' => null,
      ],
      'private' => [
        'info' => 'Only the users you allow will see it',
        'flag' => true,
      ],
      'comment' => [
        'short' => 'c',
        'info' => 'Description shown on the album page',
        'default' => null,
      ],
    ],
  )
);
function cli_album_add(array $args)
{
  $parent_id = null;

  if (null !== $args['parent'])
  {
    $parent_id = cli_album_id($args['parent']);

    if (null === $parent_id)
    {
      PwgCommand::error('album #'.$args['parent'].' does not exist');
      return PwgCommand::INVALID;
    }
  }

  $under = null === $parent_id ? 'at the top level' : 'under "'.cli_album_name($parent_id).'" (#'.$parent_id.')';

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would create '.($args['private'] ? 'a private' : 'a public').' album "'.$args['name'].'" '.$under);
    return PwgCommand::SUCCESS;
  }

  $options = [];
  if ($args['private'])
  {
    $options['status'] = 'private';
  }
  if (null !== $args['comment'])
  {
    $options['comment'] = $args['comment'];
  }

  // the core names it, ranks it, inherits the permissions and traces the activity
  $created = create_virtual_category($args['name'], $parent_id, $options);

  if (isset($created['error']))
  {
    PwgCommand::error($created['error']);
    return PwgCommand::ERROR;
  }

  PwgCommand::success('album "'.$args['name'].'" created (#'.$created['id'].') '.$under);
  return PwgCommand::SUCCESS;
}

$cli->add_command('album.edit', 'cli_album_edit',
  array(
    'description' => 'Change an album: name, description, privacy, visibility or comments',
    'boot' => 'full',
    'operands' => [
      'album_id' => ['info' => 'Album to edit'],
    ],
    'args' => [
      'name' => [
        'info' => 'New name',
        'default' => null,
      ],
      'comment' => [
        'short' => 'c',
        'info' => 'New description, an empty value clears it',
        'default' => null,
      ],
      'status' => [
        'short' => 's',
        'info' => 'public or private',
        'default' => null,
      ],
      'visible' => [
        'info' => 'true or false, a hidden album shows to nobody but the admins',
        'default' => null,
      ],
      'commentable' => [
        'info' => 'true or false, whether visitors may comment its photos',
        'default' => null,
      ],
    ],
  )
);
function cli_album_edit(array $args)
{
  $album_id = cli_album_id($args['album_id']);

  if (null === $album_id)
  {
    PwgCommand::error('album #'.$args['album_id'].' does not exist');
    return PwgCommand::INVALID;
  }

  if (null !== $args['status'] and !in_array($args['status'], ['public', 'private']))
  {
    PwgCommand::error('--status takes "public" or "private", not "'.$args['status'].'"');
    return PwgCommand::INVALID;
  }

  foreach (['visible', 'commentable'] as $boolean)
  {
    if (null !== $args[$boolean] and !in_array($args[$boolean], ['true', 'false']))
    {
      PwgCommand::error('--'.$boolean.' takes "true" or "false", not "'.$args[$boolean].'"');
      return PwgCommand::INVALID;
    }
  }

  // only what was typed, and only what would really move
  $query = '
SELECT id, name, comment, status, visible, commentable
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$album_id.'
;';
  $current = pwg_db_fetch_assoc(pwg_query($query));

  $changes = [];
  foreach (['name', 'comment', 'status', 'visible', 'commentable'] as $field)
  {
    if (null !== $args[$field] and (string) $args[$field] !== (string) $current[$field])
    {
      $changes[$field] = $args[$field];
    }
  }

  if (0 === count($changes))
  {
    PwgCommand::success('nothing to change on "'.$current['name'].'" (#'.$album_id.')');
    return PwgCommand::SUCCESS;
  }

  if (PwgCommand::is_dry_run())
  {
    $rows = [];
    foreach ($changes as $field => $value)
    {
      $rows[] = ['field' => $field, 'current' => (string) $current[$field], 'would become' => (string) $value];
    }

    PwgCommand::writeln('album #'.$album_id.' "'.$current['name'].'"');
    PwgCommand::table($rows);

    return PwgCommand::SUCCESS;
  }

  // status and visibility have their own core functions, they touch the sub-albums too
  if (isset($changes['status']))
  {
    set_cat_status([$album_id], $changes['status']);
  }
  if (isset($changes['visible']))
  {
    set_cat_visible([$album_id], $changes['visible']);
  }

  $columns = array_intersect_key($changes, array_flip(['name', 'comment', 'commentable']));

  if (count($columns) > 0)
  {
    single_update(CATEGORIES_TABLE, $columns, ['id' => $album_id]);
  }

  pwg_activity('album', $album_id, 'edit', ['fields' => implode(',', array_keys($changes))]);

  PwgCommand::success('album #'.$album_id.' updated: '.implode(', ', array_keys($changes)));
  return PwgCommand::SUCCESS;
}

$cli->add_command('album.move', 'cli_album_move',
  array(
    'description' => 'Move albums under another parent, or to the top level',
    'boot' => 'full',
    'operands' => [
      'album_id' => [
        'info' => 'Albums to move',
        'multiple' => true,
      ],
    ],
    'args' => [
      'parent' => [
        'short' => 'p',
        'info' => 'New parent album id, "root" for the top level',
        'default' => 'root',
      ],
    ],
  )
);
function cli_album_move(array $args)
{
  global $page;

  if (0 === count($args['album_id']))
  {
    PwgCommand::error('Which album? Give at least one id (see "pwg album list")');
    return PwgCommand::INVALID;
  }

  $parent_id = 'root' === $args['parent'] ? 0 : cli_album_id($args['parent']);

  if (null === $parent_id)
  {
    PwgCommand::error('album #'.$args['parent'].' does not exist');
    return PwgCommand::INVALID;
  }

  $albums = [];
  foreach ($args['album_id'] as $wanted)
  {
    $album_id = cli_album_id($wanted);

    if (null === $album_id)
    {
      PwgCommand::error('album #'.$wanted.' does not exist');
      return PwgCommand::INVALID;
    }

    $albums[$album_id] = cli_album_name($album_id).' (#'.$album_id.')';
  }

  $under = 0 === $parent_id ? 'to the top level' : 'under "'.cli_album_name($parent_id).'" (#'.$parent_id.')';

  if (PwgCommand::is_dry_run())
  {
    PwgCommand::writeln('would move '.implode(', ', $albums).' '.$under);
    return PwgCommand::SUCCESS;
  }

  // it reports through $page and returns nothing, watch what it added
  $errors_before = isset($page['errors']) ? count($page['errors']) : 0;
  move_categories(array_keys($albums), $parent_id);

  if (isset($page['errors']) and count($page['errors']) > $errors_before)
  {
    PwgCommand::error(implode(', ', array_slice($page['errors'], $errors_before)));
    return PwgCommand::ERROR;
  }

  PwgCommand::success(implode(', ', $albums).' moved '.$under);
  return PwgCommand::SUCCESS;
}

$cli->add_command('album.delete', 'cli_album_delete',
  array(
    'description' => 'Delete albums, their sub-albums, and decide what happens to the photos',
    'details' => [
      'An album never holds a photo alone: the same photo can live in several albums. So deleting an album asks what to do with the photos it holds, and tells you how many of them are also somewhere else.',
      'Without --photos the question is asked, and a closed STDIN or --yes takes the safest answer: keep every photo. A photo kept but linked to no album any more becomes an orphan, findable in the Batch Manager.',
    ],
    'examples' => [
      'pwg album delete 12 --dry-run',
      'pwg album delete 12 --photos orphans -y',
    ],
    'boot' => 'full',
    'operands' => [
      'album_id' => [
        'info' => 'Albums to delete, their sub-albums go too',
        'multiple' => true,
      ],
    ],
    'args' => [
      'photos' => [
        'info' => 'keep, orphans (delete those left in no album) or all (delete them all)',
        'default' => null,
      ],
    ],
  )
);
function cli_album_delete(array $args)
{
  if (0 === count($args['album_id']))
  {
    PwgCommand::error('Which album? Give at least one id (see "pwg album list")');
    return PwgCommand::INVALID;
  }

  $modes = ['keep' => 'no_delete', 'orphans' => 'delete_orphans', 'all' => 'force_delete'];

  if (null !== $args['photos'] and !isset($modes[$args['photos']]))
  {
    PwgCommand::error('--photos takes '.implode(', ', array_keys($modes)).', not "'.$args['photos'].'"');
    return PwgCommand::INVALID;
  }

  $albums = [];
  foreach ($args['album_id'] as $wanted)
  {
    $album_id = cli_album_id($wanted);

    if (null === $album_id)
    {
      PwgCommand::error('album #'.$wanted.' does not exist');
      return PwgCommand::INVALID;
    }

    $albums[$album_id] = cli_album_name($album_id).' (#'.$album_id.')';
  }

  // the core deletes the sub-albums too, so count what really goes
  $tree = get_subcat_ids(array_keys($albums));
  $photos = cli_album_photos($tree);

  PwgCommand::writeln('deleting '.implode(', ', $albums).cli_album_tree_line($tree, $albums));

  if (0 === $photos['total'])
  {
    PwgCommand::writeln('  no photo inside');
  }
  else
  {
    PwgCommand::writeln('  '.$photos['total'].' photos inside, '.$photos['elsewhere'].' of them also in another album, '
      .$photos['only_here'].' only here');
  }

  $doomed = ['keep' => 0, 'orphans' => $photos['only_here'], 'all' => $photos['total']];

  // a dry-run never asks: with no --photos it shows what each answer would cost
  if (PwgCommand::is_dry_run())
  {
    $line = 'would delete '.count($tree).' albums and ';
    $line .= null === $args['photos']
      ? 'the photos you choose: keep '.$doomed['keep'].', orphans '.$doomed['orphans'].', all '.$doomed['all']
      : $doomed[$args['photos']].' photos';

    PwgCommand::writeln($line);
    return PwgCommand::SUCCESS;
  }

  $choice = $args['photos'];

  if (null === $choice)
  {
    $choice = 0 === $photos['total']
      ? 'keep'
      : PwgCommand::choose('What should happen to the photos?', [
          'keep' => 'leave every photo in the gallery',
          'orphans' => 'delete the '.$photos['only_here'].' photo'.(1 === $photos['only_here'] ? '' : 's').' that would be left in no album',
          'all' => 'delete all '.$photos['total'].' photos, even those in another album',
        ], 'keep');
  }

  if (!PwgCommand::confirm('Delete '.count($tree).' albums and '.$doomed[$choice].' photos?'))
  {
    PwgCommand::writeln('aborted');
    return PwgCommand::ERROR;
  }

  delete_categories(array_keys($albums), $modes[$choice]);

  // the core leaves the counters and the ranks to the caller
  update_global_rank();
  invalidate_user_cache();

  PwgCommand::success(count($tree).' albums deleted, '.$doomed[$choice].' photo'.(1 === $doomed[$choice] ? '' : 's').' deleted');
  return PwgCommand::SUCCESS;
}

// "12" to an album id, null when the gallery has no such album
function cli_album_id(string $album_id): ?int
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

function cli_album_name(int $album_id): string
{
  $query = '
SELECT name
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$album_id.'
;';
  $row = pwg_db_fetch_row(pwg_query($query));

  return $row ? (string) $row[0] : '?';
}

// how many photos these albums hold, and how many of them live somewhere else too
function cli_album_photos(array $album_ids): array
{
  if (0 === count($album_ids))
  {
    return ['total' => 0, 'elsewhere' => 0, 'only_here' => 0];
  }

  $inside = implode(',', $album_ids);

  $query = '
SELECT DISTINCT image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id IN ('.$inside.')
;';
  $images = query2array($query, null, 'image_id');

  if (0 === count($images)) 
  {
    return ['total' => 0, 'elsewhere' => 0, 'only_here' => 0];
  }

  $query = '
SELECT DISTINCT image_id
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE image_id IN ('.implode(',', $images).')
    AND category_id NOT IN ('.$inside.')
;';
  $elsewhere = count(query2array($query, null, 'image_id'));

  return [
    'total' => count($images),
    'elsewhere' => $elsewhere,
    'only_here' => count($images) - $elsewhere,
  ];
}

// "and its 3 sub-albums", when there are any
function cli_album_tree_line(array $tree, array $albums): string
{
  $sub = count($tree) - count($albums);

  return $sub > 0 ? ' and '.$sub.' sub-album'.(1 === $sub ? '' : 's') : '';
}
