<?php
/*
 * A draft lesson is a tutor's notes in progress, and nothing else.
 *
 * Three things were wrong at once, and each one only shows up from a
 * different seat.
 *
 * Saving a draft lesson CREATED a progress report, published, on the spot.
 * A tutor opening a blank lesson, typing a date and pressing Save Draft
 * handed the family a progress screen for a lesson nobody had taught.
 *
 * Draft lessons were then COUNTED. BFTD_CPT::sessions_for returned drafts,
 * and the one place that filtered them was the code that drew the lesson
 * list. Everything else reading the same method counted them: the lesson
 * tally at the top of a family's report, the "All N lessons" link, the
 * skills-covered grid, and the lessons-used figure a family's paid-for bank
 * is worked out from. A draft marked "lesson went ahead" spent a lesson the
 * family had bought.
 *
 * And a rule kept at one call site is not a rule, which is the actual lesson
 * here. It lives in sessions_for now, once.
 */
/* A working filter, defined before the stubs so the sample's short-circuit
 * can actually be exercised rather than asserted about. */
$GLOBALS['HOOKS'] = array();
function add_filter($tag, $fn, $pri = 10, $args = 1) { $GLOBALS['HOOKS'][$tag][] = $fn; return true; }
function apply_filters($tag, $value) {
  $rest = array_slice(func_get_args(), 2);
  foreach ($GLOBALS['HOOKS'][$tag] ?? array() as $fn) { $value = call_user_func_array($fn, array_merge(array($value), $rest)); }
  return $value;
}
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS']    = array();
$GLOBALS['META']     = array();
$GLOBALS['QUERIES']  = array();
$GLOBALS['INSERTED'] = array();
$GLOBALS['NEXT_ID']  = 500;

function get_post($id = null) { if (is_object($id)) return $id; return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ($s ? '' : array()); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_the_title($id) { return 'Kaine M'; }
function is_wp_error($t) { return false; }
function get_posts($args) {
  $GLOBALS['QUERIES'][] = $args;
  $want = (array) ($args['post_status'] ?? array('publish'));
  $key  = $args['meta_query'][0]['key'] ?? '';
  $val  = (int) ($args['meta_query'][0]['value'] ?? 0);
  $out  = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $args['post_type'] && $args['post_type'] !== (array) $args['post_type']) {
      if (!in_array($p->post_type, (array) $args['post_type'], true)) continue;
    }
    if (!in_array($p->post_status, $want, true)) continue;
    if ($key && (int) ($GLOBALS['META'][$id][$key] ?? 0) !== $val) continue;
    $out[] = $id;
  }
  return $out;
}
function wp_insert_post($a) {
  $id = $GLOBALS['NEXT_ID']++;
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => $a['post_type'], 'post_status' => $a['post_status'], 'post_title' => $a['post_title']);
  $GLOBALS['INSERTED'][] = $a;
  return $id;
}

class BFTD_Roles {
  public static function post_types() { return array('bftd_student','bftd_assessment','bftd_progress','bftd_session','bftd_resource'); }
  public static function plural_for($pt) { return $pt . 's'; }
  public static function can_manage($u = 0) { return true; }
}
class BFTD_Access { public static function can_staff_view($id, $u = 0) { return true; } }
class BFTD_Audit { public static $quiet = false; public static function log(...$a) {} public static function diff(...$a) { return array(); } }
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) { return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? ''; }
}
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }

require BFTD_PATH . 'includes/class-bftd-cpt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* A student, a published progress report, and two lessons on it: one
 * published, one still a draft. */
$STUDENT = 10; $REPORT = 20; $LIVE = 30; $DRAFT = 31;
$GLOBALS['POSTS'] = array(
  $STUDENT => (object) array('ID' => $STUDENT, 'post_type' => 'bftd_student',  'post_status' => 'publish'),
  $REPORT  => (object) array('ID' => $REPORT,  'post_type' => 'bftd_progress', 'post_status' => 'publish'),
  $LIVE    => (object) array('ID' => $LIVE,    'post_type' => 'bftd_session',  'post_status' => 'publish'),
  $DRAFT   => (object) array('ID' => $DRAFT,   'post_type' => 'bftd_session',  'post_status' => 'draft'),
);
$GLOBALS['META'] = array(
  $REPORT => array('_bftd_student_id' => $STUDENT),
  $LIVE   => array('_bftd_report_id' => $REPORT, '_bftd_student_id' => $STUDENT,
                   '_bftd_session__session_date' => '2026-09-01', '_bftd_session__status' => 'held'),
  $DRAFT  => array('_bftd_report_id' => $REPORT, '_bftd_student_id' => $STUDENT,
                   '_bftd_session__session_date' => '2026-09-08', '_bftd_session__status' => 'held'),
);

