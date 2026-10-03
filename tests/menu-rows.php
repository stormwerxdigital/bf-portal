<?php
/*
 * The menu says where you are.
 *
 * Editing a session lit nothing in the Brilliant Futures menu: WordPress looks
 * for a row named post.php, finds none, and leaves the menu open with no row
 * marked. Adding one lit the hidden "Record a Session" row, which is the same
 * as lighting nothing. Each kind of record now names the row it lives under,
 * on its list, its edit screen and its add screen.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
class BFTD_Roles { const STAFF_CAP = 'x'; public static function post_types(){ return array(); } }
require BFTD_PATH . 'includes/class-bftd-admin.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }
function on($type, $base, $page = null) {
  global $current_screen, $plugin_page;
  $current_screen = (object) array('post_type' => $type, 'base' => $base);
  if (null === $page) unset($GLOBALS['plugin_page']); else $plugin_page = $page;
  return BFTD_Admin::current_row('post-new.php?post_type=' . $type);
}

foreach (array('edit', 'post') as $base) {
  check('bftd-sessions' === on('bftd_session', $base), "a session's $base screen lights Sessions");
  check('bftd-students' === on('bftd_student', $base), "a student's $base screen lights Students");
  check('edit.php?post_type=bftd_skill' === on('bftd_skill', $base), "a skill's $base screen lights Skills");
  check('edit.php?post_type=bftd_activity' === on('bftd_activity', $base), "an activity's $base screen lights Activities");
  check('edit.php?post_type=bftd_assessment' === on('bftd_assessment', $base), "a diagnostic's $base screen lights Reading Diagnostics");
  check('edit.php?post_type=bftd_progress' === on('bftd_progress', $base), "a progress report's $base screen lights Progress Reports");
  check('edit.php?post_type=bftd_resource' === on('bftd_resource', $base), "a resource's $base screen lights Resources");
}
check('bftd-students' === on('', 'toplevel_page_bftd', 'bftd-student'), 'the student overview lights Students');
check('post-new.php?post_type=post' === on('post', 'post'), 'and anything that is not ours is left to WordPress');
check(BFTD_Admin::MENU_SLUG === (function(){ global $current_screen; $current_screen = (object) array('post_type' => 'bftd_skill', 'base' => 'post'); return BFTD_Admin::keep_menu_open('edit.php'); })(),
  'and the Brilliant Futures menu stays open on every one of them');

exit($fail ? 1 : 0);
