<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

// +-----------------------------------------------------------------------+
// | Command Line Interface                                                |
// +-----------------------------------------------------------------------+

// Allow the command line tool to run. This is an operational switch, not a
// security measure: running it already requires shell access to the server.
// Set to false on hosts where the CLI must not be used.
$conf['allow_cli'] ??= true;

// Allow cli output colors
$conf['cli_allow_color'] ??= true;

// Allow test commands (usefull in dev)
$conf['cli_allow_test_commands'] ??= false;