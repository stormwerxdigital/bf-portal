<?php
/*
 * Renewal invoices (Karl, 1.219): "For regular students, without a linked
 * school, paying in advance, when they reach the end of their sessions, we
 * need a draft invoice created 1 week in advance of their last session that
 * we can adjust in the portal, and then send through GHL". GoHighLevel is a
 * stand-in here: nothing leaves the machine. Made-up families only.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

$META = array(); $STUDENTS = array(); $CALLS = array(); $REPLY = array(); $TITLE = array(); $MODE = array(); $LINKED = array(); $PEOPLE = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) {
  global $META;
  // As WordPress does: the filter may answer first.
  if (null !== BFTD_Invoices::watch_bank(null, $id, $k, $v)) return true;
  $META[$id][$k] = $v; return true;
}
function delete_post_meta($id, $k) { global $META; unset($META[$id][$k]); return true; }
function get_the_title($id) { return $GLOBALS['TITLE'][$id] ?? ''; }
function get_bloginfo($k = '') { return 'Brilliant Futures Tutoring'; }
if (!function_exists('wp_specialchars_decode')) { function wp_specialchars_decode($s, $q = 0) { return $s; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
if (!function_exists('add_filter')) { function add_filter(...$a) {} }
function get_userdata($id) { return $id ? (object) array('user_email' => 'Pat.Parent@example.com') : false; }
function get_posts($a) { return $GLOBALS['STUDENTS']; }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_CPT { const STUDENT = 'bftd_student'; public static function primary_client_id($sid) { return 7; } }
class BFTD_CRM { public static function linked($sid) { return $GLOBALS['LINKED'][$sid] ?? ''; } }
class BFTD_School_Billing { public static function mode_for($sid) { return $GLOBALS['MODE'][$sid] ?? ''; } }
class BFTD_Forecast { public static $applied = array(); public static function apply($sid) { self::$applied[] = $sid; } }
class BFTD_Roles { public static function can_administer() { return true; } }
class BFTD_GHL {
  public static function configured() { return true; }
  public static function location() { return 'loc1'; }
  public static function people($sid) { return $GLOBALS['PEOPLE'][$sid] ?? null; }
  public static function linked_students() { return $GLOBALS['LINKED']; }
  public static function request($m, $path, $q = array(), $b = null, $v = 'v3') {
    $GLOBALS['CALLS'][] = array($m, $path, $q, $b, $v);
    foreach ($GLOBALS['REPLY'] as $k => $r) if ($k === $m . ' ' . $path) return $r;
    return array(404, null, 'not found');
  }
}
if (!class_exists('BFTD_Time')) require BFTD_PATH . 'includes/class-bftd-time.php';
require BFTD_PATH . 'includes/class-bftd-invoices.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$set = function ($sid, $k, $v) { BFTD_Fields::set($sid, 'student', $k, $v); };

echo "What they bought last time:\n";
$set(1, 'lessons_bank', '24');
check(array(24) === array_column(BFTD_Invoices::purchases(1), 'n'), 'the first Sessions purchased is a purchase');
$set(1, 'lessons_bank', '24');
$set(1, 'lessons_bank', '20');
check(1 === count(BFTD_Invoices::purchases(1)), 'the same number, or a lower one, is not');
$set(1, 'lessons_bank', '36');
check(16 === BFTD_Invoices::last_purchase(1), 'going from 20 to 36 is a purchase of 16');
$META[2][BFTD_Schema::meta_key('student', 'lessons_bank')] = '16';   // from before the history was kept
check(16 === BFTD_Invoices::last_purchase(2), 'before there is a history, their Sessions purchased');

echo "\nA draft a week before the last session:\n";
$STUDENTS = array(1, 2, 3, 4);
$TITLE = array(1 => 'Ada Test', 2 => 'Ben Test', 3 => 'Cal Test', 4 => 'Dee Test');
$set(1, 'attend_to', '2027-03-10'); $set(1, 'rate', '45');
$set(2, 'attend_to', '2027-03-20');
$set(3, 'attend_to', '2027-03-09'); $set(3, 'lessons_bank', '10'); $MODE[3] = 'monthly';
$set(4, 'attend_to', '2027-03-01'); $set(4, 'lessons_bank', '10');
check(0 === BFTD_Invoices::make_drafts('2027-03-02'), 'nothing eight days out, nothing for a student a school pays for, nothing once the last session has passed');
check(1 === BFTD_Invoices::make_drafts('2027-03-03'), 'seven days before the last session, a draft');
$d = BFTD_Invoices::for_end(1, '2027-03-10');
check($d && 'draft' === $d['status'] && 16 === $d['qty'] && '45.00' === $d['rate'] && '2027-03-10' === $d['due'], 'the sessions they bought last time, at their rate, due on their last session, got ' . json_encode($d));
check(720.0 === BFTD_Invoices::total($d), 'and the total');
check(0 === BFTD_Invoices::make_drafts('2027-03-04'), 'one draft for each end date, however often it runs');
$set(1, 'attend_to', '2027-03-12');
check(1 === BFTD_Invoices::make_drafts('2027-03-05'), 'a new last session within the week gets its own');
check(null === BFTD_Invoices::for_end(1, '2027-03-12')['rate'] ? false : true, 'and it is a draft too');
check(array(1, 1) === array_values(array_map(function ($i) { return $i['student']; }, BFTD_Invoices::with_status('draft'))), 'both wait to be reviewed');
$d2 = BFTD_Invoices::for_end(1, '2027-03-12');
check(!is_wp_error(BFTD_Invoices::discard($d2['id'])) && 'void' === BFTD_Invoices::get($d2['id'])['status'], 'one can be discarded');
check(is_wp_error(BFTD_Invoices::discard($d2['id'])), 'only once');

echo "\nAdjusted before it is sent:\n";
$r = BFTD_Invoices::edit($d['id'], array('qty' => 16, 'rate' => '$42.5', 'due' => '2027-03-08', 'note' => 'Spring block'));
check(!is_wp_error($r) && 16 === $r['qty'] && '42.50' === $r['rate'] && '2027-03-08' === $r['due'] && 'Spring block' === $r['note'], 'sessions, rate, due date and a note');
check(is_wp_error(BFTD_Invoices::edit($d['id'], array('qty' => 0))) && is_wp_error(BFTD_Invoices::edit($d['id'], array('rate' => 'lots'))) && is_wp_error(BFTD_Invoices::edit($d['id'], array('due' => 'soon'))), 'and nothing that is not a number of sessions, an amount or a date');

echo "\nSent through GoHighLevel:\n";
$d = BFTD_Invoices::get($d['id']);
check(false !== strpos(BFTD_Invoices::blocked($d), 'currency'), 'not before the currency is chosen');
update_option(BFTD_Invoices::SETTINGS, array('currency' => 'CAD', 'sender' => 'u9'));
check(false !== strpos(BFTD_Invoices::blocked($d), 'not linked'), 'nor for a student not linked to GoHighLevel');
$LINKED[1] = 'rec1';
check(false !== strpos(BFTD_Invoices::blocked($d), 'no contact'), 'nor with no contact to send it to');
$PEOPLE[1] = array('contacts' => array(
  array('id' => 'c1', 'name' => 'Grandma Test', 'email' => 'gran@example.com', 'phone' => '', 'primary' => true),
  array('id' => 'c2', 'name' => 'Pat Parent', 'email' => 'pat.parent@example.com', 'phone' => '555', 'primary' => false),
));
check('c2' === BFTD_Invoices::bill_to(1)['id'], 'billed to the contact whose email is the client\'s, before the one tagged primary');
check('' === BFTD_Invoices::blocked($d), 'then it can go');
$REPLY = array('POST /invoices/' => array(403, array('message' => 'scope'), 'scope'));
$r = BFTD_Invoices::send($d['id']);
check(is_wp_error($r) && false !== strpos($r->get_error_message(), 'invoices.write') && 'draft' === BFTD_Invoices::get($d['id'])['status'], 'a token without the invoices permission says so, and it stays a draft');
$CALLS = array();
$REPLY = array('POST /invoices/' => array(200, array('_id' => 'inv9', 'invoiceNumber' => 19), ''), 'POST /invoices/inv9/send' => array(500, null, 'busy'));
$r = BFTD_Invoices::send($d['id']);
$body = $CALLS[0][3];
check('2021-07-28' === $CALLS[0][4] && 'loc1' === $body['altId'] && 'location' === $body['altType'] && 'CAD' === $body['currency'], 'made in the location, in the currency chosen');
check(16 === $body['items'][0]['qty'] && 42.5 === $body['items'][0]['amount'] && '16 tutoring sessions for Ada' === $body['items'][0]['description'], 'with the sessions and rate as adjusted, got ' . json_encode($body['items'][0]));
check('c2' === $body['contactDetails']['id'] && array('pat.parent@example.com') === $body['sentTo']['email'] && '2027-03-08' <= $body['dueDate'], 'to the client\'s contact, due as set');
check(is_wp_error($r) && 'inv9' === BFTD_Invoices::get($d['id'])['ghl_id'] && 'draft' === BFTD_Invoices::get($d['id'])['status'], 'a send that fails after the invoice is made keeps it, still a draft');
$CALLS = array();
$REPLY['POST /invoices/inv9/send'] = array(200, array('invoice' => array('status' => 'sent')), '');
$r = BFTD_Invoices::send($d['id']);
check(!is_wp_error($r) && 1 === count($CALLS) && '/invoices/inv9/send' === $CALLS[0][1], 'sending again sends that one, without making a second');
check('email' === $CALLS[0][3]['action'] && 'u9' === $CALLS[0][3]['userId'] && 'sent' === $r['status'] && '19' === $r['number'], 'by email, from the user chosen, and it is marked sent');
check(is_wp_error(BFTD_Invoices::edit($d['id'], array('qty' => 3))), 'a sent invoice can no longer be changed here');

echo "\nPaid:\n";
$REPLY['GET /invoices/inv9'] = array(200, array('status' => 'sent'), '');
check(0 === BFTD_Invoices::check_paid() && '36' === (string) BFTD_Fields::get(1, 'student', 'lessons_bank'), 'not paid yet, nothing added');
$REPLY['GET /invoices/inv9'] = array(200, array('status' => 'paid'), '');
check(1 === BFTD_Invoices::check_paid(), 'GoHighLevel says paid');
check('52' === (string) BFTD_Fields::get(1, 'student', 'lessons_bank') && in_array(1, BFTD_Forecast::$applied, true), 'its sessions are added to Sessions purchased and Attending to is worked out again');
check(16 === BFTD_Invoices::last_purchase(1), 'and that is now what they bought last time');
check(0 === BFTD_Invoices::check_paid() && '52' === (string) BFTD_Fields::get(1, 'student', 'lessons_bank'), 'never added twice');

echo "\nThe Invoices page draws:\n";
foreach (array('esc_attr', 'esc_url', 'esc_textarea') as $fn) if (!function_exists($fn)) eval("function $fn(\$s) { return htmlspecialchars((string) \$s, ENT_QUOTES); }");
if (!function_exists('selected')) { function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected'; } }
if (!function_exists('disabled')) { function disabled($a, $b = true) { if ($a === $b) echo ' disabled'; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return '/wp-admin/' . $p; } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if (!function_exists('get_edit_post_link')) { function get_edit_post_link($id) { return '/wp-admin/post.php?post=' . $id; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim((string) $s); } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
$PEOPLE[1]['staff'] = array(array('id' => 'u9', 'name' => 'Office Test', 'email' => 'office@example.com', 'role' => 'owner'));
$set(4, 'attend_to', '2027-03-20'); $set(4, 'rate', '');
BFTD_Invoices::maybe_draft(4, '2027-03-14');
ob_start(); BFTD_Invoices::render_page(); $html = ob_get_clean();
check(false !== strpos($html, 'Dee Test') && false !== strpos($html, 'Set a rate') && false !== strpos($html, ' disabled'), 'a draft with no rate shows why it cannot be sent yet');
check(false !== strpos($html, '#19') && false !== strpos($html, 'Paid, 16 sessions added'), 'the paid invoice is listed with its number');
check(false !== strpos($html, 'Office Test (office@example.com)') && preg_match('/value="CAD"\s+selected/', $html), 'the settings show the currency and who it is sent from');

echo "\nOn the screens:\n";
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-invoices.php');
check(false !== strpos($src, "check_admin_referer( 'bftd_invoice' )") && false !== strpos($src, "check_admin_referer( 'bftd_invoice_settings' )") && 2 <= substr_count($src, 'if ( ! self::can() ) wp_die('), 'changes need a nonce and a senior manager or administrator');
check(false !== strpos($src, "add_action( 'admin_notices', array( __CLASS__, 'banner' ) )"), 'a banner says when drafts are waiting');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
