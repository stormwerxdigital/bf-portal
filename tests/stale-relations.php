<?php
/*
 * Why a published lesson did not appear in the progress report.
 *
 * Every "which lessons belong to this report", "which report belongs to this
 * student" and "which students can this tutor see" is a meta_query.
 * WordPress caches those results under a key built from the POSTS
 * last-changed marker, and a POSTMETA write does not move that marker.
 *
 * So saving a lesson wrote the link correctly, and every query that reads the
 * link carried on answering from before it existed. With no persistent object
 * cache the cache dies with the request and nobody sees it. With Redis behind
 * the site it does not: a tutor publishes a lesson, the progress report is
 * created, and the report stays empty. The same silence hides a published
 * diagnostic from a family, because that is found the same way.
 *
 * The cache here behaves the way a real one does, so the test fails without
 * the fix for the same reason the live site did.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();
$GLOBALS['QCACHE'] = array();          // the query cache
$GLOBALS['LAST']  = 'v1';              // the posts last-changed marker
$GLOBALS['HOOKS'] = array();

function add_action($t, $f, $p = 10, $a = 1) { $GLOBALS['HOOKS'][$t][] = $f; }
function do_action($t, ...$args) { foreach ($GLOBALS['HOOKS'][$t] ?? array() as $f) call_user_func_array($f, $args); }
function add_filter(...$a) {}
function wp_cache_set_last_changed($group) { $GLOBALS['LAST'] = 'v' . (1 + (int) substr($GLOBALS['LAST'], 1)); }
function wp_cache_get_last_changed($group) { return $GLOBALS['LAST']; }

function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p ? $p->post_title : ''; }
function get_post_meta($id, $k, $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return null === $v ? ($s ? '' : array()) : ($s ? $v : array($v)); }

/* update_post_meta, with the hooks WordPress fires and nothing else. */
function update_post_meta($id, $k, $v) {
  $had = isset($GLOBALS['META'][$id][$k]);
  $GLOBALS['META'][$id][$k] = $v;
  do_action($had ? 'updated_post_meta' : 'added_post_meta', 1, $id, $k, $v);
  return true;
}

/* get_posts, caching its result the way WP_Query does: keyed on the query
 * plus the posts last-changed marker, and on nothing about postmeta. */
function get_posts($args) {
  $key = md5(serialize($args)) . ':' . wp_cache_get_last_changed('posts');
  if (isset($GLOBALS['QCACHE'][$key])) { $GLOBALS['HITS'] = ($GLOBALS['HITS'] ?? 0) + 1; return $GLOBALS['QCACHE'][$key]; }

  $want = (array) ($args['post_status'] ?? array('publish'));
  $out  = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if (!in_array($p->post_type, (array) $args['post_type'], true)) continue;
    if (!in_array($p->post_status, $want, true)) continue;
    if (!empty($args['meta_query'][0]['key'])) {
      $k = $args['meta_query'][0]['key'];
      if ((string) ($GLOBALS['META'][$id][$k] ?? '') !== (string) $args['meta_query'][0]['value']) continue;
    }
    $out[] = $id;
  }
  if (!empty($args['posts_per_page']) && (int) $args['posts_per_page'] > 0) $out = array_slice($out, 0, (int) $args['posts_per_page']);
  return $GLOBALS['QCACHE'][$key] = $out;
}

class BFTD_Roles { public static function post_types() { return array('bftd_student','bftd_assessment','bftd_progress','bftd_session','bftd_resource'); }
  public static function plural_for($pt) { return $pt . 's'; } }
