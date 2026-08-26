<?php
/*
Plugin Name: Piwigo CLI
Version: auto
Description: The official Piwigo command line
Author: Piwigo team
Author URI: https://github.com/Piwigo
Has Settings: false
*/

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// check root directory
if (basename(dirname(__FILE__)) != 'piwigo-cli')
{
  add_event_handler('init', 'cli_error');
  function cli_error()
  {
    global $page;
    $page['errors'][] = 'Piwigo CLI plugin folder name is incorrect, uninstall the plugin and rename it to "piwigo-cli"';
  }
  return;
}

// +-----------------------------------------------------------------------+
// | Define plugin constants                                               |
// +-----------------------------------------------------------------------+

define('CLI_ID', basename(dirname(__FILE__)));
define('CLI_PATH', PHPWG_PLUGINS_PATH . CLI_ID . '/');
define('CLI_REALPATH', realpath(CLI_PATH));
