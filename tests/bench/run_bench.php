<?php
// Plays every scenario of every bench and checks its output. A bench on its own is a demo,
// this is what makes it a test.
//
//   php tests/bench/run_bench.php
//
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

$passed = 0;
$failed = 0;

/**
* @param string $bench File name in this directory, without the extension
* @param string $scenario Scenario name the bench declares
* @param int $expected_exit What the callback must return
* @param array $expected Substrings that must all appear
* @param array $forbidden Substrings that must not appear
*/
function bench_case(string $bench, string $scenario, int $expected_exit, array $expected = [], array $forbidden = [])
{
  global $passed, $failed;

  $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/'.$bench.'.php').' '.escapeshellarg($scenario).' 2>&1 < /dev/null';
  exec($command, $output_lines, $exit);

  // colors would fight with the expectations, the bench inherits no terminal here anyway
  $output = preg_replace('/\e\[[0-9;]*m/', '', implode("\n", $output_lines));

  $errors = [];
  if (0 !== $exit)
  {
    $errors[] = 'the bench crashed';
  }
  if (!preg_match('/^exit=(-?\d+)$/m', $output, $match))
  {
    $errors[] = 'no exit code reported';
  }
  elseif ((int) $match[1] !== $expected_exit)
  {
    $errors[] = 'returned '.$match[1].', expected '.$expected_exit;
  }
  foreach ($expected as $needle)
  {
    if (false === strpos($output, $needle))
    {
      $errors[] = 'output misses "'.$needle.'"';
    }
  }
  foreach ($forbidden as $needle)
  {
    if (false !== strpos($output, $needle))
    {
      $errors[] = 'output contains "'.$needle.'"';
    }
  }

  if (empty($errors))
  {
    $passed++;
    echo '  ok   '.$bench.' '.$scenario."\n";
    return;
  }

  $failed++;
  echo '  FAIL '.$bench.' '.$scenario.' ('.implode(', ', $errors).')'."\n";
  foreach ($output_lines as $line)
  {
    echo '       > '.$line."\n";
  }
}

echo "user\n";
bench_case('user', 'edit-dry', 0, ['would become', 'alice@old.tld', 'a@new.tld'], ['check_and_save']);
bench_case('user', 'edit', 0, ['check_and_save_user_infos({"user_id":[5],"email":"a@new.tld","password":"secret"})', 'email, password'], ['secret (']);
bench_case('user', 'edit-nothing', 2, ['Nothing to change']);
bench_case('user', 'edit-unknown', 2, ['User "ghost" not found']);
bench_case('user', 'edit-refused', 1, ['Invalid status']);
bench_case('user', 'add-dry', 0, ['would create user "carol"', 'then set status'], ['register_user']);
bench_case('user', 'add', 0, ['register_user(\'carol\', generated', 'password: xxx', 'shown once']);
bench_case('user', 'add-full', 0, ['register_user(\'carol\', \'chosen\'', '"status":"admin","level":4'], ['password: ']);
bench_case('user', 'add-duplicate', 2, ['already exists'], ['register_user']);
bench_case('user', 'add-refused', 1, ['login mustn\'t start with a space']);
bench_case('user', 'add-halfway', 1, ['created (#9)', 'could not be set', 'pwg user edit 9']);
bench_case('user', 'delete-dry', 0, ['would delete 2 users', 'alice (#5)', 'bob (#6)'], ['delete_user(']);
bench_case('user', 'delete', 0, ['guest" (#2) is a protected account', '"1" (#1) is a protected account', 'delete_user(5)', '1 user deleted'], ['delete_user(2)', 'delete_user(1)']);
bench_case('user', 'delete-unknown', 2, ['not found: ghost'], ['delete_user(']);
bench_case('user', 'delete-refused', 1, ['aborted'], ['delete_user(']);
bench_case('user', 'delete-nothing', 2, ['Which user?']);
bench_case('user', 'cache-dry', 0, ['would rebuild the cache of 2 users'], ['getuserdata']);
bench_case('user', 'cache', 0, ['getuserdata(5, true)', 'getuserdata(6, true)', 'cache rebuilt for 2 users'], ['getuserdata(1']);
bench_case('user', 'cache-named', 0, ['getuserdata(5, true)', '1 user'], ['getuserdata(6']);
bench_case('user', 'cache-fresh', 0, ['Every user cache is up to date'], ['getuserdata']);
bench_case('user', 'cache-force', 0, ["SET need_update = 'true' WHERE user_id IN (1)", 'getuserdata(1, true)']);
bench_case('user', 'cache-unknown', 2, ['no such user: ghost'], ['getuserdata']);

