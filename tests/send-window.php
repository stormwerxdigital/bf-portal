<?php
/*
 * Schedule changes held until confirmed, and sent in hours (1.200).
 *
 * Karl: a tutor can make many changes without the family hearing about each
 * one; nothing reaches a family between 6:00 pm and 8:00 am; the tutor
 * confirms once, in a banner, and it is sent then, or at 8:00 am; a manager
 * can send at any time.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');

$GLOBALS['OPT'] = array(); $GLOBALS['META'] = array(); $GLOBALS['ME'] = 6; $GLOBALS['SYNCED'] = array(); $GLOBALS['EVENTS'] = array(); $GLOBALS['AUDIT'] = array();
$GLOBALS['INPUT'] = array(40 => 'a', 41 => 'b', 42 => 'c');
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; } }
function get_post_meta($id, $k = '', $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return $s ? (null === $v ? '' : $v) : (null === $v ? array() : (array) $v); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_post_type($id) { return in_array($id, array(40, 41, 42), true) ? 'bftd_student' : ''; }
function get_post_status($id) { return 'publish'; }
function get_post_field($f, $id) { return array(40 => 'Ana Lee', 41 => 'Ben Ray', 42 => 'Cy Dee')[$id] ?? ''; }
function get_current_user_id() { return $GLOBALS['ME']; }
function get_userdata($id) { return (object) array('ID' => $id, 'display_name' => 4 === (int) $id ? 'Laurel Sanders' : 'Candice Paluck'); }
function get_edit_post_link($id, $c = '') { return '/wp-admin/post.php?post=' . $id; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function wp_nonce_field($a, $n, $r = true, $e = true) { return '<input type="hidden" name="' . $n . '" value="nonce">'; }
function wp_next_scheduled($h, $a = array()) { return false; }
function wp_schedule_single_event($t, $h, $a = array()) { $GLOBALS['EVENTS'][] = array($t, $h); return true; }
function wp_is_post_revision($id) { return false; } function wp_is_post_autosave($id) { return false; }
function add_action(...$a) {}
function get_posts($a) { return array(40, 41, 42); }
if (!function_exists('apply_filters')) { function apply_filters($h, $v) { return $v; } }
$GLOBALS['MANAGERS'] = array(4);
class BFTD_Roles { public static function can_manage($u = null) { return in_array((int) $u, $GLOBALS['MANAGERS'], true); } public static function is_administrator($u = null) { return in_array((int) $u, $GLOBALS['ADMINS'] ?? array(), true); } public static function is_staff($u = null) { return true; } }
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e . ': ' . $a['summary']; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_CPT { const STUDENT = 'bftd_student'; public static function staff_ids($s) { return array(4); } }
class BFTD_Fields { public static function get($id, $g, $k) { return ''; } }
class BFTD_Schedule { const RULES_KEY = '_r'; const OFFSET_KEY = '_o'; public static function rules($s) { return array($GLOBALS['INPUT'][$s]); } public static function exceptions($s) { return array(); } }
$GLOBALS['INPLAY'] = array(40 => true, 41 => true, 42 => true);
class BFTD_Bookings {
  const MAP_KEY = '_m';
  public static function offset_on($d) { return -420; }
  public static function prefs($s) { return array('notify' => $GLOBALS['INPLAY'][$s] ? 7 : 0); }
  public static function sync_student($s) { $GLOBALS['SYNCED'][] = (int) $s; return array('error' => ''); }
}
class BFTD_GHL { const CRON = 'bftd_ghl_hourly'; public static function configured() { return true; } }
require BFTD_PATH . 'includes/class-bftd-send-window.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$W = 'BFTD_Send_Window';
$at = function ($ymd_hm) { return strtotime($ymd_hm . ':00 UTC') + 420 * 60; };   // a time in Prince George, UTC-7
$morning = $at('2026-11-16 08:00'); $evening = $at('2026-11-16 18:30'); $early = $at('2026-11-17 06:00'); $lunch = $at('2026-11-17 12:00');

/* ---- the hours ---- */
check($W::in_hours($morning) && $W::in_hours($at('2026-11-16 17:59')), '8:00 am to 5:59 pm, business time, is in hours');
check(!$W::in_hours($evening) && !$W::in_hours($at('2026-11-16 07:59')) && !$W::in_hours($early), '6:00 pm to 8:00 am is not');
check($at('2026-11-17 08:00') === $W::next_open($evening), 'after 6:00 pm the hours next open at 8:00 am tomorrow');
check($at('2026-11-17 08:00') === $W::next_open($early), 'before 8:00 am, at 8:00 am the same day');
check('8:00 am tomorrow' === $W::when($at('2026-11-17 08:00'), $evening), 'and that reads "8:00 am tomorrow"');

