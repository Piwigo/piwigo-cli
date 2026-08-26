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

include_once(CLI_ROOT_PATH.'cli_init.php');

exit($cli->run());