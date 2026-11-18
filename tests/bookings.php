<?php
/*
 * Sessions booked into GoHighLevel, booking phase 2 (1.189).
 *
 * The portal's schedule stays the one place a schedule is made; GoHighLevel
 * holds a copy, so it can send the reminders. Each upcoming session is an
 * appointment on its tutor's calendar, the chosen caregiver its contact, the
 * time sent with our offset written out. A change here changes it there: a
 * moved session keeps its appointment, a dropped one is cancelled. Nothing is
 * sent for a draft student, or before somebody is chosen to be reminded.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');

/* ---- GoHighLevel, stood in for ---- */
$GLOBALS['CALLS'] = array(); $GLOBALS['FAIL_PUT'] = false; $GLOBALS['DUP'] = array(); $GLOBALS['NEXT'] = 1;
function wp_remote_request($url, $args) {
  $m = $args['method']; $path = parse_url($url, PHP_URL_PATH); parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
  $body = isset($args['body']) ? json_decode($args['body'], true) : null;
  $GLOBALS['CALLS'][] = array($m, $path, $body, $q, $args['headers']['Version']);
  if ('POST' === $m && '/calendars/events/appointments' === $path && false !== strpos($body['startTime'] ?? '', 'T06:00')) return array('code' => 400, 'body' => json_encode(array('message' => 'The slot you have selected is no longer available.')));
  if ('POST' === $m && '/calendars/events/appointments' === $path) return array('code' => 201, 'body' => json_encode(array('id' => 'APPT' . $GLOBALS['NEXT']++)));
  if ('PUT' === $m && 0 === strpos($path, '/calendars/events/appointments/')) return $GLOBALS['FAIL_PUT'] ? array('code' => 422, 'body' => json_encode(array('message' => 'slot is not available'))) : array('code' => 200, 'body' => '{}');
  if ('GET' === $m && '/contacts/search/duplicate' === $path) { $id = $GLOBALS['DUP'][$q['email']] ?? ''; return array('code' => 200, 'body' => json_encode($id ? array('contact' => array('id' => $id)) : array('contact' => null))); }
  if ('DELETE' === $m && 0 === strpos($path, '/calendars/events/')) return !empty($GLOBALS['FAIL_DEL']) ? array('code' => 500, 'body' => json_encode(array('message' => 'try later'))) : (!empty($GLOBALS['GONE']) ? array('code' => 404, 'body' => '{}') : array('code' => 200, 'body' => '{"succeded":true}'));
  if ('POST' === $m && '/contacts/' === $path) return array('code' => 201, 'body' => json_encode(array('contact' => array('id' => 'NEWC'))));
  return array('code' => 404, 'body' => '{}');
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function calls($method, $prefix = '') { return array_values(array_filter($GLOBALS['CALLS'], function ($c) use ($method, $prefix) { return $c[0] === $method && 0 === strpos($c[1], $prefix); })); }

/* ---- WordPress, stood in for ---- */
$GLOBALS['META'] = array(); $GLOBALS['UMETA'] = array(); $GLOBALS['AUDIT'] = array(); $GLOBALS['STATUS'] = array(40 => 'publish'); $GLOBALS['TITLES'] = array(40 => 'Ana Lee');
function get_post_meta($id, $k = '', $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return $s ? (null === $v ? '' : $v) : (null === $v ? array() : (array) $v); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function get_user_meta($u, $k, $s = false) { return $GLOBALS['UMETA'][$u][$k] ?? ($s ? '' : array()); }
function update_user_meta($u, $k, $v) { $GLOBALS['UMETA'][$u][$k] = $v; return true; }
function get_post_type($id) { return isset($GLOBALS['STATUS'][$id]) ? 'bftd_student' : ''; }
function get_post_status($id) { return $GLOBALS['STATUS'][$id] ?? false; }
function get_post_field($f, $id) { return $GLOBALS['TITLES'][$id] ?? ''; }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function add_action(...$a) {} function get_transient($k) { return false; } function set_transient(...$a) { return true; }
function get_option($k, $d = false) { return $d; }
function wp_is_post_revision($id) { return false; } function wp_is_post_autosave($id) { return false; }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim(strip_tags((string) $v)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); } }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
$GLOBALS['USERS'] = array(
  7 => (object) array('ID' => 7, 'user_email' => 'mum@example.test', 'display_name' => 'Mum Lee', 'first_name' => 'Mum', 'last_name' => 'Lee'),
  8 => (object) array('ID' => 8, 'user_email' => 'dad@example.test', 'display_name' => 'Dad Lee', 'first_name' => 'Dad', 'last_name' => 'Lee'),
  9 => (object) array('ID' => 9, 'user_email' => '', 'display_name' => 'Gran Lee', 'first_name' => 'Gran', 'last_name' => 'Lee'),
  5 => (object) array('ID' => 5, 'user_email' => 'laurel@example.test', 'display_name' => 'Laurel Sanders'),
  6 => (object) array('ID' => 6, 'user_email' => 'jo@example.test', 'display_name' => 'Jo NoCalendar'),
);
function get_userdata($id) { return $GLOBALS['USERS'][(int) $id] ?? false; }
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e . ': ' . $a['summary']; } }
class BFTD_CPT { const STUDENT = 'bftd_student'; public static function client_ids($s) { return array(7, 8, 9); } }
class BFTD_Fields { public static function get($id, $g, $k, $f = array()) { return 'join_url' === $k ? 'https://meet.example.test/ana' : ''; } }
$GLOBALS['OCC'] = array();
class BFTD_Schedule { const RULES_KEY = '_bftd_schedule_rules'; public static function occurrences($s, $from = '', $to = '', $l = 200) { $GLOBALS['OCC_RANGE'] = array($from, $to); return $GLOBALS['OCC']; } }
$GLOBALS['OFFSET'] = -420;
class BFTD_Calendars {
  public static function offset() { return $GLOBALS['OFFSET']; }
  public static function zone_offset($z, $at = null) { return -480; }
  public static function calendar_for($uid) { return 5 === (int) $uid ? 'CAL-L' : ''; }
  public static function read() { return array('ok' => true, 'calendars' => array('CAL-L' => array('members' => array('U-L'))), 'users' => array()); }
}
require BFTD_PATH . 'includes/class-bftd-ghl.php';
require BFTD_PATH . 'includes/class-bftd-bookings.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function occ($date, $time, $status = 'booked', $tutor = 5, $minutes = 50, $from = '') { $o = array('key' => "$date $time", 'date' => $date, 'time' => $time, 'minutes' => $minutes, 'tutor' => $tutor, 'status' => $status, 'reason' => ''); if ($from) $o['from_key'] = $from; return $o; }
function run() { $GLOBALS['CALLS'] = array(); return BFTD_Bookings::sync_student(40); }

