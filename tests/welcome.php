<?php
/*
 * 1.204 (Karl): no notifications to families sent automatically, even
 * welcome emails; booking notifications are GoHighLevel's. Staff emails carry
 * on. The welcome is sent by hand, from the Users list: a Welcome email
 * column, a Send welcome email row action and bulk action, for family
 * accounts linked to a published student who have not had it.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['OPT'] = array(); $GLOBALS['UMETA'] = array(); $GLOBALS['MAILS'] = array(); $GLOBALS['AUDIT'] = array(); $GLOBALS['NOTICES'] = array(); $GLOBALS['TRANS'] = array();
function get_user_meta($u, $k, $s = false) { return $GLOBALS['UMETA'][$u][$k] ?? ($s ? '' : array()); }
function update_user_meta($u, $k, $v) { $GLOBALS['UMETA'][$u][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['TRANS'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['TRANS'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['TRANS'][$k]); return true; }
function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
function wp_mail($to, $subject, $body, $headers = array()) { $GLOBALS['MAILS'][] = array($to, $subject); return empty($GLOBALS['MAIL_FAILS']); }
function get_the_title($id) { return 'Student ' . $id; }
function wpautop($t) { return '<p>' . $t . '</p>'; }
function make_clickable($t) { return $t; }
function add_filter(...$a) { return true; } function remove_filter(...$a) { return true; }
function wp_lostpassword_url() { return 'https://example.test/lost'; }
function current_user_can($cap, $id = 0) { return !empty($GLOBALS['CAN'][$cap]); }
function wp_nonce_url($u, $a) { return $u . '&_wpnonce=x'; }
function wp_get_current_user() { return (object) array('ID' => 1, 'display_name' => 'Laurel Sanders', 'roles' => array('bftd_tutor')); }
function get_current_user_id() { return 1; }
$GLOBALS['USERS'] = array(
  7 => (object) array('ID' => 7, 'user_email' => 'mum@example.test', 'display_name' => 'Mum Lee', 'first_name' => 'Mum', 'roles' => array('bftd_client')),
  8 => (object) array('ID' => 8, 'user_email' => 'laurel@example.test', 'display_name' => 'Laurel', 'first_name' => 'Laurel', 'roles' => array('bftd_tutor')),
);
function get_userdata($id) { return $GLOBALS['USERS'][(int) $id] ?? false; }

class BFTD_Audit { public static function log($e, $a = array()) { $GLOBALS['AUDIT'][] = $e . ': ' . ($a['summary'] ?? ''); } }
class BFTD_Autosave { public static $dry = false; }
class BFTD_Notices { public static function add(...$a) { $GLOBALS['NOTICES'][] = $a; } }
class BFTD_Dashboard { public static function url($sid) { return 'https://example.test/portal'; } }
class BFTD_Roles {
  public static function is_client($u = null) { return in_array('bftd_client', (array) $u->roles, true); }
  public static function is_staff($u = null) { return !empty($GLOBALS['STAFF_ME']); }
}
$GLOBALS['PUBLISHED'] = array(7 => array(40, 41));
class BFTD_CPT { public static function students_for_client($uid) { return $GLOBALS['PUBLISHED'][$uid] ?? array(); } }
class BFTD_Change_Notify { public static function welcomed_key($sid) { return '_bftd_welcomed_' . (int) $sid; } }
define('BFTD_PATH', dirname(__DIR__) . '/');
require BFTD_PATH . 'includes/class-bftd-emails.php';
require BFTD_PATH . 'includes/class-bftd-welcome.php';
/* The one method this needs from the student editor, lifted as it is. */
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
preg_match('/\tpublic static function send_welcome\( \$user_id, \$student_id \) \{.*?\n\t\}\n/s', $mb, $m);
eval('class BFTD_MetaBoxes {' . $m[0] . '}');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$GLOBALS['OPT'][BFTD_Emails::OPTION_BRAND] = array('quiet_start' => '', 'quiet_end' => '', 'daily_cap' => 0);

/* ---- families are never emailed automatically ---- */
foreach (BFTD_Emails::templates() as $key => $cfg) {
  if ('client' !== ($cfg['audience'] ?? '')) continue;
  $why = BFTD_Emails::may_send($key, array('to' => 'mum@example.test'));
  check(is_string($why) && false !== strpos($why, 'only emailed when somebody sends it by hand'), "$key does not go to a family by itself");
}
$GLOBALS['MAILS'] = array();
check(false === BFTD_Emails::send('client_welcome', 'mum@example.test', array()) && array() === $GLOBALS['MAILS'], 'even the welcome, set to send automatically');
check(false !== strpos(end($GLOBALS['AUDIT']), 'Families are only emailed when somebody sends it by hand'), 'and the history says why it was held');
check(true === BFTD_Emails::may_send('client_welcome', array('to' => 'mum@example.test', 'reviewed' => true)), 'a send somebody clicked still goes');
check(true === BFTD_Emails::may_send('staff_assigned', array('to' => 'laurel@example.test')) && true === BFTD_Emails::may_send('staff_new_message', array('to' => 'laurel@example.test', 'in_digest' => true)), 'staff emails carry on as before (Karl: families only)');