echo "import\n";
bench_case('import', 'dry', 0,
  ['type not accepted', 'notes.pdf', '+ pwg cli bench import  3 photos: 3 new',
   'a_again.jpg already there earlier in this run', 'e.jpg already there #120',
   'albums: 4 to create', 'photos: 4 to import, 2 already there'],
  // the excluded folders must leave no trace: their photos, and an album of their own
  ['hidden.jpg', 'thumb.jpg', '+ private', '+ thumbnail', 'add_uploaded_file']);
bench_case('import', 'dry-flat', 0, ['6 photos: 4 new, 2 already there', 'albums: 1 to create'], ['plage', 'montagne']);
bench_case('import', 'dry-dirs-only', 0, ['photos: 0 to import', 'albums: 4 to create']);
bench_case('import', 'unwrap-needs-album', 1, ['3 photos at the top of the directory need an album']);
bench_case('import', 'not-a-directory', 1, ['is not a directory']);
bench_case('import', 'import-keep', 1,
  ['c.jpg: disk full', '3 photos: 2 imported, 1 failed', 'empty_lounge()', 'fill_caddie(501,502,504)',
   'photos: 3 imported, 2 already there, 1 failed']);
bench_case('import', 'refuse-without-yes', 1, ['Move 6 photos out of', 'aborted'], ['empty_lounge']);

