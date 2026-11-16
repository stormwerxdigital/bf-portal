<?php
/*
 * Keeping linked students in step with GoHighLevel, integration phase 3 (1.175).
 *
 * Since 1.180 a linked student is kept the same both ways, field by field
 * (BFTD_GHL::merge, tested in ghl-twoway.php). What is checked here is what
 * is around it: GoHighLevel's fields reach the older merge under the right
 * names when a student is brought in; only linked students are synced; a
 * record GoHighLevel no longer has is reported, not guessed at; drafts are
 * made only when asked; the hourly run is there only while GoHighLevel is
 * connected.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');
function get_current_user_id() { return 1; }
function wp_get_current_user() { return (object) array('display_name' => 'Karl'); }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
function get_the_title($id) { return 'Student ' . $id; }
function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; }
function get_posts($a) { return array_keys($GLOBALS['LINKS']); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_post_meta($id, $k = '', $single = false) { $v = $GLOBALS['META'][$id][$k] ?? ($single ? '' : array()); return $v; }
function get_user_by($f, $v) { return false; }
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function delete_transient($k) { unset($GLOBALS['T'][$k]); return true; }
function get_post_field($f, $id) { return $GLOBALS['TITLES'][$id] ?? ('Student ' . $id); }
function wp_update_post($a, $e = false) { $GLOBALS['TITLES'][$a['ID']] = $a['post_title']; return $a['ID']; }
class BFTD_Fields { public static function get($id, $g, $k, $f = array()) { return $GLOBALS['F'][$id][$k] ?? ''; } public static function set($id, $g, $k, $v) { $GLOBALS['F'][$id][$k] = $v; } }
class BFTD_Roles { public static function is_staff($u) { return false; } public static function can_manage() { return true; } }
$GLOBALS['RECORDS'] = array();
function wp_remote_request($url, $args) {
  $recs = array();
  foreach ($GLOBALS['RECORDS'] as $id => $p) $recs[] = array('id' => $id, 'properties' => $p);
  return array('code' => 200, 'body' => json_encode(array('total' => count($recs), 'records' => $recs)));
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
class BFTD_Audit { public static function log($e, $a = array()) {} }
class BFTD_CPT { const STUDENT = 'bftd_student';
  public static function client_ids($s) { return array(); } public static function staff_ids($s) { return array(); } public static function primary_client_id($s) { return 0; }
  public static function diagnostic_on($s) { return ''; } }
class BFTD_CRM {
  const LINK_KEY = '_bftd_crm_id';
  public static function linked($sid) { return $GLOBALS['LINKS'][$sid] ?? ''; }
  public static function upsert($in) {
    $GLOBALS['UPSERTS'][] = $in;
    $sid = array_search($in['crm_id'], $GLOBALS['LINKS'], true);
    if (false === $sid) { $sid = 900 + count($GLOBALS['UPSERTS']); $GLOBALS['LINKS'][$sid] = $in['crm_id']; return array('student_id' => $sid, 'created' => true, 'merge' => array('written' => array('school'), 'held' => array())); }
    return array('student_id' => $sid, 'created' => false, 'merge' => array('written' => array('phone'), 'held' => array('email')));
  }
}
$GLOBALS['SCHED'] = array();
function wp_next_scheduled($h) { return $GLOBALS['SCHED'][$h] ?? false; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['SCHED'][$h] = $t; $GLOBALS['RECUR'][$h] = $r; }
function wp_unschedule_event($t, $h) { unset($GLOBALS['SCHED'][$h]); }
require BFTD_PATH . 'includes/class-bftd-ghl.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- GoHighLevel's fields, as the merge knows them ---- */
$in = BFTD_GHL::to_incoming('R1', array(
  'student_name' => 'Ana Lee', 'birth_date' => 1373068800000, 'school' => 'Cedar', 'phone' => '+1555',
  'email' => 'a@b.c', 'status' => 'Active', 'diagnostic_assessment' => '2026-08-11', 'fromto' => '2022-Apr END 2023-Jun',
  'something_else' => 'ignored',
));
check('R1' === $in['crm_id'] && 'Ana Lee' === $in['Student Name'], 'the record id and name go through');
check('2013-07-06' === $in['Birth Date'], 'a birth date stamp is read as a date');
check('2022-Apr END 2023-Jun' === $in['From/To'] && 'Active' === $in['Status'] && '2026-08-11' === $in['Diagnostic Assessment'], 'under the column names the merge understands');
check(!isset($in['something_else']) && 9 === count($in), 'and nothing it does not');
check('A, B' === BFTD_GHL::to_incoming('R2', array('status' => array('A', 'B')))['Status'], 'an option list arrives as words');
$names = array_values(BFTD_GHL::property_map());
check(array() === array_diff($names, array('Student Name', 'Birth Date', 'School', 'Phone', 'Email', 'Status', 'Diagnostic Assessment', 'From/To')), 'every field maps to a column the merge already knows');

