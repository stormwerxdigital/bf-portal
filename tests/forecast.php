<?php
/*
 * Attending to, forecast (Karl, 1.214): "when we have entered a number of
 * purchased sessions, you can forecast the last session date based on our
 * calendar settings", "it will need to factor in the frequency of sessions,
 * 1-5x per week. Default should be 2 sessions per week."
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
$META = array(); $BANK = array(); $TAUGHT = array();
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
  public static function bank($s) { return $GLOBALS['BANK']; }
  public static function taught_dates($s) { return $GLOBALS['TAUGHT']; }
  public static function closed_on($d) { return '7' === gmdate('N', strtotime($d . ' 12:00:00 UTC')) ? 'Sunday' : ''; }
}
require BFTD_PATH . 'includes/class-bftd-forecast.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$set = function ($k, $v) { BFTD_Fields::set(9, 'student', $k, $v); };
$bank = function ($remaining, $unbooked, $last) { $GLOBALS['BANK'] = array('remaining' => $remaining, 'unbooked' => $unbooked, 'last_date' => $last); };

echo "Counting forward:\n";
// 2027-01-04 is a Monday.
check('2027-02-04' === BFTD_Forecast::count_forward('2027-01-04', 10, 2), 'ten sessions at two a week from a Monday end five weeks on, a Thursday, got ' . BFTD_Forecast::count_forward('2027-01-04', 10, 2));
check('2027-03-08' === BFTD_Forecast::count_forward('2027-01-04', 10, 1), 'at one a week, ten weeks on');
check('2027-01-15' === BFTD_Forecast::count_forward('2027-01-04', 10, 5), 'at five a week, two school weeks');
check('2027-01-11' === BFTD_Forecast::count_forward('2027-01-10', 1, 2), 'a session that would fall on a Sunday moves to the Monday');
check('' === BFTD_Forecast::count_forward('2027-01-04', 0, 2), 'nothing to count is no date');

echo "\nThe forecast:\n";
$T = BFTD_Time::today();
check('' === BFTD_Forecast::forecast(9), 'nothing purchased, nothing forecast');
$set('lessons_bank', 20);
check(2 === BFTD_Forecast::per_week(9), 'with no Sessions per week, two a week');
$set('per_week', '3');
check(3 === BFTD_Forecast::per_week(9), 'or the number chosen');
$bank(8, 0, '2027-02-11');
check('2027-02-11' === BFTD_Forecast::forecast(9), 'when the bookings cover every session left, the last one they cover');
$bank(8, 3, '2027-02-11');
check(BFTD_Forecast::count_forward('2027-02-12', 3, 3) === BFTD_Forecast::forecast(9), 'sessions left past the bookings carry on from the last one at Sessions per week');
$bank(6, 6, '');
$set('attend_from', '2099-01-05');
check(BFTD_Forecast::count_forward('2099-01-05', 6, 3) === BFTD_Forecast::forecast(9), 'with no bookings, from Attending from');
$set('attend_from', '2020-01-06');
check(BFTD_Forecast::count_forward($T, 6, 3) === BFTD_Forecast::forecast(9), 'or from today, once that has passed');
$bank(0, 0, ''); $TAUGHT = array('2026-10-01' => 1, '2026-11-12' => 2);
check('2026-11-12' === BFTD_Forecast::forecast(9), 'all used: the last session given');

echo "\nAttending to:\n";
$bank(8, 0, '2027-02-11'); $set('attend_to', '');
BFTD_Forecast::apply(9);
check('2027-02-11' === BFTD_Fields::get(9, 'student', 'attend_to'), 'an empty Attending to is filled in');
$bank(8, 0, '2027-02-18');
BFTD_Forecast::apply(9);
check('2027-02-18' === BFTD_Fields::get(9, 'student', 'attend_to'), 'and kept up to date as the bookings change');
$set('attend_to', '2027-06-30');
$bank(8, 0, '2027-03-01');
BFTD_Forecast::apply(9);
check('2027-06-30' === BFTD_Fields::get(9, 'student', 'attend_to'), 'a date somebody typed is never changed');
$set('attend_to', ''); $set('lessons_bank', 0);
BFTD_Forecast::apply(9);
check('' === BFTD_Fields::get(9, 'student', 'attend_to'), 'with nothing purchased it stays empty');

echo "\nThe student record:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "'per_week'     => array( 'type' => 'select', 'label' => 'Sessions per week', 'default' => '2'"), 'Student details has Sessions per week, two to start');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
