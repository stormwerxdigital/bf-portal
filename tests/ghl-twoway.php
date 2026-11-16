<?php
/*
 * Every field both ways, and caregivers added by themselves, integration
 * phase 5 (1.180).
 *
 * Karl's rules: a linked student and its GoHighLevel record end each sync
 * the same, status included, under GoHighLevel's names; where both changed
 * since they last agreed, the later change wins; a field added to students
 * in GoHighLevel is added here by itself; each contact on the student with
 * an email is added as a caregiver, quietly, and someone removed here is not
 * added again. The only thing written to GoHighLevel is the student record.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');

/* ---- GoHighLevel, stood in for ---- */
$GLOBALS['CALLS'] = array(); $GLOBALS['PUTS'] = array(); $GLOBALS['PUT_CODE'] = 200;
$GLOBALS['RECORDS'] = array(); $GLOBALS['CONTACTS'] = array(); $GLOBALS['DEFS_RAW'] = array();
function wp_remote_request($url, $args) {
  $m = $args['method']; $path = parse_url($url, PHP_URL_PATH);
  $GLOBALS['CALLS'][] = array($m, $path);
  if ('PUT' === $m && preg_match('#^/objects/custom_objects\.students/records/([^/]+)$#', $path, $x)) {
    $GLOBALS['PUTS'][] = array($x[1], json_decode($args['body'], true));
    $c = $GLOBALS['PUT_CODE'];
    return array('code' => $c, 'body' => json_encode($c < 300 ? array('record' => array()) : array('message' => 'The token is not authorized for this scope.')));
  }
  if ('POST' === $m && '/objects/custom_objects.students/records/search' === $path) {
    $recs = array();
    foreach ($GLOBALS['RECORDS'] as $id => $r) $recs[] = array('id' => $id, 'properties' => $r['p'], 'updatedAt' => $r['u'] ?? '', 'relations' => $r['rel'] ?? array(), 'owners' => array(), 'followers' => array());
    return array('code' => 200, 'body' => json_encode(array('total' => count($recs), 'records' => $recs)));
  }
  if ('GET' === $m && '/objects/custom_objects.students' === $path) return array('code' => 200, 'body' => json_encode(array('object' => array(), 'fields' => $GLOBALS['DEFS_RAW'])));
  if ('GET' === $m && preg_match('#^/contacts/(.+)$#', $path, $x) && isset($GLOBALS['CONTACTS'][$x[1]])) return array('code' => 200, 'body' => json_encode(array('contact' => $GLOBALS['CONTACTS'][$x[1]])));
  return array('code' => 404, 'body' => json_encode(array('message' => 'not found')));
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }

