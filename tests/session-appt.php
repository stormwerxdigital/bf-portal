<?php
/*
 * A session's date and time come from its Calendar appointment (Karl, 1.213):
 * "the date and time we use in our sessions actually comes from a calendar
 * appointment. And if we are creating a new session or working in draft, we
 * can link the appropriate appointment if one exists or create a new draft
 * appointment and link it." Made-up records only.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$META = array(); $TR = array(); $STATUS = array(); $SESS = array(); $OCC = array(); $BOOKED = array(); $UPDATED = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function set_transient($k, $v, $t = 0) { global $TR; $TR[$k] = $v; return true; }
function get_transient($k) { global $TR; return $TR[$k] ?? false; }
function delete_transient($k) { global $TR; unset($TR[$k]); return true; }
function get_post_status($id) { global $STATUS; return $STATUS[$id] ?? 'draft'; }
function wp_update_post($a) { global $STATUS, $UPDATED; $STATUS[$a['ID']] = $a['post_status']; $UPDATED[] = $a; return $a['ID']; }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('remove_action')) { function remove_action(...$a) {} }
function get_userdata($id) { return (object) array('ID' => $id, 'display_name' => 'Rae Tutor'); }
class BFTD_CPT {
  const SESSION = 'bftd_session'; const PROGRESS = 'bftd_progress';
  public static function report_for($sid, $t) { return $sid + 1000; }
  public static function sessions_for($rid, $drafts = false) { return $GLOBALS['SESS'][$rid - 1000] ?? array(); }
  public static function student_id($sid) { return 40; }
}
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_Schedule {
  public static function occurrences($sid, $from, $to, $l = 200) { return array_values(array_filter($GLOBALS['OCC'], function ($o) use ($from, $to) { return $o['date'] >= $from && $o['date'] <= $to; })); }
  public static function minutes_of($t) { $p = explode(':', $t); return $p[0] * 60 + $p[1]; }
  public static function pretty_time($t) { return $t; }
  public static function add_booking($sid, $raw, $quiet = false) {
    $GLOBALS['BOOKED'][] = array('raw' => $raw, 'quiet' => $quiet);
    if ('07:00' === $raw['time']) return new WP_Error('bftd_booking', 'Rae already has a session then.');
    $GLOBALS['OCC'][] = array('key' => $raw['from'] . ' ' . $raw['time'], 'date' => $raw['from'], 'time' => $raw['time'], 'minutes' => $raw['minutes'], 'tutor' => $raw['tutor'], 'status' => 'booked', 'type' => 'tutoring');
    return array('from' => $raw['from'], 'time' => $raw['time']);
  }
}
require BFTD_PATH . 'includes/class-bftd-session-appt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$T = BFTD_Time::today();
$d = function ($n) use ($T) { return BFTD_Time::add_days($T, $n); };
$occ = function ($date, $time, $type = 'tutoring', $status = 'booked') { return array('key' => "$date $time", 'date' => $date, 'time' => $time, 'minutes' => 50, 'tutor' => 5, 'status' => $status, 'type' => $type); };
$OCC = array($occ($d(-2), '10:00'), $occ($d(-1), '11:00', 'assessment'), $occ($d(-9), '10:00', 'tutoring', 'cancelled'), $occ($d(3), '10:00'), $occ($d(-40), '10:00'));
$SESS[40] = array(700, 701);

echo "The appointments a session can be linked to:\n";
$c = BFTD_Session_Appt::choices(40, 700);
$keys = array_column($c, 'key');
check($keys[0] === $d(-2) . ' 10:00' && in_array($d(3) . ' 10:00', $keys, true), 'the student\'s appointments, nearest today first');
check(!in_array($d(-1) . ' 11:00', $keys, true), 'not reading assessments');
check(!in_array($d(-40) . ' 10:00', $keys, true), 'nor ones more than a month back');
check(in_array($d(-9) . ' 10:00', $keys, true) && false !== strpos($c[array_search($d(-9) . ' 10:00', $keys)]['label'], '(cancelled)'), 'a cancelled one is offered, saying so, for a session recording it as missed');

echo "\nLinking:\n";
check(true === BFTD_Session_Appt::link(700, 40, $d(-2) . ' 10:00'), 'a session links to an appointment');
check($d(-2) === BFTD_Fields::get(700, 'session', 'session_date') && '10:00' === BFTD_Fields::get(700, 'session', 'session_time'), 'and takes its date and time');
check(!in_array($d(-2) . ' 10:00', array_column(BFTD_Session_Appt::choices(40, 701), 'key'), true), 'another session is not offered it again');
check(is_wp_error(BFTD_Session_Appt::link(701, 40, $d(-2) . ' 10:00')), 'and cannot take it');
check(is_wp_error(BFTD_Session_Appt::link(701, 40, $d(-5) . ' 09:00')), 'an appointment not on the schedule cannot be linked');

echo "\nA new appointment from a session:\n";
check(true === BFTD_Session_Appt::create_and_link(701, 40, $d(-1), '15:00', 50, 5), 'a session for a lesson with no appointment makes one and links it');
$b = end($BOOKED);
check(true === $b['quiet'] && 1 === $b['raw']['local'] && 'once' === $b['raw']['repeat'], 'for today or earlier it is kept here: no banner, no notice, not sent to GoHighLevel');
check($d(-1) . ' 15:00' === BFTD_Session_Appt::key_of(701) && '15:00' === BFTD_Fields::get(701, 'session', 'session_time'), 'and the session takes its date and time');
$SESS[40][] = 702;
BFTD_Session_Appt::create_and_link(702, 40, $d(4), '16:00', 50, 5);
$b = end($BOOKED);
check(false === $b['quiet'] && 0 === $b['raw']['local'], 'for a later day it is an ordinary booking, waiting in the banner to be confirmed');
$SESS[40][] = 703;
$r = BFTD_Session_Appt::create_and_link(703, 40, $d(-1), '07:00', 50, 5);
check(is_wp_error($r) && '' === BFTD_Session_Appt::key_of(703), 'the Calendar\'s rules still apply, and nothing is linked when one says no');

echo "\nFrom the session screen's form:\n";
$SESS[40][] = 704;
$_POST = array('bftd_appt' => $d(3) . ' 10:00');
BFTD_Session_Appt::save_from_form(704);
check($d(3) . ' 10:00' === BFTD_Session_Appt::key_of(704), 'picking an appointment and saving links it');
$_POST = array('bftd_appt' => 'new', 'bftd_appt_new' => array('date' => $d(-1), 'time' => '07:00', 'minutes' => '50', 'tutor' => '5'));
BFTD_Session_Appt::save_from_form(703);
check('Rae already has a session then.' === get_transient('bftd_appt_err_1'), 'a new one that is refused says why on the screen');

echo "\nPublishing:\n";
$TR = array(); $_POST = array();
$pub = function ($id) { return (object) array('ID' => $id, 'post_status' => 'publish'); };
check(true === BFTD_Session_Appt::hold_unlinked(703, $pub(703)) && 'draft' === $STATUS[703] && 'unlinked|' === get_transient('bftd_held_1'), 'a session with no appointment is kept a draft, and told why');
check(false === BFTD_Session_Appt::hold_unlinked(704, $pub(704)), 'a linked one publishes');
$_POST = array('original_post_status' => 'publish');
check(false === BFTD_Session_Appt::hold_unlinked(703, $pub(703)), 'a session already published before this is left published');
$_POST = array();
check(false === BFTD_Session_Appt::hold_unlinked(703, (object) array('ID' => 703, 'post_status' => 'draft')), 'and a draft saved as a draft is just saved');

echo "\nPublishing a lesson already given, with no appointment (1.213.1):\n";
$TR = array(); $_POST = array();
$SESS[40][] = 705;
BFTD_Fields::set(705, 'session', 'session_date', $d(-3)); BFTD_Fields::set(705, 'session', 'session_time', '14:00'); BFTD_Fields::set(705, 'session', 'delivered_by', 5);
$n = count($BOOKED);
check(false === BFTD_Session_Appt::hold_unlinked(705, $pub(705)), 'it publishes');
$b = end($BOOKED);
check(count($BOOKED) === $n + 1 && true === $b['quiet'] && 1 === $b['raw']['local'] && '14:00' === $b['raw']['time'] && 5 === $b['raw']['tutor'],
  'with a new appointment at its own time and tutor, sent nowhere and announced to nobody');
check($d(-3) . ' 14:00' === BFTD_Session_Appt::key_of(705), 'and linked to it');
$OCC[] = $occ($d(-4), '16:00');
$SESS[40][] = 706;
BFTD_Fields::set(706, 'session', 'session_date', $d(-4)); BFTD_Fields::set(706, 'session', 'session_time', '15:30');
$n = count($BOOKED);
check(false === BFTD_Session_Appt::hold_unlinked(706, $pub(706)) && $d(-4) . ' 16:00' === BFTD_Session_Appt::key_of(706) && count($BOOKED) === $n,
  'when that day already has an appointment of the student\'s, it is linked rather than a second made');
$SESS[40][] = 707;
BFTD_Fields::set(707, 'session', 'session_date', $d(2)); BFTD_Fields::set(707, 'session', 'session_time', '10:00');
check(true === BFTD_Session_Appt::hold_unlinked(707, $pub(707)) && '' === BFTD_Session_Appt::key_of(707), 'a session for a later day still needs its appointment chosen');
$SESS[40][] = 708;
BFTD_Fields::set(708, 'session', 'session_date', $d(-1)); BFTD_Fields::set(708, 'session', 'session_time', '07:00');
check(true === BFTD_Session_Appt::hold_unlinked(708, $pub(708)) && 'Rae already has a session then.' === get_transient('bftd_appt_err_1'),
  'and one the Calendar\'s rules turn away stays a draft, saying why');

echo "\nThe wiring:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
$a = strpos($mb, 'BFTD_Session_Appt::save_from_form( $post_id );'); $h = strpos($mb, 'self::hold_duplicate_draft( $post_id, $post );');
check(false !== $a && $a < $h && false !== strpos($mb, 'BFTD_Session_Appt::hold_unlinked( $post_id, $post );'), 'the session screen links before the publish checks, and checks for the link');
check(false !== strpos($mb, 'BFTD_Session_Appt::render( $post );'), 'and shows Appointment in place of typed Date and Time');
$ss = file_get_contents(BFTD_PATH . 'includes/class-bftd-sessions.php');
check(false !== strpos($ss, 'BFTD_Session_Appt::hold_unlinked( $sid, $as_published )'), 'publishing from the sessions list checks it too');
$cv = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
check(false !== strpos($cv, "'session_url' => \$linked ?") && false !== strpos($cv, "'&appt=' . rawurlencode( \$o['key'] )"), 'the Calendar card opens the linked session, or records one already linked');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
