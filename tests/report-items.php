<?php
/*
 * The two family lists, reachable from the report that shows them.
 *
 * Priority items and For review are drawn across the top of a report, above
 * the sections, and the item editor's own "pin to section" control names
 * report sections. The boxes to write them, though, were only ever put on
 * the student record: a tutor finishing a diagnostic had no way to ask the
 * family for anything without leaving the screen, and diagnostics went out
 * with the sections done and nothing asked.
 *
 * The store does not move. An item is a thing asked of a family, not a thing
 * belonging to one report, so both screens write the student's own two meta
 * keys. What is under test here is that the second door exists, that it opens
 * onto the student and not the report, and that it is locked when it should
 * be.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['BOXES']  = array();
$GLOBALS['SAVED']  = array();
$GLOBALS['FLAGS']  = array();
$GLOBALS['TRANS']  = array();
$GLOBALS['PARENT'] = array();
$GLOBALS['CAN']    = array();
$GLOBALS['META']   = array();

function add_meta_box($id, $title, $cb, $screen = null, $ctx = 'advanced', $pri = 'default') {
  foreach ((array) $screen as $pt) { $GLOBALS['BOXES'][] = array('id' => $id, 'title' => $title, 'cb' => $cb, 'pt' => $pt, 'ctx' => $ctx); }
}
function current_user_can($cap, $id = 0) { return !empty($GLOBALS['CAN'][(int) $id]); }
function get_current_user_id() { return 1; }
function get_the_title($id) { return 'Kaine M'; }
function get_the_date($f = '', $id = 0) { return '14 September 2026'; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['META'][$id][$k] ?? ($single ? '' : array()); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function set_transient($k, $v, $t) { $GLOBALS['TRANS'][$k] = $v; return true; }
function wp_nonce_field(...$a) {}
function wp_verify_nonce(...$a) { return true; }
function wp_is_post_revision($id) { return false; }
function get_post_type($id) { return 'bftd_assessment'; }
function metadata_exists($t, $id, $k) { return isset($GLOBALS['META'][$id][$k]); }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function add_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_userdata($u) { return null; }
function wp_list_pluck($l, $f) { $o = array(); foreach ((array) $l as $r) { $o[] = is_array($r) ? $r[$f] : $r->$f; } return $o; }

class BFTD_CPT {
  const STUDENT = 'bftd_student'; const ASSESSMENT = 'bftd_assessment';
  const PROGRESS = 'bftd_progress'; const SESSION = 'bftd_session'; const RESOURCE = 'bftd_resource';
  const STUDENT_KEY = '_bftd_student_id'; const STAFF_KEY = '_bftd_staff_ids';
  public static function student_id($id) { return $GLOBALS['PARENT'][$id] ?? 0; }
  public static function staff_ids($id) { return array(); }
}
class BFTD_Access {
  public static function report_types() { return array(BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS, BFTD_CPT::SESSION); }
  public static function can_staff_view($id, $u = 0) { return true; }
}
class BFTD_Roles {
  public static function post_types() { return array(BFTD_CPT::STUDENT, BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS, BFTD_CPT::SESSION, BFTD_CPT::RESOURCE); }
  public static function can_manage($u = 0) { return true; }
  public static function can_administer($u = null) { return true; }
  public static function is_staff() { return true; }
}
class BFTD_Items {
  const PRIORITY = 'priority'; const REVIEW = 'review';
  public static $rendered = array();
  public static function render_editor($student_id, $list, $owner = '') {
    self::$rendered[] = array($student_id, $list);
    echo '<div class="bftd-items" data-list="' . $list . '" data-student="' . (int) $student_id . '">' . $owner . '</div>';
  }
  public static function save($student_id) { $GLOBALS['SAVED'][] = $student_id; return 1; }
}
class BFTD_Change_Notify { public static function flag($id, $type, $sum) { $GLOBALS['FLAGS'][] = array($id, $type); } }
class BFTD_Fields {
  public static function save_post($id, $pt) { return 0; }
  public static function get($id, $g, $k, $f = array()) { return ''; }
  public static function sanitize_value($v, $f) { return $v; }
}
class BFTD_Audit { public static $quiet = false; public static function log(...$a) {} public static function diff(...$a) { return array(); } }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } public static function section($id) { return null; } }
class BFTD_Prefill { public static function values($sid, $pt) { return array(); } }
class BFTD_Schedule { public static function save_rules(...$a) { return false; } }
class BFTD_Preview {}
class BFTD_Threads {}

require BFTD_PATH . 'includes/class-bftd-metaboxes.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- the boxes exist where the lists are read ---- */
BFTD_MetaBoxes::boxes();
foreach (array('bftd_priority' => 'Priority items', 'bftd_review' => 'For review') as $id => $title) {
  $on = array();
  foreach ($GLOBALS['BOXES'] as $b) { if ($b['id'] === $id) $on[$b['pt']] = $b['title']; }
  foreach (array(BFTD_CPT::STUDENT, BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS) as $pt) {
    check(isset($on[$pt]), "$title is on $pt");
  }
  check($title === ($on[BFTD_CPT::ASSESSMENT] ?? ''), "and is called \"$title\" on the report too");
  check(!isset($on[BFTD_CPT::SESSION]), "$title is not bolted onto a lesson record, which never draws it");
}