/* ---- WordPress, stood in for ---- */
$GLOBALS['META'] = array(); $GLOBALS['OPT'] = array(); $GLOBALS['T'] = array(); $GLOBALS['TITLES'] = array();
function get_post_meta($id, $k = '', $single = false) {
  $v = $GLOBALS['META'][$id][$k] ?? null;
  if ($single) return null === $v ? '' : (is_array($v) && array_key_exists('__multi', $v) ? ($v['__multi'][0] ?? '') : $v);
  return null === $v ? array() : (is_array($v) && array_key_exists('__multi', $v) ? $v['__multi'] : array($v));
}
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function add_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k]['__multi'][] = $v; return true; }
function delete_post_meta($id, $k, $v = null) {
  if (null !== $v && isset($GLOBALS['META'][$id][$k]['__multi'])) { $GLOBALS['META'][$id][$k]['__multi'] = array_values(array_diff($GLOBALS['META'][$id][$k]['__multi'], array($v))); return true; }
  unset($GLOBALS['META'][$id][$k]); return true;
}
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
if (!function_exists('update_option')) { function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; } }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
function delete_transient($k) { unset($GLOBALS['T'][$k]); return true; }
function get_the_title($id) { return $GLOBALS['TITLES'][$id] ?? ''; }
function get_post_field($f, $id) { return $GLOBALS['TITLES'][$id] ?? ''; }
function wp_update_post($a, $e = false) { $GLOBALS['TITLES'][$a['ID']] = $a['post_title']; $GLOBALS['SAVES'][] = $a['ID']; BFTD_GHL::on_save($a['ID'], (object) array('post_status' => 'publish')); return $a['ID']; }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return (object) array('display_name' => 'Karl'); }
function get_posts($a) { return array_keys($GLOBALS['LINKS']); }
function current_user_can($c, ...$a) { return true; }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($v) { return trim(strip_tags((string) $v)); } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($v) { return trim(strip_tags((string) $v)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
$GLOBALS['USERS'] = array();
function get_user_by($f, $v) { foreach ($GLOBALS['USERS'] as $id => $u) if (strtolower($u['email']) === strtolower($v)) return (object) array('ID' => $id); return false; }
function email_exists($e) { return (bool) get_user_by('email', $e); }
function username_exists($l) { foreach ($GLOBALS['USERS'] as $u) if (($u['login'] ?? '') === $l) return true; return false; }
function sanitize_email($e) { return trim($e); }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function sanitize_user($l, $s = false) { return preg_replace('/[^a-z0-9_.-]/i', '', $l); }
function wp_generate_password($n = 12, $a = true, $b = false) { return str_repeat('x', $n); }
$GLOBALS['INSERTED'] = array();
function wp_insert_user($d) { $id = 500 + count($GLOBALS['INSERTED']); $GLOBALS['INSERTED'][] = $d; $GLOBALS['USERS'][$id] = array('email' => $d['user_email'], 'login' => $d['user_login']); return $id; }
$GLOBALS['MAIL'] = 0;
function wp_mail() { $GLOBALS['MAIL']++; return true; }
$GLOBALS['AUDIT'] = array();
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e . ': ' . ($a['summary'] ?? ''); } }
class BFTD_Notices { public static function add(...$a) {} }
class BFTD_Roles { const CLIENT_ROLE = 'bftd_client'; public static function is_staff($u) { return !empty($GLOBALS['USERS'][$u]['staff']); } public static function can_manage() { return true; } }
class BFTD_CPT {
  const STUDENT = 'bftd_student'; const CLIENT_KEY = '_bftd_client_user_id'; const PRIMARY_KEY = '_bftd_client_primary'; const STAFF_KEY = '_bftd_assigned_staff';
  public static function client_ids($s) { return array_map('intval', get_post_meta($s, self::CLIENT_KEY)); }
  public static function staff_ids($s) { return array_map('intval', get_post_meta($s, self::STAFF_KEY)); }
  public static function primary_client_id($s) { return (int) get_post_meta($s, self::PRIMARY_KEY, true); }
  public static function diagnostic_on($s) { return (string) ($GLOBALS['F'][$s]['diagnostic_on'] ?? ''); }
}
$GLOBALS['F'] = array();
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) { return $GLOBALS['F'][$id][$k] ?? ''; }
  public static function set($id, $g, $k, $v) { $GLOBALS['F'][$id][$k] = $v; return true; }
}
$GLOBALS['LINKS'] = array();
class BFTD_CRM {
  const LINK_KEY = '_bftd_crm_id';
  const SYNCED_KEY = '_bftd_crm_synced';
  public static function linked($sid) { return $GLOBALS['LINKS'][$sid] ?? ''; }
  public static function split_span($t) {
    $o = array('from' => '', 'to' => '');
    $p = preg_split('/\s*END\s*/i', trim($t), 2);
    foreach (array('from' => 0, 'to' => 1) as $k => $i) {
      if (isset($p[$i]) && preg_match('/^(\d{4})-([A-Za-z]{3})/', $p[$i], $m)) $o[$k] = sprintf('%04d-%02d-01', $m[1], date_parse($m[2])['month']);
    }
    return $o;
  }
}
require BFTD_PATH . 'includes/class-bftd-ghl.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function puts_for($rid) { return array_values(array_filter($GLOBALS['PUTS'], function ($p) use ($rid) { return $p[0] === $rid; })); }