echo "plugin\n";
bench_case('plugin', 'list', 0, ['| AdminTools |', 'not installed', '| active']);
bench_case('plugin', 'list-search', 0, ['AdminTools'], ['oAuth']);
bench_case('plugin', 'list-search-none', 0, ['no plugin matching "zzz"']);
bench_case('plugin', 'activate-failing', 1, ['missing php extension curl']);
bench_case('plugin', 'activate-already', 0, ['already active, nothing to do'], ['perform_action']);
bench_case('plugin', 'activate-unknown', 2, ['not found in plugins/: ghost'], ['perform_action']);
bench_case('plugin', 'deactivate-dry', 0, ['would deactivate "AdminTools"'], ['perform_action']);
bench_case('plugin', 'uninstall', 0, ["perform_action('uninstall', 'AdminTools')", 'uninstalled']);
bench_case('plugin', 'uninstall-refused', 1, ['Their tables and settings are dropped', 'aborted'], ['perform_action']);
bench_case('plugin', 'delete-itself', 0, ['is the plugin this command runs from, skipped'], ['perform_action']);
bench_case('plugin', 'nothing-named', 2, ['Which plugin?']);
bench_case('plugin', 'search-all', 0, ['| Plugin 1 ', 'page 1/3, 25 plugins, --page 2 for the next']);
bench_case('plugin', 'search-page-3', 0, ['page 3/3'], ['for the next']);
bench_case('plugin', 'search-beyond', 0, ['there is no page 9, 25 plugins fit in 3 pages']);
bench_case('plugin', 'search-term', 0, ['Admin Helper 8', 'Plugin 3'], ['Plugin 1 ']);
bench_case('plugin', 'search-nothing-asked', 2, ['Give a text to search for, or --all']);
bench_case('plugin', 'search-unknown-version', 0, ['nothing found for "editor"', 'try again with --beta']);
bench_case('plugin', 'search-offline', 1, ['Could not reach https://piwigo.org/ext']);
bench_case('plugin', 'install-dry', 0, ['would install "Plugin 3" 1.3'], ['extract_plugin_files']);
bench_case('plugin', 'install', 0, ["extract_plugin_files('install', rev=5003", 'installed as "NewPlugin"', "perform_action('activate', 'NewPlugin')"]);
bench_case('plugin', 'install-version', 0, ['get_revision_list.php, ext=3, versions=160,150', 'without checking it supports Piwigo', 'rev=3003', '15.a installed']);
bench_case('plugin', 'install-version-missing', 2, ['has no revision "9.9"', 'its latest one is 1.3'], ['extract_plugin_files']);
bench_case('plugin', 'install-version-two', 2, ['--version applies to a single plugin, you named 2'], ['fetchRemote']);
bench_case('plugin', 'install-ambiguous', 2, ['matches 3 plugins', 'Admin Helper 8'], ['extract_plugin_files']);
bench_case('plugin', 'install-unknown', 2, ['is not on https://piwigo.org/ext']);
bench_case('plugin', 'install-failing', 1, ['cannot download the archive']);
bench_case('plugin', 'update-list', 0, ['| oAuth  | 1.2     | 1.5', '1 plugin can be updated', '2 plugins in dev mode'], ['perform_action']);
bench_case('plugin', 'update-all', 0, ["perform_action('update', 'oAuth')", 'updated to 1.5'], ['AdminTools']);
bench_case('plugin', 'update-dry', 0, ['would update 1 plugin'], ['perform_action']);
bench_case('plugin', 'update-up-to-date', 0, ['"community" is already up to date (16.f)', 'Everything is up to date'], ['perform_action']);
bench_case('plugin', 'update-dev-mode', 0, ['1 plugin in dev mode (version "auto") left alone'], ['perform_action']);

echo "theme\n";
bench_case('theme', 'list', 0, ['| modus', 'active, default'], ['standard_pages', '| default ']);
bench_case('theme', 'list-search', 0, ['bootstrap_darkroom'], ['modus']);
bench_case('theme', 'activate-failing', 1, ['the parent theme is missing']);
bench_case('theme', 'activate-already', 0, ['already active, nothing to do'], ['perform_action']);
bench_case('theme', 'activate-unknown', 2, ['not found in themes/: ghost']);
bench_case('theme', 'deactivate-dry', 0, ['would deactivate "bootstrap_darkroom"'], ['perform_action']);
bench_case('theme', 'delete-active', 0, ['is active, deactivate it first, skipped'], ['perform_action']);
bench_case('theme', 'delete-builtin', 0, ['"default" belongs to the core', '"standard_pages" belongs to the core'], ['perform_action']);
bench_case('theme', 'delete', 0, ["perform_action('delete', 'elegant')", 'deleted']);
bench_case('theme', 'default-set', 0, ["perform_action('set_default', 'bootstrap_darkroom')", 'is now the default theme']);
bench_case('theme', 'default-same', 0, ['already the default theme'], ['perform_action']);
bench_case('theme', 'default-inactive', 2, ['is not active, activate it first']);
bench_case('theme', 'nothing-named', 2, ['Which theme?']);
bench_case('theme', 'standard-pages-show', 0, ['standard pages are off'], ['conf_update_param']);
bench_case('theme', 'standard-pages-on', 0, ["conf_update_param('use_standard_pages', true)", 'turned on']);
bench_case('theme', 'standard-pages-already', 0, ['already off'], ['conf_update_param']);
bench_case('theme', 'standard-pages-bad', 2, ['say "on" or "off", not "oui"']);
bench_case('theme', 'standard-pages-dry', 0, ['would turn the standard pages on'], ['conf_update_param']);
bench_case('theme', 'search-all', 0, ['| Grum', '| Clear', 'versions=160']);
bench_case('theme', 'search-term', 0, ['Grum'], ['Clear']);
bench_case('theme', 'search-nothing-asked', 2, ['Give a text to search for, or --all']);
bench_case('theme', 'search-offline', 1, ['Could not reach https://piwigo.org/ext']);
bench_case('theme', 'install-dry', 0, ['would install "Grum" 1.0'], ['extract_theme_files']);
bench_case('theme', 'install', 0, ["extract_theme_files('install', rev=8001", 'installed as "NewTheme"', "perform_action('activate', 'NewTheme')"]);
bench_case('theme', 'install-version', 0, ['versions=160,150', 'without checking it supports Piwigo', 'rev=7900', '0.9 installed']);
bench_case('theme', 'install-version-missing', 2, ['has no revision "9.9"'], ['extract_theme_files']);
bench_case('theme', 'install-version-two', 2, ['--version applies to a single theme, you named 2'], ['fetchRemote']);
bench_case('theme', 'update-list', 0, ['| elegant | 2.1     | 2.5', '1 theme can be updated'], ['extract_theme_files']);
bench_case('theme', 'update-all', 0, ["extract_theme_files('upgrade', rev=7001, dest=elegant)", 'updated to 2.5']);

