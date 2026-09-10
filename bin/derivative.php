#!/usr/bin/env php
<?php
// Generates one derivative through the core's i.php, which is the only code in
// Piwigo that writes into the derivative cache. Started as a subprocess by
// "pwg photo generate-derivatives", one process per file, never called by hand.
if (PHP_SAPI !== 'cli')
{
  @ob_end_clean();
  die('Hacking attempt!');
}

// e.g. "/upload/2026/09/10/20260910123456-1a2b3c4d-th.jpg"
$request = $argv[1] ?? '';

if ('' === $request)
{
  fwrite(STDERR, 'usage: derivative.php <derivative path, relative to the cache directory>'."\n");
  exit(2);
}

// i.php defines PHPWG_ROOT_PATH as "./" and includes everything from there
chdir(dirname(__DIR__, 3));

// i.php reads a web request: give it the keys it looks at, and nothing more
$_GET = ['ajaxload' => 'true'];
$_SERVER['QUERY_STRING'] = $request;
$_SERVER['REQUEST_URI'] = '/i.php?'.$request;
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';

// ajaxload makes i.php answer a short json instead of sending the image bytes
include './i.php';