/* ---- times, with our offset written out ---- */
check('2026-11-17T16:00:00-07:00' === BFTD_Bookings::iso('2026-11-17', '16:00'), 'a session at four is sent as 16:00 at UTC-7, got ' . BFTD_Bookings::iso('2026-11-17', '16:00'));
check('2026-11-17T16:50:00-07:00' === BFTD_Bookings::iso('2026-11-17', '16:00', 50) && '2026-11-18T00:20:00-07:00' === BFTD_Bookings::iso('2026-11-17', '23:30', 50), 'and its end, even past midnight');
$GLOBALS['OFFSET'] = null;
check('2026-11-17T16:00:00-08:00' === BFTD_Bookings::iso('2026-11-17', '16:00'), 'with no offset set on Calendars, the site\'s own time zone is used');
$GLOBALS['OFFSET'] = -420;

/* ---- who is reminded ---- */
check(true === BFTD_Bookings::save_prefs(40, array('notify' => 99, 'location' => '', 'notes' => 'Bring the reader')) && 0 === BFTD_Bookings::prefs(40)['notify'], 'only one of the student\'s own caregivers can be the one reminded');
check(false === BFTD_Bookings::save_prefs(40, array('notify' => 99, 'location' => '', 'notes' => 'Bring the reader')), 'and saving the same again changes nothing');

