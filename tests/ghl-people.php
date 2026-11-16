<?php
/*
 * Caregivers, teachers and tutors from GoHighLevel, integration phase 4 (1.176).
 *
 * GoHighLevel links contacts (parents, caregivers, teachers) to a student,
 * and its users (staff) own or follow the record. The sync keeps who they
 * are; the portal offers each one it does not have yet: link an account,
 * make one quietly, or assign a tutor. Only what is on offer can be done,
 * nothing is emailed to a family, and nothing is written to GoHighLevel.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');
$GLOBALS['CALLS'] = array();
$GLOBALS['API'] = array();
function wp_remote_request($url, $args) {
  $GLOBALS['CALLS'][] = array($args['method'], $url);
  $path = parse_url($url, PHP_URL_PATH);
  $r = $GLOBALS['API'][$path] ?? array(404, array('message' => 'not found'));
  return array('code' => $r[0], 'body' => json_encode($r[1]));
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
$GLOBALS['META'] = array();
function get_post_meta($id, $k = '', $single = false) {
  $v = $GLOBALS['META'][$id][$k] ?? null;
  if ($single) return null === $v ? '' : (is_array($v) && array_key_exists('__multi', $v) ? ($v['__multi'][0] ?? '') : $v);
  return null === $v ? array() : (is_array($v) && array_key_exists('__multi', $v) ? $v['__multi'] : array($v));
}
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function add_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k]['__multi'][] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function get_the_title($id) { return 'Student ' . $id; }
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
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e; } }
$GLOBALS['NOTICES'] = array();
class BFTD_Notices { public static function add($u, $t, $s, $p, $sec, $title, $b = '') { $GLOBALS['NOTICES'][] = $u; } }
class BFTD_Roles {
  const CLIENT_ROLE = 'bftd_client';
  public static function is_staff($u) { return !empty($GLOBALS['USERS'][$u]['staff']); }
}
class BFTD_CPT {
  const STUDENT = 'bftd_student'; const CLIENT_KEY = '_bftd_client_user_id'; const PRIMARY_KEY = '_bftd_client_primary'; const STAFF_KEY = '_bftd_assigned_staff';
  public static function client_ids($s) { return array_map('intval', get_post_meta($s, self::CLIENT_KEY)); }
  public static function staff_ids($s) { return array_map('intval', get_post_meta($s, self::STAFF_KEY)); }
  public static function primary_client_id($s) { return (int) get_post_meta($s, self::PRIMARY_KEY, true); }
}
require BFTD_PATH . 'includes/class-bftd-ghl.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- what a student record links to ---- */
$rec = array('id' => 'R1', 'owners' => array('U1'), 'followers' => array('U2'), 'relations' => array(
  array('associationId' => 'A', 'objectKey' => 'contact', 'recordId' => 'C1'),
  array('associationId' => 'A', 'objectKey' => 'contact', 'recordId' => 'C2'),
  array('associationId' => 'B', 'objectKey' => 'opportunity', 'recordId' => 'O1'),
  array('associationId' => 'A', 'objectKey' => 'contact', 'recordId' => 'C1'),
));
check(array('C1', 'C2') === BFTD_GHL::contact_ids($rec['relations']), 'a student\'s contacts are read from its relations, each once, and nothing else');
check(array('U2' => 'follower', 'U1' => 'owner') === BFTD_GHL::staff_ids($rec), 'its staff are the users who own or follow it');
check(array() === BFTD_GHL::contact_ids(null) && array() === BFTD_GHL::staff_ids(array()), 'a record with no links has nobody');

$c = BFTD_GHL::shape_contact('C1', array('firstName' => ' Dana ', 'lastName' => 'Ellery', 'email' => ' Dana@Example.COM ', 'phone' => '+1555', 'tags' => array('Primary Parent', 'current students')));
check('Dana Ellery' === $c['name'] && 'dana@example.com' === $c['email'] && '+1555' === $c['phone'], 'a contact keeps a name, a lower case email and a phone');
check(true === $c['primary'], 'the "primary parent" tag marks the main contact, whatever its case');
check(false === BFTD_GHL::shape_contact('C9', array('firstName' => 'A', 'tags' => array('past')))['primary'], 'and no tag means not the main contact');