/* ---- sending the welcome by hand ---- */
$GLOBALS['STAFF_ME'] = true; $GLOBALS['CAN']['edit_user'] = true;
check(array(40, 41) === BFTD_Welcome::pending(7), 'a family member linked to two published students is owed two welcomes');
$col = BFTD_Welcome::render_column('', 'bftd_welcome', 7);
check(false !== strpos($col, 'Not sent') && false !== strpos($col, 'Send welcome email'), 'the Users list says Not sent, with Send welcome email');
check('' === BFTD_Welcome::render_column('', 'bftd_welcome', 8), 'and says nothing for a tutor');
check(isset(BFTD_Welcome::row_action(array(), get_userdata(7))['bftd_send_welcome']) && !isset(BFTD_Welcome::row_action(array(), get_userdata(8))['bftd_send_welcome']), 'Send welcome email is a row action for the family member, not for staff');

$GLOBALS['MAIL_FAILS'] = true; $GLOBALS['MAILS'] = array(); $GLOBALS['NOTICES'] = array();
list($sent, $failed) = BFTD_Welcome::send_to(7);
check(0 === $sent && 2 === $failed && array(40, 41) === BFTD_Welcome::pending(7) && array() === $GLOBALS['NOTICES'], 'a welcome that did not go is not marked sent, and leaves no portal notice');
$GLOBALS['MAIL_FAILS'] = false; $GLOBALS['MAILS'] = array();
list($sent, $failed) = BFTD_Welcome::send_to(7);
check(2 === $sent && 2 === count($GLOBALS['MAILS']) && array() === BFTD_Welcome::pending(7) && 2 === count($GLOBALS['NOTICES']), 'sent by hand: one welcome per student, each marked sent, each with its portal notice');
check(false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'The welcome email was sent by hand to Mum Lee (2 students).'), 'and the history says who sent it to whom');
$col = BFTD_Welcome::render_column('', 'bftd_welcome', 7);
check(0 === strpos($col, 'Sent ') && false === strpos($col, 'Send welcome email') && array() === BFTD_Welcome::row_action(array(), get_userdata(7)), 'then the list says Sent, with no offer to send again');
$GLOBALS['MAILS'] = array();
BFTD_Welcome::send_to(7);
check(array() === $GLOBALS['MAILS'], 'nobody is welcomed twice');

$GLOBALS['PUBLISHED'][7][] = 42;
check(false !== strpos(BFTD_Welcome::render_column('', 'bftd_welcome', 7), 'Not yet for 1 more student'), 'a new published student is offered again, for that student only');
$GLOBALS['PUBLISHED'][7] = array();
$GLOBALS['UMETA'][7] = array();
check(false !== strpos(BFTD_Welcome::render_column('', 'bftd_welcome', 7), 'No published student yet'), 'with no published student, there is nothing to send');

$GLOBALS['PUBLISHED'][7] = array(40);
$GLOBALS['STAFF_ME'] = false;
check(false === BFTD_Welcome::may_send_to(7) && array() === BFTD_Welcome::row_action(array(), get_userdata(7)), 'only staff who can edit the account may send it');
$GLOBALS['STAFF_ME'] = true; $GLOBALS['CAN']['edit_user'] = false;
check(false === BFTD_Welcome::may_send_to(7), 'and only when they can edit that person');
$GLOBALS['CAN']['edit_user'] = true;

/* ---- bulk ---- */
$GLOBALS['MAILS'] = array();
$GLOBALS['PUBLISHED'][8] = array(40);   // a tutor somehow on a student as a caregiver: still not sent a family welcome
BFTD_Welcome::handle_bulk('/users.php', 'bftd_send_welcome', array(7, 8));
$fl = $GLOBALS['TRANS']['bftd_welcome_flash_1'] ?? array();
check(1 === count($GLOBALS['MAILS']) && false !== strpos($fl[1] ?? '', 'Welcome email sent to 1 person.') && false !== strpos($fl[1] ?? '', '1 was left out'), 'the bulk action welcomes whoever is owed it and says who was left out');

/* ---- nothing welcomes by itself any more ---- */
$cn = file_get_contents(BFTD_PATH . 'includes/class-bftd-change-notify.php');
check(1 === preg_match('/public static function on_publish\( \$new_status, \$old_status, \$post \) \{\s*(\/\/[^\n]*\n\s*)*return;\s*\}/', $cn), 'publishing a student welcomes nobody');
check(1 === preg_match("/if \( 'client_linked' === \\\$event \) \{\s*(\/\/[^\n]*\n\s*)*return;/", $mb), 'linking a caregiver welcomes nobody');
check(false !== strpos($mb, "'reviewed' => true )"), 'the welcome sent by hand counts as a send somebody clicked');
$em = file_get_contents(BFTD_PATH . 'includes/class-bftd-emails.php');
check(false !== strpos($em, 'Families are never emailed automatically'), 'the Emails screen says family emails go only by hand, whatever the rule');
$main = file_get_contents(BFTD_PATH . 'bf-tutoring-dashboard.php');
check(false !== strpos($main, "'welcome',") && false !== strpos($main, 'BFTD_Welcome::init();'), 'and the Users list part is loaded');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
