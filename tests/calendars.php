<?php
/*
 * GoHighLevel calendars and the time zone, booking phase 1 (1.187).
 *
 * Each tutor's GoHighLevel calendar is linked by a manager, with a
 * suggestion from who GoHighLevel has on it; a tutor has one calendar. The
 * time zone cannot be changed from here, so it is checked: the offset Karl
 * sets against what GoHighLevel's time zone gives today, with the zone to
 * choose when they differ. Nothing is written to GoHighLevel.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');

$GLOBALS['CALLS'] = array(); $GLOBALS['ZONE'] = 'America/Vancouver'; $GLOBALS['LOC_CODE'] = 200;
function wp_remote_request($url, $args) {
  $path = parse_url($url, PHP_URL_PATH);
  $GLOBALS['CALLS'][] = array($args['method'], $path, $args['headers']['Version']);
  if ('/calendars/' === $path) return array('code' => 200, 'body' => json_encode(array('calendars' => array(
    array('id' => 'CAL-L', 'name' => 'Reading Specialist - Laurel', 'calendarType' => 'round_robin', 'isActive' => true, 'teamMembers' => array(array('userId' => 'U-L'))),
    array('id' => 'CAL-C', 'name' => 'Reading Specialist - Candace', 'calendarType' => 'round_robin', 'isActive' => true, 'teamMembers' => array(array('userId' => 'U-C'))),
    array('id' => 'CAL-M', 'name' => 'Meeting', 'calendarType' => 'round_robin', 'isActive' => true, 'teamMembers' => array(array('userId' => 'U-L'))),
    array('id' => 'CAL-X', 'name' => 'Nobody on it', 'calendarType' => 'personal', 'isActive' => false, 'teamMembers' => array()),
    array('id' => 'CAL-Z', 'name' => 'Front desk', 'calendarType' => 'personal', 'isActive' => true, 'teamMembers' => array(array('userId' => 'U-Z'))),
  ))));
  if ('/users/' === $path) return array('code' => 200, 'body' => json_encode(array('users' => array(
    array('id' => 'U-L', 'firstName' => 'Laurel', 'lastName' => 'Sanders', 'email' => 'Laurel@Example.test'),
    array('id' => 'U-C', 'firstName' => 'Candice', 'lastName' => 'Paluck', 'email' => 'candice@example.test'),
    array('id' => 'U-Z', 'firstName' => 'Front', 'lastName' => 'Desk', 'email' => 'desk@example.test'),
  ))));
  if ('/locations/LOC42' === $path) return array('code' => $GLOBALS['LOC_CODE'], 'body' => json_encode($GLOBALS['LOC_CODE'] < 300 ? array('location' => array('timezone' => $GLOBALS['ZONE'])) : array('message' => 'Unauthorized')));
  return array('code' => 404, 'body' => '{}');
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$GLOBALS['OPT'] = array(); $GLOBALS['T'] = array(); $GLOBALS['AUDIT'] = array(); $GLOBALS['NOTES'] = array();
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; } }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
function add_action(...$a) {}
function remove_accents($s) { return $s; }
function add_settings_error($s, $c, $m, $t = 'error') { $GLOBALS['NOTES'][] = $t . ': ' . $m; }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim(strip_tags((string) $v)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
$GLOBALS['STAFF'] = array(
  5 => (object) array('ID' => 5, 'user_email' => 'laurel@example.test', 'display_name' => 'Laurel Sanders'),
  6 => (object) array('ID' => 6, 'user_email' => 'candice@example.test', 'display_name' => 'Candice Paluck'),
);
function get_userdata($id) { return $GLOBALS['STAFF'][$id] ?? false; }
$GLOBALS['MANAGE'] = true;
class BFTD_Roles { public static function staff_users() { return array_values($GLOBALS['STAFF']); } public static function can_manage() { return $GLOBALS['MANAGE']; } }
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e . ': ' . $a['summary']; } }
class BFTD_Settings { const PAGE_SLUG = 'bftd-settings'; }
require BFTD_PATH . 'includes/class-bftd-ghl.php';
require BFTD_PATH . 'includes/class-bftd-calendars.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- reading GoHighLevel's calendars ---- */
$r = BFTD_Calendars::read(true);
check($r['ok'] && 5 === count($r['calendars']) && array('U-L') === $r['calendars']['CAL-L']['members'], 'every calendar is read, with who GoHighLevel has on it');
check('laurel@example.test' === $r['users']['U-L']['email'] && 'Candice Paluck' === $r['users']['U-C']['name'], 'and GoHighLevel\'s users, by name and email');
check(in_array(array('GET', '/calendars/', '2021-04-15'), $GLOBALS['CALLS'], true), 'calendars are read with the version GoHighLevel\'s calendar API asks for');
$n = count($GLOBALS['CALLS']); BFTD_Calendars::read();
check($n === count($GLOBALS['CALLS']), 'and kept ten minutes rather than read on every load');