$T = BFTD_Time::today();
foreach (array('D1' => 1, 'D2' => 2, 'D8' => 8, 'D9' => 9, 'D15' => 15, 'P6' => -6, 'P15' => -15) as $n => $d) $$n = BFTD_Time::add_days($T, $d);

/* ---- nothing sent too soon ---- */
$GLOBALS['OCC'] = array(occ($D1, '16:00'), occ($D8, '16:00'), occ($P6, '16:00', 'held'), occ($D15, '16:00', 'cancelled'));
$s = run();
check(array() === $GLOBALS['CALLS'] && false !== strpos($s['error'], 'choose who is reminded'), 'with nobody chosen to be reminded, nothing is sent, and it says why');
BFTD_Bookings::save_prefs(40, array('notify' => 7, 'location' => '', 'notes' => ''));
$GLOBALS['STATUS'][40] = 'draft';
$s = run();
check(array() === $GLOBALS['CALLS'] && false !== strpos($s['error'], 'draft'), 'a draft student sends nothing: nothing reaches a family about a draft');
$GLOBALS['STATUS'][40] = 'publish';

/* ---- the caregiver as a contact ---- */
$GLOBALS['META'][40][BFTD_GHL::PEOPLE_KEY] = array('contacts' => array(array('id' => 'C-MUM', 'email' => 'mum@example.test')), 'staff' => array());
$GLOBALS['CALLS'] = array();
check('C-MUM' === BFTD_Bookings::contact_for(40, 7) && array() === $GLOBALS['CALLS'], 'a caregiver GoHighLevel already links to the student is that contact, with nothing asked');
$GLOBALS['CALLS'] = array(); $GLOBALS['DUP']['dad@example.test'] = 'C-DAD';
check('C-DAD' === BFTD_Bookings::contact_for(40, 8) && array() === calls('POST'), 'one with the same email in GoHighLevel is found, not made again');
$GLOBALS['CALLS'] = array(); unset($GLOBALS['UMETA'][8]); unset($GLOBALS['DUP']['dad@example.test']);
$c = BFTD_Bookings::contact_for(40, 8);
$made = calls('POST', '/contacts/');
check('NEWC' === $c && 1 === count($made) && 'dad@example.test' === $made[0][2]['email'] && 'LOC42' === $made[0][2]['locationId'], 'one GoHighLevel does not have is added as a contact');
check(false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'Dad Lee was added to GoHighLevel as a contact'), 'and that is in the history');
$GLOBALS['CALLS'] = array();
check('NEWC' === BFTD_Bookings::contact_for(40, 8) && array() === $GLOBALS['CALLS'], 'and remembered, so it is not looked up again');
check(is_wp_error(BFTD_Bookings::contact_for(40, 9)), 'a caregiver with no email cannot be reminded, and says so');

/* ---- booking ---- */
$s = run();
$posts = calls('POST', '/calendars/events/appointments');
check(2 === count($posts) && 2 === $s['made'], 'each booked session ahead is booked in GoHighLevel, and only those, got ' . count($posts));
$b = $posts[0][2];
check('CAL-L' === $b['calendarId'] && 'C-MUM' === $b['contactId'] && 'U-L' === $b['assignedUserId'] && 'LOC42' === $b['locationId'], 'on the tutor\'s calendar, with the caregiver as the contact and the tutor assigned');
check($D1 . 'T16:00:00-07:00' === $b['startTime'] && $D1 . 'T16:50:00-07:00' === $b['endTime'], 'at the session\'s time, our offset written out');
check('Reading session: Ana Lee' === $b['title'] && 'https://meet.example.test/ana' === $b['address'] && 'confirmed' === $b['appointmentStatus'], 'with the student\'s name, and the joining link when no place is typed');
check(true === $b['toNotify'], 'GoHighLevel tells the contact about the next session');
check(false === $b['ignoreFreeSlotValidation'] && false === $b['ignoreDateRange'], 'and GoHighLevel\'s availability and booking rules decide whether the time can be booked (1.192)');
check(false === $posts[1][2]['toNotify'], 'the sessions after it are booked quietly, so turning reminders on is one confirmation, not one per session (1.191)');
check('2021-04-15' === $posts[0][4] && array($T, BFTD_Time::add_days($T, 60)) === $GLOBALS['OCC_RANGE'], 'sixty days ahead, with the calendar API\'s version');
check(false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'Sessions in GoHighLevel: 2 booked.'), 'and the history says so');