class BFTD_Access { public static function can_staff_view($id, $u = 0) { return true; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Fields { public static function get($id, $g, $k, $f = array()) { return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? ''; } }

require BFTD_PATH . 'includes/class-bftd-cpt.php';
BFTD_CPT::init();

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$STUDENT = 10; $REPORT = 20; $LESSON = 30; $DX = 40;
foreach (array($STUDENT => 'bftd_student', $REPORT => 'bftd_progress', $LESSON => 'bftd_session', $DX => 'bftd_assessment') as $id => $type) {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'x');
}
update_post_meta($REPORT, BFTD_CPT::STUDENT_KEY, $STUDENT);

/* The report is looked at while it is still empty — a tutor opening it, the
 * portal drawing it, the list table counting lessons. That is what puts the
 * empty answer in the cache. */
check(array() === BFTD_CPT::sessions_for($REPORT), 'the report starts with no lessons on it');

/* Now the lesson is published and attached, which is a postmeta write.

   What attaches it is the student. A lesson belongs to a child, the report
   belongs to the same child, and the lessons on a report are worked out from
   that rather than from a link written onto the lesson pointing at whichever
   report happened to be newest — which is how a student with two progress
   reports ended up with a report showing none of their lessons. */
update_post_meta($LESSON, BFTD_CPT::STUDENT_KEY, $STUDENT);
check(array($LESSON) === BFTD_CPT::sessions_for($REPORT),
  'and the lesson appears the moment it is attached, rather than when the cache expires');

/* A second progress report for the same child shows the same lessons, because
   they are the child's lessons. Nothing has to be re-pointed. */
$SECOND = 21;
$GLOBALS['POSTS'][$SECOND] = (object) array('ID' => $SECOND, 'post_type' => 'bftd_progress', 'post_status' => 'publish', 'post_title' => 'x');
update_post_meta($SECOND, BFTD_CPT::STUDENT_KEY, $STUDENT);
check(array($LESSON) === BFTD_CPT::sessions_for($SECOND),
  'and a second progress report for that child shows them too');

/* A report with no student on it has no lessons, rather than everybody's. */
$ORPHAN = 22;
$GLOBALS['POSTS'][$ORPHAN] = (object) array('ID' => $ORPHAN, 'post_type' => 'bftd_progress', 'post_status' => 'publish', 'post_title' => 'x');
check(array() === BFTD_CPT::sessions_for($ORPHAN), 'while a report belonging to nobody lists nobody\'s lessons');

/* The same for a diagnostic, found by the student it belongs to. */
check(0 === BFTD_CPT::report_for($STUDENT, 'bftd_assessment'), 'no diagnostic for this student yet');
update_post_meta($DX, BFTD_CPT::STUDENT_KEY, $STUDENT);
check($DX === BFTD_CPT::report_for($STUDENT, 'bftd_assessment'),
  'and a published diagnostic reaches the family as soon as it is attached');

/* And the cache is still a cache: nothing here turns it off. */
$GLOBALS['HITS'] = 0;
BFTD_CPT::sessions_for($REPORT);
BFTD_CPT::sessions_for($REPORT);
check($GLOBALS['HITS'] > 0, 'reading twice without writing still comes from the cache');

/* The rule is on the metadata hooks, not at each call site, so a writer added
 * later is covered without knowing this exists. */
$src = '';
foreach (token_get_all(file_get_contents(BFTD_PATH . 'includes/class-bftd-cpt.php')) as $t) {
  if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $src .= $t[1]; }
  else { $src .= $t; }
}
foreach (array('added_post_meta', 'updated_post_meta', 'deleted_post_meta') as $hook) {
  check(false !== strpos($src, "'" . $hook . "'"), "every way meta is written is listened for: $hook");
}
check(1 === preg_match('/relationship_keys\(\).*?STUDENT_KEY.*?REPORT_KEY.*?STAFF_KEY.*?CLIENT_KEY/s', $src),
  'and all four relationship keys count');

/* A key that is not a relationship must not throw the cache away, or every
 * field a tutor types becomes a cache flush. */
$GLOBALS['HITS'] = 0;
update_post_meta($LESSON, '_bftd_session__notes', 'typing');
BFTD_CPT::sessions_for($REPORT);
check($GLOBALS['HITS'] > 0, 'while an ordinary field being saved leaves the cache alone');

exit($fail ? 1 : 0);