/* GoHighLevel's eight fields as Karl's account has them, and two more. */
$opt = function ($pairs) { $o = array(); foreach ($pairs as $k => $l) $o[] = array('key' => $k, 'label' => $l); return $o; };
$GLOBALS['DEFS_RAW'] = array(
  array('fieldKey' => 'custom_objects.students.student_name', 'name' => 'Student Name', 'dataType' => 'TEXT'),
  array('fieldKey' => 'custom_objects.students.birth_date', 'name' => 'Birth Date', 'dataType' => 'DATE'),
  array('fieldKey' => 'custom_objects.students.diagnostic_assessment', 'name' => 'Diagnostic Assessment', 'dataType' => 'TEXT'),
  array('fieldKey' => 'custom_objects.students.school', 'name' => 'School', 'dataType' => 'TEXT'),
  array('fieldKey' => 'custom_objects.students.phone', 'name' => 'Phone', 'dataType' => 'PHONE'),
  array('fieldKey' => 'custom_objects.students.email', 'name' => 'Email', 'dataType' => 'TEXT'),
  array('fieldKey' => 'custom_objects.students.status', 'name' => 'Status', 'dataType' => 'SINGLE_OPTIONS', 'options' => $opt(array('current_student' => 'Current Student', 'past_student' => 'Past Student', 'pending' => 'Pending'))),
  array('fieldKey' => 'custom_objects.students.fromto', 'name' => 'From/To', 'dataType' => 'LARGE_TEXT'),
  array('fieldKey' => 'custom_objects.students.subjects', 'name' => 'Subjects', 'dataType' => 'MULTIPLE_OPTIONS', 'options' => $opt(array('reading' => 'Reading', 'math' => 'Math'))),
  array('fieldKey' => 'custom_objects.students.consent_form', 'name' => 'Consent form', 'dataType' => 'FILE_UPLOAD'),
);

/* ---- status: one status, GoHighLevel's names ---- */
check(array('prospect' => 'pending', 'active' => 'current_student', 'past' => 'past_student') === BFTD_GHL::STATUS_MAP, 'Pending, Current student and Past student are the same status in both');
check('active' === BFTD_GHL::to_incoming('R0', array('status' => 'current_student'))['Status'], 'a student brought in from GoHighLevel arrives with its status, which it never did before');
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "'prospect' => 'Pending',") && false !== strpos($mb, "'active'   => 'Current student',") && false !== strpos($mb, "'past'     => 'Past student',"), 'the portal shows GoHighLevel\'s names, and keeps its own keys, so nothing stored changes');

/* ---- fields GoHighLevel has, read at each sync ---- */
$defs = BFTD_GHL::field_defs(true);
check(10 === count($defs) && 'MULTIPLE_OPTIONS' === $defs['subjects']['type'] && array('reading' => 'Reading', 'math' => 'Math') === $defs['subjects']['options'], 'every Students field is read, with its kind and its options');
check(array('subjects', 'consent_form') === array_keys(BFTD_GHL::extra_fields($defs)), 'the ones the portal has no place of its own for are added to the student by themselves');
$keys = BFTD_GHL::sync_keys($defs);
check(isset($keys['subjects']) && !isset($keys['consent_form']) && 9 === count($keys), 'all of them kept the same both ways, except a file, which a form here cannot change');

/* ---- the first sync: what GoHighLevel has stands, its blanks filled from here ---- */
$GLOBALS['TITLES'][11] = 'Ana Lee';
$GLOBALS['F'][11] = array('school' => 'Oak', 'phone' => '+15550001', 'status' => '', 'attend_from' => '', 'attend_to' => '');
$GLOBALS['RECORDS']['R1'] = array('u' => '2026-11-01T10:00:00.000Z', 'p' => array(
  'student_name' => 'Ana Lee', 'school' => 'Cedar', 'status' => 'current_student', 'diagnostic_assessment' => '2023-2024',
  'fromto' => '2022-Apr END 2023-Jun', 'birth_date' => '2013-07-06', 'subjects' => array('reading', 'math'),
));
$GLOBALS['LINKS'] = array(11 => 'R1');
$log = BFTD_GHL::sync();
$row = $log['rows'][0];
check('Cedar' === $GLOBALS['F'][11]['school'], 'a field both have, differently, takes GoHighLevel\'s the first time');
check('active' === $GLOBALS['F'][11]['status'], 'GoHighLevel\'s status arrives as the portal\'s');
check('2022-04-01' === $GLOBALS['F'][11]['attend_from'] && '2023-06-01' === $GLOBALS['F'][11]['attend_to'], 'From/To becomes the two dates');
check('2013-07-06' === $GLOBALS['F'][11]['birth_date'], 'and the birth date');
check('reading, math' === ($GLOBALS['META'][11][BFTD_GHL::EXTRA_PREFIX . 'subjects'] ?? ''), 'a field the portal had no place for is kept on the student');
check(in_array('diagnostic_assessment', $row['kept'], true) && !isset($GLOBALS['F'][11]['diagnostic_on']), 'a diagnostic written as words, not a date, is left as it is');
$p = puts_for('R1');
check(1 === count($p) && array('phone' => '+15550001') === $p[0][1]['properties'], 'a field GoHighLevel has blank is filled from here, and nothing else is sent');
check(array('phone') === $row['sent'], 'and the log says what was sent');
check(!empty($GLOBALS['META'][11]['_bftd_crm_synced']), 'and the student\'s Last checked is set (it said never)');
check(false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'Sent to GoHighLevel: Phone.'), 'and the history');