$s = run();
check(array() === $GLOBALS['CALLS'], 'a second sync with nothing changed sends nothing');

/* ---- changes ---- */
BFTD_Bookings::save_prefs(40, array('notify' => 7, 'location' => '12 Oak St', 'notes' => ''));
$s = run();
$puts = calls('PUT');
check(2 === count($puts) && '12 Oak St' === $puts[0][2]['address'] && '/calendars/events/appointments/APPT1' === $puts[0][1], 'a new place changes the appointments already made, not new ones');
check(true === $puts[0][2]['toNotify'] && true === $puts[1][2]['toNotify'], 'and a change to a session is always told');

$GLOBALS['OCC'] = array(occ($D1, '16:00', 'moved'), occ($D2, '17:00', 'booked', 5, 50, $D1 . ' 16:00'), occ($D8, '16:00'));
$s = run();
$puts = calls('PUT'); $posts = calls('POST', '/calendars/events/appointments');
check(1 === count($puts) && '/calendars/events/appointments/APPT1' === $puts[0][1] && $D2 . 'T17:00:00-07:00' === $puts[0][2]['startTime'] && array() === $posts, 'a rescheduled session moves its own appointment, so the family hears it moved, not cancelled and booked again');
check(1 === $s['moved'] && 0 === $s['cancelled'], 'and is counted as changed');

$GLOBALS['OCC'] = array(occ($D8, '16:00'));
$s = run();
$puts = calls('PUT');
check(1 === count($puts) && array('appointmentStatus' => 'cancelled') === $puts[0][2] && '/calendars/events/appointments/APPT1' === $puts[0][1], 'a session no longer on the schedule is cancelled in GoHighLevel');

$GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$P15 . ' 16:00'] = array('id' => 'OLD', 'calendar' => 'CAL-L');
$s = run();
check(array() === $GLOBALS['CALLS'] && !isset($GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$P15 . ' 16:00']), 'one already past is let go, not cancelled after the fact');

BFTD_Bookings::save_prefs(40, array('notify' => 8, 'location' => '12 Oak St', 'notes' => ''));
$s = run();
$puts = calls('PUT');
check(1 === count($puts) && 'NEWC' === $puts[0][2]['contactId'], 'choosing someone else to remind changes the appointment\'s contact');

