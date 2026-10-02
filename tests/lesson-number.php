<?php
/*
 * The lesson number, counted rather than typed.
 *
 * It used to be a box. Two tutors sharing a student both reached for the same
 * number, one coming back after a cancellation guessed, and deleting a lesson
 * left a gap nobody went back to close. A number written down has to be kept
 * in step by hand, and it never is.
 *
 * So it is the lesson's place in the student's own sequence and it is not
 * stored anywhere. A new lesson takes the next number by existing. A deleted
 * one closes the gap by the same arithmetic. There is nothing to renumber,
 * which is the point: the operation Karl asked for, "decrement on delete", is
 * not an operation at all once the number is derived.
 *
 * Only published lessons are numbered. The family is served published lessons
 * and nothing else, so numbering drafts as well would leave their report
 * reading "Lesson 1, Lesson 3" with no lesson 2 anywhere on it.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();

function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_status($id) { return $GLOBALS['POSTS'][(int) $id]->post_status ?? false; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ($s ? '' : array()); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_the_title($id) { return $GLOBALS['POSTS'][(int) $id]->post_title ?? ''; }
function get_post_type($id) { return $GLOBALS['POSTS'][(int) $id]->post_type ?? ''; }
function get_posts($args) {
  $want = (array) ($args['post_status'] ?? array('publish'));
  $key  = $args['meta_query'][0]['key'] ?? '';
  $val  = (int) ($args['meta_query'][0]['value'] ?? 0);
  $out  = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if (!in_array($p->post_type, (array) $args['post_type'], true)) continue;
    if (!in_array($p->post_status, $want, true)) continue;
    if ($key && (int) ($GLOBALS['META'][$id][$key] ?? 0) !== $val) continue;
    $out[] = $id;
  }
  if (!empty($args['posts_per_page']) && 1 === (int) $args['posts_per_page']) $out = array_slice($out, 0, 1);
  return $out;
}

class BFTD_Roles { public static function post_types() { return array('bftd_student','bftd_assessment','bftd_progress','bftd_session','bftd_resource'); }
  public static function plural_for($pt) { return $pt . 's'; } }
class BFTD_Access { public static function can_staff_view($id, $u = 0) { return true; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) { return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? ''; }
}
require BFTD_PATH . 'includes/class-bftd-cpt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$STUDENT = 10; $REPORT = 20;
$GLOBALS['POSTS'][$STUDENT] = (object) array('ID' => $STUDENT, 'post_type' => 'bftd_student', 'post_status' => 'publish');
$GLOBALS['POSTS'][$REPORT]  = (object) array('ID' => $REPORT,  'post_type' => 'bftd_progress', 'post_status' => 'publish');
$GLOBALS['META'][$REPORT]   = array('_bftd_student_id' => $STUDENT);

function lesson($id, $date, $status = 'publish') {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => 'bftd_session', 'post_status' => $status);
  $GLOBALS['META'][$id]  = array('_bftd_report_id' => 20, '_bftd_student_id' => 10, '_bftd_session__session_date' => $date);
}

/* Entered out of order on purpose: a tutor writing up last Tuesday after
 * today's lesson is the normal case, not the exception. */
lesson(31, '2026-07-09');
lesson(30, '2026-07-07');
lesson(32, '2026-07-16');

check(array(30, 31, 32) === BFTD_CPT::sessions_in_order($REPORT),
  'the sequence is by the date on the lesson, not the order records were made');
check(array(30 => 1, 31 => 2, 32 => 3) === BFTD_CPT::lesson_numbers(BFTD_CPT::sessions_in_order($REPORT)),
  'and the numbers count along it');
check(2 === BFTD_CPT::lesson_number(31), 'one lesson knows its own number');

/* Creating one takes the next number, with nothing written anywhere. */
lesson(33, '2026-07-20');
check(4 === BFTD_CPT::lesson_number(33), 'a new lesson at the end is the next number');
check(!isset($GLOBALS['META'][33]['_bftd_session__session_number']),
  'and nothing was stored to say so');

