<?php
/*
 * Tutor notes, the internal session report (1.194).
 *
 * Staff only. One student, newest session first, narrowed by a date range or
 * a quick range, days of the week, a time of day, what happened and who
 * taught it. Each session in Karl's order: activities by name, the tutor's
 * words without images, attendance, texts read, morphology matrices.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
function add_action(...$a) {}
function get_post_status($id) { return 103 === $id ? 'draft' : 'publish'; }
function get_post_field($f, $id) { return 'post_author' === $f ? 9 : 'Ana Lee'; }
function get_userdata($id) { return (object) array('ID' => $id, 'display_name' => 9 === (int) $id ? 'Laurel Sanders' : 'Candice Paluck'); }
function get_edit_post_link($id, $c = '') { return '/wp-admin/post.php?post=' . $id . '&action=edit'; }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($t) { return strip_tags($t); } }
if (!function_exists('nl2br')) {}
$GLOBALS['F'] = array(
  101 => array('session_date' => '2026-11-02', 'session_time' => '10:00', 'status' => 'held', 'delivered_by' => 9,
    'notes' => '<p>Read <strong>fluently</strong> today.</p><p><img src="x.jpg"> Worked on &quot;spect&quot;.</p><ul><li>one</li><li>two</li></ul>',
    'texts' => array(array('title' => 'SR-71 Blackbird', 'level' => '10'), array('title' => '', 'level' => '')),
    'matrices' => array(array('matrix' => 'Latin root "spect"'))),
  102 => array('session_date' => '2026-11-04', 'session_time' => '16:00', 'status' => 'late', 'status_why' => 'Late arrival', 'delivered_by' => 4),
  103 => array('session_date' => '2026-11-09', 'session_time' => '10:30', 'status' => 'missed', 'missed_by' => '7', 'missed_why' => 'Sick', 'missed_madeup' => '2026-11-12'),
);
class BFTD_Fields { public static function get($id, $g, $k, $f = array()) { return $GLOBALS['F'][$id][$k] ?? ''; } }
class BFTD_CPT {
  public static function sessions_of($ids, $drafts = false) { return array(40 => array(101, 102, 103)); }
  public static function lesson_number($id) { return array(101 => 15, 102 => 16)[$id] ?? 0; }
  public static function student_id($id) { return 40; }
  public static function cancelled_by_label($v, $s = 0) { return '7' === $v ? 'Nadine Encinas' : $v; }
}
class BFTD_Activities { public static function for_session($id) { return 101 === $id ? array(array('name' => 'Syllable division', 'note' => '<p>Got it <img src="y.png"> quickly.</p>', 'about' => 'A long description', 'key' => 'a')) : array(); } }
class BFTD_Charts { public static function text_level_label($l) { return '' === $l ? '' : 'Grade ' . $l; } }
class BFTD_MetaBoxes { public static function madeup_how($id) { return 'date'; } }
class BFTD_Schedule { public static function pretty_time($t) { return $t; } public static function weekdays() { return array('1' => 'Monday'); } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
require BFTD_PATH . 'includes/class-bftd-tutor-notes.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$T = 'BFTD_Tutor_Notes';

/* ---- filters ---- */
$f = $T::filters(array('student' => '40', 'range' => '7'), '2026-11-16');
check('2026-11-10' === $f['from'] && '2026-11-16' === $f['to'], 'the last 7 days, today included');
$f = $T::filters(array('range' => 'month'), '2026-11-16');
check('2026-11-01' === $f['from'] && '2026-11-16' === $f['to'], 'this month');
$f = $T::filters(array('from' => '2026-11-09', 'to' => '2026-11-01'), '2026-11-16');
check('custom' === $f['range'] && '2026-11-01' === $f['from'] && '2026-11-09' === $f['to'], 'typed dates are a range of their own, in order whichever way round');
$f = $T::filters(array('days' => array('1', '9', 'x'), 'status' => array('held', 'nope'), 'tfrom' => '9am', 'tto' => '12:00'), '2026-11-16');
check(array('1') === $f['days'] && array('held') === $f['status'] && '' === $f['tfrom'] && '12:00' === $f['tto'], 'only real days, statuses and times are kept');