/* ---- changes are held, not sent ---- */
$GLOBALS['INPLAY'][42] = false;
$W::note_change(42);
check(array() === $W::pending(), 'a student GoHighLevel has nothing to do with is not held');
$W::note_change(40); $W::note_change(40); $W::note_change(40);
check(array(40) === array_keys($W::pending()) && 'editing' === $W::pending()[40]['state'] && array(6) === $W::pending()[40]['by'] && array() === $GLOBALS['SYNCED'], 'any number of changes to a student are one held change, with nothing sent');
$GLOBALS['ME'] = 5; $W::note_change(41); $GLOBALS['ME'] = 6;
check(array(40) === array_keys($W::mine(6)) && array(40, 41) === array_keys($W::mine(4)), 'a tutor\'s banner is their own changes; a manager\'s is everyone\'s');

/* ---- confirming ---- */
list($what, $when, $n) = $W::confirm(6, false, $evening);
check('queued' === $what && $at('2026-11-17 08:00') === $when && 1 === $n && array() === $GLOBALS['SYNCED'], 'confirmed after 6:00 pm, it waits for 8:00 am, and nothing is sent');
check('queued' === $W::pending()[40]['state'] && array(array($at('2026-11-17 08:00'), 'bftd_ghl_send_queued')) === $GLOBALS['EVENTS'], 'and is set to go at 8:00 am');
check(false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'to be sent at 8:00 am tomorrow'), 'which is in the history');
list($what) = $W::confirm(6, true, $evening);
check('queued' === $what && array() === $GLOBALS['SYNCED'], 'a tutor cannot send out of hours, even asking to');
$W::note_change(40);
$GLOBALS['INPUT'][40] = 'a2'; $W::note_change(40);
check('editing' === $W::pending()[40]['state'] && 0 === $W::pending()[40]['send_at'], 'changing it again after confirming puts it back to not sent, to be confirmed again');
check(array() === $W::run_queue($at('2026-11-17 09:00')), 'so the 8:00 am run does not send it');
$W::confirm(6, false, $evening);
check(array() === $W::run_queue($at('2026-11-17 07:00')) && array() === $GLOBALS['SYNCED'], 'a queue is never sent out of hours');
$W::run_queue($at('2026-11-17 08:05'));
check(array(40) === $GLOBALS['SYNCED'] && !isset($W::pending()[40]), 'at 8:00 am it is sent, once');
$GLOBALS['SYNCED'] = array();
$W::note_change(40);
check(!isset($W::pending()[40]), 'and with nothing changed since, nothing is held again');

$GLOBALS['ME'] = 4;
list($what) = $W::confirm(4, true, $evening);
check('queued' === $what && array() === $GLOBALS['SYNCED'], 'a manager who is not an administrator cannot send out of hours: it is queued like anyone\'s (1.206.3)');
$GLOBALS['ADMINS'] = array(4);
list($what) = $W::confirm(4, true, $evening);
check('sent' === $what && array(41) === $GLOBALS['SYNCED'], 'an administrator can send at any time');
$GLOBALS['ADMINS'] = array();
$GLOBALS['SYNCED'] = array(); $GLOBALS['ME'] = 6;
$GLOBALS['INPUT'][40] = 'a3'; $W::note_change(40);
list($what) = $W::confirm(6, false, $lunch);
check('sent' === $what && array(40) === $GLOBALS['SYNCED'], 'confirmed in hours, it is sent at once');
check(array('nothing', 0, 0) !== $W::confirm(6, false, $lunch) && 'none' === $W::confirm(6, false, $lunch)[0], 'with nothing waiting, confirming does nothing');

/* ---- the hourly run ---- */
$GLOBALS['SYNCED'] = array(); $GLOBALS['INPLAY'][42] = true;
$GLOBALS['META'][42][BFTD_Send_Window::SENT_KEY] = $W::inputs(42);
$GLOBALS['ME'] = 0;   // the hourly run is nobody
$W::hourly($evening);
check(array() === $GLOBALS['SYNCED'] && array() === $W::pending(), 'out of hours, the hourly run sends nothing at all');
$GLOBALS['INPUT'][41] = 'b2';
$W::hourly($lunch);
check(array(40, 42) === $GLOBALS['SYNCED'], 'in hours it keeps the days ahead filled for students whose schedule has not changed');
check(isset($W::pending()[41]) && !in_array(41, $GLOBALS['SYNCED'], true), 'and one that changed without anybody confirming is held, not sent');
check(array() === $W::pending()[41]['by'] && isset($W::mine(4)[41]) && !isset($W::mine(6)[41]), 'held by nobody, so it is on the managers\' banner');
$GLOBALS['SYNCED'] = array();
$GLOBALS['INPUT'][41] = 'b';   // changed back to what was last sent
$GLOBALS['META'][41][BFTD_Send_Window::SENT_KEY] = $W::inputs(41);
$W::hourly($lunch);
check(!in_array(41, $GLOBALS['SYNCED'], true) && isset($W::pending()[41]), 'a held student is left for somebody to confirm, even if it was changed back');
$p = $W::pending(); $p[41]['state'] = 'queued'; $p[41]['send_at'] = $at('2026-11-16 08:00'); update_option(BFTD_Send_Window::PENDING, $p);
check(array() === $W::run_queue($at('2026-11-17 19:00')), 'and a queue left over is not sent out of hours either, however late it is');
unset($p[41]); update_option(BFTD_Send_Window::PENDING, $p);
$GLOBALS['ME'] = 6;

