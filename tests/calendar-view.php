<?php
/*
 * The calendar, by day, week or month (1.193).
 *
 * Managers see every tutor; a tutor sees only their own sessions, whatever
 * they ask for. What is shown is the portal's schedule, with appointments
 * made in GoHighLevel itself alongside, in our wall time and never twice.
 * Sessions are changed through the schedule's own action; a GoHighLevel
 * appointment only by a manager or the tutor whose calendar it is on, and
 * never one the portal made.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');

$GLOBALS['CALLS'] = array(); $GLOBALS['GHL_EVENTS'] = array(); $GLOBALS['APPT'] = array();
function wp_remote_request($url, $args) {
  $m = $args['method']; $path = parse_url($url, PHP_URL_PATH); parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
  $GLOBALS['CALLS'][] = array($m, $path, isset($args['body']) ? json_decode($args['body'], true) : null, $q);
  if ('GET' === $m && '/calendars/events' === $path) return array('code' => 200, 'body' => json_encode(array('events' => $GLOBALS['GHL_EVENTS'][$q['calendarId']] ?? array())));
  if ('GET' === $m && 0 === strpos($path, '/calendars/events/appointments/')) { $id = substr($path, strlen('/calendars/events/appointments/')); return isset($GLOBALS['APPT'][$id]) ? array('code' => 200, 'body' => json_encode(array('appointment' => $GLOBALS['APPT'][$id]))) : array('code' => 404, 'body' => json_encode(array('message' => 'Not found'))); }
  if ('PUT' === $m) return array('code' => 200, 'body' => '{}');
  return array('code' => 404, 'body' => '{}');
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function calls($m) { return array_values(array_filter($GLOBALS['CALLS'], function ($c) use ($m) { return $c[0] === $m; })); }

$GLOBALS['META'] = array(); $GLOBALS['T'] = array(); $GLOBALS['OPT'] = array(); $GLOBALS['AUDIT'] = array();
function get_post_meta($id, $k = '', $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return $s ? (null === $v ? '' : $v) : (null === $v ? array() : (array) $v); }
function get_post_field($f, $id) { return array(40 => 'Ana Lee', 41 => 'Ben Ray')[$id] ?? ''; }
function get_post_status($id) { return 41 === $id ? 'draft' : 'publish'; }
function get_edit_post_link($id, $c = '') { return '/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; } }
function add_action(...$a) {}
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim(strip_tags((string) $v)); } }
$GLOBALS['STAFF'] = array(4 => 'Laurel Sanders', 6 => 'Candice Paluck');
$GLOBALS['MANAGERS'] = array(4);
function get_userdata($id) { return isset($GLOBALS['STAFF'][$id]) ? (object) array('ID' => $id, 'display_name' => $GLOBALS['STAFF'][$id]) : false; }
function user_can($u, $cap, ...$a) { return 'edit_post' === $cap ? (in_array((int) $u, $GLOBALS['MANAGERS'], true) || (6 === (int) $u && 40 === (int) ($a[0] ?? 0))) : false; }
class BFTD_Roles {
  const STAFF_CAP = 'bftd_manage_students';
  public static function can_manage($u = null) { return in_array((int) $u, $GLOBALS['MANAGERS'], true); }
  public static function is_staff($u = null) { return isset($GLOBALS['STAFF'][(int) $u]); }
  public static function is_administrator($u = null) { return in_array((int) $u, $GLOBALS['ADMINS'] ?? array(), true); }
  public static function staff_users() { $o = array(); foreach ($GLOBALS['STAFF'] as $id => $n) $o[] = (object) array('ID' => $id, 'display_name' => $n); return $o; }
}
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e . ': ' . $a['summary']; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
$GLOBALS['OCC'] = array();
class BFTD_Schedule {
  public static function clean_type($t) { return 'assessment' === $t ? 'assessment' : 'tutoring'; }
  public static function type_label($t) { return 'assessment' === $t ? 'Reading assessment' : 'Tutoring session'; }
  public static function closed_on($d) { return '7' === gmdate('N', strtotime($d . ' 12:00:00 UTC')) ? 'Sunday' : ''; }
  public static function scheduled_students() { return array(40, 41); }
  public static function occurrences($sid, $from = '', $to = '', $l = 200) { return $GLOBALS['OCC'][$sid] ?? array(); }
  public static function minutes_of($hhmm) { $p = explode(':', $hhmm); return (int) $p[0] * 60 + (int) $p[1]; }
}
class BFTD_Bookings {
  const MAP_KEY = '_bftd_ghl_appts'; const VERSION = '2021-04-15';
  public static function offset_on($d) { return -420; }
  public static function iso($date, $hhmm, $add = 0) { $l = strtotime($date . ' ' . $hhmm . ':00 UTC') + 60 * $add; return gmdate('Y-m-d\TH:i:s', $l) . '-07:00'; }
}
class BFTD_Calendars {
  public static function links() { return array('CAL-L' => 4, 'CAL-C' => 6); }
  public static function calendar_for($u) { $c = array_search((int) $u, self::links(), true); return false === $c ? '' : $c; }
}
require BFTD_PATH . 'includes/class-bftd-ghl.php';
require BFTD_PATH . 'includes/class-bftd-calendar-view.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function o($date, $time, $tutor, $status = 'booked', $min = 50) { return array('key' => "$date $time", 'date' => $date, 'time' => $time, 'minutes' => $min, 'tutor' => $tutor, 'status' => $status); }

/* ---- who sees whom ---- */
check(array(4 => 'Laurel Sanders', 6 => 'Candice Paluck') === BFTD_Calendar_View::tutors_for(4), 'a manager sees every tutor');
$GLOBALS['STAFF'][1] = 'karlsanders'; $GLOBALS['ADMINS'] = array(1);
check(array(4 => 'Laurel Sanders', 6 => 'Candice Paluck') === BFTD_Calendar_View::tutors_for(4), 'administrators are not on the tutor list (Karl, 1.212), senior managers are');
unset($GLOBALS['STAFF'][1]); $GLOBALS['ADMINS'] = array();
check(array(6 => 'Candice Paluck') === BFTD_Calendar_View::tutors_for(6), 'a tutor sees only themselves');

