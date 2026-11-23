<?php
/*
 * Attending to, forecast. Karl, 1.214: "when we have entered a number of
 * purchased sessions, you can forecast the last session date based on our
 * calendar settings", "it will need to factor in the frequency of sessions,
 * 1-5x per week. Default should be 2 sessions per week." And 1.216.2: "Your
 * attending to date is wrong for all students it appears. You must account
 * for our calendar, holidays and school year settings."
 *
 * So: every session purchased, counted from Attending from, on the
 * student's bookings where the Calendar has them and at Sessions per week
 * where it does not, with no session on a closed day.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
$META = array(); $OCC = array(); $CLOSED = array(); $RULES = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { global $META; unset($META[$id][$k]); return true; }
if (!function_exists('add_action')) { function add_action(...$a) {} }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_Schedule {
  public static function occurrences($s, $f, $t, $l = 200) { return array_values(array_filter($GLOBALS['OCC'], function ($o) use ($f, $t) { return $o['date'] >= $f && $o['date'] <= $t; })); }
  public static function rules($s) { return $GLOBALS['RULES']; }
  public static function is_once($r) { return 'once' === ($r['repeat'] ?? ''); }
  public static function counts($o) { return 'assessment' !== ($o['type'] ?? 'tutoring'); }
  // Sundays, plus whatever the test closes: a statutory holiday, a school break.
  public static function closed_on($d) { return '7' === gmdate('N', strtotime($d . ' 12:00:00 UTC')) ? 'Sunday' : ($GLOBALS['CLOSED'][$d] ?? ''); }
}
require BFTD_PATH . 'includes/class-bftd-forecast.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$set = function ($k, $v) { BFTD_Fields::set(9, 'student', $k, $v); };
$occ = function ($date, $status = 'booked', $type = 'tutoring') { return array('date' => $date, 'status' => $status, 'type' => $type); };
$close = function ($from, $to, $why) { for ($d = $from; $d <= $to; $d = BFTD_Time::add_days($d, 1)) $GLOBALS['CLOSED'][$d] = $why; };

echo "Counting forward, open days only:\n";
// 2027-01-04 is a Monday.
check('2027-02-04' === BFTD_Forecast::count_forward('2027-01-04', 10, 2), 'ten sessions at two a week from a Monday end five weeks on, a Thursday');
check('2027-03-08' === BFTD_Forecast::count_forward('2027-01-04', 10, 1), 'at one a week, ten weeks on');
check('2027-01-15' === BFTD_Forecast::count_forward('2027-01-04', 10, 5), 'at five a week, two school weeks');
$close('2027-01-11', '2027-01-11', 'Statutory holiday');
check('2027-02-08' === BFTD_Forecast::count_forward('2027-01-04', 10, 2), 'a statutory holiday has no session, so the last one comes a session later, got ' . BFTD_Forecast::count_forward('2027-01-04', 10, 2));
$CLOSED = array();
$close('2027-01-11', '2027-01-22', 'Christmas holidays');
check('2027-02-18' === BFTD_Forecast::count_forward('2027-01-04', 10, 2), 'a two week school break pushes it two weeks, got ' . BFTD_Forecast::count_forward('2027-01-04', 10, 2));
check(array('2027-01-04', '2027-01-07') === BFTD_Forecast::dates_forward('2027-01-04', 10, 2, '2027-01-11'), 'and counting stops at a given day');
$CLOSED = array();

echo "\nThe forecast:\n";
check('' === BFTD_Forecast::forecast(9), 'nothing purchased, nothing forecast');
$set('lessons_bank', 10);
check('' === BFTD_Forecast::forecast(9), 'nor with no Attending from to count from');
$set('attend_from', '2027-01-04');
check(2 === BFTD_Forecast::per_week(9), 'with no Sessions per week, two a week');
check('2027-02-04' === BFTD_Forecast::forecast(9), 'with no bookings, every session purchased counted from Attending from at two a week');
$set('attend_from', '2025-09-08');   // a Monday, long past
check(BFTD_Forecast::count_forward('2025-09-08', 10, 2) === BFTD_Forecast::forecast(9), 'from Attending from even when that is long past: sessions given count, written up or not');
$set('attend_from', '2027-01-04');
$set('per_week', '1');
check('2027-03-08' === BFTD_Forecast::forecast(9), 'at the Sessions per week chosen');

echo "\nThe Calendar's bookings:\n";
$set('per_week', '2');
$OCC = array();
foreach (array('2027-01-04', '2027-01-11', '2027-01-18', '2027-01-25', '2027-02-01', '2027-02-08', '2027-02-15', '2027-02-22', '2027-03-01', '2027-03-08') as $d) $OCC[] = $occ($d);
check('2027-03-08' === BFTD_Forecast::forecast(9), 'ten weekly bookings: the tenth is the last, whatever Sessions per week says');
$OCC[1]['status'] = 'cancelled'; $OCC[2]['status'] = 'moved';
$OCC[] = $occ('2027-01-19');   // where the moved one went
$OCC[] = $occ('2027-01-20', 'booked', 'assessment');
usort($OCC, function ($a, $b) { return strcmp($a['date'], $b['date']); });
check('2027-03-08' === BFTD_Forecast::forecast(9), 'a cancelled one still spends a session, a moved one counts where it went, a reading assessment is not one');
$OCC = array($occ('2027-02-01'), $occ('2027-02-08'));
check('2027-02-08' === BFTD_Forecast::forecast(9), 'bookings starting later: two a week before them (eight), then the two bookings make ten, got ' . BFTD_Forecast::forecast(9));
$set('lessons_bank', 14);
check(BFTD_Forecast::count_forward('2027-02-09', 4, 2) === BFTD_Forecast::forecast(9), 'and with more purchased than that, two a week again after the last booking');
$set('lessons_bank', 10);
$OCC = array();

/* The days they actually come (Karl: "For an accurate end date, you need the
   date of his second appointment"). */
