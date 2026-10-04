<?php
/*
 * Who may destroy a record for good.
 *
 * WordPress has one capability for two very different acts. delete_post
 * covers both "put this in the trash" and "erase this", and which one is
 * happening is decided by the record's status, not by a second capability.
 * So a tutor manager who could tidy a mistyped lesson could also empty the
 * trash, and a child's whole assessment history goes with it: the diagnostic,
 * the lessons hanging off it, the figures a year of progress is measured
 * against.
 *
 * The rule is therefore written on the status, which is what makes it hold on
 * the Delete Permanently link, the bulk action, Empty Trash, and any
 * wp_delete_post a screen written next year happens to call.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['CAPS']  = array();   // user id => array of caps
$GLOBALS['POSTS'] = array();   // post id => (object) type + status
$GLOBALS['ME']    = 0;

function user_can($u, $cap) { $u = is_object($u) ? $u->ID : (int) $u; return !empty($GLOBALS['CAPS'][$u][$cap]); }
function get_current_user_id() { return $GLOBALS['ME']; }
function get_post($id) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_userdata($id) { return null; }
function wp_get_current_user() { return (object) array('ID' => $GLOBALS['ME'], 'roles' => array()); }
function get_role($s) { return null; }
function add_role(...$a) {}
function get_users($a = array()) { return array(); }
function get_user_meta($u, $k, $s = false) { return $s ? '' : array(); }
function update_user_meta(...$a) { return true; }
function get_option($k, $d = false) { return $GLOBALS['OPT'][$k] ?? $d; }
function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; }

require BFTD_PATH . 'includes/class-bftd-roles.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$TUTOR = 1; $MANAGER = 2; $SENIOR = 3; $ADMIN = 4; $NOBODY = 5;
$GLOBALS['CAPS'] = array(
  $TUTOR   => array(BFTD_Roles::STAFF_CAP => true),
  $MANAGER => array(BFTD_Roles::STAFF_CAP => true, BFTD_Roles::MANAGE_CAP => true),
  $SENIOR  => array(BFTD_Roles::STAFF_CAP => true, BFTD_Roles::MANAGE_CAP => true,
                    BFTD_Roles::TEAM_CAP => true, BFTD_Roles::ERASE_CAP => true),
  $ADMIN   => array('manage_options' => true),
  $NOBODY  => array(),
);

/* ---- who holds the power ---- */
check(!BFTD_Roles::can_erase($TUTOR),   'a tutor cannot erase');
check(!BFTD_Roles::can_erase($MANAGER), 'nor can a tutor manager');
check(BFTD_Roles::can_erase($SENIOR),   'a senior manager can');
check(BFTD_Roles::can_erase($ADMIN),    'and an administrator can, through manage_options');

/* Erasing is its own capability, not a second meaning bolted onto another. */
check(BFTD_Roles::ERASE_CAP !== BFTD_Roles::TEAM_CAP && BFTD_Roles::ERASE_CAP !== BFTD_Roles::MANAGE_CAP,
  'it is a capability of its own, so a future tier can manage the team without it');

/* ---- the guard, record by record ---- */
$live = (object) array('post_type' => 'bftd_assessment', 'post_status' => 'publish');
$gone = (object) array('post_type' => 'bftd_assessment', 'post_status' => 'trash');
$GLOBALS['POSTS'] = array(10 => $live, 11 => $gone);

$allow = array('delete_others_bftd_assessments');
$deny  = array('do_not_allow');

check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array(10)),
  'a tutor manager may still trash a live report');
check($deny === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array(11)),
  'but may not erase one already in the trash');
check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $SENIOR, array(11)),
  'a senior manager may');
check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $ADMIN, array(11)),
  'and so may an administrator');

/* Every record type Karl named, not just the one that prompted it. */
foreach (array('bftd_student', 'bftd_assessment', 'bftd_progress', 'bftd_session') as $pt) {
  $GLOBALS['POSTS'][20] = (object) array('post_type' => $pt, 'post_status' => 'trash');
  check($deny === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array(20)),
    "a trashed $pt is safe from a tutor manager");
  check(in_array($pt, BFTD_Roles::post_types(), true), "$pt is one of ours, so the guard reaches it");
}

/* Nothing outside the plugin is touched: a page or a core post is WordPress's
 * business, and a permission plugin that quietly reaches into them is the kind
 * of thing found months later. */
$GLOBALS['POSTS'][30] = (object) array('post_type' => 'page', 'post_status' => 'trash');
check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array(30)),
  'a trashed page is left to WordPress');

/* Other capabilities pass straight through. */
check($allow === BFTD_Roles::guard_permanent_delete($allow, 'edit_post', $MANAGER, array(11)),
  'editing is not the delete rule');
check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array()),
  'and a check with no record named is left alone');
check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array(999)),
  'as is one naming a record that is gone');

