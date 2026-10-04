<?php
/*
 * Whose lesson was it.
 *
 * A published lesson pays somebody, so a published lesson has to say who.
 * There are two halves to that and they pull in opposite directions.
 *
 * A tutor writing up their own lesson should never have to name themselves.
 * It is the only answer their record can have, and a field they fill in
 * fifteen times a fortnight is a field they will eventually get wrong.
 *
 * A manager publishing somebody else's backlog has to name them, because the
 * obvious default — whoever pressed the button — pays the manager for a
 * lesson they did not teach. That one is not an inconvenience, it is a wrong
 * payment with an audit trail saying it was intended.
 *
 * So it fills itself for a tutor, is asked of an approver, and a lesson that
 * still has nobody on it is held back as a draft rather than published to
 * nowhere.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS']   = array();
$GLOBALS['SM']      = array();   // session meta, by post then key
$GLOBALS['UPDATES'] = array();
$GLOBALS['ME']      = 0;
$GLOBALS['CAPS']    = array();

function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_current_user_id() { return (int) $GLOBALS['ME']; }
function user_can($id, $cap) { return in_array($cap, $GLOBALS['CAPS'][(int) $id] ?? array(), true); }
function remove_action(...$a) {}
function add_action(...$a) {}
function wp_update_post($args) {
  $GLOBALS['UPDATES'][] = $args;
  if (isset($GLOBALS['POSTS'][$args['ID']]) && isset($args['post_status'])) {
    $GLOBALS['POSTS'][$args['ID']]->post_status = $args['post_status'];
  }
  return $args['ID'];
}
function set_transient($k, $v, $t = 0) { $GLOBALS['TRANS'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['TRANS'][$k] ?? false; }

function lift($file, $start, $end) {
  $src = file_get_contents(BFTD_PATH . $file);
  $a = strpos($src, $start);
  $b = strpos($src, $end, $a);
  if (false === $a || false === $b) { echo "FAIL: cannot find $start in $file\n"; exit(1); }
  return substr($src, $a, $b - $a);
}

class BFTD_Roles {
  const ADMIN_CAP = 'bftd_administer';
  const STAFF_CAP = 'bftd_staff';
  public static function is_staff($id = null) {
    $id = $id ? $id : get_current_user_id();
    return user_can($id, self::STAFF_CAP) || user_can($id, 'manage_options');
  }
}
class BFTD_Pay {
  public static function can_approve($id = 0) {
    $id = $id ? $id : get_current_user_id();
    return user_can($id, BFTD_Roles::ADMIN_CAP) || user_can($id, 'manage_options');
  }
}
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) { return $GLOBALS['SM'][$id][$k] ?? ''; }
  public static function set($id, $g, $k, $v) { $GLOBALS['SM'][$id][$k] = $v; }
}

/* The two methods under test, lifted so the test drives the real code. */
$M = 'includes/class-bftd-metaboxes.php';
eval('class MB { '
  . lift($M, "\tpublic static function claim_session(", "\t/**\n\t * A published lesson with nobody")
  . lift($M, "\tpublic static function hold_unclaimed(", "\n\tpublic static function hold_unexplained(")
  . ' }');

$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? "  ok   " : "FAIL  ") . $msg . "\n"; if (!$ok) $fail++; }

function a_session($id, $status = 'publish', $author = 0) {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => 'bftd_session', 'post_status' => $status, 'post_author' => $author);
  $GLOBALS['SM'][$id] = array();
}
function get_post_field($f, $id) { $p = $GLOBALS['POSTS'][(int) $id] ?? null; return $p ? ($p->$f ?? '') : ''; }
function taught_by($id) { return (string) ($GLOBALS['SM'][$id]['delivered_by'] ?? ''); }

/* Laurel is a tutor. The boss can approve pay. */
$GLOBALS['CAPS'][5] = array(BFTD_Roles::STAFF_CAP);
$GLOBALS['CAPS'][9] = array(BFTD_Roles::STAFF_CAP, BFTD_Roles::ADMIN_CAP);
$GLOBALS['CAPS'][3] = array();   // somebody with no business here at all

/* ---- a tutor writing up their own ---- */
$GLOBALS['ME'] = 5;
a_session(40, 'publish', 5);
MB::claim_session(40);
check('5' === taught_by(40), 'a tutor publishing a session is the tutor who taught it');
check(false === MB::hold_unclaimed(40, get_post(40)), 'so nothing is held back');
check('publish' === get_post(40)->post_status, 'and it publishes');

/* ---- a tutor who covered for somebody, and said so ---- */
a_session(41);
BFTD_Fields::set(41, 'session', 'delivered_by', '8');
MB::claim_session(41);
check('8' === taught_by(41), 'a name already on the record is left alone, because covering is real');

/* ---- whoever creates the session taught it ----
 * Karl's rule. A senior manager or administrator who starts a session is its
 * tutor by default, the same as anybody else, and the Taught by box beside
 * Who can see this changes it. */
$GLOBALS['ME'] = 9;
a_session(42, 'publish', 9);
MB::claim_session(42);
check('9' === taught_by(42), 'a senior manager who creates a session taught it');
check(false === MB::hold_unclaimed(42, get_post(42)), 'so it publishes');

$GLOBALS['ME'] = 9;
a_session(46, 'publish', 5);
MB::claim_session(46);
check('5' === taught_by(46), 'the creator, not whoever presses Publish, when somebody else publishes it');

/* With nobody on it at all it is still held, and says why. */
$GLOBALS['ME'] = 3;
a_session(47, 'publish', 3);
MB::claim_session(47);
check(true === MB::hold_unclaimed(47, get_post(47)), 'a session with nobody to pay is held');
check('draft' === get_post(47)->post_status, 'as a draft, which keeps every word the tutor wrote');
check(false !== strpos((string) get_transient('bftd_held_3'), 'unclaimed'), 'and says why');
BFTD_Fields::set(47, 'session', 'delivered_by', '5');
$GLOBALS['POSTS'][47]->post_status = 'publish';
check(false === MB::hold_unclaimed(47, get_post(47)), 'named in the Taught by box, it publishes');

/* ---- a draft is nobody's problem yet ---- */
a_session(43, 'draft');
check(false === MB::hold_unclaimed(43, get_post(43)), 'a draft with nobody on it is just a draft');

/* ---- somebody who is not staff at all ---- */
$GLOBALS['ME'] = 3;
a_session(44);
MB::claim_session(44);
check('' === taught_by(44), 'somebody who is not staff does not get claimed as a tutor');

/* ---- nobody logged in ---- */
$GLOBALS['ME'] = 0;
a_session(45);
MB::claim_session(45);
check('' === taught_by(45), 'and neither does nobody');
check(true === MB::hold_unclaimed(45, get_post(45)), 'an unattributable session does not go out');


exit($fail ? 1 : 0);
