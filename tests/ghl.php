<?php
/*
 * Reading GoHighLevel, Phase 1 (1.173): the connection test.
 *
 * GoHighLevel is faked here: each request is answered from a table, and every
 * request is recorded so the test can see exactly what was sent. The rules
 * checked are the ones agreed before it was written: read only, the token in
 * a header and nowhere else, and a report that says which fields hold
 * something without ever saying what.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-SECRET-TOKEN-123');
define('BFTD_GHL_LOCATION', 'LOC42');

$GLOBALS['SENT'] = array();
$GLOBALS['ANSWERS'] = array();
function wp_remote_request($url, $args) {
  $GLOBALS['SENT'][] = array('url' => $url, 'args' => $args);
  foreach ($GLOBALS['ANSWERS'] as $match => $answer) {
    list($method, $needle, $version) = array_pad(explode(' ', $match), 3, '');
    if ($method !== $args['method'] || false === strpos($url, $needle)) continue;
    if ('' !== $version && $version !== $args['headers']['Version']) continue;
    return array('code' => $answer[0], 'body' => json_encode($answer[1]));
  }
  return array('code' => 404, 'body' => json_encode(array('message' => 'not faked: ' . $url)));
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
function get_current_user_id() { return 1; }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['LOG'][] = $a['summary']; } }
require BFTD_PATH . 'includes/class-bftd-ghl.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$objects = array('objects' => array(
  array('key' => 'contact', 'standard' => true, 'labels' => array('singular' => 'Contact', 'plural' => 'Contacts')),
  array('key' => 'custom_objects.students', 'standard' => false, 'labels' => array('singular' => 'Student', 'plural' => 'Students'), 'primaryDisplayProperty' => 'custom_objects.students.student_name'),
));
$fields = array('fields' => array(
  array('name' => 'Student name', 'fieldKey' => 'custom_objects.students.student_name', 'dataType' => 'TEXT'),
  array('name' => 'Birth date', 'fieldKey' => 'custom_objects.students.birth_date', 'dataType' => 'DATE'),
  array('name' => 'School', 'fieldKey' => 'custom_objects.students.school', 'dataType' => 'TEXT'),
));
$records = array('total' => 12, 'records' => array(array('id' => 'REC1', 'properties' => array(
  'student_name' => 'Kaine Realname', 'birth_date' => '2013-07-06', 'school' => '', 'grade' => 8,
))));
// As GoHighLevel really answers (seen on Karl's account, 1.173): the student
// can be either end of a link, and the link names itself in associationKey.
$relations = array('relations' => array(
  array('firstObjectKey' => 'contact', 'firstRecordId' => 'CON9', 'secondObjectKey' => 'custom_objects.students', 'secondRecordId' => 'REC1', 'associationKey' => 'contact'),
  array('firstObjectKey' => 'custom_objects.students', 'firstRecordId' => 'REC1', 'secondObjectKey' => 'contact', 'secondRecordId' => 'CON7', 'associationKey' => 'contact'),
));
$contact = array('contact' => array('name' => 'Dana Realparent', 'email' => 'dana@example.com', 'phone' => '+15550001111', 'additionalPhones' => array()));

/* v3 refused, 2021-07-28 answers: the fallback is used and reported. */
$GLOBALS['ANSWERS'] = array(
  'GET /objects/? v3'         => array(400, array('message' => 'Version header is invalid')),
  'GET /objects/? 2021-07-28' => array(200, $objects),
  'GET /objects/custom_objects.students?' => array(200, $fields),
  'POST /records/search'      => array(200, $records),
  'GET /associations/relations/REC1' => array(200, $relations),
  'GET /contacts/CON9'        => array(200, $contact),
);
$r = BFTD_GHL::discover();
check($r['ok'], 'every step answered');
check('2021-07-28' === $r['version'], 'the API version that answered is the one used and reported');
check('custom_objects.students' === $r['object'], 'the Students object is found by its key and label');
check(3 === count($r['fields']) && 'DATE' === $r['fields'][1]['type'], 'its fields are listed with their types');
check(12 === $r['total'], 'with how many records it holds');
check(array('birth_date' => 'text', 'grade' => 'number', 'school' => 'empty', 'student_name' => 'text') === $r['sample'], 'a record is described by shape only');
check('contact' === $r['links'][0]['with'] && 'contact' === $r['links'][1]['with'], 'each link names the other end, whichever end the student is on');
check('contact' === $r['links'][0]['label'], 'and what the link is called');
check(1 === count(array_filter($GLOBALS['SENT'], function ($s) { return false !== strpos($s['url'], '/contacts/CON9'); })), 'the contact read is the other end of the first link');
check(array('name' => 'text', 'email' => 'text', 'phone' => 'text', 'additionalPhones' => 'empty') === $r['contact'], 'and a linked contact can be read');

/* Nothing a person typed reaches the report. */
$all = json_encode($r);
foreach (array('Kaine Realname', '2013-07-06', 'Dana Realparent', 'dana@example.com', '+15550001111') as $private) {
  check(false === strpos($all, $private), "the report does not carry \"$private\"");
}

/* Read only, and the token only in the header. */
$methods = array_unique(array_map(function ($s) { return $s['args']['method']; }, $GLOBALS['SENT']));
sort($methods);
check(array('GET', 'POST') === $methods, 'only reads are sent (the one POST is a search)');
foreach ($GLOBALS['SENT'] as $s) {
  if ('POST' === $s['args']['method']) check(false !== strpos($s['url'], '/records/search'), 'and that POST is the record search, got ' . $s['url']);
}
$leak = false;
foreach ($GLOBALS['SENT'] as $s) { if (false !== strpos($s['url'] . ($s['args']['body'] ?? ''), 'SECRET')) $leak = true; }
check(!$leak, 'the token is never in a URL or a body');
check('Bearer pit-SECRET-TOKEN-123' === $GLOBALS['SENT'][0]['args']['headers']['Authorization'], 'it travels in the Authorization header');
check(false === strpos($all, 'SECRET'), 'and never reaches the report');
check(0 === strpos($GLOBALS['SENT'][0]['url'], 'https://services.leadconnectorhq.com/objects/?locationId=LOC42'), 'sent to GoHighLevel for this location');

/* A refused token stops at once and says so. */
$GLOBALS['SENT'] = array();
$GLOBALS['ANSWERS'] = array('GET /objects/?' => array(401, array('message' => 'Invalid JWT')));
$r = BFTD_GHL::discover();
check(!$r['ok'] && 1 === count($GLOBALS['SENT']) && 'Invalid JWT' === $r['steps'][0][3], 'a refused token stops after one request, with GoHighLevel\'s reason');

/* No Students object. */
$GLOBALS['ANSWERS'] = array('GET /objects/?' => array(200, array('objects' => array($objects['objects'][0]))));
$r = BFTD_GHL::discover();
check(!$r['ok'] && '' === $r['object'] && false !== strpos(end($r['steps'])[3], 'student'), 'no Students object is reported, not guessed at');

/* Shapes. */
check('empty' === BFTD_GHL::shape(null) && 'empty' === BFTD_GHL::shape('') && 'list of 2' === BFTD_GHL::shape(array('a', 'b')) && 'group of 1' === BFTD_GHL::shape(array('k' => 1)), 'shapes name the kind of value only');

/* The test is kept for the person who ran it. */
$r = BFTD_GHL::run_and_keep();
check(is_array(BFTD_GHL::last_report()) && !empty($GLOBALS['LOG']), 'the last test is kept and logged');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
