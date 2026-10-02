<?php
/*
 * The student record stops being a second place to type what was measured.
 *
 * It carried a reading level, a reading speed and a starting speed, by hand,
 * beside the reading diagnostics that measure exactly those three things.
 * Two answers to one question, and the typed one went stale the moment a
 * reassessment was written up: the portal greeted a family with a level the
 * child had passed months earlier, on the same page as a chart that knew
 * better.
 *
 * So they are read off the diagnostics. "Now" is the most recent by the date
 * of the assessment — the one the progress report calls the last reading
 * assessment — and "at the start" is the first.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();
$GLOBALS['SECT']  = array();

function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p ? $p->post_title : ''; }
function get_post_meta($id, $k, $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return null === $v ? ($s ? '' : array()) : ($s ? $v : array($v)); }
function is_admin() { return true; }
function get_current_user_id() { return 1; }
function get_posts($a) {
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (!empty($a['meta_query'])) {
      $q = $a['meta_query'][0];
      if ((string) ($GLOBALS['META'][$id][$q['key']] ?? '') !== (string) $q['value']) continue;
    }
    $out[] = $id;
  }
  return $out;
}

class BFTD_Roles { public static function post_types() { return array('bftd_session'); } public static function plural_for($p) { return $p . 's'; } }
class BFTD_Access { public static function visible_student_ids($u) { return array(); } public static function can_staff_view(...$a) { return true; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema {
  public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; }
  /* The real list, so a level code that reaches the portal has to be a real
     level rather than whatever the stub happened to name. */
  public static function reading_levels() {
    $l = array('preprimer' => 'Preprimer', 'primer' => 'Primer');
    for ($g = 1; $g <= 8; $g++) $l['grade-' . $g] = 'Grade ' . $g;
    return $l;
  }
}
class BFTD_Schedule {
  public static function pretty_time($t) { return $t; }
  public static function next_lesson_label($s) { return ''; }
  public static function bank($s) { return array('purchased' => 0, 'used' => 0, 'remaining' => 0); }
}
/*
 * Words correct per minute is worked out from the passage rather than stored,
 * so a bare get() on it returns an empty string on a real site. The stub
 * refuses it, because a stub that answered would model a database that does
 * not exist — and that is exactly how the last chart shipped drawing nothing.
 */
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) {
    if ('dxsec-4' === $g && 'score' === $k) {
      throw new Exception('dxsec-4 score is derived; read it through get_section');
    }
    return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? '';
  }
  public static function get_section($id, $g) { return $GLOBALS['SECT'][$id][$g] ?? array(); }
}
require BFTD_PATH . 'includes/class-bftd-cpt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$mk = function ($id, $type, $title, $meta = array()) {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title);
  $GLOBALS['META'][$id]  = $meta;
};

$mk(11, 'bftd_student', 'Kaine M', array('_bftd_student__diagnostic_on' => '2025-01-04'));

/* Two diagnostics, written up out of order: the reassessment happened later
   but was typed up first. It is still the newer assessment. */
$mk(80, 'bftd_assessment', 'Initial', array(
  '_bftd_student_id' => 11,
  '_bftd_sec-assessment-overview__assessed_on' => '2026-02-10',
  '_bftd_dxsec-3__score' => 'grade-1',
));
$GLOBALS['SECT'][80] = array('dxsec-4' => array('score' => 41));

$mk(81, 'bftd_assessment', 'Interim', array(
  '_bftd_student_id' => 11,
  '_bftd_sec-assessment-overview__assessed_on' => '2026-08-30',
  '_bftd_dxsec-3__score' => 'grade-4',
));
$GLOBALS['SECT'][81] = array('dxsec-4' => array('score' => 96));

$f = BFTD_CPT::from_diagnostics(11);
check('grade-4' === $f['level'], 'the reading level is the most recent diagnostic, got ' . $f['level']);
check(96 === $f['wpm'], 'and so is the speed now, got ' . $f['wpm']);
check(41 === $f['wpm_start'], 'while the starting speed is the first, got ' . $f['wpm_start']);
check('2026-08-30' === BFTD_CPT::diagnostic_on(11), 'and the date is the most recent, got ' . BFTD_CPT::diagnostic_on(11));

/* One diagnostic answers both questions, which is correct: that is where
   they started and that is where they are. */
$mk(90, 'bftd_student', 'Alice B');
$mk(91, 'bftd_assessment', 'Initial', array(
  '_bftd_student_id' => 90,
  '_bftd_sec-assessment-overview__assessed_on' => '2026-03-02',
  '_bftd_dxsec-3__score' => 'primer',
));
$GLOBALS['SECT'][91] = array('dxsec-4' => array('score' => 22));
$f = BFTD_CPT::from_diagnostics(90);
check(22 === $f['wpm'] && 22 === $f['wpm_start'], 'one diagnostic is both where they started and where they are');

