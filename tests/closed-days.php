<?php
/*
 * 1.203 (Karl): bookings have a type, a tutoring session (the default) or a
 * reading assessment, and a reading assessment is not one of the sessions
 * bought, used or remaining. Nothing is booked on a Sunday, on a statutory
 * holiday where the business is (its province and country from
 * GoHighLevel's business info), or in our own school year's Christmas
 * holidays and spring break, which are entered each year. Bookings can be
 * added from the calendar, held to the same rules.
 */
$GLOBALS['FIRED'] = array();
function do_action($h, ...$a) { $GLOBALS['FIRED'][] = $h; }
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array(); $GLOBALS['OPT'] = array();
function get_post_meta($id,$k,$single=false){$v=$GLOBALS['META'][$id][$k]??($single?'':array());return $v;}
function update_post_meta($id,$k,$v){$GLOBALS['META'][$id][$k]=$v;return true;}
function get_post_type($id){return 'bftd_student';}
function get_the_title($id){return 'Student '.$id;}
function get_current_user_id(){return 1;}
function get_userdata($id){$u=new stdClass;$u->ID=$id;$u->display_name='Tutor '.$id;return $u;}
function set_transient($k,$v,$t=0){$GLOBALS['TRANSIENTS'][$k]=$v;return true;}
function get_transient($k){return $GLOBALS['TRANSIENTS'][$k]??false;}
function get_posts($args){ if (($args['post_type'] ?? '') === 'bftd_session') return array(); return $GLOBALS['STUDENT_IDS']; }
$GLOBALS['AUDIT'] = array();
class BFTD_Audit { public static function log($e,$a=array()){ $GLOBALS['AUDIT'][] = $e . ': ' . ($a['summary'] ?? ''); } }
class BFTD_CPT {
  const STUDENT='bftd_student'; const PROGRESS='bftd_progress'; const SESSION='bftd_session'; const STUDENT_KEY='_bftd_student_id';
  public static function report_for($sid,$t){ return 900; }
  public static function sessions_for($rid){ return $GLOBALS['SESSIONS'] ?? array(); }
  public static function sessions_of($ids, $drafts = false) { $out = array(); foreach ((array) $ids as $i) $out[(int) $i] = array(); return $out; }
  public static function student_id($sid){ return 0; }
  public static function staff_ids($sid){ return $GLOBALS['STAFF'][$sid] ?? array(); }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Fields {
  public static function get($id,$group,$key,$f=array()){
    if ('session' === $group) return $GLOBALS['SESSION_META'][$id][$key] ?? '';
    return $GLOBALS['META'][$id]['_bftd_student__'.$key] ?? '';
  }
}
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-stat-holidays.php';
require BFTD_PATH . 'includes/class-bftd-schedule.php';
$H = 'BFTD_Stat_Holidays'; $S = 'BFTD_Schedule';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function booked($sid, $from, $to) { $o = array(); foreach (BFTD_Schedule::occurrences($sid, $from, $to) as $x) if ('booked' === $x['status']) $o[] = $x; return $o; }

/* ---- where the business is ---- */
check(array('CA', 'BC') === $H::place_codes('Canada', 'British Columbia') && array('CA', 'BC') === $H::place_codes('ca', 'bc'), 'a country and province from GoHighLevel are read as codes, names or codes alike');
check('' === $H::closed_because('2026-12-25') && 'Sunday' === $H::closed_because('2026-11-22'), 'before GoHighLevel has said where the business is, only Sundays are closed');
$GLOBALS['OPT'][$H::PLACE] = array('country' => 'CA', 'state' => 'AB', 'label' => 'Alberta, Canada');
check('' === $H::closed_because('2026-12-25'), 'a place the portal has no list for closes no holidays, rather than guessing');
$GLOBALS['OPT'][$H::PLACE] = array('country' => 'CA', 'state' => 'BC', 'label' => 'British Columbia, Canada');
check('Christmas Day' === $H::closed_because('2026-12-25') && 'Family Day' === $H::closed_because('2026-02-16') && '' === $H::closed_because('2026-12-26'), 'in British Columbia its statutory holidays are closed, the same list payroll uses (and not Boxing Day)');

/* ---- our school year ---- */
$kept = $H::save_school_years(array(
  array('year' => 2026, 'xmas_from' => '2026-12-21', 'xmas_to' => '2027-01-01', 'spring_from' => '2027-03-15', 'spring_to' => '2027-03-26'),
  array('year' => 2027, 'xmas_from' => '2027-12-24', 'xmas_to' => '2027-12-20', 'spring_from' => '', 'spring_to' => ''),
  array('year' => 2028, 'xmas_from' => '', 'xmas_to' => '', 'spring_from' => '', 'spring_to' => ''),
));
check(array(2026) === array_keys($kept) && array(2026) === array_keys($H::school_years()), 'each school year\'s breaks are kept; a break ending before it starts is dropped, and a year with no dates goes');
check('Christmas holidays' === $H::closed_because('2026-12-22') && 'Spring break' === $H::closed_because('2027-03-19') && '' === $H::closed_because('2027-03-29'), 'nothing is booked in the Christmas holidays or spring break, first and last days included');

/* ---- bookings refused on closed days ---- */
$GLOBALS['STUDENT_IDS'] = array(10, 11);
$GLOBALS['STAFF'] = array(10 => array(7), 11 => array(7));
$r = $S::add_booking(10, array('repeat' => 'once', 'from' => '2026-11-22', 'time' => '10:00'));
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'that is a Sunday') && array() === $S::rules(10), 'a one-time booking on a Sunday is refused, and says why');
$r = $S::add_booking(10, array('repeat' => 'once', 'from' => '2027-11-11', 'time' => '10:00'));
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'that is Remembrance Day'), 'and on a statutory holiday');
$r = $S::add_booking(10, array('repeat' => 'once', 'from' => '2026-12-23', 'time' => '10:00', 'type' => 'assessment'));
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'in the Christmas holidays'), 'and in a school break, a reading assessment as much as a session');
$r = $S::add_booking(10, array('repeat' => 'once', 'from' => BFTD_Time::add_days(BFTD_Time::today(), -1), 'time' => '10:00'));
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'that date has passed'), 'a one-time booking on a date that has passed is refused (the other bookings of the tutor are only checked from today)');
$r = $S::add_booking(10, array('repeat' => 'weekly', 'from' => '2026-11-22', 'time' => '10:00'));
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'weekly booking on Sundays'), 'a weekly booking on Sundays is refused');

