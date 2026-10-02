<?php
/*
 * Naming the client is also giving them access.
 *
 * A caregiver is anyone responsible for the child, and they all see the same
 * portal. The client is one person: the contact the practice deals with and
 * the one the invoice goes to. They are separate facts, so they are separate
 * meta keys — but a billing contact who cannot open the portal is a support
 * call, and nothing on the screen would say why. So the client is folded into
 * the caregiver list on save rather than being a second list somebody has to
 * remember to keep in step.
 *
 * Only the save is under test, lifted out rather than booting the plugin,
 * which would want a database.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META']  = array();
$GLOBALS['AUDIT'] = array();
$GLOBALS['WELCOMED'] = array();

function get_the_title($id) { return 'Kaine M'; }
function get_userdata($id) { return (object) array('ID' => $id, 'display_name' => 'User ' . $id); }
function get_post_meta($id, $k, $single = false) {
  $v = $GLOBALS['META'][$id][$k] ?? null;
  if (null === $v) return $single ? '' : array();
  return $single ? (is_array($v) ? ($v[0] ?? '') : $v) : (array) $v;
}
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k, $v = '') { unset($GLOBALS['META'][$id][$k]); return true; }
function add_post_meta($id, $k, $v) {
  if (!isset($GLOBALS['META'][$id][$k])) $GLOBALS['META'][$id][$k] = array();
  $GLOBALS['META'][$id][$k] = array_merge((array) $GLOBALS['META'][$id][$k], array($v));
  return true;
}
function wp_unslash($v) { return $v; }

class BFTD_CPT {
  const CLIENT_KEY  = '_bftd_client_user_id';
  const PRIMARY_KEY = '_bftd_client_primary';
  const STAFF_KEY   = '_bftd_assigned_staff';
  public static function primary_client_id($id) { return (int) get_post_meta($id, self::PRIMARY_KEY, true); }
}
class BFTD_Roles { public static function can_manage($u = null) { return true; } }
class BFTD_Audit { public static function log($e, $a) { $GLOBALS['AUDIT'][] = $e . ': ' . $a['summary']; } }

$src = file_get_contents(dirname(__DIR__) . '/includes/class-bftd-metaboxes.php');
$a = strpos($src, "\tprivate static function save_relationships(");
$b = strpos($src, "\n\t/**", $a);
if (false === $a || false === $b) { echo "FAIL: save_relationships() is not in the file\n"; exit(1); }
eval('class SR { public static function welcome($u, $p, $e) { $GLOBALS["WELCOMED"][] = $u; } '
  . str_replace('private static function save_relationships', 'public static function save_relationships', substr($src, $a, $b - $a))
  . ' }');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$S = 11;

/* Somebody who is not on the caregiver list is designated the client. */
$_POST = array(
  'bftd_client_primary' => array(20),
  'bftd_clients'        => array(30),
  'bftd_staff'          => array(5),
);
SR::save_relationships($S);

check(20 === BFTD_CPT::primary_client_id($S), 'the client is stored');
$care = array_map('intval', get_post_meta($S, BFTD_CPT::CLIENT_KEY));
check(in_array(20, $care, true), 'and can open the portal, got ' . json_encode($care));
check(in_array(30, $care, true), 'without displacing the caregivers somebody listed');
check(in_array(20, $GLOBALS['WELCOMED'], true), 'and is welcomed like any caregiver, because that is what they now are');

/* Designating somebody already listed adds nobody twice. */
$_POST['bftd_client_primary'] = array(30);
SR::save_relationships($S);
$care = array_map('intval', get_post_meta($S, BFTD_CPT::CLIENT_KEY));
check(1 === count(array_keys($care, 30)), 'a caregiver made the client is still one caregiver, got ' . json_encode($care));

/* Clearing it. */
$_POST['bftd_client_primary'] = array();
SR::save_relationships($S);
check(0 === BFTD_CPT::primary_client_id($S), 'the client can be cleared');
check(in_array(30, array_map('intval', get_post_meta($S, BFTD_CPT::CLIENT_KEY)), true),
  'and clearing it does not take their access away, because that is a separate decision');

/* A screen that does not carry the field must not wipe it. Every tutor-facing
   save posts a subset of the form, and treating "not posted" as "cleared" is
   how a student silently loses their billing contact. */
$_POST = array('bftd_clients' => array(30));
update_post_meta($S, BFTD_CPT::PRIMARY_KEY, 20);
SR::save_relationships($S);
check(20 === BFTD_CPT::primary_client_id($S), 'a save from a screen without the field leaves the client alone');

/* Changing it says so in the log, because who a family's money and messages
   go through is not an edit to the copy. */
$GLOBALS['AUDIT'] = array();
$_POST = array('bftd_client_primary' => array(31), 'bftd_clients' => array(30));
SR::save_relationships($S);
check(1 === count(array_filter($GLOBALS['AUDIT'], function ($l) { return false !== strpos($l, 'is now the client'); })),
  'changing the client is logged once, got ' . json_encode($GLOBALS['AUDIT']));

$GLOBALS['AUDIT'] = array();
SR::save_relationships($S);
check(0 === count(array_filter($GLOBALS['AUDIT'], function ($l) { return false !== strpos($l, 'is now the client'); })),
  'and saving again with the same client is not a change');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
