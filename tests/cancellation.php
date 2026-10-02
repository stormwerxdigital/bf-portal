<?php
/*
 * A cancelled session has to say who and why.
 *
 * "Cancelled by: Family" told a practice nothing it could act on — which
 * parent, or whether it was the tutor who moved it — and it was also what the
 * record said when somebody picked the wrong one of two options. So the
 * question is answered with a name off the child's own record.
 *
 * Both answers are required, and the requirement is enforced twice. The
 * browser asks before it will publish, which is the part a tutor meets. This
 * file is about the other part: quick edit, bulk edit, a REST call and a
 * tutor with scripts blocked all go straight past a browser check, and what
 * comes out the other side is a row on a family's progress report saying
 * their child's session did not happen and nothing else.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS']   = array();
$GLOBALS['META']    = array();
$GLOBALS['UPDATES'] = array();
$GLOBALS['USERS']   = array();

function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p ? $p->post_title : ''; }
function get_post_meta($id, $k, $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return null === $v ? ($s ? '' : array()) : ($s ? $v : (array) $v); }
function get_userdata($id) { return $GLOBALS['USERS'][(int) $id] ?? false; }
function get_current_user_id() { return 1; }
function remove_action(...$a) {}
function wp_update_post($args) { $GLOBALS['UPDATES'][] = $args; if (isset($GLOBALS['POSTS'][$args['ID']])) $GLOBALS['POSTS'][$args['ID']]->post_status = $args['post_status']; return $args['ID']; }
function set_transient($k, $v, $t = 0) { $GLOBALS['TRANS'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['TRANS'][$k] ?? false; }
function delete_transient($k) { unset($GLOBALS['TRANS'][$k]); return true; }

/* Lift a run of source out of a file, so the test drives the real code rather
   than a second copy of it that can agree with a rule nobody wrote. */
function lift($file, $start, $end) {
  $src = file_get_contents(BFTD_PATH . $file);
  $a = strpos($src, $start);
  $b = strpos($src, $end, $a);
  if (false === $a || false === $b) { echo "FAIL: cannot find $start in $file\n"; exit(1); }
  return substr($src, $a, $b - $a);
}

/* The two rules about people are the real ones: how the option list is built
   and how a stored answer is written out have to agree, and a stub of either
   would let them drift apart in exactly the way this guards. */
eval('class BFTD_CPT {
  const STUDENT = "bftd_student"; const SESSION = "bftd_session";
  const CLIENT_KEY = "_bftd_client_user_id"; const STAFF_KEY = "_bftd_assigned_staff";
  const PRIMARY_KEY = "_bftd_client_primary";
  public static function student_id($id) { return (int) ($GLOBALS["META"][$id]["_bftd_student_id"] ?? 0); }
  public static function client_ids($sid) { return array_map("intval", (array) ($GLOBALS["META"][$sid][self::CLIENT_KEY] ?? array())); }
  public static function staff_ids($sid) { return array_map("intval", (array) ($GLOBALS["META"][$sid][self::STAFF_KEY] ?? array())); }
  public static function primary_client_id($sid) { return (int) ($GLOBALS["META"][$sid][self::PRIMARY_KEY] ?? 0); }
  '
  . lift('includes/class-bftd-cpt.php', "\tpublic static function people_on(", "\t/**\n\t * What to print where")
  . lift('includes/class-bftd-cpt.php', "\tpublic static function cancelled_by_label(", "\n\t/** The one client")
  . ' }');

class BFTD_Roles {
  public static function relationship($u) { return 'Parent or guardian'; }
  public static function role_name($u) { return 'Tutor'; }
}
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) { return $GLOBALS['SESSION_META'][$id][$k] ?? ''; }
}

/* The three methods under test, lifted out rather than booting the plugin. */
$M = 'includes/class-bftd-metaboxes.php';
eval('class MB { '
  . lift($M, "\tpublic static function session_fields(", "\t/**\n\t * The people this session")
  . lift($M, "\tpublic static function cancelled_by_options(", "\t/**\n\t * Which lesson this is")
  . lift($M, "\tpublic static function hold_unexplained(", "\n\t/**\n\t * Linking a caregiver")
  . ' }');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* A child with two caregivers, one of them the client, and a tutor. */
$GLOBALS['USERS'][20] = (object) array('ID' => 20, 'display_name' => 'Laurel Sanders');
$GLOBALS['USERS'][21] = (object) array('ID' => 21, 'display_name' => 'Dana Cole');
$GLOBALS['USERS'][5]  = (object) array('ID' => 5,  'display_name' => 'Rae T');
$GLOBALS['META'][11] = array(
  '_bftd_client_user_id' => array(20, 21),
  '_bftd_client_primary' => 20,
  '_bftd_assigned_staff' => array(5),
);
$GLOBALS['META'][40] = array('_bftd_student_id' => 11);
$GLOBALS['POSTS'][40] = (object) array('ID' => 40, 'post_type' => 'bftd_session', 'post_status' => 'publish', 'post_title' => 'x');
$GLOBALS['SESSION_META'][40] = array();

