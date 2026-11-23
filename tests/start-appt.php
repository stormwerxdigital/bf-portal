<?php
/*
 * A student's start comes from an appointment on the Calendar (Karl, 1.217):
 * "since we have booking, start date should be tied to an appointment in the
 * calendar we can link to. if no calendar available, create a new
 * appointment draft". Made-up records only.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
$META = array(); $TR = array(); $OCC = array(); $BOOKED = array(); $SENT = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function set_transient($k, $v, $t = 0) { global $TR; $TR[$k] = $v; return true; }
function get_transient($k) { global $TR; return $TR[$k] ?? false; }
function delete_transient($k) { global $TR; unset($TR[$k]); return true; }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
class BFTD_CPT { const STUDENT = 'bftd_student'; }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_Schedule {
  public static function occurrences($s, $f, $t, $l = 200) { return array_values(array_filter($GLOBALS['OCC'], function ($o) use ($f, $t) { return $o['date'] >= $f && $o['date'] <= $t; })); }
  public static function add_booking($sid, $raw, $quiet = false) {
    $GLOBALS['BOOKED'][] = array('raw' => $raw, 'quiet' => $quiet);
    if ('07:00' === $raw['time']) return new WP_Error('bftd_booking', 'Rae already has a session then.');
    $GLOBALS['OCC'][] = array('key' => $raw['from'] . ' ' . $raw['time'], 'date' => $raw['from'], 'time' => $raw['time'], 'minutes' => 50, 'tutor' => 0, 'status' => 'booked', 'type' => 'tutoring');
    return array('from' => $raw['from'], 'time' => $raw['time']);
  }
}
class BFTD_CRM { public static function linked($s) { return 'rec'; } }
class BFTD_GHL { public static function configured() { return true; } public static function send_changes($s, $r) { $GLOBALS['SENT'][] = $s; } }
require BFTD_PATH . 'includes/class-bftd-start-appt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$T = BFTD_Time::today();
$d = function ($n) use ($T) { return BFTD_Time::add_days($T, $n); };
$occ = function ($date, $time, $type = 'tutoring', $status = 'booked') { return array('key' => "$date $time", 'date' => $date, 'time' => $time, 'minutes' => 50, 'tutor' => 5, 'status' => $status, 'type' => $type); };
$from = function () { return BFTD_Fields::get(9, 'student', 'attend_from'); };
$save = function ($post) { $_POST = $post; BFTD_Start_Appt::on_save(9, (object) array('post_type' => 'bftd_student', 'post_status' => 'publish')); };
$OCC = array($occ($d(-20), '10:00', 'assessment'), $occ($d(-13), '16:00'), $occ($d(-6), '16:00'), $occ($d(1), '16:00', 'tutoring', 'moved'), $occ($d(8), '16:00'));

echo "The appointments offered:\n";
$keys = array_column(BFTD_Start_Appt::choices(9), 'key');
check(array($d(-20) . ' 10:00', $d(-13) . ' 16:00', $d(-6) . ' 16:00', $d(8) . ' 16:00') === $keys, 'the student\'s appointments, oldest first, the reading assessment among them, not one moved away');

echo "\nLinking the start:\n";
$save(array('bftd_start_appt' => $d(-13) . ' 16:00'));
check($d(-13) === $from() && $d(-13) . ' 16:00' === BFTD_Start_Appt::key_of(9), 'picking an appointment makes its date Attending from');
check(in_array(9, $SENT, true), 'and sends the new start to GoHighLevel');
check(is_wp_error(BFTD_Start_Appt::link(9, $d(-3) . ' 09:00')), 'an appointment not on the schedule cannot be linked');

echo "\nNo appointment to link: a new one:\n";
$META = array(); $SENT = array();
$save(array('bftd_start_appt' => 'new', 'bftd_start_new' => array('date' => $d(-40), 'time' => '15:00', 'minutes' => '50', 'tutor' => '5')));
$b = end($BOOKED);
check($d(-40) === $from() && $d(-40) . ' 15:00' === BFTD_Start_Appt::key_of(9), 'a new appointment is made and linked, and is Attending from');
check(true === $b['quiet'] && 1 === $b['raw']['local'] && 'once' === $b['raw']['repeat'], 'an earlier day is kept here: no banner, no notice, not sent to GoHighLevel');
$META = array();
$save(array('bftd_start_appt' => 'new', 'bftd_start_new' => array('date' => $d(30), 'time' => '15:00')));
$b = end($BOOKED);
check(false === $b['quiet'] && 0 === $b['raw']['local'] && $d(30) === $from(), 'a later day is a draft booking, waiting in the banner to be confirmed');
$META = array();
$save(array('bftd_start_appt' => 'new', 'bftd_start_new' => array('date' => $d(-2), 'time' => '07:00')));
check('' === $from() && 'Rae already has a session then.' === get_transient('bftd_start_err_1'), 'the Calendar\'s rules apply, and a refusal says why, linking nothing');

echo "\nA start typed before 1.217:\n";
$META = array(); BFTD_Fields::set(9, 'student', 'attend_from', $d(-6));
$save(array('bftd_start_appt' => ''));
check($d(-6) . ' 16:00' === BFTD_Start_Appt::key_of(9), 'is linked on save to their appointment that day');
$META = array(); BFTD_Fields::set(9, 'student', 'attend_from', $d(-5));
$save(array('bftd_start_appt' => ''));
check('' === BFTD_Start_Appt::key_of(9) && $d(-5) === $from(), 'and with none that day, left as it was, offering the choice');

echo "\nThe wiring:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "if ( 'attend_from' === \$key && class_exists( 'BFTD_Start_Appt' ) ) { BFTD_Start_Appt::render_field( \$post, \$field ); continue; }"), 'History draws Attending from as the appointment choice');
$gh = file_get_contents(BFTD_PATH . 'includes/class-bftd-ghl.php');
check(false !== strpos($gh, "if ( ! class_exists( 'BFTD_Start_Appt' ) || '' === BFTD_Start_Appt::key_of( \$sid ) ) BFTD_Fields::set( \$sid, 'student', 'attend_from', \$sp['from'] );"), 'and GoHighLevel does not overwrite a start linked to the Calendar');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
