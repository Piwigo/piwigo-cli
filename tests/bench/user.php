<?php
// Bench for cli.user.php: a gallery with webmaster #1, guest #2, alice #5, bob #6
if (PHP_SAPI !== 'cli') die('Hacking attempt!');

include __DIR__.'/bench.inc.php';

define('USERS_TABLE', 'users');
define('USER_INFOS_TABLE', 'user_infos');
define('USER_CACHE_TABLE', 'user_cache');

$conf = [
  'user_fields' => ['id' => 'id', 'username' => 'username', 'email' => 'mail_address'],
  'guest_id' => 2,
  'default_user_id' => 2,
  'webmaster_id' => 1,
];
$user = ['id' => 1, 'status' => 'webmaster'];
$names = [1 => 'webmaster', 2 => 'guest', 5 => 'alice', 6 => 'bob'];

function get_username($id) { global $names; return $names[$id] ?? false; }
function get_userid($name) { global $names; $id = array_search($name, $names, true); return false === $id ? false : $id; }
function pwg_query($query)
{
  // only the writes are worth a line, the reads are answered by the fetchers below
  if (0 === strpos(ltrim($query), 'UPDATE')) { bench_core('query: '.trim(preg_replace('/\s+/', ' ', $query), '; ')); }
  return $query;
}
function pwg_db_fetch_row($query)
{
  global $names;
  return preg_match('/WHERE id = (\d+)/', $query, $m) && isset($names[$m[1]]) ? [$m[1]] : false;
}
function pwg_db_fetch_assoc($query)
{
  return ['username' => 'alice', 'email' => 'alice@old.tld', 'status' => 'normal',
          'level' => '0', 'language' => 'en_UK', 'theme' => 'modus'];
}
function query2array($query, $key = null, $value = null)
{
  // webmaster #1 and guest #2 are up to date, alice #5 and bob #6 are not
  if (false !== strpos($query, 'FROM user_cache')) { return [1, 2]; }
  if (false !== strpos($query, 'FROM users')) { return [1, 2, 5, 6]; }
  return [];
}
function getuserdata($id, $use_cache) { bench_core('getuserdata('.$id.', '.var_export($use_cache, true).')'); }
function generate_key($length) { return str_repeat('x', $length); }
function register_user($login, $password, $mail, $notify_admin, &$errors, $notify_user)
{
  global $names;
  bench_core("register_user('$login', ".(10 < strlen($password) ? 'generated' : "'$password'").", '$mail', notify_admin=".var_export($notify_admin, true).", notify_user=".var_export($notify_user, true).')');

  if ('bad login' === $login)
  {
    $errors[] = 'login mustn\'t start with a space character';
    return false;
  }

  $names[9] = $login;
  return 9;
}
function check_and_save_user_infos($params)
{
  bench_core('check_and_save_user_infos('.json_encode($params).')');

  return isset($params['status']) && 'nope' === $params['status']
    ? ['error' => ['code' => 1, 'message' => 'Invalid status']]
    : ['user_id' => $params['user_id']];
}
function delete_user($id) { bench_core("delete_user($id)"); }

include CLI_ROOT_PATH.'commands/cli.user.php';

$blank = ['username' => null, 'email' => null, 'password' => null, 'status' => null,
          'level' => null, 'language' => null, 'theme' => null];
$page = ['page' => 1, 'limit' => 20];

bench_run([
  'edit-dry' => function () use ($blank) {
    PwgCommand::set_dry_run();
    return cli_user_edit(['username_or_id' => 'alice', 'email' => 'a@new.tld', 'level' => '4'] + $blank);
  },
  'edit' => function () use ($blank) {
    return cli_user_edit(['username_or_id' => '5', 'email' => 'a@new.tld', 'password' => 'secret'] + $blank);
  },
  'edit-nothing' => function () use ($blank) {
    return cli_user_edit(['username_or_id' => 'alice'] + $blank);
  },
  'edit-unknown' => function () use ($blank) {
    return cli_user_edit(['username_or_id' => 'ghost', 'email' => 'x@y.z'] + $blank);
  },
  'edit-refused' => function () use ($blank) {
    return cli_user_edit(['username_or_id' => 'alice', 'status' => 'nope'] + $blank);
  },
  'add-dry' => function () use ($blank) {
    PwgCommand::set_dry_run();
    return cli_user_add(['username' => 'carol', 'email' => 'c@test.tld', 'status' => 'admin'] + $blank);
  },
  'add' => function () use ($blank) {
    return cli_user_add(['username' => 'carol', 'email' => 'c@test.tld'] + $blank);
  },
  'add-full' => function () use ($blank) {
    return cli_user_add(['username' => 'carol', 'email' => 'c@test.tld', 'password' => 'chosen', 'status' => 'admin', 'level' => '4'] + $blank);
  },
  'add-duplicate' => function () use ($blank) {
    return cli_user_add(['username' => 'alice'] + $blank);
  },
  'add-refused' => function () use ($blank) {
    return cli_user_add(['username' => 'bad login'] + $blank);
  },
  'add-halfway' => function () use ($blank) {
    return cli_user_add(['username' => 'carol', 'status' => 'nope'] + $blank);
  },
  'delete-dry' => function () {
    PwgCommand::set_dry_run();
    return cli_user_delete(['username_or_id' => ['alice', 'bob']]);
  },
  'delete' => function () {
    PwgCommand::assume_yes();
    return cli_user_delete(['username_or_id' => ['alice', 'guest', '1']]);
  },
  'delete-unknown' => function () {
    return cli_user_delete(['username_or_id' => ['alice', 'ghost']]);
  },
  'delete-refused' => function () {
    return cli_user_delete(['username_or_id' => ['alice']]);
  },
  'delete-nothing' => function () {
    return cli_user_delete(['username_or_id' => []]);
  },
  'cache-dry' => function () {
    PwgCommand::set_dry_run();
    return cli_user_rebuild_cache(['username_or_id' => [], 'force' => false]);
  },
  'cache' => function () {
    return cli_user_rebuild_cache(['username_or_id' => [], 'force' => false]);
  },
  'cache-named' => function () {
    return cli_user_rebuild_cache(['username_or_id' => ['alice'], 'force' => false]);
  },
  'cache-fresh' => function () {
    return cli_user_rebuild_cache(['username_or_id' => ['webmaster'], 'force' => false]);
  },
  'cache-force' => function () {
    return cli_user_rebuild_cache(['username_or_id' => ['webmaster'], 'force' => true]);
  },
  'cache-unknown' => function () {
    return cli_user_rebuild_cache(['username_or_id' => ['ghost'], 'force' => false]);
  },
]);