/* ---- what a family's report is built from ---- */
$shown = BFTD_CPT::sessions_for($REPORT);
check(array($LIVE) === $shown, 'only the published lesson is behind the report');
check(!in_array($DRAFT, $shown, true), 'the draft is not, however it is marked');

/* Staff asking explicitly still see it. */
$all = BFTD_CPT::sessions_for($REPORT, true);
check(in_array($DRAFT, $all, true) && in_array($LIVE, $all, true),
  'a staff screen can ask for drafts and get them');
check(2 === count($all), 'and gets both, not a third thing');

/* The default is the safe one: a new reader of this method gets published
 * only without having to know to ask. */
$r = new ReflectionMethod('BFTD_CPT', 'sessions_for');
$args = $r->getParameters();
check(2 === count($args) && $args[1]->isDefaultValueAvailable() && false === $args[1]->getDefaultValue(),
  'published-only is the default, so forgetting the flag is the safe mistake');

/* ---- and the fixture path is untouched ---- */
$before = count($GLOBALS['QUERIES']);
add_filter('bftd_pre_sessions_for', function () { return array(901, 902); });
check(array(901, 902) === BFTD_CPT::sessions_for($REPORT),
  'a sample report still stands in its own lessons, which no one published');
check($before === count($GLOBALS['QUERIES']),
  'and the status rule never runs, because the database was never asked');
$GLOBALS['HOOKS'] = array();

/* ================================================================== */
/* A draft lesson creates nothing and joins nothing                    */
/* ================================================================== */

class BFTD_Change_Notify { public static function flag(...$a) {} }
class BFTD_Prefill { public static function values($s, $p) { return array(); } }
class BFTD_Schedule { public static function save_rules(...$a) { return false; } }
class BFTD_Items { const PRIORITY = 'priority'; const REVIEW = 'review';
  public static function render_editor(...$a) {} public static function save($id) { return 0; } }
class BFTD_Preview {} class BFTD_Threads {}
function add_meta_box(...$a) {}
function current_user_can($c, $id = 0) { return true; }
function get_current_user_id() { return 1; }
function metadata_exists($t, $id, $k) { return isset($GLOBALS['META'][$id][$k]); }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function add_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_userdata($u) { return null; }
function get_the_date($f = '', $id = 0) { return '1 September 2026'; }
function set_transient(...$a) { return true; }
function wp_nonce_field(...$a) {}
function get_post_type($id) { return isset($GLOBALS['POSTS'][$id]) ? $GLOBALS['POSTS'][$id]->post_type : ''; }
function wp_update_post($a) { return true; }
function wp_list_pluck($l, $f) { $o = array(); foreach ((array) $l as $r) { $o[] = is_array($r) ? $r[$f] : $r->$f; } return $o; }
require BFTD_PATH . 'includes/class-bftd-metaboxes.php';

/* A student with no progress report at all, and a brand new lesson on them. */
$FRESH = 40; $NEW_LESSON = 41;
$GLOBALS['POSTS'][$FRESH]      = (object) array('ID' => $FRESH, 'post_type' => 'bftd_student', 'post_status' => 'publish');
$GLOBALS['POSTS'][$NEW_LESSON] = (object) array('ID' => $NEW_LESSON, 'post_type' => 'bftd_session', 'post_status' => 'draft', 'post_title' => 'Lesson');
$GLOBALS['META'][$NEW_LESSON]  = array('_bftd_student_id' => $FRESH);
$GLOBALS['INSERTED'] = array();

BFTD_MetaBoxes::attach_session_to_report($NEW_LESSON);
check(array() === $GLOBALS['INSERTED'], 'a draft lesson creates no progress report');
check(0 === BFTD_CPT::report_for($FRESH, 'bftd_progress'), 'so the family still has no progress screen');
check(!isset($GLOBALS['META'][$NEW_LESSON]['_bftd_report_id']), 'and the draft is attached to nothing');

