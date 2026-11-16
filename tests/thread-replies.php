<?php
/*
 * Who hears about a tutor's reply in a conversation (1.186).
 *
 * Karl: only the person who posted. A reply went to every caregiver on the
 * student, and since 1.180 those include school and agency contacts brought
 * in from GoHighLevel, who never wrote anything. A family writing still
 * reaches the tutors as before.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$GLOBALS['COMMENTS'] = array(); $GLOBALS['SENT'] = array(); $GLOBALS['NOTED'] = array(); $GLOBALS['QUEUED'] = 0;
$GLOBALS['USERS'] = array(
  1 => array('email' => 'tutor@example.test', 'staff' => true),
  7 => array('email' => 'mum@example.test'),
  8 => array('email' => 'dad@example.test'),
  9 => array('email' => 'casemanager@example.test'),
  10 => array('email' => 'gone@example.test'),
);
$GLOBALS['CLIENTS'] = array(7, 8, 9);
$GLOBALS['LIVE'] = true;
function add_action(...$a) {} function add_filter(...$a) {}
function get_comment($id) { return $GLOBALS['COMMENTS'][$id] ?? null; }
function get_comments($a) { $o = array(); foreach ($GLOBALS['COMMENTS'] as $c) if ((int) $c->comment_parent === (int) $a['parent']) $o[] = $c; return $o; }
function get_userdata($id) { if (!isset($GLOBALS['USERS'][$id])) return false; $u = $GLOBALS['USERS'][$id]; return (object) array('ID' => $id, 'user_email' => $u['email'], 'first_name' => '', 'display_name' => 'User ' . $id); }
function get_the_title($id) { return 'Student ' . $id; }
function wp_strip_all_tags($t, $b = false) { return strip_tags($t); }
if (!function_exists('wp_trim_words')) { function wp_trim_words($t, $n = 55) { return $t; } }
class BFTD_CPT { const STUDENT = 'bftd_student'; public static function student_id($id) { return 50; } public static function client_ids($id) { return $GLOBALS['CLIENTS']; } }
class BFTD_Roles { public static function is_staff($u = 0) { return !empty($GLOBALS['USERS'][$u]['staff']); } }
class BFTD_Change_Notify { public static function is_published($id) { return $GLOBALS['LIVE']; } public static function queue_message(...$a) { $GLOBALS['QUEUED']++; } }
class BFTD_Emails { public static function send($tpl, $to, $tags = array(), $ctx = array()) { $GLOBALS['SENT'][] = $tpl . ' ' . $to; return true; } }
class BFTD_Notices { public static function add($uid, ...$a) { $GLOBALS['NOTED'][] = $uid; } }
class BFTD_Dashboard { public static function url(...$a) { return '#'; } }
class BFTD_Access { public static function notify_staff_ids($s) { return array(1); } }
class BFTD_Settings { public static function routing() { return array(); } public static function copy_addresses() { return array(); } }
class BFTD_Schema { public static function label($s) { return 'Reading'; } public static function sections() { return array(); } }
require BFTD_PATH . 'includes/class-bftd-threads.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function msg($id, $parent, $user) { $GLOBALS['COMMENTS'][$id] = (object) array('comment_ID' => $id, 'comment_parent' => $parent, 'user_id' => $user, 'comment_type' => BFTD_Threads::TYPE); }
$notify = new ReflectionMethod('BFTD_Threads', 'notify'); $notify->setAccessible(true);
function reply($thread, $by) { global $notify; $GLOBALS['SENT'] = array(); $GLOBALS['NOTED'] = array(); $notify->invoke(null, $thread, 999, 60, 'reading', $by, 'Thanks for asking.'); }

/* Mum asks; the tutor replies. */
msg(100, 0, 7); msg(101, 100, 1);
reply(100, 1);
check(array('client_new_message mum@example.test') === $GLOBALS['SENT'], 'a tutor\'s reply emails the caregiver who asked, and nobody else, got ' . json_encode($GLOBALS['SENT']));
check(array(7) === $GLOBALS['NOTED'], 'and only they get the portal notice');

/* Dad joins in; the next reply reaches both who wrote, once each. */
msg(102, 100, 8); msg(103, 100, 7);
reply(100, 1);
check(array('client_new_message mum@example.test', 'client_new_message dad@example.test') === $GLOBALS['SENT'], 'everyone who wrote in the conversation hears, each once');
check(!in_array('client_new_message casemanager@example.test', $GLOBALS['SENT'], true), 'a caregiver who never wrote, such as a case manager from GoHighLevel, does not');

/* A conversation the tutor started, with no caregiver in it yet. */
msg(200, 0, 1);
reply(200, 1);
check(array() === $GLOBALS['SENT'] && array() === $GLOBALS['NOTED'], 'a conversation only staff have written in emails no caregiver');
check($GLOBALS['QUEUED'] > 0, 'it is still offered on the review bar, for somebody to send');

/* Someone who wrote and has since lost access to the student. */
msg(300, 0, 10); msg(301, 300, 7);
reply(300, 1);
check(array('client_new_message mum@example.test') === $GLOBALS['SENT'], 'someone no longer a caregiver on the student is not told, even if they wrote');

/* A family writing still reaches the tutor. */
reply(100, 7);
check(array('staff_new_message tutor@example.test') === $GLOBALS['SENT'], 'a caregiver writing still reaches the tutor');

/* Nothing about a draft. */
$GLOBALS['LIVE'] = false;
reply(100, 1);
check(array() === $GLOBALS['SENT'], 'and a reply on a draft record still tells nobody');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
