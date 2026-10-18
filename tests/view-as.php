<?php
/*
 * View as client (Karl, 1.150).
 *
 * Administrators and senior managers get "View as client" on a client's row
 * on the Users screen. It opens the portal with the current user switched to
 * that client for that one page, so what is drawn is exactly what they see,
 * and nothing can be done as them. These are the rules around the switch;
 * tests/real-wp/view-as.mjs shows the page itself.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$GLOBALS['ROLES'] = array(1 => 'administrator', 2 => 'bftd_senior_manager', 3 => 'bftd_tutor_manager', 4 => 'bftd_tutor', 5 => 'bftd_client');
$GLOBALS['CUR'] = 1; $GLOBALS['QOBJ'] = 99; $GLOBALS['FILTERS'] = array();
function get_current_user_id() { return $GLOBALS['CUR']; }
function wp_set_current_user($id) { $GLOBALS['CUR'] = $id; $GLOBALS['SWITCHED'] = $id; }
function user_can($id, $cap) { return 'bftd_administer' === $cap && in_array($GLOBALS['ROLES'][$id] ?? '', array('administrator', 'bftd_senior_manager'), true); }
function current_user_can($cap) { return user_can(get_current_user_id(), $cap); }
function get_userdata($id) { if (!isset($GLOBALS['ROLES'][$id])) return false; $u = new stdClass; $u->ID = $id; $u->roles = array($GLOBALS['ROLES'][$id]); $u->display_name = 'User ' . $id; return $u; }
function wp_create_nonce($a) { return 'n-' . $a . '-' . get_current_user_id(); }
function wp_verify_nonce($n, $a) { return $n === 'n-' . $a . '-' . get_current_user_id(); }
function get_queried_object_id() { return $GLOBALS['QOBJ']; }
function add_query_arg($a, $u) { return $u . '?' . http_build_query($a); }
function wp_unslash($v) { return $v; }
class BFTD_Roles {
  const ADMIN_CAP = 'bftd_administer';
  public static function is_client($u) { return in_array('bftd_client', (array) $u->roles, true); }
}
class BFTD_Dashboard { public static function page_id() { return 99; } public static function url() { return 'https://example.test/portal/'; } }
require BFTD_PATH . 'includes/class-bftd-view-as.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$reset = function () { $r = new ReflectionClass('BFTD_View_As'); foreach (array('client' => 0, 'viewer' => 0, 'link_nonce' => '') as $k => $v) { $p = $r->getProperty($k); $p->setAccessible(true); $p->setValue(null, $v); } unset($GLOBALS['SWITCHED']); };

/* ---- the link ---- */
$GLOBALS['CUR'] = 1;
$a = BFTD_View_As::row_action(array('edit' => 'Edit'), get_userdata(5));
check(isset($a['bftd_view_as']) && false !== strpos($a['bftd_view_as'], 'View as client'), 'an administrator gets "View as client" on a client');
check(!isset(BFTD_View_As::row_action(array(), get_userdata(4))['bftd_view_as']), 'but not on a tutor');
$GLOBALS['CUR'] = 2;
check(isset(BFTD_View_As::row_action(array(), get_userdata(5))['bftd_view_as']), 'a senior manager gets it too');
foreach (array(3 => 'a tutor manager', 4 => 'a tutor') as $id => $who) {
  $GLOBALS['CUR'] = $id;
  check(!isset(BFTD_View_As::row_action(array(), get_userdata(5))['bftd_view_as']), "$who does not");
}

/* ---- the switch ---- */
$open = function ($viewer, $client, $nonce_for = null) use ($reset) {
  $reset(); $GLOBALS['CUR'] = $viewer;
  $_GET = array('bftd_view_as' => $client, 'bftd_view_as_nonce' => 'n-bftd_view_as_' . (null === $nonce_for ? $client : $nonce_for) . '-' . $viewer);
  BFTD_View_As::maybe_switch();
  return $GLOBALS['SWITCHED'] ?? 0;
};
check(5 === $open(1, 5), 'an administrator with the link sees the portal as the client');
check(BFTD_View_As::active() && 5 === BFTD_View_As::client_id(), 'and the page knows it is a client view');
check(5 === get_current_user_id(), 'for this page the current user is the client, so everything drawn is theirs');
check(isset(BFTD_View_As::link_args()['bftd_view_as']) && 'n-bftd_view_as_5-1' === BFTD_View_As::link_args()['bftd_view_as_nonce'],
  'and links carry the view, signed for the staff member who opened it');
check(5 === $open(2, 5), 'a senior manager too');
check(0 === $open(3, 5), 'a tutor manager cannot, even with a link');
check(0 === $open(4, 5), 'nor a tutor');
check(0 === $open(1, 4), 'a staff account can never be viewed as');
check(0 === $open(1, 5, 6), 'a link made for somebody else does not work');
$reset(); $GLOBALS['CUR'] = 1; $_GET = array('bftd_view_as' => 5, 'bftd_view_as_nonce' => 'n-bftd_view_as_5-2');
BFTD_View_As::maybe_switch();
check(!isset($GLOBALS['SWITCHED']), 'nor one made by another staff member');
$GLOBALS['QOBJ'] = 7; check(0 === $open(1, 5), 'and it only works on the portal page'); $GLOBALS['QOBJ'] = 99;

/* ---- read only ---- */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-view-as.php');
check(false !== strpos($src, "add_filter( 'show_admin_bar', '__return_false'"), 'the staff toolbar is not drawn over the client view');
check(1 === preg_match('/BFTD\.read_only=1.*admin-ajax\.php.*read-only view/s', $src), 'and the page script stops anything it would send, and says why');
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php'), 'BFTD_View_As::link_args()'), 'portal links keep the view as you move about');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