/* ---- the sessions ---- */
$all = $T::entries(40, $T::filters(array(), '2026-11-16'));
check(array(103, 102, 101) === array_column($all, 'id'), 'newest first');
$s = $all[2];
check(array('Syllable division') === array_column($s['activities'], 'name') && !isset($s['activities'][0]['about']), 'activities by name, without their descriptions');
check(false === strpos($s['notes'], 'img') && false !== strpos($s['notes'], "Read fluently today.\nWorked on \"spect\".") && false !== strpos($s['notes'], '• one'), 'the tutor\'s words only: no images, no markup, lines and lists kept, got ' . json_encode($s['notes']));
check('Got it quickly.' === $s['activities'][0]['note'], 'and what they wrote on an activity, the same way');
check('Before. After.' === str_replace("\n", ' ', $T::words('<p>Before.</p><figure><img src="a.jpg"><figcaption>Photo of her work</figcaption></figure><p>After.</p>')), 'a captioned image goes with its caption: the notes are the tutor\'s words, not the pictures');
check(array(array('title' => 'SR-71 Blackbird', 'level' => 'Grade 10')) === $s['texts'] && array('Latin root "spect"') === $s['matrices'], 'texts read with their level, and the matrices; empty rows left out');
check(15 === $s['number'] && 9 === $s['tutor'], 'numbered as the family sees it, with who taught it');
check(0 === $all[0]['number'] && true === $all[0]['draft'] && 9 === $all[0]['tutor'], 'a draft is shown, unnumbered, and falls back to whoever wrote it');
check('Nadine Encinas' === $all[0]['missed_by'] && 'Sick' === $all[0]['missed_why'] && 'date' === $all[0]['madeup'], 'a missed session says who cancelled, why, and how it is made up');

/* ---- narrowing ---- */
$ids = function ($q) use ($T) { return array_column($T::entries(40, $T::filters($q, '2026-11-16')), 'id'); };
check(array(103, 102) === $ids(array('from' => '2026-11-03', 'to' => '2026-11-10')), 'between two dates');
check(array(103, 101) === $ids(array('days' => array('1'))), 'on a day of the week (Mondays)');
check(array(103, 101) === $ids(array('tto' => '11:00')), 'before a time of day');
check(array(102) === $ids(array('tfrom' => '12:00')), 'and after one');
check(array(102) === $ids(array('status' => array('late'))), 'by what happened');
check(array(102) === $ids(array('tutor' => '4')), 'and by who taught it');

/* ---- the card, in Karl's order ---- */
ob_start(); $T::card($all[2]); $html = ob_get_clean();
$order = array_map(function ($h) use ($html) { return strpos($html, '<h3>' . $h . '</h3>'); }, array('Activities', 'Tutor notes', 'Attendance', 'What we read', 'Morphology matrices studied'));
check(!in_array(false, $order, true) && $order === array_values(array_unique($order)) && $order == array_values((function ($o) { sort($o); return $o; })($order)), 'activities, tutor notes, attendance, texts read, matrices, in that order');
check(false === strpos($html, '<img') && false === strpos($html, 'A long description'), 'no images and no activity descriptions');
check(false !== strpos($html, 'Session 15') && false !== strpos($html, 'Laurel Sanders') && false !== strpos($html, '>Attended<'), 'headed with its number, tutor and what happened');
ob_start(); $T::card($all[0]); $miss = ob_get_clean();
check(false !== strpos($miss, 'Cancelled by Nadine Encinas') && false !== strpos($miss, 'Made up on') && false === strpos($miss, '<h3>Activities</h3>'), 'a missed session shows its attendance, and no empty boxes');

/* ---- staff only ---- */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-tutor-notes.php');
check(false !== strpos($src, "add_submenu_page( BFTD_Admin::MENU_SLUG, 'Tutor notes', 'Tutor notes', BFTD_Roles::STAFF_CAP,"), 'a staff screen: families cannot open it');
check(false !== strpos($src, 'BFTD_Access::visible_student_ids( $me )') && false !== strpos($src, 'BFTD_Access::can_staff_view( $f[\'student\'], $me )'), 'and a tutor sees only students they can open');
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-students.php'), "page=bftd-tutor-notes&student="), 'reached from each student on the Students list');

check(1 === preg_match('#<fieldset class="bftd-tn-time"><legend>Time of day</legend>\s*<label>from <input type="time" name="tfrom".*?</label>\s*<label>until <input type="time" name="tto"#s', file_get_contents(BFTD_PATH . 'includes/class-bftd-tutor-notes.php')), 'from and until are one filter, kept together (1.195)');

$src2 = file_get_contents(BFTD_PATH . 'includes/class-bftd-tutor-notes.php');
check('all' === $T::filters(array(), '2026-11-16')['range'] && false !== strpos($src2, "array( 'all' => 'All time',"), 'all time by default, and called that (1.197)');
check(false !== strpos($src2, "'No sessions recorded for ' . \$students[ \$sid ] . ' yet.'") && false !== strpos($src2, "'No sessions match these filters. Widen the range, or clear a filter.'"), 'a student with none says so, rather than blaming a filter that is not set');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