/* Published, the same lesson does both. */
$GLOBALS['POSTS'][$NEW_LESSON]->post_status = 'publish';
BFTD_MetaBoxes::attach_session_to_report($NEW_LESSON);
check(1 === count($GLOBALS['INSERTED']), 'publishing it makes the report a tutor should not have to make');
check('bftd_progress' === $GLOBALS['INSERTED'][0]['post_type'], 'of the right kind');
$made = (int) $GLOBALS['META'][$NEW_LESSON]['_bftd_report_id'];
check($made && $made === BFTD_CPT::report_for($FRESH, 'bftd_progress'), 'and the lesson joins it');

/* Twice is once. */
BFTD_MetaBoxes::attach_session_to_report($NEW_LESSON);
check(1 === count($GLOBALS['INSERTED']), 'saving again does not make a second report');

/* Back to draft, the link stays. Detaching would lose it on the round trip,
 * and every reader filters on status anyway. */
$GLOBALS['POSTS'][$NEW_LESSON]->post_status = 'draft';
BFTD_MetaBoxes::attach_session_to_report($NEW_LESSON);
check($made === (int) $GLOBALS['META'][$NEW_LESSON]['_bftd_report_id'],
  'a published lesson put back to draft keeps its link');
check(!in_array($NEW_LESSON, BFTD_CPT::sessions_for($made), true),
  'but drops straight back out of what the family is shown');

/* It reaches publishing that never touches our form: Quick Edit, a bulk
 * action, a scheduled post going live. */
$QUICK = 42;
$GLOBALS['POSTS'][$QUICK] = (object) array('ID' => $QUICK, 'post_type' => 'bftd_session', 'post_status' => 'publish', 'post_title' => 'Lesson');
$GLOBALS['META'][$QUICK]  = array('_bftd_student_id' => $FRESH);
BFTD_MetaBoxes::on_session_published('publish', 'draft', $GLOBALS['POSTS'][$QUICK]);
check($made === (int) ($GLOBALS['META'][$QUICK]['_bftd_report_id'] ?? 0),
  'a lesson published from Quick Edit joins the report too');
BFTD_MetaBoxes::on_session_published('draft', 'publish', $GLOBALS['POSTS'][$QUICK]);
check(1 === count($GLOBALS['INSERTED']), 'and unpublishing one makes nothing');

/* Nothing else is dragged into this. */
$NOTALESSON = 43;
$GLOBALS['POSTS'][$NOTALESSON] = (object) array('ID' => $NOTALESSON, 'post_type' => 'bftd_assessment', 'post_status' => 'publish', 'post_title' => 'Dx');
$GLOBALS['META'][$NOTALESSON]  = array('_bftd_student_id' => $FRESH);
BFTD_MetaBoxes::on_session_published('publish', 'draft', $GLOBALS['POSTS'][$NOTALESSON]);
check(!isset($GLOBALS['META'][$NOTALESSON]['_bftd_report_id']), 'publishing a diagnostic is not a lesson');

/* And that path is hooked, not merely written: without it, a lesson published
 * any way but through our own form never joins a report at all. */
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(1 === preg_match("/add_action\(\s*'transition_post_status',\s*array\(\s*__CLASS__,\s*'on_session_published'\s*\),\s*10,\s*3\s*\)/", $mb),
  'publishing by any route is listened for');

/* ---- and every reader of the lesson list goes through the one rule ---- */
function code_of($file) {
  $out = '';
  foreach (token_get_all(file_get_contents($file)) as $t) {
    if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $out .= $t[1]; }
    else { $out .= $t; }
  }
  return $out;
}
$family = array('class-bftd-report-view.php', 'class-bftd-charts.php', 'class-bftd-dashboard.php', 'class-bftd-schedule.php');
foreach ($family as $file) {
  $src = code_of(BFTD_PATH . 'includes/' . $file);
  check(0 === preg_match('/sessions_for\([^)]*,\s*true\s*\)/', $src),
    "$file does not ask for drafts");
  check(0 === preg_match('/publish.{0,3}\\s*!==\\s*get_post_status/', $src),
    "$file does not keep a second copy of the rule");
}

exit($fail ? 1 : 0);