/* ---- the banner ---- */
$GLOBALS['INPUT'][40] = 'a4'; $W::note_change(40);
$b = $W::banner_html(6, $lunch);
check(false !== strpos($b, '1 student has schedule changes not sent:') && false !== strpos($b, 'Ana Lee') && false === strpos($b, 'Families have not been told') && false !== strpos($b, '>Confirm and send now<'), 'in hours: what is held, and Confirm and send now, with no line saying families have not been told (1.204.1)');
$b = $W::banner_html(6, $evening);
check(false !== strpos($b, '>Confirm<') && false !== strpos($b, 'Ana Lee</a>. <span class="bftd-sw-when">Send at 8:00 am tomorrow.</span>') && false === strpos($b, 'Send now'), 'after 6:00 pm a tutor sees Confirm, with when it will go in grey after the names, and no way round it (1.206.3)');
$b = $W::banner_html(4, $evening);
check(false === strpos($b, '>Send now<') && false === strpos($b, 'name="override" value="1"'), 'a manager who is not an administrator cannot send out of hours either (1.206.3)');
$GLOBALS['ADMINS'] = array(4);
$b = $W::banner_html(4, $evening);
check(false !== strpos($b, '>Send now<') && false !== strpos($b, 'name="override" value="1"') && false === strpos($b, 'anyway'), 'an administrator can: Send now');
$GLOBALS['ADMINS'] = array();
$W::confirm(6, false, $evening);
$b = $W::banner_html(6, $evening);
check(false !== strpos($b, 'Confirmed: the changes for 1 student will be sent at 8:00 am tomorrow.') && false !== strpos($b, 'between 8:00 am and 6:00 pm'), 'once confirmed, it says when it will go');
check('' === $W::banner_html(99, $evening), 'and somebody with nothing waiting sees no banner');

/* ---- where it is wired ---- */
$sw = file_get_contents(BFTD_PATH . 'includes/class-bftd-send-window.php');
check(false !== strpos($sw, "check_admin_referer( 'bftd_send_changes' );") && false !== strpos($sw, "\$override = \$override && BFTD_Roles::is_administrator( \$uid );"), 'confirming is signed, and only an administrator can override the hours (1.206.3)');
/* Where it is drawn (1.202, Karl: "this banner should only appear in calendars, and it cannot overlay or cover any controls"). */
$swsrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-send-window.php');
$cvsrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
$css   = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$all   = '';
foreach (glob(BFTD_PATH . 'includes/*.php') as $f) $all .= file_get_contents($f);
check(false === strpos($swsrc, "'banner_shell' )") && 1 === substr_count($all, 'BFTD_Send_Window::banner_shell()'), 'the banner is drawn in one place only, not on every admin screen');
check(1 === preg_match("/<h1[^\n]*\n\t*<\?php if \( class_exists\( 'BFTD_Send_Window' \) \) BFTD_Send_Window::banner_shell\(\); \?>/", $cvsrc), 'and that place is the Calendar page, under its heading');
preg_match('/\.bftd-sw\{[^}]*\}/', $css, $rule);
check(isset($rule[0]) && false === strpos($rule[0], 'position:fixed') && false === strpos($rule[0], 'position:absolute') && false === strpos($rule[0], 'z-index'), 'it sits in the page, so it pushes the calendar down rather than covering any control');
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-bookings.php'), 'Open the Calendar to confirm'), 'a student with changes waiting says to confirm them on the Calendar');
check(false !== strpos(file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js'), "document.dispatchEvent( new Event( 'bftd:schedule-changed' ) )") && false !== strpos(file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js'), "document.dispatchEvent( new Event( 'bftd:schedule-changed' ) )"), 'a change on the calendar or the student\'s list brings the banner up at once');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