/* ---- suggestions ---- */
$s = BFTD_Calendars::suggest($r, BFTD_Calendars::staff_by_email());
check(5 === ($s['CAL-L'] ?? 0) && 6 === ($s['CAL-C'] ?? 0), 'each tutor calendar is suggested for the staff account with the same email, whatever its capitals');
check(!isset($s['CAL-M']), 'a tutor on several calendars is suggested for the one named after them, not Meeting');
check(!isset($s['CAL-X']), 'and a calendar with nobody on it gets no suggestion');
check(!isset($s['CAL-Z']), 'nor one whose person in GoHighLevel has no staff account here');

/* GoHighLevel has the team at bftutoring.ca, the portal at bftutoring.com (1.188). */
$ca = $r;
foreach ($ca['users'] as $gid => $u) $ca['users'][$gid]['email'] = str_replace('example.test', 'example.ca', $u['email']);
$ca['calendars']['CAL-C']['name'] = 'Reading Specialist - Candace';
$s2 = BFTD_Calendars::suggest($ca, BFTD_Calendars::staff_by_email());
check(5 === ($s2['CAL-L'] ?? 0) && 6 === ($s2['CAL-C'] ?? 0) && !isset($s2['CAL-M']) && !isset($s2['CAL-Z']), 'with no email in common, the same name still suggests the tutor, for their own calendar, got ' . json_encode($s2));
$ca['calendars']['CAL-L']['name'] = 'Reading Specialist';
$s3 = BFTD_Calendars::suggest($ca, BFTD_Calendars::staff_by_email());
check(!isset($s3['CAL-L']) && !isset($s3['CAL-M']) && 6 === ($s3['CAL-C'] ?? 0), 'and someone on several calendars none of which is named after them gets no suggestion, rather than a guess');
check(BFTD_Calendars::names_person('Reading Specialist - Candace', 'candice') && !BFTD_Calendars::names_person('Reading Specialist - Candace', 'laurel'), 'a calendar is named after someone allowing one letter\'s difference');
check(BFTD_Calendars::names_person('Reading Specialist - Jo', 'jo') && !BFTD_Calendars::names_person('Ja Meeting', 'jo'), 'a short name matches exactly, but one letter off is not the same name when the name is two letters');
$both = $ca; $both['calendars']['CAL-L']['name'] = 'Reading Specialist - Laurel'; $both['calendars']['CAL-M']['name'] = 'Laurel meetings';
check(!isset(BFTD_Calendars::suggest($both, BFTD_Calendars::staff_by_email())['CAL-L']), 'and when two of their calendars are named after them, neither is guessed');
$GLOBALS['OPT'][BFTD_Calendars::LINKS] = array('CAL-C' => 6);
$s4 = BFTD_Calendars::suggest($r, BFTD_Calendars::staff_by_email());
check(!isset($s4['CAL-C']) && 5 === ($s4['CAL-L'] ?? 0), 'a calendar or tutor already linked is not suggested again');
$GLOBALS['OPT'][BFTD_Calendars::LINKS] = array('CAL-L' => 6);
$s5 = BFTD_Calendars::suggest($r, BFTD_Calendars::staff_by_email());
check(!isset($s5['CAL-L']) && 5 === ($s5['CAL-M'] ?? 0), 'a calendar linked to someone else is never suggested for another tutor, got ' . json_encode($s5));
unset($GLOBALS['OPT'][BFTD_Calendars::LINKS]);