$S::save_rules(10, array(array('repeat' => 'once', 'from' => '2026-11-29', 'time' => '10:00'), array('repeat' => 'once', 'from' => '2026-11-30', 'time' => '10:00')));
check(1 === count($S::rules(10)) && '2026-11-30' === $S::rules(10)[0]['from'], 'the student\'s own form turns a closed day away too, keeping the rest');
check(false !== strpos(implode(' ', (array) get_transient('bftd_sched_warn_1')), 'The booking on'), 'and says which and why');
$GLOBALS['META'][10][$S::RULES_KEY][] = array('id' => 'old', 'day' => '7', 'time' => '09:00', 'minutes' => 50, 'from' => '', 'tutor' => 0, 'repeat' => 'weekly', 'until' => '', 'type' => 'tutoring');
$S::save_rules(10, array_merge($S::rules(10), array()));
check(2 === count($S::rules(10)), 'a booking already saved before 1.203 is not dropped when the student is saved again');
$GLOBALS['META'][10][$S::RULES_KEY] = array();

/* ---- weekly bookings skip closed days ---- */
$S::save_rules(10, array(array('repeat' => 'weekly', 'from' => '2026-12-07', 'time' => '16:00', 'until' => '2027-01-11')));
$dates = array_map(function ($o) { return $o['date']; }, booked(10, '2026-12-01', '2027-01-31'));
check(array('2026-12-07', '2026-12-14', '2027-01-04', '2027-01-11') === $dates, 'a weekly booking skips the Christmas holidays (21 and 28 Dec), got ' . implode(', ', $dates));
$S::save_rules(10, array(array('repeat' => 'weekly', 'from' => '2026-09-28', 'time' => '16:00', 'until' => '2026-10-19')));
$dates = array_map(function ($o) { return $o['date']; }, booked(10, '2026-09-01', '2026-10-31'));
check(array('2026-09-28', '2026-10-05', '2026-10-19') === $dates, 'and statutory holidays (Thanksgiving, 12 Oct), got ' . implode(', ', $dates));
$e = $S::set_exception(10, '2026-10-05 16:00', 'move', '2026-11-11', '16:00');
check(is_wp_error($e) && false !== strpos($e->get_error_message(), 'Remembrance Day'), 'a session cannot be moved onto a closed day');