/* ---- the offer comes off the screen too ---- */
$GLOBALS['ME'] = $MANAGER;
$actions = BFTD_Roles::strip_delete_bulk_action(array('edit' => 'Edit', 'trash' => 'Trash', 'delete' => 'Delete permanently'));
check(!isset($actions['delete']), 'a tutor manager is not offered the bulk erase');
check(isset($actions['trash']) && isset($actions['edit']), 'and keeps the ones that are theirs');
$GLOBALS['ME'] = $SENIOR;
$actions = BFTD_Roles::strip_delete_bulk_action(array('edit' => 'Edit', 'trash' => 'Trash', 'delete' => 'Delete permanently'));
check(isset($actions['delete']), 'a senior manager is');

/* ---- and the roles are actually granted it ---- */
$audit = BFTD_Roles::audit_capabilities();
$senior_n  = $audit[BFTD_Roles::SENIOR_ROLE]['expected'];
$manager_n = $audit[BFTD_Roles::MANAGER_ROLE]['expected'];
/* Three capabilities of their own, plus the resources library. Skills and
 * activities are the tutor manager's as well. Counted rather than listed, so
 * a library granted to one side and forgotten on the other shows up here. */
$libs  = count(array_unique(BFTD_Roles::caps_for('bftd_resource', 'bftd_resources', true)));
check(3 + $libs === $senior_n - $manager_n,
  'a senior manager holds what a manager does not: team, erase, administer, and the resources library');
$exp = BFTD_Roles::expected_caps();
check(array() === array_diff(BFTD_Roles::activity_caps(), $exp[BFTD_Roles::MANAGER_ROLE]),
  'a tutor manager writes the skills and activities');
check(array() === array_diff(BFTD_Roles::library_read_caps(), $exp[BFTD_Roles::TUTOR_ROLE])
  && array() === array_intersect(array_diff(BFTD_Roles::activity_caps(), BFTD_Roles::library_read_caps()), $exp[BFTD_Roles::TUTOR_ROLE]),
  'a tutor gets the two lists and nothing that creates, edits, publishes or deletes');
foreach (BFTD_Roles::erasable_library_types() as $lt) {
  $GLOBALS['POSTS'][990] = (object) array('ID' => 990, 'post_type' => $lt, 'post_status' => 'trash');
  /* Now a tutor manager writes the library, they may trash from it, and the
     same line as everywhere else stops them erasing. */
  check($deny === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $MANAGER, array(990)),
    "a trashed $lt is safe from a tutor manager");
  check($allow === BFTD_Roles::guard_permanent_delete($allow, 'delete_post', $SENIOR, array(990)),
    "and a senior manager may erase it");
}
check(count(BFTD_Roles::activity_caps()) > 0 && !in_array('bftd_manage_all', BFTD_Roles::activity_caps(), true),
  'and the library capabilities are their own set, not a rename of an existing one');
/*
 * Asked of the capability list itself rather than of the source text.
 *
 * These three used to be regular expressions over class-bftd-roles.php,
 * matching the shape the grant happened to be written in: a sync_role() call
 * with a particular variable name, an add_cap() with a particular argument.
 * Rewriting that grant to have one definition instead of two broke all three
 * without changing who can erase anything, which is a test reporting on the
 * code's spelling rather than on its behaviour.
 */
$expect = BFTD_Roles::expected_caps();
check(in_array(BFTD_Roles::ERASE_CAP, $expect[BFTD_Roles::SENIOR_ROLE], true),
  'the senior role is granted it');
check(! in_array(BFTD_Roles::ERASE_CAP, $expect[BFTD_Roles::MANAGER_ROLE], true),
  'and the manager role is not');
check(in_array(BFTD_Roles::ERASE_CAP, $expect['administrator'], true),
  'nor is the administrator forgotten');

/* A new capability no longer waits on a version bump to reach a live site:
   healing compares the roles against expected_caps() on every load and puts
   back whatever is missing. The stamp is kept because a bump still forces the
   full re-grant that takes back capabilities we have stopped handing out. */
check(BFTD_Roles::CAPS_VERSION >= 6, 'the capability version is still stamped');
/* Whether healing repairs a drift is asked in tests/cap-drift.php, which has a
   role stub that models WP_Role. get_role() returns null in this file, so the
   question cannot be put here and an assertion that tried would only ever be
   reporting on the fixture. */

/* The guard is hooked, not merely written. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-roles.php');
check(1 === preg_match("/add_filter\(\s*'map_meta_cap',\s*array\(\s*__CLASS__,\s*'guard_permanent_delete'/", $src),
  'the guard is hooked into map_meta_cap');
check(1 === preg_match("/bulk_actions-edit-'\s*\.\s*\\\$pt/", $src),
  'and the bulk action is stripped on every one of our list tables');

exit($fail ? 1 : 0);