/* ---- gathering, read once a run ---- */
$GLOBALS['API'] = array(
  '/contacts/C1' => array(200, array('contact' => array('firstName' => 'Dana', 'lastName' => 'Ellery', 'email' => 'dana@example.com', 'tags' => array()))),
  '/contacts/C2' => array(200, array('contact' => array('firstName' => 'Avery', 'lastName' => 'Ellery', 'email' => 'avery@example.com', 'tags' => array('primary parent')))),
  '/users/U1'    => array(401, array('statusCode' => 401, 'message' => 'The token is not authorized for this scope.')),
  '/users/U2'    => array(401, array('statusCode' => 401, 'message' => 'The token is not authorized for this scope.')),
);
$lookup = array('contacts' => array(), 'users' => array());
$g = BFTD_GHL::gather(array('contacts' => array('C1', 'C2'), 'staff' => array('U1' => 'owner', 'U2' => 'follower')), $lookup);
check(2 === count($g['contacts']) && 'Avery Ellery' === $g['contacts'][0]['name'], 'contacts are kept, the main contact first');
check(array() === $g['staff'] && false !== strpos($g['staff_error'], 'users.readonly'), 'staff GoHighLevel will not share say which permission to add');
$before = count($GLOBALS['CALLS']);
BFTD_GHL::gather(array('contacts' => array('C1'), 'staff' => array('U1' => 'owner')), $lookup);
check($before === count($GLOBALS['CALLS']), 'a person linked to two children is read once a run');
foreach ($GLOBALS['CALLS'] as $call) if ('GET' !== $call[0]) { check(false, 'only reads are sent to GoHighLevel'); break; }
check(true, 'only reads are sent to GoHighLevel');

$GLOBALS['API']['/users/U3'] = array(200, array('id' => 'U3', 'name' => 'Pat Tutor', 'email' => 'Pat@BF.example'));
$lookup = array('contacts' => array(), 'users' => array());
$g2 = BFTD_GHL::gather(array('contacts' => array(), 'staff' => array('U3' => 'follower')), $lookup);
check(array(array('id' => 'U3', 'name' => 'Pat Tutor', 'email' => 'pat@bf.example', 'role' => 'follower')) === $g2['staff'] && '' === $g2['staff_error'], 'with the permission, a staff member is kept with name, email and whether they own or follow');

/* ---- what is offered ---- */
$people = array(
  'contacts' => array(
    array('id' => 'C2', 'name' => 'Avery Ellery', 'first' => 'Avery', 'last' => 'Ellery', 'email' => 'avery@example.com', 'phone' => '', 'primary' => true),
    array('id' => 'C1', 'name' => 'Dana Ellery', 'first' => 'Dana', 'last' => 'Ellery', 'email' => 'dana@example.com', 'phone' => '', 'primary' => false),
    array('id' => 'C3', 'name' => 'Ms Teacher', 'first' => 'Ms', 'last' => 'Teacher', 'email' => 'teach@school.example', 'phone' => '', 'primary' => false),
    array('id' => 'C4', 'name' => 'No Email', 'first' => 'No', 'last' => 'Email', 'email' => '', 'phone' => '', 'primary' => false),
    array('id' => 'C5', 'name' => 'Staff Parent', 'first' => '', 'last' => '', 'email' => 'staff@bf.example', 'phone' => '', 'primary' => false),
    array('id' => 'C6', 'name' => 'Dana Again', 'first' => '', 'last' => '', 'email' => 'dana@example.com', 'phone' => '', 'primary' => false),
  ),
  'staff' => array(
    array('id' => 'U3', 'name' => 'Pat Tutor', 'email' => 'pat@bf.example', 'role' => 'follower'),
    array('id' => 'U4', 'name' => 'Lee Nobody', 'email' => 'lee@bf.example', 'role' => 'owner'),
    array('id' => 'U5', 'name' => 'Already Here', 'email' => 'here@bf.example', 'role' => 'owner'),
    array('id' => 'U6', 'name' => 'A Parent', 'email' => 'teach@school.example', 'role' => 'follower'),
  ),
);
$accounts = array(
  'dana@example.com' => array('id' => 21, 'staff' => false),
  'teach@school.example' => array('id' => 22, 'staff' => false),
  'staff@bf.example' => array('id' => 31, 'staff' => true),
  'pat@bf.example' => array('id' => 32, 'staff' => true),
  'here@bf.example' => array('id' => 33, 'staff' => true),
);
$r = BFTD_GHL::offers($people, array('clients' => array(22), 'staff' => array(33), 'primary' => 0), $accounts);
$by = array(); foreach ($r['offers'] as $o) $by[$o['key']] = $o;
check('create' === ($by['c:C2']['kind'] ?? '') && true === $by['c:C2']['primary'], 'a caregiver with no account is offered a new one, as the client when tagged primary parent');
check('link' === ($by['c:C1']['kind'] ?? '') && 21 === $by['c:C1']['user'] && false === $by['c:C1']['primary'], 'a caregiver whose email has an account is offered that account');
check(!isset($by['c:C3']), 'someone already a caregiver here is not offered again');
check(!isset($by['c:C4']) && in_array('No Email has no email in GoHighLevel, so cannot be given a portal account.', $r['notes'], true), 'a contact with no email is not offered, and the note says why');
check(!isset($by['c:C5']), 'a staff account is never made a caregiver');
check(!isset($by['c:C6']), 'two contacts with one email are offered once');
check('assign' === ($by['u:U3']['kind'] ?? '') && 32 === $by['u:U3']['user'] && 'follower' === $by['u:U3']['role'], 'a GoHighLevel user with a staff account here is offered as a tutor');
check(!isset($by['u:U4']) && !isset($by['u:U5']), 'staff with no account here, or already a tutor, are not offered');
check(!isset($by['u:U6']), 'a staff offer never goes to a family account');
check(3 === count($r['offers']), 'and nothing else is offered');
$r2 = BFTD_GHL::offers($people, array('clients' => array(), 'staff' => array(), 'primary' => 99), $accounts, array('c:C1', 'u:U3'));
$k2 = array_map(function ($o) { return $o['key']; }, $r2['offers']);
check(!in_array('c:C1', $k2, true) && !in_array('u:U3', $k2, true), 'an offer somebody said no to stays hidden');
check(false === $r2['offers'][0]['primary'], 'a student who already has a client is not given another');