$GLOBALS['OCC'][40] = array(o('2026-11-18', '16:00', 6), o('2026-11-19', '16:00', 6, 'held'), o('2026-11-20', '16:00', 6, 'cancelled'), o('2026-11-21', '16:00', 6, 'moved'), o('2026-11-30', '16:00', 6));
$GLOBALS['OCC'][41] = array(o('2026-11-18', '09:00', 4, 'booked', 30));
$GLOBALS['META'][40]['_bftd_ghl_appts'] = array('2026-11-18 16:00' => array('id' => 'PORTAL1'));

$tut = BFTD_Calendar_View::events('2026-11-15', '2026-11-21', array(4, 6), 6);
check(array('Ana Lee') === array_values(array_unique(array_column($tut, 'title'))), 'a tutor asking for everyone still gets only their own sessions');

$GLOBALS['OCC'][41][] = o('2026-11-20', '11:00', 6);
$cover = array_values(array_filter(BFTD_Calendar_View::events('2026-11-15', '2026-11-21', array(), 6), function ($e) { return 'Ben Ray' === $e['title']; }));
check(1 === count($cover) && true === $cover[0]['can_edit'], 'a tutor can change a session they teach on a student they are not assigned to (1.193)');
array_pop($GLOBALS['OCC'][41]);