/* A prospect booked in but not yet seen. The CRM seeded a date when it made
   the record, and that is the only answer there is. */
$mk(95, 'bftd_student', 'Not seen yet', array('_bftd_student__diagnostic_on' => '2026-10-01'));
check('2026-10-01' === BFTD_CPT::diagnostic_on(95), 'a student with no diagnostic yet falls back to what the CRM seeded');
$f = BFTD_CPT::from_diagnostics(95);
check('' === $f['level'] && 0 === $f['wpm'], 'and claims no level or speed, rather than zero of one');

/* ---- the client ---- */
/* A caregiver is anyone responsible for the child; they all see the same
   portal. The client is one person: the contact, and the one who pays. */
$GLOBALS['META'][11]['_bftd_client_primary'] = 20;
check(20 === BFTD_CPT::primary_client_id(11), 'a student names one client');
check(array(11) === BFTD_CPT::students_of_client(20), 'and that client can be asked for their children');
check(array() === BFTD_CPT::students_of_client(21), 'while somebody else has none');
check(0 === BFTD_CPT::primary_client_id(90), 'a student with nobody named has nobody, not a guess at a caregiver');

/* The two are separate keys, so designating a client cannot be mistaken for
   the caregiver list and vice versa. */
$GLOBALS['META'][90]['_bftd_client_user_id'] = 20;
check(0 === BFTD_CPT::primary_client_id(90), 'being a caregiver is not being the client');

/* ---- and the portal says the same thing ---- */
/*
 * A rule can be right in one function and reach no screen. The portal's
 * greeting and its reading-speed card were the two places the typed fields
 * were read, and they are the two places a family sees them, so they are
 * rendered here rather than reasoned about.
 */
function esc_url($s) { return (string) $s; }
function wp_get_current_user() { return (object) array('first_name' => 'Laurel', 'display_name' => 'Laurel Sanders'); }
function wp_get_attachment_image($id, $s = '', $i = false, $a = array()) { return ''; }
if (!function_exists('wpautop')) { function wpautop($s, $br = true) { return '<p>' . $s . '</p>'; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s, $b = false) { return trim(strip_tags((string) $s)); } }
if (!function_exists('get_permalink')) { function get_permalink($id = 0) { return ''; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('get_edit_post_link')) { function get_edit_post_link($id) { return ''; } }

class BFTD_Settings {
  public static function portal() { return array('recording_days' => 14, 'contact_line' => '', 'welcome' => ''); }
  public static function standing_text($k, $f = '') { return $f; }
}
class BFTD_Threads { public static function find_thread() { return null; } public static function messages() { return array(); } }
class BFTD_Items {}
class BFTD_Preview { public static function active() { return false; } public static function url($id) { return ''; } }
class BFTD_Charts {}
class BFTD_Skills { public static function for_session($s) { return array(); } }
class BFTD_Activities { public static function for_session($s) { return array(); } }
class BFTD_Derived { public static function attendance($r) { return array(); } public static function missed($r) { return array(); } }
class BFTD_MetaBoxes { public static function level_options() { return array('grade-4' => 'Grade 4'); } }
require BFTD_PATH . 'includes/class-bftd-dashboard.php';

$home = new ReflectionMethod('BFTD_Dashboard', 'screen_home');
$home->setAccessible(true);
ob_start(); $home->invoke(null, 11); $html = ob_get_clean();

check(false !== strpos($html, 'Kaine M is reading at Grade 4.'),
  'the portal greets a family with the level from the latest diagnostic');
check(false !== strpos($html, '96 <span>words correct per minute</span>'),
  'and the speed card shows the speed it was last measured at');
check(false !== strpos($html, 'Started at 41.'), 'with where they started under it');

/* One diagnostic is a figure but not a journey. "Started at 22" under "22"
   reads as no progress at all, which is the opposite of what it means. */
ob_start(); $home->invoke(null, 90); $one = ob_get_clean();
check(false !== strpos($one, '22 <span>'), 'a child assessed once still has a speed');
check(false === strpos($one, 'Started at'), 'and is not told they started where they are');

/* And a prospect has neither, rather than a zero. */
ob_start(); $home->invoke(null, 95); $none = ob_get_clean();
check(false === strpos($none, 'words correct per minute'), 'a child not yet assessed is shown no speed');
check(false === strpos($none, 'is reading at'), 'and no level');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
