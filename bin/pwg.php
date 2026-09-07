#!/usr/bin/env php
<?php
// use this file only in php-cli context
if (PHP_SAPI !== 'cli')
{
  @ob_end_clean();
  die('Hacking attempt!');
}

define('PHPWG_ROOT_PATH', dirname(__DIR__, 3).'/');
define('CLI_ROOT_PATH', dirname(__DIR__, 1).'/');

define('IN_CLI', true);

// the core assumes the current directory is the Piwigo root, like a web request does
chdir(PHPWG_ROOT_PATH);
// whatever the CLI creates stays writable by the web server user, Piwigo's 777 doctrine
umask(0);

include_once(CLI_ROOT_PATH.'cli_init.php');

exit($cli->run());