/* ---- a tutor with no calendar ---- */
$GLOBALS['OCC'] = array(occ($D8, '16:00'), occ($D9, '16:00', 'booked', 6));
$s = run();
check(array() === calls('POST', '/calendars/events/appointments') && 'Jo NoCalendar has no GoHighLevel calendar linked' === ($s['waiting'][$D9 . ' 16:00'] ?? ''), 'a session whose tutor has no calendar is not sent, and says why');
$mark = BFTD_Bookings::occ_mark(40, occ($D9, '16:00', 'booked', 6));
check(false !== strpos($mark, 'Not in GoHighLevel: Jo NoCalendar has no GoHighLevel calendar linked') && false !== strpos(BFTD_Bookings::occ_mark(40, occ($D8, '16:00')), 'In GoHighLevel'), 'the session list says which sessions GoHighLevel has, and why not');
$GLOBALS['OCC'] = array(occ($D8, '16:00'), occ($D9, '06:00'));
$s = run();
check('GoHighLevel would not book it: The slot you have selected is no longer available.' === ($s['waiting'][$D9 . ' 06:00'] ?? ''), 'a time GoHighLevel\'s availability turns away is not booked, and that session says why, got ' . json_encode($s['waiting']));
check(false !== strpos(BFTD_Bookings::occ_mark(40, occ($D9, '06:00')), 'Not in GoHighLevel: GoHighLevel would not book it'), 'in the session list too');
$GLOBALS['FAIL_PUT'] = true;
$GLOBALS['OCC'] = array(occ($D8, '16:00', 'moved'), occ($D9, '17:00', 'booked', 5, 50, $D8 . ' 16:00'));
$s = run();
check(0 === strpos($s['waiting'][$D9 . ' 17:00'] ?? '', 'GoHighLevel would not move it: slot is not available'), 'and a move it turns away says so on the session it was moved to');
$GLOBALS['FAIL_PUT'] = false;
$GLOBALS['OCC'] = array(occ($D8, '16:00'));
run();
/* A custom schedule: booked outside the tutor's GoHighLevel hours (1.192). */
$pp = BFTD_Bookings::prefs(40);
BFTD_Bookings::save_prefs(40, array_merge($pp, array('custom' => '1')));
check(1 === BFTD_Bookings::prefs(40)['custom'], 'a student can be put on a custom schedule');
$GLOBALS['OCC'] = array(occ($D8, '16:00'), occ($D15, '07:30'));
$s = run();
$cp = calls('POST', '/calendars/events/appointments');
check(1 === count($cp) && true === $cp[0][2]['ignoreFreeSlotValidation'] && true === $cp[0][2]['ignoreDateRange'], 'and is then booked whatever GoHighLevel\'s hours say');
BFTD_Bookings::save_prefs(40, array_merge($pp, array('custom' => '')));
check(0 === BFTD_Bookings::prefs(40)['custom'], 'and taken off it again, back to GoHighLevel\'s availability');
$GLOBALS['OCC'] = array(occ($D8, '16:00'));
run();
$GLOBALS['FAIL_PUT'] = false;
$GLOBALS['OCC'] = array(occ($D8, '16:00'));
run();

/* ---- one-time sessions, and deleting quietly (1.201) ---- */
$GLOBALS['OCC'] = array(occ($D8, '16:00'), array_merge(occ($D15, '07:30'), array('once' => true)));
$s = run();
$cp = calls('POST', '/calendars/events/appointments');
check(1 === count($cp) && true === $cp[0][2]['ignoreFreeSlotValidation'] && true === $cp[0][2]['ignoreDateRange'], 'a one-time session is booked at the time picked, whatever GoHighLevel\'s hours say');
$id15 = $GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$D15 . ' 07:30']['id'];
$GLOBALS['CALLS'] = array();
$r = BFTD_Bookings::delete_quietly(40, occ($D15, '07:30'));
$del = calls('DELETE');
check(true === $r && 1 === count($del) && '/calendars/events/' . $id15 === $del[0][1] && array() === calls('PUT') && array() === calls('POST'), 'deleting a session deletes its appointment in GoHighLevel, with no status change and nothing sent');
check(!isset($GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$D15 . ' 07:30']) && isset($GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$D8 . ' 16:00']), 'and only that one is let go');
$GLOBALS['CALLS'] = array();
check(true === BFTD_Bookings::delete_quietly(40, occ($D9, '16:00')) && array() === $GLOBALS['CALLS'], 'one never sent to GoHighLevel needs nothing asked of it');
$id8 = $GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$D8 . ' 16:00']['id'];
$GLOBALS['FAIL_DEL'] = true; $GLOBALS['CALLS'] = array();
$r = BFTD_Bookings::delete_quietly(40, occ($D9, '17:00', 'booked', 5, 50, $D8 . ' 16:00'));
check(is_wp_error($r) && isset($GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$D8 . ' 16:00']) && '/calendars/events/' . $id8 === calls('DELETE')[0][1], 'a move not yet sent is found under the date it left; and if GoHighLevel will not delete it, it is kept, and says so');
$GLOBALS['FAIL_DEL'] = false; $GLOBALS['GONE'] = true;
check(true === BFTD_Bookings::delete_quietly(40, occ($D8, '16:00')) && !isset($GLOBALS['META'][40][BFTD_Bookings::MAP_KEY][$D8 . ' 16:00']), 'one already gone from GoHighLevel counts as deleted');
$GLOBALS['GONE'] = false;
$GLOBALS['OCC'] = array(occ($D8, '16:00'));
run();