echo "album\n";
bench_case('album', 'list', 0, ['| Vacances |', '| 45 | 2024', '| photos']);
bench_case('album', 'add', 0, ["create_virtual_category('Noel', NULL, [])", 'created (#101) at the top level']);
bench_case('album', 'add-under', 0, ['{"status":"private","comment":"la mer"}', 'under "Vacances" (#12)']);
bench_case('album', 'add-dry', 0, ['would create a private album "Noel" under "Vacances" (#12)'], ['create_virtual_category']);
bench_case('album', 'add-unknown-parent', 2, ['album #999 does not exist'], ['create_virtual_category']);
bench_case('album', 'edit', 0, ["set_cat_status(12, 'private')", 'single_update({"name":"Vacances 2024"})', 'updated: name, status']);
bench_case('album', 'edit-dry', 0, ['would become', 'Vacances 2024'], ['single_update']);
bench_case('album', 'edit-nothing', 0, ['nothing to change on "Vacances" (#12)'], ['single_update']);
bench_case('album', 'edit-bad-status', 2, ['--status takes "public" or "private"']);
bench_case('album', 'edit-bad-visible', 2, ['--visible takes "true" or "false"']);
bench_case('album', 'edit-unknown', 2, ['album #999 does not exist']);
bench_case('album', 'move', 0, ['move_categories(45, 7)', 'moved under "Divers" (#7)']);
bench_case('album', 'move-root', 0, ['move_categories(45, 0)', 'moved to the top level']);
bench_case('album', 'move-dry', 0, ['would move 2024 (#45) under "Divers" (#7)'], ['move_categories']);
bench_case('album', 'move-into-itself', 1, ['You cannot move an album in its own sub album']);
bench_case('album', 'move-nothing', 2, ['Which album?']);
bench_case('album', 'delete-dry', 0,
  ['and 1 sub-album', '2 photos inside, 1 of them also in another album, 1 only here',
   'keep 0, orphans 1, all 2'],
  ['delete_categories', 'What should happen']);
bench_case('album', 'delete-keep', 0, ["delete_categories(12, 'no_delete')", '2 albums deleted, 0 photos deleted']);
bench_case('album', 'delete-orphans', 0, ["delete_categories(12, 'delete_orphans')", '1 photo deleted']);
bench_case('album', 'delete-all', 0, ["delete_categories(12, 'force_delete')", '2 photos deleted']);
bench_case('album', 'delete-asks', 1, ['What should happen to the photos?', 'delete the 1 photo that would be left in no album', 'aborted'], ['delete_categories']);
bench_case('album', 'delete-bad-mode', 2, ['--photos takes keep, orphans, all']);
bench_case('album', 'delete-empty-album', 0, ['no photo inside', "delete_categories(45, 'no_delete')"], ['What should happen']);
bench_case('album', 'delete-nothing', 2, ['Which album?']);