/* One slotted into the middle pushes the rest along. */
lesson(34, '2026-07-12');
check(3 === BFTD_CPT::lesson_number(34), 'a lesson written up late takes its place by date');
check(4 === BFTD_CPT::lesson_number(32), 'and the ones after it move up');
check(5 === BFTD_CPT::lesson_number(33), 'all of them');

/* Deleting closes the gap, because there was never a gap to close. */
unset($GLOBALS['POSTS'][31], $GLOBALS['META'][31]);
check(array(30 => 1, 34 => 2, 32 => 3, 33 => 4) === BFTD_CPT::lesson_numbers(BFTD_CPT::sessions_in_order($REPORT)),
  'deleting the second lesson renumbers everything after it, with nothing to renumber');
check(2 === BFTD_CPT::lesson_number(34), 'the third lesson is now the second');

/* Two on one day are still two lessons, in the order they were recorded. */
lesson(35, '2026-07-07');
check(1 === BFTD_CPT::lesson_number(30) && 2 === BFTD_CPT::lesson_number(35),
  'two lessons on one day are ordered by which record came first');

/* ---- drafts ---- */
lesson(36, '2026-07-10', 'draft');
$nums = BFTD_CPT::lesson_numbers(BFTD_CPT::sessions_in_order($REPORT));
check(!isset($nums[36]), 'a draft is not in the family\'s numbering');
check(3 === $nums[34], 'so it does not push the published lessons along');
check(3 === BFTD_CPT::lesson_number(36),
  'but the tutor is told where it will land, which is the useful thing while writing it');

lesson(37, '2026-08-30', 'draft');
check(6 === BFTD_CPT::lesson_number(37), 'a draft after everything would be last');

/* ---- the edges ---- */
$GLOBALS['POSTS'][40] = (object) array('ID' => 40, 'post_type' => 'bftd_session', 'post_status' => 'draft');
$GLOBALS['META'][40]  = array();
check(0 === BFTD_CPT::lesson_number(40), 'a lesson with no student yet has no number');

$FRESH = 11;
$GLOBALS['POSTS'][$FRESH] = (object) array('ID' => $FRESH, 'post_type' => 'bftd_student', 'post_status' => 'publish');
$GLOBALS['POSTS'][41] = (object) array('ID' => 41, 'post_type' => 'bftd_session', 'post_status' => 'draft');
$GLOBALS['META'][41]  = array('_bftd_student_id' => $FRESH, '_bftd_session__session_date' => '2026-09-01');
check(1 === BFTD_CPT::lesson_number(41), 'the very first lesson for a student is lesson one');

/* ---- and nothing types it any more ---- */
function code_of($file) {
  $out = '';
  foreach (token_get_all(file_get_contents($file)) as $t) {
    if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $out .= $t[1]; }
    else { $out .= $t; }
  }
  return $out;
}
$mb = code_of(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(0 === preg_match("/'session_number'\s*=>\s*array\(/", $mb), 'the lesson screen has no number box');
check(1 === preg_match('/lesson_number_line/', $mb), 'it states the number instead');

/* ---- the two fields Karl asked to go ---- */
foreach (array("'summary'" => 'the one-line summary', "'home_practice'" => 'what to do at home') as $key => $what) {
  check(0 === preg_match("/" . preg_quote($key, '/') . "\s*=>\s*array\(\s*'type'/", $mb),
    "$what is off the lesson screen");
}
$dash = code_of(BFTD_PATH . 'includes/class-bftd-dashboard.php');
check(0 === preg_match("/get\(\s*'summary'\s*\)/", $dash), 'and off the family\'s report');
check(0 === preg_match("/'home_practice'/", $dash), 'both of them');
check(1 === preg_match("/'homework'\s*=>\s*array\(\s*'Homework'/", $dash),
  'while the homework block stays, which is the one they were duplicating');

$sample = code_of(BFTD_PATH . 'includes/class-bftd-sample.php');
check(0 === preg_match("/'summary'\s*=>|'home_practice'\s*=>|'session_number'\s*=>/", $sample),
  'and the sample report does not still supply them');

exit($fail ? 1 : 0);
