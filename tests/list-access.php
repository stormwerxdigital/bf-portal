<?php
/*
 * The WordPress lists of students and sessions open for staff.
 *
 * Both have screens of our own in their menu rows, and their own rows come
 * out of the menu. WordPress decides who may open edit.php?post_type=... by
 * finding that row in $submenu. Removed during admin_menu, the row is not
 * there when it looks, so it judges the page against Posts, which staff do
 * not have, and the list refused everyone but an administrator. That is the
 * Sessions advanced view, and the "sessions in draft" link to it.
 *
 * So, as with the student hub: still in $submenu after admin_menu, gone
 * before anything draws the menu.
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

class BFTD_Roles { const STAFF_CAP = 'bftd_manage_students'; }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_CPT { const SESSION = 'bftd_session'; const STUDENT = 'bftd_student'; }
require BFTD_PATH . 'includes/class-bftd-students.php';
require BFTD_PATH . 'includes/class-bftd-sessions.php';

/* The post types' own rows, as register_post_type leaves them. */
$GLOBALS['submenu']['bftd'] = array(
  array('Students', 'edit_posts', 'edit.php?post_type=bftd_student'),
  array('Sessions', 'edit_posts', 'edit.php?post_type=bftd_session'),
  array('Skills', 'edit_posts', 'edit.php?post_type=bftd_skill'),
);
BFTD_Students::init();
BFTD_Sessions::init();
do_action('admin_menu');

foreach (array('bftd_student' => 'students', 'bftd_session' => 'sessions') as $type => $word) {
  $slug = 'edit.php?post_type=' . $type;
  check('bftd' === parent_of($slug), "the list of $word still has its parent when WordPress checks access");
}
check('bftd' === parent_of('bftd-students') && 'bftd' === parent_of('bftd-sessions'), 'beside the screens that replace them');

do_action('admin_enqueue_scripts');
foreach (array('bftd_student' => 'students', 'bftd_session' => 'sessions') as $type => $word) {
  check('' === parent_of('edit.php?post_type=' . $type), "the list of $word is out of the menu before it is drawn");
}
check('bftd' === parent_of('bftd-students') && 'bftd' === parent_of('bftd-sessions') && 'bftd' === parent_of('edit.php?post_type=bftd_skill'),
  'and nothing else goes with them');

foreach (array('BFTD_Students', 'BFTD_Sessions') as $cls) {
  $prio = null;
  foreach ($GLOBALS['hooks']['admin_enqueue_scripts'] as $p => $cbs) foreach ($cbs as $cb) if (is_array($cb) && $cls === $cb[0] && 'hide_list_row' === $cb[1]) $prio = $p;
  check(0 === $prio, "$cls hides its row at priority 0, ahead of anything that reads the menu");
}
exit($fail ? 1 : 0);