/* ---- what a manager sees ---- */
$GLOBALS['GHL_EVENTS']['CAL-L'] = array(
  array('id' => 'G1', 'title' => 'Reading assessment: Pat', 'startTime' => '2026-11-19T17:00:00Z', 'endTime' => '2026-11-19T18:00:00Z', 'appointmentStatus' => 'confirmed'),
  array('id' => 'G2', 'title' => 'Gone', 'startTime' => '2026-11-19T19:00:00Z', 'endTime' => '2026-11-19T20:00:00Z', 'appointmentStatus' => 'cancelled'),
);
$GLOBALS['GHL_EVENTS']['CAL-C'] = array(
  array('id' => 'PORTAL1', 'title' => 'Reading session: Ana Lee', 'startTime' => '2026-11-18T23:00:00Z', 'endTime' => '2026-11-18T23:50:00Z', 'appointmentStatus' => 'confirmed'),
);
$GLOBALS['T'] = array();   // what the tutor's view above read is not this one's
$ev = BFTD_Calendar_View::events('2026-11-15', '2026-11-21', array(), 4);
$by = array(); foreach ($ev as $e) $by[$e['kind'] . ' ' . $e['date'] . ' ' . $e['start']] = $e;
check(isset($by['session 2026-11-18 09:00'], $by['session 2026-11-18 16:00'], $by['session 2026-11-19 16:00'], $by['session 2026-11-20 16:00']), 'every tutor\'s booked, taught and cancelled sessions, got ' . json_encode(array_keys($by)));
check(!isset($by['session 2026-11-30 16:00']), 'not one outside the range');
$mv = $by['session 2026-11-21 16:00'] ?? array();
check('moved' === ($mv['status'] ?? '') && false === $mv['can_edit'], 'where a rescheduled session was is sent too, marked rescheduled and not editable, for the Rescheduled filter (1.203)');
check('Tutoring session' === $by['session 2026-11-18 16:00']['type_label'], 'each session says what type it is');
$cl = BFTD_Calendar_View::closed('2026-11-15', '2026-11-21');
check(array('2026-11-15' => 'Sunday') === $cl, 'the days nothing can be booked on come with the range, so Sundays are greyed out');
check('16:50' === $by['session 2026-11-18 16:00']['end'] && '09:30' === $by['session 2026-11-18 09:00']['end'], 'each with its end, from its length');
check(true === $by['session 2026-11-18 16:00']['in_ghl'] && true === $by['session 2026-11-18 09:00']['draft'], 'saying which are in GoHighLevel, and which student is a draft');
check(true === $by['session 2026-11-18 16:00']['can_edit'] && false === $by['session 2026-11-19 16:00']['can_edit'] && false === $by['session 2026-11-20 16:00']['can_edit'], 'a booked session can be changed; a taught or cancelled one cannot');
check(isset($by['ghl 2026-11-19 10:00']) && '11:00' === $by['ghl 2026-11-19 10:00']['end'] && 4 === $by['ghl 2026-11-19 10:00']['tutor'], 'an appointment made in GoHighLevel is shown on its tutor, at our time (17:00 UTC is 10:00 here)');
check(1 === count(array_filter($ev, function ($e) { return 'ghl' === $e['kind']; })), 'a cancelled one is not, and one the portal made is not shown twice');
check(true === $by['ghl 2026-11-19 10:00']['can_edit'], 'a manager can change a GoHighLevel appointment');
$tutG = BFTD_Calendar_View::ghl_events(array(4), '2026-11-15', '2026-11-21', 6);
check(false === $tutG[0]['can_edit'], 'a tutor cannot change one on another tutor\'s calendar');
$n = count(calls('GET')); BFTD_Calendar_View::events('2026-11-15', '2026-11-21', array(), 4);
check($n === count(calls('GET')), 'GoHighLevel is asked again only after five minutes');