/* ---- on a report it edits the student, not the report ---- */
$report = 56; $student = 10;
$GLOBALS['PARENT'][$report] = $student;
$GLOBALS['CAN'][$student] = true;
$post = (object) array('ID' => $report, 'post_type' => BFTD_CPT::ASSESSMENT, 'post_title' => 'Kaine M', 'post_status' => 'draft');

BFTD_Items::$rendered = array();
ob_start(); BFTD_MetaBoxes::box_priority($post); BFTD_MetaBoxes::box_review($post); $html = ob_get_clean();
check(array(array($student, 'priority'), array($student, 'review')) === BFTD_Items::$rendered,
  'both editors are bound to the student, not the report');
check(false !== strpos($html, 'data-student="' . $student . '"'), 'and say so in the markup the confirm button reads');
check(false === strpos($html, 'data-student="' . $report . '"'), 'the report id is nowhere in them');
check(false !== strpos($html, 'Kaine M'), 'the note names whose list it is');

/* The name goes into the editor's own description, not a second paragraph
 * stacked above it. Two descriptions on one box is how a box stops being
 * read at all, and the editor already has one. */
check(0 === preg_match_all('/class="description"/', $html),
  'the box adds no paragraph of its own above the editor');
check(false !== strpos($html, '>Kaine M<'), 'it hands the name to the editor instead');

/* On the student record itself nothing changed. */
BFTD_Items::$rendered = array();
$spost = (object) array('ID' => $student, 'post_type' => BFTD_CPT::STUDENT, 'post_title' => 'Kaine M', 'post_status' => 'publish');
ob_start(); BFTD_MetaBoxes::box_priority($spost); $own = ob_get_clean();
check(array(array($student, 'priority')) === BFTD_Items::$rendered, 'the student record still edits its own list');
check(false === strpos($own, 'Kaine M'), 'and is not told whose list it is, being already on their record');

/* ---- no student, no editor ---- */
BFTD_Items::$rendered = array();
$orphan = (object) array('ID' => 99, 'post_type' => BFTD_CPT::ASSESSMENT, 'post_title' => 'Untitled', 'post_status' => 'draft');
ob_start(); BFTD_MetaBoxes::box_priority($orphan); $none = ob_get_clean();
check(array() === BFTD_Items::$rendered, 'a report with no student draws no editor to type into and lose');
check(false !== stripos($none, 'Choose a student'), 'it says why instead');

/* ---- a student this tutor may not edit is read only ---- */
BFTD_Items::$rendered = array();
$GLOBALS['CAN'][$student] = false;
ob_start(); BFTD_MetaBoxes::box_priority($post); $locked = ob_get_clean();
check(array() === BFTD_Items::$rendered, 'someone who cannot edit the student gets no editor');
check(false !== stripos($locked, 'read only'), 'and is told why');
$GLOBALS['CAN'][$student] = true;

/* ---- saving from the report writes the student's record ---- */
$_POST = array('bftd_items' => array('priority' => array(array('text' => 'Print the sheet'))));
$GLOBALS['SAVED'] = $GLOBALS['FLAGS'] = $GLOBALS['TRANS'] = array();
BFTD_MetaBoxes::save_fields($report, $post);
check(array($student) === $GLOBALS['SAVED'], 'the save goes to the student, once');

/* The report is not flagged for a change that was not the report's. */
$report_flags = array();
foreach ($GLOBALS['FLAGS'] as $f) { if ($f[0] === $report) $report_flags[] = $f; }
check(array() === $report_flags, 'and the report is not flagged as having unsent changes of its own');
check(!empty($GLOBALS['TRANS']), 'but the tutor is still told it saved');

/* ---- the guards on the save path ---- */
$GLOBALS['SAVED'] = array();
BFTD_MetaBoxes::save_fields(99, $orphan);
check(array() === $GLOBALS['SAVED'], 'a report with no student writes nothing');

$GLOBALS['SAVED'] = array();
$GLOBALS['CAN'][$student] = false;
BFTD_MetaBoxes::save_fields($report, $post);
check(array() === $GLOBALS['SAVED'], 'and neither does someone who cannot edit that student');
$GLOBALS['CAN'][$student] = true;

$GLOBALS['SAVED'] = array();
$_POST = array();
BFTD_MetaBoxes::save_fields($report, $post);
check(array() === $GLOBALS['SAVED'], 'a save that carried no lists leaves them alone');

/* ---- and the browser sends them, so a draft autosave keeps them ---- */
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(1 === preg_match('/var keep = [^;]*bftd_items/', $js),
  'the autosave payload carries the lists, so a draft does not lose them between saves');

exit($fail ? 1 : 0);