/* ---- after that, the side that changed is copied to the other ---- */
$GLOBALS['PUTS'] = array();
$GLOBALS['RECORDS']['R1']['p']['phone'] = '+15550001';
$log = BFTD_GHL::sync();
check(array() === $GLOBALS['PUTS'] && array() === $log['rows'][0]['written'], 'once they agree, a sync changes nothing on either side, the settled diagnostic included');

$GLOBALS['F'][11]['school'] = 'Elm';
$log = BFTD_GHL::sync();
$p = puts_for('R1');
check(1 === count($p) && array('school' => 'Elm') === $p[0][1]['properties'], 'changed here only: sent to GoHighLevel');

$GLOBALS['PUTS'] = array();
$GLOBALS['RECORDS']['R1']['p']['school'] = 'Elm';
$GLOBALS['RECORDS']['R1']['p']['status'] = 'past_student';
$GLOBALS['RECORDS']['R1']['u'] = '2026-11-10T10:00:00.000Z';
$log = BFTD_GHL::sync();
check('past' === $GLOBALS['F'][11]['status'] && array() === $GLOBALS['PUTS'], 'changed in GoHighLevel only: copied here, and not sent back');

/* ---- changed on both sides: the later change wins ---- */
$GLOBALS['F'][11]['school'] = 'Here School';
$GLOBALS['META'][11][BFTD_GHL::EDITED_KEY] = array('school' => strtotime('2026-11-12T10:00:00Z'));
$GLOBALS['RECORDS']['R1']['p']['school'] = 'There School';
$GLOBALS['RECORDS']['R1']['u'] = '2026-11-11T10:00:00.000Z';
BFTD_GHL::sync();
$p = puts_for('R1');
check('Here School' === $GLOBALS['F'][11]['school'] && 1 === count($p) && 'Here School' === $p[0][1]['properties']['school'], 'changed here after GoHighLevel: this side wins and is sent');
$GLOBALS['PUTS'] = array();
$GLOBALS['F'][11]['school'] = 'Here Again';
$GLOBALS['META'][11][BFTD_GHL::EDITED_KEY] = array('school' => strtotime('2026-11-13T10:00:00Z'));
$GLOBALS['RECORDS']['R1']['p']['school'] = 'There Again';
$GLOBALS['RECORDS']['R1']['u'] = '2026-11-14T10:00:00.000Z';
BFTD_GHL::sync();
check('There Again' === $GLOBALS['F'][11]['school'] && array() === $GLOBALS['PUTS'], 'changed in GoHighLevel after here: GoHighLevel wins');

/* ---- saving the student sends what changed, there and then ---- */
$GLOBALS['PUTS'] = array();
$GLOBALS['F'][11]['status'] = 'active';
BFTD_GHL::on_save(11, (object) array('post_status' => 'publish'));
$p = puts_for('R1');
check(1 === count($p) && array('status' => 'current_student') === $p[0][1]['properties'], 'a status changed here goes to GoHighLevel when the student is saved, under its name there');
check(isset($GLOBALS['META'][11][BFTD_GHL::EDITED_KEY]['status']), 'and when it was changed is kept, for the later change to win');
$GLOBALS['PUTS'] = array();
BFTD_GHL::on_save(11, (object) array('post_status' => 'publish'));
check(array() === $GLOBALS['PUTS'], 'saving with nothing changed sends nothing');
$GLOBALS['RECORDS']['R1']['p']['status'] = 'current_student';
$GLOBALS['RECORDS']['R1']['p']['student_name'] = 'Ana Lee-Park';
BFTD_GHL::sync();
check('Ana Lee-Park' === $GLOBALS['TITLES'][11] && array() === $GLOBALS['PUTS'], 'a name changed in GoHighLevel renames the student, and the save that makes is not sent straight back');
$GLOBALS['PUTS'] = array();
$GLOBALS['TITLES'][12] = 'Not Yet'; $GLOBALS['LINKS'][12] = 'R2';
BFTD_GHL::on_save(12, (object) array('post_status' => 'publish'));
check(array() === $GLOBALS['PUTS'], 'a student linked but not yet synced sends nothing on save: the first sync settles it');
unset($GLOBALS['LINKS'][12]);