/* ---- linking ---- */
list($changed, $refused) = BFTD_Calendars::save_links(array('CAL-L' => 5, 'CAL-C' => 6, 'CAL-M' => 5, 'NOPE' => 6, 'CAL-X' => 99));
check(array('CAL-L' => 5, 'CAL-C' => 6) === BFTD_Calendars::links(), 'calendars are linked to tutors as picked, got ' . json_encode(BFTD_Calendars::links()));
check(2 === $changed && false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'GoHighLevel calendar Reading Specialist - Laurel is now Laurel Sanders\'s.'), 'and each link is in the history');
check(false !== strpos(implode(' ', $refused), 'Meeting (Laurel Sanders already has Reading Specialist - Laurel)'), 'a tutor has one calendar, and a second is refused, saying which they have');
check(false !== strpos(implode(' ', $refused), 'Nobody on it (that account is not a tutor)'), 'only a staff account can have a calendar');
check('CAL-C' === BFTD_Calendars::calendar_for(6) && '' === BFTD_Calendars::calendar_for(7), 'a tutor\'s calendar can be looked up');
$GLOBALS['AUDIT'] = array();
list($changed) = BFTD_Calendars::save_links(array('CAL-L' => 5, 'CAL-C' => 0));
check(1 === $changed && array('CAL-L' => 5) === BFTD_Calendars::links() && false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'is no longer linked'), 'a link can be taken off');
$GLOBALS['OPT'][BFTD_Calendars::LINKS] = array('CAL-L' => 5, 'CAL-GONE' => 6);
BFTD_Calendars::save_links(array('CAL-L' => 5));
check(array('CAL-L' => 5, 'CAL-GONE' => 6) === BFTD_Calendars::links(), 'a calendar GoHighLevel did not list this time is kept, not dropped');

/* ---- the time zone ---- */
$winter = gmmktime(12, 0, 0, 1, 15, 2026); $summer = gmmktime(12, 0, 0, 7, 15, 2026);
check('UTC-7:00' === BFTD_Calendars::offset_label(-420) && 'UTC+5:30' === BFTD_Calendars::offset_label(330), 'an offset reads as UTC-7:00');
check(-480 === BFTD_Calendars::zone_offset('America/Vancouver', $winter) && -420 === BFTD_Calendars::zone_offset('America/Vancouver', $summer) && null === BFTD_Calendars::zone_offset('Not/AZone'), 'a time zone\'s offset at a moment, by this server\'s rules');
$z = BFTD_Calendars::zones_for(-420, 'America/Vancouver', $winter);
check('America/Dawson_Creek' === ($z[0] ?? '') && 3 === count($z), 'for UTC-7 all year, BC\'s own Dawson Creek is offered first, got ' . json_encode($z));
$all = BFTD_Calendars::zones_for(-420, 'America/Vancouver', $winter, 500);
check(!in_array('America/Denver', $all, true) && !in_array('America/Edmonton', $all, true) && in_array('America/Phoenix', $all, true), 'a zone on UTC-7 today that changes in summer is never offered');
foreach ($z as $one) {
  check(-420 === BFTD_Calendars::zone_offset($one, $winter) && -420 === BFTD_Calendars::zone_offset($one, $summer), $one . ' is UTC-7 in winter and in summer');
}

