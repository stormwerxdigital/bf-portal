<?php
/*
 * Linking the portal's students to GoHighLevel's, integration phase 2 (1.174).
 *
 * A suggestion is never a link. The matching proposes; a senior manager or an
 * administrator ticks and saves; the save refuses a record GoHighLevel does
 * not have and a record already linked to another child. A wrong link would
 * put one family's details on another child's record, so these are the rules
 * that are checked.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
define('BFTD_GHL_TOKEN', 'pit-test');
define('BFTD_GHL_LOCATION', 'LOC42');
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
function remove_accents($s) { return strtr($s, array('é' => 'e', 'ï' => 'i', 'ñ' => 'n')); }
function get_current_user_id() { return 1; }
function set_transient($k, $v, $t = 0) { $GLOBALS['T'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['T'][$k] ?? false; }
$GLOBALS['SENT'] = array();
function wp_remote_request($url, $args) {
  $GLOBALS['SENT'][] = json_decode($args['body'] ?? 'null', true);
  $page = json_decode($args['body'], true)['page'];
  $recs = array();
  for ($i = 1; $i <= ($page < 2 ? 100 : 5); $i++) {
    $n = ($page - 1) * 100 + $i;
    $recs[] = array('id' => 'R' . $n, 'properties' => array('student_name' => 'Kid ' . $n, 'birth_date' => 1 === $n ? '2013-07-06T00:00:00Z' : ''));
  }
  return array('code' => 200, 'body' => json_encode(array('total' => 105, 'records' => $recs)));
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_body($r) { return $r['body']; }
class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['LOG'][] = $e . ': ' . $a['summary']; } }
require BFTD_PATH . 'includes/class-bftd-ghl.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- reading every GoHighLevel student ---- */
$all = BFTD_GHL::students();
check($all['ok'] && 105 === count($all['records']), 'every student is read, a hundred at a time, got ' . count($all['records']));
check(array(1, 2) === array_map(function ($b) { return $b['page']; }, $GLOBALS['SENT']), 'two pages for 105 records');
check('Kid 1' === $all['records']['R1']['name'] && '2013-07-06' === $all['records']['R1']['birth'], 'with the name and birth date of each');
check(isset($all['records']['R1']['props']['student_name']), 'and everything the record holds, for the sync');
BFTD_GHL::students();
check(2 === count($GLOBALS['SENT']), 'and kept for ten minutes rather than read on every load');
BFTD_GHL::students(true);
check(4 === count($GLOBALS['SENT']), 'unless asked to read again');
check('2013-07-06' === BFTD_GHL::birth(1373068800000) && '' === BFTD_GHL::birth('soon'), 'a birth date is read from a stamp or a date, and nothing else');

/* ---- suggestions ---- */
$ghl = array(
  'G1' => array('name' => 'Eleanor Rettkowski', 'birth' => '2009-04-30'),
  'G2' => array('name' => 'SAHAI  Bhangu',      'birth' => ''),
  'G3' => array('name' => 'Raphael Encinas',    'birth' => '2015-02-01'),
  'G4' => array('name' => 'Olivier Encinas',    'birth' => ''),
  'G5' => array('name' => 'Emanuel Encinas',    'birth' => ''),
  'G6' => array('name' => 'Kaine Miller',       'birth' => '2013-07-06'),
);
$portal = array(
  1 => array('name' => 'Eleanor Rettkowski', 'birth' => '2009-04-30'),
  2 => array('name' => 'Sahai Bhangu',       'birth' => '2012-01-01'),
  3 => array('name' => 'Raphael Encinas',    'birth' => '2016-05-05'),
  4 => array('name' => 'Emmanuel Encinas',   'birth' => ''),
  5 => array('name' => 'Kaine Miller',       'birth' => ''),
  6 => array('name' => 'Nobody Known',       'birth' => ''),
);
$o = BFTD_GHL::propose($portal, $ghl, array('G6'));
check('G1' === $o[1]['record'] && 'Same name and birth date' === $o[1]['why'], 'the same name and birth date is offered first');
check('G2' === $o[2]['record'] && 'Same name' === $o[2]['why'], 'capitals and spacing do not stop a match, and one birth date missing is still a match');
check(!isset($o[3]), 'two birth dates that disagree rule a pair out, whatever the names say');
check('G5' === $o[4]['record'] && 'Similar name, check it' === $o[4]['why'], 'a close spelling is offered, marked to be checked');
check(!isset($o[5]), 'a record already linked to another student is not offered');
check(!isset($o[6]), 'and a name with nothing like it gets no suggestion');
$twins = BFTD_GHL::propose(array(7 => array('name' => 'Ana Lee', 'birth' => ''), 8 => array('name' => 'Ana Lee', 'birth' => '')), array('X' => array('name' => 'Ana Lee', 'birth' => '')));
check(1 === count($twins), 'one GoHighLevel student is never offered to two students here');
$close_first = BFTD_GHL::propose(array(9 => array('name' => 'Emanuel Encinas', 'birth' => ''), 10 => array('name' => 'Emmanuel Encinas', 'birth' => '')), array('Y' => array('name' => 'Emmanuel Encinas', 'birth' => '')));
check('Y' === $close_first[10]['record'] && !isset($close_first[9]), 'an exact name takes its record before a close spelling can');