/* ---- a reading assessment is not a bought session ---- */
$GLOBALS['META'][11]['_bftd_student__lessons_bank'] = 2;
$T = BFTD_Time::today();
$open = function ($d) { while ('' !== BFTD_Stat_Holidays::closed_because($d)) $d = BFTD_Time::add_days($d, 1); return $d; };
$a1 = $open(BFTD_Time::add_days($T, 2));
$a2 = $open(BFTD_Time::add_days($a1, 1));
$r1 = $S::add_booking(11, array('repeat' => 'once', 'from' => $a1, 'time' => '09:00', 'type' => 'assessment', 'minutes' => 60));
$r2 = $S::add_booking(11, array('repeat' => 'once', 'from' => $a2, 'time' => '09:00'));
check(is_array($r1) && 'assessment' === $r1['type'] && 'tutoring' === $r2['type'], 'a booking is a tutoring session unless it is said to be a reading assessment');
$b = $S::bank(11);
check(1 === $b['booked'] && 2 === $b['remaining'] && 1 === $b['unbooked'], 'the reading assessment is not counted among the sessions booked against those bought, got booked ' . $b['booked']);
$occ = booked(11, $T, BFTD_Time::add_days($T, 30));
check(2 === count($occ) && 'Reading assessment' === $S::type_label($occ[0]['type']), 'though it is on the schedule, taking the tutor\'s time, and says what it is');
$GLOBALS['SESSIONS'] = array(900);
$GLOBALS['SESSION_META'][900] = array('session_date' => $a1, 'status' => 'held');
$st = array_values(array_filter($S::occurrences(11, $T, BFTD_Time::add_days($T, 30)), function ($o) { return 'assessment' === $o['type']; }));
check('booked' === $st[0]['status'], 'a session written up that day does not mark the reading assessment taught');
$GLOBALS['SESSIONS'] = array();
$GLOBALS['STUDENT_IDS'][] = 12; $GLOBALS['STAFF'][12] = array(7);   // a student not asked about before in this run: a tutor's bookings are kept per request
$r3 = $S::add_booking(12, array('repeat' => 'once', 'from' => $a1, 'time' => '09:30'));
check(is_wp_error($r3) && false !== strpos($r3->get_error_message(), 'two sessions at once') && in_array('bftd_schedule_changed', $GLOBALS['FIRED'], true), 'a booking from the calendar may not run over the tutor\'s reading assessment, and a booking made is sent on for the banner');

/* ---- where it is wired ---- */
$bk = file_get_contents(BFTD_PATH . 'includes/class-bftd-bookings.php');
$ov = file_get_contents(BFTD_PATH . 'includes/class-bftd-oversight.php');
$cl = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendars.php');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js');
check(false !== strpos($bk, "( 'assessment' === ( \$o['type'] ?? '' ) ? 'Reading assessment: ' : 'Reading session: ' )"), 'in GoHighLevel the appointment says which it is');
check(false !== strpos($ov, 'if ( ! BFTD_Schedule::counts( $slot ) ) continue;'), 'Oversight does not ask for a session record for a reading assessment');
check(false !== strpos($cl, "self::remember_place( (array) ( \$data['location'] ?? array() ) );") && false !== strpos($cl, 'self::render_school_year();'), 'the business place is read with the hourly check, and the school year is set on the Calendars tab');
check(false !== strpos($js, "var HOUR_MIN = 96;") && false !== strpos($js, "( state.closed[ s ] ? ' is-closed' : '' )") && false !== strpos($js, "'moved' === e.status && ! showMoved.checked"), 'the calendar is tall enough to read each booking, greys closed days, and filters rescheduled ones');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
