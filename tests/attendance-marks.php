<?php
/*
 * Attendance marked from the Calendar (Karl, 1.211): "in the Calendar, we also
 * need the ability to mark the attendance of each lesson as well, and that
 * needs to update its corresponding session. If no session yet, then when
 * that session is created/entered, it will pull its attendance from the
 * schedule." A mark counts against paid sessions only once its session is
 * published, which is the bank's existing rule.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$META = array(); $STATUS = array(); $SESS = array(); $OCC = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { global $META; unset($META[$id][$k]); return true; }
function get_post_status($id) { global $STATUS; return $STATUS[$id] ?? 'publish'; }
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('user_can')) { function user_can($u, $c, $id = 0) { return 9 === (int) $u; } }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
class BFTD_CPT {
  const SESSION = 'bftd_session'; const STUDENT = 'bftd_student'; const PROGRESS = 'bftd_progress'; const STUDENT_KEY = '_bftd_student_id';
  public static function report_for($sid, $t) { return $sid + 1000; }
  public static function sessions_for($rid, $drafts = false) { global $SESS, $STATUS; $o = array(); foreach ($SESS[$rid - 1000] ?? array() as $id) if ($drafts || 'publish' === ($STATUS[$id] ?? 'publish')) $o[] = $id; return $o; }
}
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_MetaBoxes { public static function cancelled_by_options($p, $s = 0) { return array('' => 'Choose someone', '31' => 'Pat Parent (Client)', '5' => 'Rae T (Tutor)'); } }
class BFTD_Schedule { public static function occurrences($s, $f, $t, $l = 200) { return $GLOBALS['OCC'][$s][$f] ?? array(); } }
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['LOGGED'][] = $e; } }
class BFTD_Session_Appt { public static function linked_keys($s) { return $GLOBALS['LINKED'] ?? array(); } }
require BFTD_PATH . 'includes/class-bftd-attendance.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$today = BFTD_Time::today();
$ago   = function ($n) use ($today) { return BFTD_Time::add_days($today, -$n); };
$st    = function ($sid) { return BFTD_Fields::get($sid, 'session', 'status'); };
$S = 40;

echo "No session yet:\n";
check('booking' === BFTD_Attendance::mark($S, $ago(2), 'noshow'), 'the mark is kept on the booking');
check('noshow' === (BFTD_Attendance::mark_for($S, $ago(2))['status'] ?? ''), 'and read back from it');
$b = BFTD_Attendance::for_booking(BFTD_Attendance::sessions_by_date($S), BFTD_Attendance::marks($S), $ago(2));
check('noshow' === $b['attend'] && 'booking' === $b['attend_on'], 'the Calendar shows it, saying it is on the booking');
check(is_wp_error(BFTD_Attendance::mark($S, BFTD_Time::add_days($today, 1), 'held')), 'a session that has not happened yet cannot be marked');
check(is_wp_error(BFTD_Attendance::mark($S, $ago(1), 'rescheduled')), 'only Attended, Late start, No show and Missed are marked here');
check(is_wp_error(BFTD_Attendance::mark($S, $ago(1), 'missed', '', 'ill')), 'Missed needs who cancelled');
check(is_wp_error(BFTD_Attendance::mark($S, $ago(1), 'missed', '77', 'ill')), 'and it must be someone on the student');
check(is_wp_error(BFTD_Attendance::mark($S, $ago(1), 'missed', '31', '  ')), 'and why, which the family sees');
check('booking' === BFTD_Attendance::mark($S, $ago(1), 'missed', '31', 'Unwell'), 'with both, a missed session is kept');
check('31' === BFTD_Attendance::mark_for($S, $ago(1))['missed_by'] && 'Unwell' === BFTD_Attendance::mark_for($S, $ago(1))['missed_why'], 'with who and why');
BFTD_Attendance::mark($S, $ago(1), '');
check(null === BFTD_Attendance::mark_for($S, $ago(1)), 'Not marked clears a booking\'s mark');

echo "\nA session recorded later starts from the mark, and then holds it:\n";
$SESS[$S] = array(501);
$STATUS[501] = 'draft';
$META[501] = array('_bftd_student_id' => $S, '_bftd_session__session_date' => $ago(2), '_bftd_session__status' => 'noshow');
BFTD_Attendance::session_saved(501, (object) array('post_type' => 'bftd_session'));
check(null === BFTD_Attendance::mark_for($S, $ago(2)), 'once a session for that date is saved, the booking\'s mark is dropped');

echo "\nWith a session, the mark goes to it:\n";
check('session' === BFTD_Attendance::mark($S, $ago(2), 'held'), 'marking a booking that has a session writes to the session');
check('held' === $st(501), 'its What happened is now Attended, got ' . $st(501));
check(in_array('session_updated', $GLOBALS['LOGGED'] ?? array(), true), 'and the change is in the activity log');
check(null === BFTD_Attendance::mark_for($S, $ago(2)), 'with nothing left on the booking');
$b = BFTD_Attendance::for_booking(BFTD_Attendance::sessions_by_date($S), BFTD_Attendance::marks($S), $ago(2));
check('held' === $b['attend'] && 'session' === $b['attend_on'] && true === $b['session_draft'], 'the Calendar reads it from the session, and knows it is a draft');
check('session' === BFTD_Attendance::mark($S, $ago(2), 'missed', '5', 'Tutor ill') && 'missed' === $st(501)
  && '5' === BFTD_Fields::get(501, 'session', 'missed_by') && 'Tutor ill' === BFTD_Fields::get(501, 'session', 'missed_why'), 'Missed writes who and why to the session too');
check(is_wp_error(BFTD_Attendance::mark($S, $ago(2), '')), 'a session cannot be left with no answer from here');

echo "\nThe session linked to the appointment (1.213):\n";
$SESS[$S][] = 502; $STATUS[502] = 'draft';
$META[502] = array('_bftd_student_id' => $S, '_bftd_session__session_date' => $ago(2), '_bftd_session__status' => 'held');
$META[501]['_bftd_session__session_date'] = $ago(5);
$GLOBALS['LINKED'] = array($ago(2) . ' 10:00' => 501);
BFTD_Attendance::mark($S, $ago(2), 'late', '', '', 0, $ago(2) . ' 10:00');
check('late' === $st(501) && 'held' === $st(502), 'a mark sent with its appointment goes to the session linked to it, not another on that date');
unset($GLOBALS['LINKED']);
$META[501]['_bftd_session__session_date'] = $ago(2);
array_pop($SESS[$S]);

echo "\nWho can mark:\n";
$OCC[$S][$ago(2)] = array(array('tutor' => 5));
check(BFTD_Attendance::can_mark($S, $ago(2), 9), 'someone who can edit the student');
check(BFTD_Attendance::can_mark($S, $ago(2), 5), 'the booking\'s tutor');
check(!BFTD_Attendance::can_mark($S, $ago(2), 6), 'and nobody else');

echo "\nThe Calendar wiring:\n";
$cv = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
check(false !== strpos($cv, "\$o['date'] <= BFTD_Time::today() && in_array( \$o['status'], array( 'booked', 'held', 'noshow' ), true )"), 'only bookings from today back can be marked');
check(false !== strpos($cv, "'attend'        => \$att['attend'],"), 'each booking carries its attendance');
$sched = file_get_contents(BFTD_PATH . 'includes/class-bftd-schedule.php');
check(false === strpos($sched, 'MARKS_KEY') && false === strpos($sched, 'BFTD_Attendance'), 'a mark on a booking never spends a paid session: the bank reads only sessions');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