$RULES = array(array('day' => '2', 'repeat' => 'weekly'), array('day' => '5', 'repeat' => 'weekly'), array('day' => '3', 'repeat' => 'once'));
check(array(2, 5) === BFTD_Forecast::booked_weekdays(9), 'the weekdays of their weekly bookings, Tuesday and Friday, not a one-time booking');
$OCC = array($occ('2027-02-02'), $occ('2027-02-05'));
// From Monday 4 Jan on Tue and Fri: 5, 8, 12, 15, 19, 22, 26, 29 Jan (eight) before the first booking, then the two bookings.
check('2027-02-05' === BFTD_Forecast::forecast(9), 'before the bookings, the sessions fall on their own days, Tuesday and Friday, got ' . BFTD_Forecast::forecast(9));
$set('lessons_bank', 12);
check('2027-02-12' === BFTD_Forecast::forecast(9), 'and after them too: the 11th and 12th on Tuesday 9 and Friday 12 Feb, got ' . BFTD_Forecast::forecast(9));
$set('lessons_bank', 10);
/* Sessions per week follows the basic schedule (Karl, 1.217: "a schedule will
   be the day or days they will be attending, its always the same"). */
$set('per_week', '3');
BFTD_Forecast::apply(9);
check('2' === BFTD_Fields::get(9, 'student', 'per_week'), 'Sessions per week is set to the days of their schedule, two, got ' . BFTD_Fields::get(9, 'student', 'per_week'));
$RULES = array(array('day' => '3', 'repeat' => 'weekly'));
BFTD_Forecast::apply(9);
check('1' === BFTD_Fields::get(9, 'student', 'per_week'), 'and one when they come one day a week');
$RULES = array(); $OCC = array();
$set('per_week', '');
$set('attend_to', '');

echo "\nAttending to:\n";
$set('attend_to', '');
BFTD_Forecast::apply(9);
check('2027-02-04' === BFTD_Fields::get(9, 'student', 'attend_to'), 'an empty Attending to is filled in');
$set('lessons_bank', 12);
BFTD_Forecast::apply(9);
check('2027-02-11' === BFTD_Fields::get(9, 'student', 'attend_to'), 'and kept up to date when more are purchased');
$set('attend_to', '2027-06-30');
$set('lessons_bank', 20);
BFTD_Forecast::apply(9);
check('2027-06-30' === BFTD_Fields::get(9, 'student', 'attend_to'), 'a date somebody typed is never changed');
$set('attend_to', ''); $set('lessons_bank', 0);
BFTD_Forecast::apply(9);
check('' === BFTD_Fields::get(9, 'student', 'attend_to'), 'with nothing purchased it stays empty');

echo "\nThe student record:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "'per_week'     => array( 'type' => 'select', 'label' => 'Sessions per week', 'default' => '2'"), 'Student details has Sessions per week, two to start');
$fc = file_get_contents(BFTD_PATH . 'includes/class-bftd-forecast.php');
check(false !== strpos($fc, "add_action( 'admin_init', array( __CLASS__, 'redo_all' ) );") && false !== strpos($fc, "if ( '3' === (string) get_option( self::PASS, '' ) ) return;"), 'every student is worked out again once after the update');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
