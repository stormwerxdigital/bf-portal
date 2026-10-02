<?php
/*
 * The line between running the teaching and running the practice.
 *
 * A tutor manager runs the teaching: students, diagnostics, progress reports,
 * lessons, and who is assigned to whom. Four things sit under that, and they
 * are the practice itself rather than any one student's work:
 *
 *   Settings        how the whole thing behaves
 *   Emails          the words every family receives
 *   Activity Log    who opened whose child's record, across every family
 *   The libraries   resources and activities, written once and reused, so
 *                   editing one edits what every other tutor's students see
 *
 * One capability covers all four, because they are four faces of the same
 * question and four separate checks is four places to get it wrong. Senior
 * managers and administrators hold it; nobody else does, including a tutor
 * manager.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['CAPS'] = array();
function user_can($u, $cap) { $u = is_object($u) ? $u->ID : (int) $u; return !empty($GLOBALS['CAPS'][$u][$cap]); }
function get_current_user_id() { return $GLOBALS['ME'] ?? 0; }
function get_userdata($id) { return null; }
function get_role($s) { return null; }
function add_role(...$a) {}
function get_users($a = array()) { return array(); }
function get_user_meta($u, $k, $s = false) { return $s ? '' : array(); }
function update_user_meta(...$a) { return true; }
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; }
function get_post($id = null) { return null; }
function wp_get_current_user() { return (object) array('ID' => 0, 'roles' => array()); }

require BFTD_PATH . 'includes/class-bftd-roles.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$TUTOR = 1; $MANAGER = 2; $SENIOR = 3; $ADMIN = 4;
$GLOBALS['CAPS'] = array(
  $TUTOR   => array(BFTD_Roles::STAFF_CAP => true),
  $MANAGER => array(BFTD_Roles::STAFF_CAP => true, BFTD_Roles::MANAGE_CAP => true),
  $SENIOR  => array(BFTD_Roles::STAFF_CAP => true, BFTD_Roles::MANAGE_CAP => true,
                    BFTD_Roles::TEAM_CAP => true, BFTD_Roles::ERASE_CAP => true, BFTD_Roles::ADMIN_CAP => true),
  $ADMIN   => array('manage_options' => true),
);

/* ---- who runs the practice ---- */
check(!BFTD_Roles::can_administer($TUTOR),   'a tutor does not');
check(!BFTD_Roles::can_administer($MANAGER), 'nor does a tutor manager, who runs the teaching');
check(BFTD_Roles::can_administer($SENIOR),   'a senior manager does');
check(BFTD_Roles::can_administer($ADMIN),    'and an administrator, through manage_options');

check(BFTD_Roles::ADMIN_CAP !== BFTD_Roles::MANAGE_CAP,
  'it is not the same capability as running the teaching, or the two could never be separated');

/* ---- the four screens all ask the one question ---- */
function code_of($file) {
  $out = '';
  foreach (token_get_all(file_get_contents($file)) as $t) {
    if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $out .= $t[1]; }
    else { $out .= $t; }
  }
  return $out;
}
$screens = array(
  'class-bftd-settings.php' => 'Settings',
  'class-bftd-emails.php'   => 'Emails',
  'class-bftd-audit.php'    => 'Activity Log',
);
foreach ($screens as $file => $label) {
  $src = code_of(BFTD_PATH . 'includes/' . $file);
  if (!preg_match('/function menu\(\).*?\}/s', $src, $m)) { check(false, "$label has a menu"); continue; }
  check(false !== strpos($m[0], 'BFTD_Roles::ADMIN_CAP'), "$label is behind the practice capability");
  check(false === strpos($m[0], 'STAFF_CAP') && false === strpos($m[0], 'MANAGE_CAP'),
    "and not behind one a tutor or a tutor manager holds: $label");
}

/* The per-record History panel is the same log, one record at a time. Drawing
 * it for a tutor while hiding the page it comes from is the same information
 * behind a thinner door. */
$mb = code_of(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(1 === preg_match("/can_administer\(\s*\).*?add_meta_box\(\s*'bftd_log'/s", $mb),
  'and the History panel on an edit screen answers to it too');

/* ---- the libraries are not granted to every tier ---- */
$lib = BFTD_Roles::caps_for('bftd_resource', 'bftd_resources', true);
$roles = code_of(BFTD_PATH . 'includes/class-bftd-roles.php');
check(1 === preg_match("/function library_types\(\).*?'bftd_resource'/s", $roles),
  'a resource is a library, not a student record');
/* Asked of the code rather than read off it. The grant that every tier gets
 * must not contain a single resource capability, and the separate one must
 * contain all of them. A regex over the source matched the negated copy of
 * the same line in the other method and passed either way. */
$every = new ReflectionMethod('BFTD_Roles', 'all_caps');
$every->setAccessible(true);
$tiered = $every->invoke(null, true);
$leaked = array();
foreach ($tiered as $cap) { if (false !== strpos($cap, 'bftd_resource')) $leaked[] = $cap; }
check(array() === $leaked, 'the every-tier grant carries no resource capability: ' . implode(', ', $leaked));
check(in_array('edit_bftd_sessions', $tiered, true), 'but does still carry the records a tutor works in');

/* Counted, so that adding a library type on one side and forgetting the other
 * shows up here rather than as a tutor editing the curriculum. */
$r = new ReflectionMethod('BFTD_Roles', 'library_caps');
$r->setAccessible(true);
$caps = $r->invoke(null);
foreach ($lib as $cap) {
  check(in_array($cap, $caps, true), "the senior grant carries $cap");
}
check(in_array('edit_bftd_activities', $caps, true), 'and the activity library with it');

/* ---- a new capability only reaches a live site if the version says so ---- */
check(BFTD_Roles::CAPS_VERSION >= 8, 'the capability version is bumped, so live roles heal on the next admin load');

exit($fail ? 1 : 0);