/* ---- a field added in GoHighLevel, changed here ---- */
$_POST = array('bftd_ghl_more_nonce' => wp_create_nonce('bftd_ghl_more_11'), 'bftd_ghl_more' => array('subjects' => array('math', 'art')));
BFTD_GHL::on_save(11, (object) array('post_status' => 'publish'));
$p = puts_for('R1');
check('math' === $GLOBALS['META'][11][BFTD_GHL::EXTRA_PREFIX . 'subjects'], 'its box keeps only options GoHighLevel has');
check(1 === count($p) && array('subjects' => array('math')) === $p[0][1]['properties'], 'and sends it as GoHighLevel takes a list');
$_POST = array('bftd_ghl_more_nonce' => 'forged', 'bftd_ghl_more' => array('subjects' => array('reading')));
BFTD_GHL::on_save(11, (object) array('post_status' => 'publish'));
check('math' === $GLOBALS['META'][11][BFTD_GHL::EXTRA_PREFIX . 'subjects'], 'and nothing without its nonce');
$_POST = array();

/* ---- GoHighLevel refusing ---- */
$GLOBALS['PUTS'] = array(); $GLOBALS['PUT_CODE'] = 401;
$GLOBALS['F'][11]['email'] = 'ana@example.test';
$err = BFTD_GHL::on_save(11, (object) array('post_status' => 'publish'));
$keep = get_post_meta(11, BFTD_GHL::PUSH_ERROR, true);
check(false !== strpos($keep['error'] ?? '', 'objects/record.write'), 'a change GoHighLevel will not take says which permission it needs, on the student');
$GLOBALS['PUT_CODE'] = 200; $GLOBALS['PUTS'] = array();
BFTD_GHL::sync();
$p = puts_for('R1');
check(1 === count($p) && 'ana@example.test' === $p[0][1]['properties']['email'], 'and is sent again at the next sync, once it can be');
check('' === get_post_meta(11, BFTD_GHL::PUSH_ERROR, true), 'and the warning goes');

/* ---- a date written another way is the same date ---- */
$GLOBALS['PUTS'] = array();
$GLOBALS['TITLES'][13] = 'Cy Dee'; $GLOBALS['F'][13] = array('diagnostic_on' => '2023-06-03');
$GLOBALS['RECORDS']['R3'] = array('u' => '2026-11-01T10:00:00.000Z', 'p' => array('student_name' => 'Cy Dee', 'diagnostic_assessment' => 'June 3, 2023'));
$GLOBALS['LINKS'][13] = 'R3';
$log = BFTD_GHL::sync(13);
check(!in_array('diagnostic_assessment', $log['rows'][0]['written'], true) && array() === puts_for('R3'), '"June 3, 2023" in GoHighLevel and 3 June 2023 here agree, and neither side is changed');
unset($GLOBALS['LINKS'][13], $GLOBALS['RECORDS']['R3']);

/* ---- a phone number, as GoHighLevel takes it (1.183) ---- */
$GLOBALS['PUTS'] = array();
$GLOBALS['TITLES'][14] = 'Dee Fone'; $GLOBALS['F'][14] = array('phone' => '(778) 675-8433');
$GLOBALS['RECORDS']['R4'] = array('u' => '2026-11-01T10:00:00.000Z', 'p' => array('student_name' => 'Dee Fone'));
$GLOBALS['LINKS'][14] = 'R4';
BFTD_GHL::sync(14);
$p = puts_for('R4');
check(1 === count($p) && '+17786758433' === ($p[0][1]['properties']['phone'] ?? ''), 'a phone typed "(778) 675-8433" is sent as "+17786758433", which GoHighLevel refused otherwise');
$GLOBALS['PUTS'] = array();
$GLOBALS['RECORDS']['R4']['p']['phone'] = '+17786758433';
$log = BFTD_GHL::sync(14);
check(array() === puts_for('R4') && array() === $log['rows'][0]['written'] && '(778) 675-8433' === $GLOBALS['F'][14]['phone'], 'and once GoHighLevel has it, the two agree: nothing is sent again, and the number here is left as typed');
$GLOBALS['TITLES'][15] = 'Ed Fone'; $GLOBALS['F'][15] = array('phone' => '778-675-8433');
$GLOBALS['RECORDS']['R6'] = array('u' => '2026-11-01T10:00:00.000Z', 'p' => array('student_name' => 'Ed Fone', 'phone' => '778.675.8433'));
$GLOBALS['LINKS'][15] = 'R6';
$log = BFTD_GHL::sync(15);
check(array() === puts_for('R6') && array() === $log['rows'][0]['written'], 'the same number written two ways is the same number the first time too');
check('+447700900123' === BFTD_GHL::phone('+44 7700 900123') && '+17786758433' === BFTD_GHL::phone('1 778 675 8433') && 'ext 12' === BFTD_GHL::phone('ext 12'), 'an international number keeps its country, and what is not a number is left alone');
unset($GLOBALS['LINKS'][14], $GLOBALS['LINKS'][15], $GLOBALS['RECORDS']['R4'], $GLOBALS['RECORDS']['R6']);