/* ---- GoHighLevel refusing ---- */
$GLOBALS['FAIL_PUT'] = true;
BFTD_Bookings::save_prefs(40, array('notify' => 8, 'location' => 'Library', 'notes' => ''));
$s = run();
check(false !== strpos($s['error'], 'GoHighLevel did not take 1 of the changes: slot is not available'), 'a change GoHighLevel will not take is said, with its reason');
$GLOBALS['FAIL_PUT'] = false;
$s = run();
check(1 === count(calls('PUT')) && '' === $s['error'], 'and is tried again next time');

/* ---- nobody to remind any more, and the trash ---- */
BFTD_Bookings::save_prefs(40, array('notify' => 0, 'location' => 'Library', 'notes' => ''));
$s = run();
check(1 === count(calls('PUT')) && 'cancelled' === calls('PUT')[0][2]['appointmentStatus'] && array() === $GLOBALS['META'][40][BFTD_Bookings::MAP_KEY], 'with nobody to remind, the appointments are cancelled, since an appointment needs a contact');
BFTD_Bookings::save_prefs(40, array('notify' => 7, 'location' => 'Library', 'notes' => ''));
run();
$GLOBALS['STATUS'][40] = 'trash';
$s = run();
check(1 === count(calls('PUT')) && 'cancelled' === calls('PUT')[0][2]['appointmentStatus'] && array() === $GLOBALS['META'][40][BFTD_Bookings::MAP_KEY], 'a student in the trash has their sessions ahead cancelled in GoHighLevel when the change is sent (1.200)');
$GLOBALS['STATUS'][40] = 'publish';

/* ---- where it is wired ---- */
$sc = file_get_contents(BFTD_PATH . 'includes/class-bftd-schedule.php');
check(false !== strpos($sc, "do_action( 'bftd_schedule_changed', \$student_id );") && false !== strpos($sc, 'BFTD_Bookings::occ_mark( $student_id, $o )'), 'cancelling or moving a session sends it on, and the list shows it');
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "BFTD_Bookings::save_prefs( \$post_id, wp_unslash( \$_POST['bftd_booking'] ) )") && false !== strpos($mb, "'bftd_booking' );") && false !== strpos($mb, 'BFTD_Bookings::render_box( $post );'), 'the student\'s form saves who is reminded, in the schedule box');
$bk = file_get_contents(BFTD_PATH . 'includes/class-bftd-bookings.php');
$sw = file_get_contents(BFTD_PATH . 'includes/class-bftd-send-window.php');
check(false !== strpos($sw, "add_action( 'save_post', array( __CLASS__, 'on_save' ), 100, 2 );") && false === strpos($sw, "add_action( 'save_post_'"), 'a change is noted after the student\'s form is saved, so the choice just made is the one held (save_post_bftd_student runs before it)');
check(false !== strpos($sw, "if ( ! \$post || BFTD_CPT::STUDENT !== \$post->post_type ) return;"), 'and only for students');
check(false === strpos($bk, "add_action("), 'and nothing in the mirror sends by itself any more: only on confirm, in hours (1.200)');
check(1 === preg_match_all("/request\\( 'DELETE'/", $bk) && false !== strpos($bk, 'public static function delete_quietly('), 'a session is only deleted from GoHighLevel when somebody deletes it; one dropped from the schedule is cancelled, so its history stays (1.201)');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