/* ---- changing a GoHighLevel appointment ---- */
$GLOBALS['APPT']['G1'] = array('id' => 'G1', 'calendarId' => 'CAL-L', 'title' => 'Reading assessment: Pat', 'startTime' => '2026-11-19T10:00:00-07:00', 'endTime' => '2026-11-19T11:00:00-07:00');
$GLOBALS['APPT']['PORTAL1'] = array('id' => 'PORTAL1', 'calendarId' => 'CAL-C', 'startTime' => '2026-11-18T16:00:00-07:00', 'endTime' => '2026-11-18T16:50:00-07:00');
$r = BFTD_Calendar_View::change_ghl('G1', 'move', '2026-11-20', '13:30', 6);
check(is_wp_error($r) && array() === calls('PUT'), 'a tutor cannot move one on another tutor\'s calendar');
$r = BFTD_Calendar_View::change_ghl('PORTAL1', 'cancel', '', '', 4);
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'student') && array() === calls('PUT'), 'one the portal made is changed on the student\'s schedule, not here');
$r = BFTD_Calendar_View::change_ghl('G1', 'move', '2026-11-20', '13:30', 4);
$put = calls('PUT');
check(true === $r && 1 === count($put) && '2026-11-20T13:30:00-07:00' === $put[0][2]['startTime'] && '2026-11-20T14:30:00-07:00' === $put[0][2]['endTime'], 'a manager moves it, and it keeps its hour');
check(false === $put[0][2]['ignoreFreeSlotValidation'] && 'CAL-L' === $put[0][2]['calendarId'], 'following GoHighLevel\'s availability, on its own calendar');
check(1 === (int) get_option('bftd_cal_ghl_v') && false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'was moved to 2026-11-20 13:30 from the calendar'), 'the calendar reads it fresh, and it is in the history');
$GLOBALS['CALLS'] = array();
$r = BFTD_Calendar_View::change_ghl('G1', 'cancel', '', '', 4);
check(true === $r && array('appointmentStatus' => 'cancelled') === calls('PUT')[0][2], 'and cancels it, never deletes it');
$r = BFTD_Calendar_View::change_ghl('G1', 'move', 'soon', '', 4);
check(is_wp_error($r), 'a move needs a real date and time');

