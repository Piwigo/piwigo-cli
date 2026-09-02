<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');

include_once(CLI_ROOT_PATH.'cli_core.php');
include_once(CLI_ROOT_PATH.'cli_command.php');

include_once(PHPWG_ROOT_PATH.'include/config_default.inc.php');
include_once(CLI_ROOT_PATH.'cli_default_config.php');
@include(PHPWG_ROOT_PATH.'local/config/config.inc.php');

if (!$conf['allow_cli'])
{
  PwgCommand::error('CLI are disabled');
  exit(PwgCommand::ERROR);
}

$cli = new PwgCli();
$cli->boot();

// Everything below runs at file scope on purpose: common.inc.php and its friends
// create $conf, $user, $page, $template... as globals. Including them from
// inside a method would make them locals of that method, and every core
// function doing "global $conf" would then read null.

$boot_level = $cli->boot_level();

if ('none' === $boot_level)
{
  return;
}

$init = $cli->init_minimal();
if ($init === PwgCommand::ERROR)
{
  exit(PwgCommand::ERROR);
}

if ('minimal' === $boot_level)
{
  $init = $cli->init_db();
  if ($init === PwgCommand::ERROR)
  {
    exit(PwgCommand::ERROR);
  }
  return;
}

// at this point consider as boot_level as 'full'
$init = $cli->init_piwigo();
if ($init === PwgCommand::ERROR)
{
  exit(PwgCommand::ERROR);
}

$cli->prepare_cli_user();
$cli->prepare_cli_logger();
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');

// reload config default after full boot
// because common.inc.php erase conf with $conf = array()
include(CLI_ROOT_PATH.'cli_default_config.php');