/* ---- syncing linked students ---- */
$GLOBALS['RECORDS'] = array('R1' => array('student_name' => 'Ana Lee'), 'R2' => array('student_name' => 'Bo Ray', 'status' => 'current_student'), 'R9' => array('student_name' => 'New Kid', 'status' => 'pending'));
$GLOBALS['LINKS'] = array(11 => 'R1', 12 => 'GONE');
$GLOBALS['UPSERTS'] = array();
$log = BFTD_GHL::sync();
check($log['ok'] && 2 === count($log['rows']), 'every linked student is synced');
check(array() === $GLOBALS['UPSERTS'], 'not through the older one-way merge any more');
check(array('student_name') === $log['rows'][0]['written'] && 'Ana Lee' === $GLOBALS['TITLES'][11], 'GoHighLevel\'s value is brought here, and what came is recorded');
check(true === $log['rows'][1]['missing'], 'a link to a record GoHighLevel no longer has is reported, not guessed at');
check(is_array($GLOBALS['META'][11][BFTD_GHL::PEOPLE_KEY] ?? null) && !isset($GLOBALS['META'][12]), 'each synced student keeps who GoHighLevel links it to');
check('Karl' === $log['by'] && $GLOBALS['OPT'][BFTD_GHL::SYNC_LOG] === $log, 'the sync is logged, with who ran it');
check(!isset($GLOBALS['LINKS'][901]), 'and no new student is made by a sync');

$GLOBALS['UPSERTS'] = array(); $GLOBALS['OPT'] = array();
$one = BFTD_GHL::sync(11);
check(1 === count($one['rows']) && 11 === $one['rows'][0]['student'], 'one student can be synced on its own');
check(!isset($GLOBALS['OPT'][BFTD_GHL::SYNC_LOG]), 'without replacing the log of the last full sync');

/* ---- bringing in the rest, only when asked ---- */
$GLOBALS['RECORDS']['R5'] = array('student_name' => '   ', 'status' => 'pending');
$GLOBALS['RECORDS']['R7'] = array('student_name' => 'Old Kid', 'status' => 'past_student');
$GLOBALS['RECORDS']['R8'] = array('student_name' => 'No Status');
BFTD_GHL::students(true);   // read again, past the ten minute cache
$GLOBALS['UPSERTS'] = array();
check(array('R2' => 'Bo Ray', 'R9' => 'New Kid', 'R5' => '', 'R7' => 'Old Kid', 'R8' => 'No Status') === BFTD_GHL::unlinked_records(), 'the GoHighLevel students with no student here are counted');
check(array('R2' => 'Bo Ray', 'R9' => 'New Kid', 'R5' => '') === BFTD_GHL::to_bring_in(), 'only the current and pending ones are to be brought in (Karl, 1.181)');
list($made, $skipped) = BFTD_GHL::import_missing();
check(2 === $made && 1 === $skipped, 'they are made drafts, and one with no name is skipped, got ' . $made . '/' . $skipped);
check(array('R2', 'R9') === array_map(function ($u) { return $u['crm_id']; }, $GLOBALS['UPSERTS']), 'each through the same merge, and linked');
check(!isset(array_flip($GLOBALS['LINKS'])['R7']) && !isset(array_flip($GLOBALS['LINKS'])['R8']), 'a past student, and one with no status, are not brought in');
$GLOBALS['UPSERTS'] = array();
BFTD_GHL::import_missing();
check(array() === $GLOBALS['UPSERTS'], 'asking twice makes nothing twice');

/* ---- the hourly run ---- */
$GLOBALS['SCHED'] = array(BFTD_GHL::OLD_CRON => 123);
BFTD_GHL::schedule();
check(isset($GLOBALS['SCHED'][BFTD_GHL::CRON]), 'a sync is scheduled while GoHighLevel is connected');
check('hourly' === $GLOBALS['RECUR'][BFTD_GHL::CRON], 'and it runs every hour');
check(!isset($GLOBALS['SCHED'][BFTD_GHL::OLD_CRON]), 'the nightly run it replaces is cleared');

/* ---- where it is asked for ---- */
$set = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');
check(false !== strpos($set, "if ( empty( \$_POST['bftd_ghl_import_ok'] ) ) {"), 'bringing students in has to be ticked to confirm');
$a = strpos($set, 'private static function save_links()');
check(false !== $a && strpos($set, "self::may_edit_text()", $a) < strpos($set, "bftd_ghl_import", $a), 'and is behind the senior manager check');
$g = file_get_contents(BFTD_PATH . 'includes/class-bftd-ghl.php');
check(false !== strpos($g, "check_admin_referer( 'bftd_ghl_sync_' . \$sid );") && false !== strpos($g, "! BFTD_Roles::can_manage() || ! current_user_can( 'edit_post', \$sid )"), 'a student\'s Sync now is signed and needs a manager who can edit that student');

check(false !== strpos($g, "'From GoHighLevel: ' . \$say( \$row['written'] )") && false !== strpos($g, "array_map( array( __CLASS__, 'label' ), (array) \$keys )"),
  'the message names fields as the screen labels them, not by their keys');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