/* ---- accepting ---- */
$sid = 40;
$GLOBALS['USERS'] = array(21 => array('email' => 'dana@example.com', 'login' => 'dana'), 32 => array('email' => 'pat@bf.example', 'login' => 'pat', 'staff' => true));
update_post_meta($sid, BFTD_GHL::PEOPLE_KEY, array('when' => 1, 'contacts' => array_slice($people['contacts'], 0, 2), 'staff' => array($people['staff'][0]), 'staff_error' => '', 'error' => ''));
$keys = array_map(function ($o) { return $o['key']; }, BFTD_GHL::offers_for($sid));
check(array('c:C2', 'c:C1', 'u:U3') === $keys, 'the offers for a student come from what the last sync kept');
$res = BFTD_GHL::accept($sid, array('c:C2', 'c:C1', 'u:U3', 'c:NOT-OFFERED', 'u:U4'));
check(3 === count($res['done']) && array() === $res['failed'], 'everything ticked that was on offer is done, got ' . json_encode($res));
check(1 === count($GLOBALS['INSERTED']) && 'avery@example.com' === $GLOBALS['INSERTED'][0]['user_email'] && 'bftd_client' === $GLOBALS['INSERTED'][0]['role'], 'one new family account, for the caregiver who had none');
check('Avery' === $GLOBALS['INSERTED'][0]['first_name'] && 'Avery Ellery' === $GLOBALS['INSERTED'][0]['display_name'], 'named as GoHighLevel names them');
check(array(500, 21) === BFTD_CPT::client_ids($sid), 'both caregivers can now see the student');
check(500 === BFTD_CPT::primary_client_id($sid), 'and the one tagged primary parent is the client');
check(array(32) === BFTD_CPT::staff_ids($sid), 'the tutor is assigned');
check(array(32) === $GLOBALS['NOTICES'], 'the tutor gets a notice in the portal');
check(0 === $GLOBALS['MAIL'], 'and nobody is emailed');
check(in_array('client_created', $GLOBALS['AUDIT'], true) && in_array('staff_assigned', $GLOBALS['AUDIT'], true), 'each change is in the activity log');
check(array() === BFTD_GHL::offers_for($sid), 'once added, nobody is offered again');
$again = BFTD_GHL::accept($sid, array('c:C2'));
check(array() === $again['done'] && 1 === count($GLOBALS['INSERTED']), 'accepting twice makes nothing twice');

$GLOBALS['USERS'][77] = array('email' => 'taken@example.com', 'login' => 'taken');
check(is_wp_error(BFTD_GHL::create_client(array('email' => 'taken@example.com', 'name' => 'X'))), 'an account is never made for an email that already has one');
$GLOBALS['USERS'][78] = array('email' => 'zz@else.example', 'login' => 'dup');
BFTD_GHL::create_client(array('email' => 'dup@example.com', 'name' => 'Dup', 'first' => '', 'last' => ''));
check('dup2' === end($GLOBALS['INSERTED'])['user_login'], 'a username already used gets a number');

/* ---- saying no ---- */
BFTD_GHL::dismiss(41, array('c:C1', 'u:U3', 'c:C1', 'bad key!', 'x:1'));
check(array('c:C1', 'u:U3') === BFTD_GHL::dismissed(41), 'a "not this one" is kept once, and nothing that is not an offer key');

/* ---- where it is asked for ---- */
$set = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');
$a = strpos($set, 'private static function save_family()');
check(false !== $a && strpos($set, 'self::may_edit_text()', $a) < strpos($set, 'BFTD_GHL::accept', $a), 'adding people is behind the senior manager check');
check(preg_match('/if \( ! isset\( \$linked\[ \$sid \] \) \) continue;\s+\$keys = array_diff/', substr($set, $a)) && preg_match('/if \( ! isset\( \$linked\[ \$sid \] \) \) continue;\s+\$keys = array_map/', substr($set, $a)), 'and only on a student linked to GoHighLevel, for adding and for hiding');
check(false !== strpos($set, '<h3 id="bftd-offers-<?php echo (int) $sid; ?>"'), 'each student on the tab can be linked to from the Students list');
$g = file_get_contents(BFTD_PATH . 'includes/class-bftd-ghl.php');
check(false === strpos($g, "'POST', '/contacts") && false === strpos($g, "'PUT'") && false === strpos($g, "'DELETE'"), 'nothing is written to GoHighLevel');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