/* ---- saving: the save is lifted out of Settings and run against stubs ---- */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');
$a = strpos($src, "\tprivate static function save_links() {");
$b = strpos($src, "\t/**\n\t * Each portal student, its link, and a suggested");
// The sync that runs on linking (1.180) is stood in for: it is tested in ghl-twoway.php.
eval('class LinkSave { public static $may = true; public static $rows = array(); public static $syncs = 0;
  public static function may_edit_text() { return self::$may; }
  public static function link_rows() { return self::$rows; }
  public static function sync() { self::$syncs++; return array("ok" => true, "error" => "", "rows" => array(array("added" => array("A", "B")))); }
  ' . str_replace(array('private static function save_links', 'BFTD_GHL::sync()'), array('public static function save_links', 'self::sync()'), substr($src, $a, $b - $a)) . ' }');
class BFTD_CRM { public static function link($sid, $rid) { $GLOBALS['LINKS'][$sid] = $rid; } }
function add_settings_error($s, $c, $m, $t = 'error') { $GLOBALS['NOTES'][] = $t . ': ' . $m; }
$GLOBALS['T']['bftd_ghl_students'] = array('ok' => true, 'error' => '', 'records' => $ghl);

function save($post, $rows) {
  $_POST = $post; $GLOBALS['LINKS'] = array(); $GLOBALS['NOTES'] = array(); $GLOBALS['LOG'] = array();
  LinkSave::$syncs = 0;
  LinkSave::$rows = $rows;
  return LinkSave::save_links();
}
$rows = array(
  1 => array('name' => 'Eleanor Rettkowski', 'birth' => '', 'linked' => ''),
  2 => array('name' => 'Sahai Bhangu', 'birth' => '', 'linked' => ''),
  5 => array('name' => 'Kaine Miller', 'birth' => '', 'linked' => 'G6'),
);
save(array('bftd_link' => array(1 => 'G1', 2 => 'G2')), $rows);
check(array() === $GLOBALS['LINKS'], 'a suggestion that is not ticked links nothing');
check(0 === LinkSave::$syncs, 'and syncs nothing');
save(array('bftd_link' => array(1 => 'G1', 2 => 'G2'), 'bftd_link_ok' => array(1)), $rows);
check(array(1 => 'G1') === $GLOBALS['LINKS'], 'only the ticked row is linked');
check(1 === LinkSave::$syncs && false !== strpos(implode(' ', $GLOBALS['NOTES']), '2 caregivers added, with no email'), 'and it is synced there and then, its caregivers added and counted');
check(false !== strpos(implode(' ', $GLOBALS['LOG']), 'Eleanor Rettkowski was linked to GoHighLevel student Eleanor Rettkowski'), 'and the link is in the history');
save(array('bftd_link' => array(2 => 'G6'), 'bftd_link_ok' => array(2)), $rows);
check(array() === $GLOBALS['LINKS'] && false !== strpos(implode(' ', $GLOBALS['NOTES']), 'already linked to Kaine Miller'), 'a record already linked to another child is refused, and says to whom');
save(array('bftd_link' => array(2 => 'NOPE'), 'bftd_link_ok' => array(2)), $rows);
check(array() === $GLOBALS['LINKS'] && false !== strpos(implode(' ', $GLOBALS['NOTES']), 'was not found'), 'a record GoHighLevel does not have is refused');
save(array('bftd_link' => array(1 => 'G1', 2 => 'G1'), 'bftd_link_ok' => array(1, 2)), $rows);
check(array(1 => 'G1') === $GLOBALS['LINKS'], 'one record ticked for two students goes to the first only');
save(array('bftd_unlink' => array(5)), $rows);
check(array(5 => '') === $GLOBALS['LINKS'], 'a linked student can be unlinked');
check(0 === LinkSave::$syncs, 'unlinking alone syncs nothing');
save(array('bftd_unlink' => array(5), 'bftd_link' => array(2 => 'G6'), 'bftd_link_ok' => array(2)), $rows);
check(array(5 => '', 2 => 'G6') === $GLOBALS['LINKS'], 'and its record moved to another student in the same save');
LinkSave::$may = false;
check(false === save(array('bftd_link' => array(1 => 'G1'), 'bftd_link_ok' => array(1)), $rows) && array() === $GLOBALS['LINKS'], 'only a senior manager or an administrator can change links');
LinkSave::$may = true;
$GLOBALS['T']['bftd_ghl_students'] = array('ok' => false, 'error' => 'Invalid JWT', 'records' => array());
check(false === save(array('bftd_link' => array(1 => 'G1'), 'bftd_link_ok' => array(1)), $rows) && array() === $GLOBALS['LINKS'], 'nothing is linked when GoHighLevel did not answer');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