echo "photo\n";
bench_case('photo', 'list', 0, ['| sunset.jpg |', '| size_kb |']);
bench_case('photo', 'list-album', 0, ['| 900 |']);
bench_case('photo', 'list-unknown-album', 2, ['album #999 does not exist']);
bench_case('photo', 'info', 0, ['| albums', 'Vacances', '["mer","été"]']);
bench_case('photo', 'info-unknown', 2, ['photo #999 does not exist']);
bench_case('photo', 'move', 0, ['move_images_to_categories(900,901, 7)', '2 photos now in album #7'], ['associate_images']);
bench_case('photo', 'move-add', 0, ['associate_images_to_categories(900, 7)'], ['move_images_to_categories']);
bench_case('photo', 'move-dry', 0, ['would move 1 photo into album #7'], ['move_images_to_categories']);
bench_case('photo', 'move-no-album', 2, ['Into which album?']);
bench_case('photo', 'move-unknown-photo', 2, ['Photo not found: 999'], ['move_images_to_categories']);
bench_case('photo', 'delete', 0, ['delete_elements(900, true)', '1 photo deleted']);
bench_case('photo', 'delete-keep-files', 0, ['delete_elements(900, false)']);
bench_case('photo', 'delete-dry', 0, ['would delete 2 photos and their files'], ['delete_elements']);
bench_case('photo', 'delete-refused', 1, ['aborted'], ['delete_elements']);
bench_case('photo', 'delete-nothing', 2, ['Which photo?']);
bench_case('photo', 'import', 0, ["add_uploaded_file('one.jpg', album=12)", 'empty_lounge()', '1 photo imported into album #12']);
bench_case('photo', 'import-one-fails', 1, ['two.jpg: disk full', '1 photo imported', '1 failed']);
bench_case('photo', 'import-dry', 0, ['would import 1 file into album #12', 'moving them out of their directory'], ['add_uploaded_file']);
bench_case('photo', 'import-bad-type', 2, ['is not a type this gallery accepts'], ['add_uploaded_file']);
bench_case('photo', 'import-missing-file', 2, ['is not a file']);
bench_case('photo', 'import-no-album', 2, ['Into which album?']);
bench_case('photo', 'sync-ids', 0, ['sync_metadata(2 photos)', 'metadata read again for 2 photos']);
bench_case('photo', 'sync-album', 0, ['sync_metadata(2 photos)']);
bench_case('photo', 'sync-all', 0, ['sync_metadata(2 photos)']);
bench_case('photo', 'sync-dry', 0, ['would read the metadata of 2 photos again'], ['sync_metadata']);
bench_case('photo', 'sync-nothing', 2, ['Which photos? Give ids, --album or --all']);
bench_case('photo', 'deriv-dry', 0, ['would generate 2 sizes', '| square | 1', '| small  | 1'], ['thumb']);
bench_case('photo', 'deriv-one-type', 0, ['would generate 1 size', 'square']);
bench_case('photo', 'deriv-nothing-missing', 0, ['Every size is there already']);
bench_case('photo', 'deriv-bad-type', 2, ['no such size: huge', 'square, thumb, small, wide']);
bench_case('photo', 'deriv-bad-jobs', 2, ['--jobs takes a number between 1 and 32']);
bench_case('photo', 'deriv-no-selection', 2, ['Which photos?']);
bench_case('photo', 'deriv-outcomes', 0, ['is there: generated', 'nothing written: failed', 'no answer at all: skipped', 'i.php error: failed']);

echo "\n".$passed.' passed, '.$failed.' failed'."\n";
exit($failed > 0 ? 1 : 0);
