<?php
/*
 * The student hub opens for staff, administrators included.
 *
 * WordPress decides access to admin.php?page=bftd-student by looking the page
 * up in $submenu to find its parent, then building a hookname from that parent
 * and checking it against the registered pages. If the row has already been
 * removed from $submenu when that check runs, no parent is found, the hookname
 * comes out as admin_page_bftd-student, nothing is registered under that name,
 * and the page dies with "Sorry, you are not allowed to access this page" for
 * every user. That shipped, and it blocked the "Open student" link on live.
 *
 * So the order is the test: after admin_menu the row must still be in
 * $submenu, and it must be gone before anything draws the menu. Core fires
 * admin_enqueue_scripts after the access check and before the command palette
 * and the sidebar read the menu.
 */
define('ABSPATH', '/');
define('BFTD_PATH', dirname(__DIR__) . '/');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* Just enough of the menu and hook API, behaving the way core does. */
$GLOBALS['hooks']   = array();
$GLOBALS['menu']    = array();
$GLOBALS['submenu'] = array();
function add_action($tag, $cb, $prio = 10) { $GLOBALS['hooks'][$tag][$prio][] = $cb; }
function add_filter($tag, $cb, $prio = 10) { add_action($tag, $cb, $prio); }
function do_action($tag) {
  if (empty($GLOBALS['hooks'][$tag])) return;
  ksort($GLOBALS['hooks'][$tag]);
  foreach ($GLOBALS['hooks'][$tag] as $cbs) foreach ($cbs as $cb) call_user_func($cb);
}
function add_menu_page($pt, $mt, $cap, $slug) { $GLOBALS['menu'][] = array($mt, $cap, $slug); return 'toplevel_page_' . $slug; }
function add_submenu_page($parent, $pt, $mt, $cap, $slug) { $GLOBALS['submenu'][$parent][] = array($mt, $cap, $slug); return $parent . '_page_' . $slug; }
function remove_submenu_page($parent, $slug) {
  if (empty($GLOBALS['submenu'][$parent])) return false;
  foreach ($GLOBALS['submenu'][$parent] as $i => $row) {
    if ($row[2] === $slug) { unset($GLOBALS['submenu'][$parent][$i]); return $row; }
  }
  return false;
}
/* What get_admin_page_parent() does for admin.php?page=...: search $submenu. */
function parent_of($slug) {
  foreach ($GLOBALS['submenu'] as $parent => $rows) foreach ($rows as $row) if ($row[2] === $slug) return $parent;
  return '';
}

class BFTD_Roles { const STAFF_CAP = 'bftd_manage_students'; public static function post_types() { return array(); } }
require BFTD_PATH . 'includes/class-bftd-admin.php';

BFTD_Admin::init();
do_action('admin_menu');

/* The access check runs here, between admin_menu and admin-header.php. */
$parent = parent_of(BFTD_Admin::HUB_SLUG);
check(BFTD_Admin::MENU_SLUG === $parent,
  'the hub still has its parent when WordPress checks access (got "' . $parent . '")');

do_action('admin_enqueue_scripts');
check('' === parent_of(BFTD_Admin::HUB_SLUG),
  'the hub row is gone before the menu or the command palette is drawn');

$other = parent_of(BFTD_Admin::INBOX_SLUG);
check(BFTD_Admin::MENU_SLUG === $other, 'hiding the hub leaves the other rows alone');

$prio = null;
foreach ($GLOBALS['hooks']['admin_enqueue_scripts'] as $p => $cbs) {
  foreach ($cbs as $cb) if (is_array($cb) && 'hide_hub_row' === $cb[1]) $prio = $p;
}
check(0 === $prio, 'the row is hidden at priority 0, ahead of anything that reads the menu');

exit($fail ? 1 : 0);
