<?php
/*
 * Publishing a draft from the Sessions screen.
 *
 * It runs the same checks as the Publish button on the session's own screen,
 * in the same order: Taught by is filled in first, then the holds are asked
 * about the session as it would be once published. A session that is going
 * to be held is never published, not even for a moment, and opens on its own
 * screen where the reason shows. One that passes is published and comes back
 * to the list.
 *
 * Only someone allowed to publish gets this far, and only with a signed link.
 */
define('ABSPATH', '/');
define('BFTD_PATH', dirname(__DIR__) . '/');
class Stopped extends Exception {}

$GLOBALS['LOG'] = array(); $GLOBALS['STATUS'] = array(); $GLOBALS['HOLD'] = '';
$GLOBALS['CAN'] = true; $GLOBALS['NONCE_OK'] = true;
function add_action(...$a) {} function add_filter(...$a) {}
function get_post_type($id){ return 'bftd_session'; }
function get_post_status($id){ return $GLOBALS['STATUS'][$id] ?? 'draft'; }
function get_post($id){ return (object) array('ID' => $id, 'post_status' => get_post_status($id), 'post_type' => 'bftd_session'); }
function get_post_type_object($t){ return (object) array('cap' => (object) array('publish_posts' => 'publish_bftd_sessions')); }
function current_user_can($c, $id = 0){ return $GLOBALS['CAN']; }
function check_admin_referer($a){ $GLOBALS['LOG'][] = 'nonce:' . $a; if (!$GLOBALS['NONCE_OK']) throw new Stopped('nonce'); return 1; }
function wp_die($m = '', $code = 0){ $GLOBALS['LOG'][] = 'die'; throw new Stopped('die'); }
function wp_get_referer(){ return 'https://example.test/wp-admin/admin.php?page=bftd-sessions&paged=2'; }
function get_edit_post_link($id, $c = ''){ return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
function remove_query_arg($k, $u){ return preg_replace('#[?&]' . $k . '=[^&]*#', '', $u); }
function add_query_arg($k, $v, $u){ return $u . (strpos($u, '?') === false ? '?' : '&') . $k . '=' . $v; }
function wp_safe_redirect($u){ $GLOBALS['LOG'][] = 'go:' . $u; throw new Stopped('redirect'); }
function wp_update_post($a){ $GLOBALS['LOG'][] = 'update:' . $a['post_status']; $GLOBALS['STATUS'][$a['ID']] = $a['post_status']; return $a['ID']; }
require __DIR__ . '/wp-stubs.php';

class BFTD_CPT { const SESSION = 'bftd_session'; }
class BFTD_MetaBoxes {
  public static function claim_session($sid){ $GLOBALS['LOG'][] = 'claim'; }
  private static function hold($name, $sid, $post){
    $GLOBALS['LOG'][] = $name . ':' . $post->post_status . '/' . get_post_status($sid);
    return $GLOBALS['HOLD'] === $name;
  }
  public static function hold_duplicate_draft($sid, $post){ return self::hold('duplicate', $sid, $post); }
  public static function hold_unexplained($sid, $post){ return self::hold('unexplained', $sid, $post); }
  public static function hold_unclaimed($sid, $post){ return self::hold('unclaimed', $sid, $post); }
}
require BFTD_PATH . 'includes/class-bftd-sessions.php';

$fail = 0;
function check($ok, $msg){ global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function run($sid){
  $GLOBALS['LOG'] = array(); $_GET = array('session' => (string) $sid);
  try { BFTD_Sessions::publish_from_list(); } catch (Stopped $e) {}
  return $GLOBALS['LOG'];
}

/* ---- a session that passes ---- */
$log = run(7);
check('nonce:bftd_publish_session_7' === $log[0], 'the link is signed for that one session');
check(array('claim', 'duplicate:publish/draft', 'unexplained:publish/draft', 'unclaimed:publish/draft', 'update:publish') === array_slice($log, 1, 5),
  'Taught by is filled in, then each hold is asked about it as published while it is still a draft, then it is published: ' . implode(', ', $log));
check(false !== strpos(end($log), 'page=bftd-sessions&paged=2&bftd_published=7'), 'and it comes back to the list it was on, saying so');

/* ---- each hold keeps it a draft ---- */
foreach (array('duplicate' => 'a duplicate of another draft', 'unexplained' => 'a cancellation with no reason', 'unclaimed' => 'a session with nobody to pay') as $h => $why) {
  $GLOBALS['STATUS'] = array(); $GLOBALS['HOLD'] = $h;
  $log = run(8);
  check(!in_array('update:publish', $log, true) && 'draft' === get_post_status(8), "held as $why, it is never published");
  check('go:https://example.test/wp-admin/post.php?post=8&action=edit' === end($log), 'and opens on its own screen, where the reason shows');
}
$GLOBALS['HOLD'] = '';

/* ---- nothing to do ---- */
$GLOBALS['STATUS'] = array(9 => 'publish');
$log = run(9);
check(!in_array('claim', $log, true) && !in_array('update:publish', $log, true), 'a session already published is left alone');

/* ---- not allowed ---- */
$GLOBALS['STATUS'] = array(); $GLOBALS['CAN'] = false;
$log = run(10);
check(in_array('die', $log, true) && !in_array('claim', $log, true), 'someone who may not publish is turned away before anything is written');
$GLOBALS['CAN'] = true; $GLOBALS['NONCE_OK'] = false;
$log = run(11);
check(!in_array('claim', $log, true) && !in_array('update:publish', $log, true), 'and so is a link that is not signed');

exit($fail ? 1 : 0);