$c = BFTD_Calendars::check_timezone();
check('off' === $c['state'] && array() === array_filter($GLOBALS['CALLS'], function ($x) { return '/locations/LOC42' === $x[1]; }), 'with no offset set, nothing is checked');
$GLOBALS['OPT'][BFTD_Calendars::OFFSET] = (string) BFTD_Calendars::zone_offset('America/Vancouver');
$c = BFTD_Calendars::check_timezone();
check('ok' === $c['state'] && 'America/Vancouver' === $c['zone'], 'when GoHighLevel\'s zone gives our time, it says they agree');
$ours = BFTD_Calendars::zone_offset('America/Vancouver') + 60;
$GLOBALS['OPT'][BFTD_Calendars::OFFSET] = (string) $ours;
$GLOBALS['AUDIT'] = array();
$c = BFTD_Calendars::check_timezone();
check('differs' === $c['state'] && $c['suggest'] && BFTD_Calendars::zone_offset($c['suggest'][0]) === $ours, 'when it does not, it says so and offers a zone on our time');
check(1 === count($GLOBALS['AUDIT']), 'the change is in the history once');
BFTD_Calendars::check_timezone();
check(1 === count($GLOBALS['AUDIT']), 'and not again every hour while it stays wrong');
$say = BFTD_Calendars::say($c);
check(false !== strpos($say, 'GoHighLevel is an hour behind.') && false !== strpos($say, 'change Time zone under Settings, Business profile, to ' . $c['suggest'][0]), 'it says by how much, which way, and what to change it to: ' . $say);
$GLOBALS['LOC_CODE'] = 401;
$c = BFTD_Calendars::check_timezone();
check('error' === $c['state'] && false !== strpos(BFTD_Calendars::say($c), 'Could not check: Unauthorized'), 'if GoHighLevel will not say, that is what it says');
$GLOBALS['LOC_CODE'] = 200;

/* ---- the warning for managers ---- */
function get_current_screen() { return (object) array('id' => $GLOBALS['SCREEN']); }
function admin_url($p = '') { return '/wp-admin/' . $p; }
BFTD_Calendars::check_timezone();
$GLOBALS['SCREEN'] = 'brilliant-futures_page_bftd-students';
ob_start(); BFTD_Calendars::notice(); $w = ob_get_clean();
check(false !== strpos($w, 'GoHighLevel\'s time zone is wrong.') && false !== strpos($w, 'tab=calendars'), 'while it is wrong, managers see it at the top of the portal\'s screens, linked to Calendars');
$GLOBALS['MANAGE'] = false; ob_start(); BFTD_Calendars::notice(); $w2 = ob_get_clean(); $GLOBALS['MANAGE'] = true;
$GLOBALS['SCREEN'] = 'plugins'; ob_start(); BFTD_Calendars::notice(); $w3 = ob_get_clean();
check('' === $w2 && '' === $w3, 'not tutors, and not on WordPress\'s own screens');
$GLOBALS['OPT'][BFTD_Calendars::OFFSET] = (string) BFTD_Calendars::zone_offset('America/Vancouver');
BFTD_Calendars::check_timezone(); $GLOBALS['SCREEN'] = 'brilliant-futures_page_bftd-students';
ob_start(); BFTD_Calendars::notice(); $w4 = ob_get_clean();
check('' === $w4, 'and it goes once they agree');

/* ---- saving the tab ---- */
$_POST = array('bftd_tz_offset' => '-420', 'bftd_cal' => array('CAL-L' => 5, 'CAL-C' => 6));
BFTD_Calendars::save();
check('-420' === get_option(BFTD_Calendars::OFFSET) && array('CAL-L' => 5, 'CAL-C' => 6) === BFTD_Calendars::links(), 'the tab saves the offset and the links');
$_POST = array('bftd_tz_offset' => '-417');
BFTD_Calendars::save();
check('' === get_option(BFTD_Calendars::OFFSET), 'an offset that is not a real one is not kept');
$GLOBALS['MANAGE'] = false; $_POST = array('bftd_tz_offset' => '-420');
check(false === BFTD_Calendars::save() && '' === get_option(BFTD_Calendars::OFFSET), 'and only a manager or above can save it');
$GLOBALS['MANAGE'] = true; $_POST = array();

/* ---- nothing is written to GoHighLevel ---- */
check(array() === array_filter($GLOBALS['CALLS'], function ($x) { return 'GET' !== $x[0]; }), 'every call to GoHighLevel here is a read');
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendars.php');
check(0 === preg_match("/request\\( '(PUT|POST|DELETE)'/", $src), 'and the code has no write in it');
$set = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');
check(false !== strpos($set, "'calendars' => 'Calendars'") && false !== strpos($set, "case 'calendars': if ( ! BFTD_Calendars::save() ) return; break;"), 'Settings has the Calendars tab, saved through its own check');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