/* ---- caregivers, added by themselves ---- */
$GLOBALS['USERS'] = array(300 => array('email' => 'pat@example.test', 'login' => 'pat'), 301 => array('email' => 'staff@example.test', 'login' => 's', 'staff' => true));
$GLOBALS['CONTACTS'] = array(
  'C1' => array('firstName' => 'Nova', 'lastName' => 'New', 'email' => 'nova@example.test', 'tags' => array('primary parent')),
  'C2' => array('firstName' => 'Pat', 'lastName' => 'Parent', 'email' => 'pat@example.test'),
  'C3' => array('firstName' => 'Sam', 'lastName' => 'None', 'email' => ''),
);
$GLOBALS['RECORDS']['R1']['rel'] = array(
  array('objectKey' => 'contact', 'recordId' => 'C1'), array('objectKey' => 'contact', 'recordId' => 'C2'), array('objectKey' => 'contact', 'recordId' => 'C3'),
);
$GLOBALS['MAIL'] = 0;
$log = BFTD_GHL::sync();
$clients = BFTD_CPT::client_ids(11);
check(2 === count($log['rows'][0]['added']), 'each contact with an email is added at the sync, got ' . json_encode($log['rows'][0]['added']));
check(in_array(300, $clients, true) && 1 === count($GLOBALS['INSERTED']) && 'nova@example.test' === $GLOBALS['INSERTED'][0]['user_email'], 'an existing account is linked, and one is made for a contact with none');
check(0 === $GLOBALS['MAIL'], 'with no email sent');
check(BFTD_CPT::primary_client_id(11) === 500, 'the contact tagged primary parent becomes the client, as there was none');
check(2 === count($clients), 'a contact with no email is not added');
delete_post_meta(11, BFTD_CPT::CLIENT_KEY, 300);
$log = BFTD_GHL::sync();
check(!in_array(300, BFTD_CPT::client_ids(11), true) && array() === $log['rows'][0]['added'], 'someone removed here is not added again by the next sync');
check(1 === count(BFTD_GHL::offers_for(11)), 'they stay on offer, for somebody to add back on purpose');
check(1 === $log['rows'][0]['offers'], 'and the log counts them as still to add');

/* ---- what is written to GoHighLevel ---- */
$writes = array_filter($GLOBALS['CALLS'], function ($c) { return 'GET' !== $c[0]; });
$odd = array_filter($writes, function ($c) { return !('POST' === $c[0] && '/objects/custom_objects.students/records/search' === $c[1]) && !('PUT' === $c[0] && 0 === strpos($c[1], '/objects/custom_objects.students/records/')); });
check(array() === $odd, 'the only thing written to GoHighLevel is a student record: no contact, user or link');
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-ghl.php');
check(1 === substr_count($src, "self::request( 'PUT'") && false === strpos($src, "'DELETE'"), 'and it is written in one place, push()');

/* ---- linking: the sync runs there and then ---- */
$set = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');
check(1 === preg_match('/\$newly\+\+;.*if \( \$newly \) \{\s+\$log\s+= BFTD_GHL::sync\(\);/s', $set), 'linking a student syncs it at once, so its caregivers and fields arrive without waiting the hour');

$set2 = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');
check(false !== strpos($set2, '<?php if ( $may && $to_bring ) : ?>') && false !== strpos($set2, 'Yes, make <?php echo esc_html( $to_bring ); ?> draft'), 'the bring-in offer counts only current and pending students');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
