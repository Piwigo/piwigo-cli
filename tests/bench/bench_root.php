<?php
// Included before bench.inc.php by the benches that shadow a core file, since the root
// must exist before PHPWG_ROOT_PATH is defined.
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

/**
* Build a throwaway Piwigo root under the temp dir, for a bench whose command includes a
* core file it wants to shadow. $stubs are emptied, $borrowed are loaded from the real
* core. Returns the path, to define PHPWG_ROOT_PATH with before including this file.
*/
function bench_fake_root(string $name, array $stubs, array $borrowed): string
{
  $real = dirname(__DIR__, 4).'/';
  $root = sys_get_temp_dir().'/'.$name.'/';

  foreach (array_merge($stubs, $borrowed) as $relative)
  {
    @mkdir($root.dirname($relative), 0777, true);
  }

  foreach ($stubs as $relative)
  {
    file_put_contents($root.$relative, '<?php // emptied by the bench, which declares these itself'."\n");
  }

  foreach ($borrowed as $relative)
  {
    file_put_contents($root.$relative, '<?php include '.var_export($real.$relative, true).';'."\n");
  }

  return $root;
}

/**
* Remove a directory the bench made, whatever it holds. Registered as a shutdown handler,
* so a bench leaves the temp dir as it found it even when a scenario throws.
*/
function bench_rmtree(string $path)
{
  if (!is_dir($path))
  {
    return;
  }

  $entries = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($entries as $entry)
  {
    $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
  }

  @rmdir($path);
}