/* ---- where it is wired ---- */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
check(false !== strpos($src, "add_submenu_page( BFTD_Admin::MENU_SLUG, 'Calendar', 'Calendar', BFTD_Roles::STAFF_CAP, self::SLUG,") && false !== strpos($src, "<?php echo \$manage ? 'Calendar' : 'My schedule'; ?>"), 'a Calendar screen for staff, called My schedule for a tutor');
check(substr_count($src, "check_ajax_referer( 'bftd_nonce', 'nonce' );") === 3 && false !== strpos($src, 'const MAX_DAYS = 42;'), 'all three actions are signed, and a range is at most six weeks');
check(false !== strpos($src, "! user_can( \$viewer, 'edit_post', \$sid )") && false !== strpos($src, "if ( \$tutor && ( ! BFTD_Roles::can_manage( \$viewer ) || ! BFTD_Roles::is_staff( \$tutor ) ) ) \$tutor = 0;"), 'a booking from the calendar is only for a student this person can edit, and only a manager picks another tutor');
check(false !== strpos($src, 'class="button button-primary bftd-calv-addbtn"') && false !== strpos($src, '<form id="bftd-calv-add" class="bftd-calv-add" hidden>') && false !== strpos($src, 'class="bftd-calv-showmoved"'), 'Add a booking sits in the side panel, opening in place, and Rescheduled is a filter beside Cancelled');
check(false !== strpos($src, 'class="bftd-calv-showghl" checked> Scheduled</label>') && false !== strpos($src, 'class="bftd-calv-showdraft" checked> Drafts</label>') && false !== strpos(file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js'), "if ( e.draft && ! showDraft.checked ) return false;"), 'the filters read Scheduled, and Drafts shows or hides the bookings of draft students');
$cjs = file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js');
check(false !== strpos($cjs, "esc( clock( e.start ) ) + ( e.type ? '<span class=\"bftd-calv-ev-y\"> &middot; ' + ( 'assessment' === e.type ? 'Assessment' : 'Tutoring' )") && false !== strpos($cjs, "C.manage && who && shownTutors().length > 1"), 'a booking reads time and type on one line, then the student, and names the tutor only when several are shown (1.203.1, live bookings were cut off)');
check(false !== strpos($cjs, "if ( need > 1 ) drawTimed( days, Math.min( 400, Math.ceil( HOUR * need ) ) );") && false !== strpos($cjs, "el.scrollHeight > el.clientHeight + 1"), 'and an hour grows until every booking in view fits, whatever the text size (1.203.2, still cut off on live in accessibility mode)');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js');
check(false !== strpos($js, "action: 'bftd_schedule_except'") && false !== strpos($js, "action: 'bftd_calendar_ghl'") && false !== strpos($js, "action: 'bftd_calendar_events'"), 'the screen changes sessions through the schedule\'s own action');
foreach (array("'day' === state.view", "'week' === state.view", 'drawMonth') as $v) check(false !== strpos($js, $v), 'and draws ' . $v);
$sc = file_get_contents(BFTD_PATH . 'includes/class-bftd-schedule.php');
check(false !== strpos($sc, "( isset( \$_POST['tutor'] ) && BFTD_Roles::can_manage() && BFTD_Roles::is_staff( absint( \$_POST['tutor'] ) ) ) ? absint( \$_POST['tutor'] ) : 0"), 'another tutor for one session is a manager\'s change, and only to a staff account');

check(false !== strpos(file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js'), "if ( ( 'ghl' === e.kind || e.in_ghl ) && ! showGhl.checked ) return false;"), 'Scheduled shows or hides everything in the CRM: bookings the portal sent there and appointments made there (1.205)');
check(false !== strpos($src, '<span class="bftd-calv-k is-ghl">Made in CRM</span>') && false === strpos($src, 'Made in GoHighLevel, change it there'), 'the key reads Made in CRM (1.205.1)');
check(false !== strpos($src, '<span class="bftd-calv-k is-moved">Rescheduled</span>'), 'and Rescheduled (1.205.1)');
$acss = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$cjs2 = file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js');
check(false !== strpos($acss, '.bftd-calv-ev{border-left-color:#5c7a29;background:#eef4e2}') && false !== strpos($acss, '.bftd-calv-ev.is-draft{border-left-color:#70567F;background:#efe9f6}') && false !== strpos($acss, '.bftd-calv-ev.is-held{border-left-color:#b9c99c;background:#f7f9f2;color:#5f6b55}'), 'bookings are coloured by state: booked green, draft purple, taught a faded green (1.206)');
check(false === strpos($acss, 'data-c=') && false === strpos($cjs2, 'data-c='), 'and no longer by tutor');
check(false === strpos($cjs2, 'bftd-calv-ev-dot') && false === strpos($acss, 'bftd-calv-ev-dot'), 'no colour square on a booking (Karl, 1.206.1: "remove the square")');
check(false !== strpos($src, "'colours' => self::colour_map( array_keys( \$tutors ) ),") && false !== strpos($src, '<span class="bftd-calv-swatch" data-t="<?php echo (int) ( $config[\'colours\'][ (int) $uid ] ?? 0 ); ?>"></span>') && false !== strpos($cjs2, "C.colours[ e.tutor ] % 8") && false !== strpos($acss, '.bftd-calv-ev[data-t="1"]{border-left-color:#2e6b8a}') && false !== strpos($acss, '.bftd-calv-swatch[data-t="1"]{background:#2e6b8a}'), 'the left border is the tutor colour, set on the profile or by side panel order, so border and swatch always match (1.206.1, 1.209.1)');
check(false !== strpos($acss, '.bftd-wrap.bftd-calv{max-width:1920px}') && strpos($acss, '.bftd-wrap{max-width:1240px}') < strpos($acss, '.bftd-wrap.bftd-calv{max-width:1920px}'), 'the Calendar page uses up to 1920px of the screen; other pages keep 1240px (1.206.2)');
check(strpos($acss, '.bftd-calv-ev.is-held{border-left-color') < strpos($acss, '.bftd-calv-ev[data-t="1"]'), 'and the tutor colour wins the border over the state colour, which keeps the background');
check(strpos($acss, 'Coloured by state') < strpos($acss, '.bftd-calv-ev.is-moved{') && strpos($acss, 'Coloured by state') < strpos($acss, '.bftd-calv-ev.is-cancelled{'), 'a cancelled or rescheduled booking keeps its own look over the state colour');
check(false !== strpos($src, 'class="bftd-calv-showheld" checked> Past</label>') && false !== strpos($cjs2, "if ( e.date < C.today && ! showHeld.checked ) return false;") && false !== strpos($src, '<span class="bftd-calv-k is-draft">Draft</span>'), 'a Past filter shows or hides every booking before today, taught or not, and the key has Draft (1.206.4, was Taught; attended today is the Attended filter since 1.210)');
check(false !== strpos($acss, '.wp-core-ui .button.button-primary.bftd-calv-addbtn{background:var(--bftd-purple);border-color:var(--bftd-purple)}'), 'Add a booking is purple, not the sage of other primary buttons (1.204.1)');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