/* ---- who it can have been ---- */
$opts = MB::cancelled_by_options(40);
check(isset($opts['20']) && false !== strpos($opts['20'], 'Laurel Sanders'), 'the list offers the caregivers by name');
check(false !== strpos($opts['20'], 'Client'), 'saying which one is the client, because that is who you ring');
check(isset($opts['21']) && false !== strpos($opts['21'], 'Parent or guardian'), 'and what the others are');
check(isset($opts['5']) && false !== strpos($opts['5'], 'Rae T'), 'and the tutor, because a practice cancels too');
check(isset($opts['']) && 'Choose someone' === $opts[''], 'with nothing preselected, so it cannot be left at a default');
check(false === array_search('Family', $opts, true), 'and never the two words it used to offer');

/* A record already holding an answer that is no longer on the list keeps it.
   Opening a session must not silently blank an answer somebody gave. */
$GLOBALS['SESSION_META'][40]['missed_by'] = 'family';
$opts = MB::cancelled_by_options(40);
check(isset($opts['family']) && false !== strpos($opts['family'], 'Family'),
  'an answer recorded before this still reads as itself, got ' . json_encode($opts['family'] ?? null));
check(false !== strpos($opts['family'], 'no longer listed'), 'and says it is not a choice any more');

$GLOBALS['SESSION_META'][40]['missed_by'] = '99';
$opts = MB::cancelled_by_options(40);
check(isset($opts['99']), 'so does a caregiver who has since been removed');
$GLOBALS['SESSION_META'][40]['missed_by'] = '';

/* The save sanitises a select against its own options, so a list built only
   at render time throws away every answer on the way in. */
$fields = MB::session_fields(40);
check(isset($fields['missed_by']['options']['20']), 'the field the save reads is built from the same list');
check(!isset($fields['missed_counts']), 'and there is no "counts against allowance" box left to answer');

/* And the save has to ask for that list. A select is sanitised against its
   own options, so save_fields() calling session_fields() without the session
   would throw away every answer on the way in — the tutor picks a name,
   presses update, and the field is empty again with nothing said. */
$save = lift('includes/class-bftd-metaboxes.php',
  '} elseif ( BFTD_CPT::SESSION === $post->post_type ) {', '} elseif ( BFTD_CPT::RESOURCE');
check(false !== strpos($save, 'self::session_fields( $post_id )'),
  'the save asks for the list built from this session, not a bare one');

$bare = MB::session_fields();
check(array('' => 'Choose someone') === $bare['missed_by']['options'],
  'asked without a session it offers nobody, rather than guessing, got ' . json_encode($bare['missed_by']['options']));

/* ---- and it cannot be published without them ---- */
function held($status, $by, $why) {
  $GLOBALS['UPDATES'] = array(); $GLOBALS['TRANS'] = array();
  $GLOBALS['POSTS'][40]->post_status = 'publish';
  $GLOBALS['SESSION_META'][40] = array('status' => $status, 'missed_by' => $by, 'missed_why' => $why);
  return MB::hold_unexplained(40, $GLOBALS['POSTS'][40]);
}

check(true === held('missed', '', ''), 'publishing a cancellation with nothing said about it is held');
check('draft' === $GLOBALS['POSTS'][40]->post_status, 'as a draft, so nothing written is lost');
check(false !== strpos((string) get_transient('bftd_held_1'), 'who cancelled it'), 'and the tutor is told what is missing');
check(false !== strpos((string) get_transient('bftd_held_1'), 'why'), 'both of them');

check(true === held('missed', '20', ''), 'a name with no reason is still held');
check(true === held('missed', '', 'Family away'), 'and a reason with no name');
check(false === held('missed', '20', 'Family away'), 'with both, it publishes');
check('publish' === $GLOBALS['POSTS'][40]->post_status, 'and stays published');

check(true === held('rescheduled', '', ''), 'a reschedule is held on the same terms');
check(false === held('held', '', ''), 'while a session that went ahead is asked none of this');

/* Whitespace is not an answer. */
check(true === held('missed', '20', '   '), 'a reason of spaces is no reason');
check(true === held('missed', '20', '<p></p>'), 'and neither is an empty paragraph the editor left behind');

/* A draft is allowed to be half written. That is what a draft is for, and it
   is where this puts the record. */
$GLOBALS['POSTS'][40]->post_status = 'draft';
$GLOBALS['SESSION_META'][40] = array('status' => 'missed', 'missed_by' => '', 'missed_why' => '');
$GLOBALS['UPDATES'] = array();
check(false === MB::hold_unexplained(40, $GLOBALS['POSTS'][40]), 'a draft is left alone');
check(array() === $GLOBALS['UPDATES'], 'and nothing is written to say so');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
