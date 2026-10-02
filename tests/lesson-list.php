<?php
/*
 * The Lessons list.
 *
 * Every lesson takes its title from the student it belongs to, so a tutor
 * with fifteen children was reading fifteen rows of names with no way to tell
 * one child's Wednesday from another's. The only date on the row was the day
 * somebody happened to type the record up, which is not the day of the
 * lesson and is never the thing being looked for.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();
function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p ? $p->post_title : ''; }
function get_post_meta($id, $k, $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return null === $v ? ($s ? '' : array()) : ($s ? $v : array($v)); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function get_posts($a) {
  // Modelled, not blank: two of the three filters here resolve a set of
  // students out of postmeta, and a stub that always answers "none" would
  // make an empty result look like a working filter.
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (!empty($a['meta_query'])) {
      $q = $a['meta_query'][0];
      $have = $GLOBALS['META'][$id][$q['key']] ?? null;
      if (null === $have) continue;
      $want = (array) $q['value'];
      if (!in_array((string) $have, array_map('strval', $want), true)) continue;
    }
    $out[] = $id;
  }
  return $out;
}
function is_admin() { return true; }
function get_current_user_id() { return 1; }
function get_edit_post_link($id) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function get_userdata($id) { return $GLOBALS['USERS'][(int) $id] ?? false; }
function get_users($a) {
  $out = array();
  $term = isset($a['search']) ? trim($a['search'], '*') : '';
  foreach ($GLOBALS['USERS'] as $id => $u) {
    if ('' !== $term
      && false === stripos($u->display_name, $term)
      && false === stripos($u->user_email, $term)) continue;
    $out[] = $id;
  }
  return $out;
}
$GLOBALS['USERS'] = array();

class BFTD_Roles { public static function post_types() { return array('bftd_session'); } public static function plural_for($p) { return $p . 's'; } }
class BFTD_Access { public static function visible_student_ids($u) { return $GLOBALS['VISIBLE'] ?? array(); } public static function can_staff_view(...$a) { return true; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Fields { public static function get($id, $g, $k, $f = array()) { return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? ''; } }
class BFTD_Schedule { public static function pretty_time($t) { list($h, $m) = array_pad(explode(':', $t), 2, '00'); $ap = $h < 12 ? 'am' : 'pm'; $h = (int) $h % 12; if (!$h) $h = 12; return $h . ':' . $m . ' ' . $ap; } }
require BFTD_PATH . 'includes/class-bftd-cpt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- the columns ---- */
$cols = BFTD_CPT::session_columns(array('cb' => '<input>', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date'));
check(isset($cols['bftd_student']), 'the list says which child a lesson belongs to');
check(isset($cols['bftd_when']), 'and when the lesson was');
check(isset($cols['bftd_tutor']), 'and who is on it');
check(isset($cols['bftd_client']), 'and who is paying for it');

/* The title is generated from the student and the date, so a Title column was
   those two columns again in one string. */
check(!isset($cols['title']), 'the title, which is those two things already, is not a column');
check(isset($cols['cb']), 'the checkbox stays, or nothing can be acted on in bulk');

$order = array_keys($cols);
check(array_search('bftd_student', $order) < array_search('bftd_when', $order),
  'the child first, then the day');

/* Removing the title takes away the thing WordPress hangs Edit and Trash off,
   and a list nobody can open a record from is worse than one with a redundant
   column. So it has to be told which column carries them now. */
check('bftd_student' === BFTD_CPT::session_primary('title', 'edit-bftd_session'),
  'the student column is the one that carries Edit and Trash');
check('title' === BFTD_CPT::session_primary('title', 'edit-page'),
  'and no other list is touched');

/* And that column has to be a link to the record, not to somewhere else. */
$GLOBALS['POSTS'][40] = (object) array('ID' => 40, 'post_type' => 'bftd_session', 'post_status' => 'publish', 'post_title' => 'x');
$GLOBALS['META'][40]  = array('_bftd_student_id' => 11);
$GLOBALS['POSTS'][11] = (object) array('ID' => 11, 'post_type' => 'bftd_student', 'post_status' => 'publish', 'post_title' => 'Kaine M');
ob_start(); BFTD_CPT::session_column('bftd_student', 40); $cell = ob_get_clean();
check(false !== strpos($cell, 'Kaine M'), 'the student cell names the child');
check(false !== strpos($cell, 'post=40&action=edit'), 'and opens that session, got ' . $cell);
check(false === strpos($cell, 'bftd_student=11'),
  'rather than the filter it used to be, which now sits in the row actions');

$acts = BFTD_CPT::session_row_actions(array('edit' => 'Edit'), $GLOBALS['POSTS'][40]);
check(isset($acts['bftd_only']) && false !== strpos($acts['bftd_only'], 'bftd_student=11'),
  'narrowing to one child is still one click away');
check(isset($acts['edit']), 'and nothing WordPress put in the row actions is lost');

/* The tutor cell falls back to the child's tutors, because a session with
   nobody named on it belongs to whoever has the child. */
$GLOBALS['USERS'][5] = (object) array('ID' => 5, 'display_name' => 'Rae T', 'user_email' => 'rae@bftutoring.com');
$GLOBALS['META'][11]['_bftd_assigned_staff'] = 5;
ob_start(); BFTD_CPT::session_column('bftd_tutor', 40); $tut = ob_get_clean();
check(false !== strpos($tut, 'Rae T'), 'an unstaffed session shows the child\'s tutor, got ' . $tut);

$GLOBALS['USERS'][6] = (object) array('ID' => 6, 'display_name' => 'Jo P', 'user_email' => 'jo@bftutoring.com');
$GLOBALS['META'][40]['_bftd_assigned_staff'] = 6;
ob_start(); BFTD_CPT::session_column('bftd_tutor', 40); $tut = ob_get_clean();
check(false !== strpos($tut, 'Jo P') && false === strpos($tut, 'Rae T'),
  'and one with its own takes that instead, got ' . $tut);
unset($GLOBALS['META'][40]['_bftd_assigned_staff']);

/* ---- the date, in the shape a tutor reads a week in ---- */
$L = 30;
$GLOBALS['POSTS'][$L] = (object) array('ID' => $L, 'post_type' => 'bftd_session', 'post_status' => 'publish', 'post_title' => 'Kaine M');
$GLOBALS['META'][$L]  = array('_bftd_session__session_date' => '2026-09-16', '_bftd_session__session_time' => '16:00');

$when = BFTD_CPT::when_label($L);
check(0 === strpos($when, 'Wed: 09/16/26'),
  'the day name leads, because a tutor is looking for Wednesday rather than for the sixteenth');
check(false !== strpos($when, '4:00 pm'), 'with the time under it');

$GLOBALS['META'][$L]['_bftd_session__session_time'] = '';
check('Wed: 09/16/26' === BFTD_CPT::when_label($L), 'a lesson with no time is just the day');
$GLOBALS['META'][$L]['_bftd_session__session_date'] = '';
check(false !== strpos(BFTD_CPT::when_label($L), 'No date yet'),
  'and one with no date says so rather than showing today');

/* It is the lesson's own date. The record was filed whenever it was filed. */
$GLOBALS['META'][$L]['_bftd_session__session_date'] = '2026-01-05';
$GLOBALS['POSTS'][$L]->post_date = '2026-09-19 22:52:00';
check(0 === strpos(BFTD_CPT::when_label($L), 'Mon: 01/05/26'),
  'and it never quietly falls back to when somebody typed it up');

/* ---- one student at a time ---- */
$GLOBALS['VISIBLE'] = array(11, 12);
$GLOBALS['POSTS'][11] = (object) array('ID' => 11, 'post_type' => 'bftd_student', 'post_status' => 'publish', 'post_title' => 'Kaine M');
$GLOBALS['POSTS'][12] = (object) array('ID' => 12, 'post_type' => 'bftd_student', 'post_status' => 'publish', 'post_title' => 'Alice B');

ob_start(); BFTD_CPT::session_filter('bftd_session'); $f = ob_get_clean();
check(false !== strpos($f, 'name="bftd_student"'), 'the list can be narrowed to one child');
check(false !== strpos($f, 'Every student'), 'or left showing all of them');
check(2 === substr_count($f, '<option value="1'), 'with only the students this person may see');

ob_start(); BFTD_CPT::session_filter('bftd_assessment'); $other = ob_get_clean();
check('' === $other, 'and it appears on the Lessons list and nowhere else');

$GLOBALS['VISIBLE'] = array();
ob_start(); BFTD_CPT::session_filter('bftd_session'); $empty = ob_get_clean();
check('' === $empty, 'somebody with no students is not offered an empty dropdown');

/* ---- the client ---- */
/* A caregiver is anyone responsible for the child and they all see the same
   portal. The client is one person: the contact the practice deals with and
   the one the invoice goes to. The column names that person, not a list. */
$GLOBALS['USERS'][20] = (object) array('ID' => 20, 'display_name' => 'Laurel Sanders', 'user_email' => 'laurel@example.test');
ob_start(); BFTD_CPT::session_column('bftd_client', 40); $cl = ob_get_clean();
check(false !== strpos($cl, 'Nobody yet'), 'a student with no client named says so, got ' . $cl);

$GLOBALS['META'][11]['_bftd_client_primary'] = 20;
ob_start(); BFTD_CPT::session_column('bftd_client', 40); $cl = ob_get_clean();
check(false !== strpos($cl, 'Laurel Sanders'), 'and one with a client names them, got ' . $cl);

/* ---- narrowing by tutor ---- */
/* A tutor is assigned to children, not to sessions, so "their sessions" means
   their children's. The dropdown lists only people who actually have somebody:
   a practice of two tutors and eleven accounts that could technically hold a
   caseload otherwise offers thirteen names, eleven of which return nothing. */
$GLOBALS['VISIBLE'] = array(11, 12);
$GLOBALS['POSTS'][12] = (object) array('ID' => 12, 'post_type' => 'bftd_student', 'post_status' => 'publish', 'post_title' => 'Alice B');
$GLOBALS['USERS'][7]  = (object) array('ID' => 7, 'display_name' => 'Unstaffed U', 'user_email' => 'u@bftutoring.com');
$GLOBALS['META'][12]['_bftd_assigned_staff'] = 6;

ob_start(); BFTD_CPT::session_filter('bftd_session'); $f2 = ob_get_clean();
check(false !== strpos($f2, 'name="bftd_tutor"'), 'the list can be narrowed to one tutor');
check(false !== strpos($f2, 'Rae T') && false !== strpos($f2, 'Jo P'), 'naming the people who have somebody');
check(false === strpos($f2, 'Unstaffed U'), 'and not the ones who have nobody');

/* What the filter actually does to the query. A dropdown that renders and
   changes nothing is the failure this catches. */
class FakeQuery {
  public $vars = array();
  public function __construct($v = array()) { $this->vars = $v; }
  public function is_main_query() { return true; }
  public function get($k) { return $this->vars[$k] ?? ''; }
  public function set($k, $v) { $this->vars[$k] = $v; }
}

$_GET = array('bftd_tutor' => 5);
$q = new FakeQuery(array('post_type' => 'bftd_session'));
BFTD_CPT::session_query($q);
$mq = $q->get('meta_query');
check(!empty($mq) && '_bftd_student_id' === $mq[0]['key'] && array(11) === $mq[0]['value'],
  'choosing a tutor narrows to that tutor\'s children, got ' . json_encode($mq));

/* Both at once is an AND, and the pair that cannot both be true is empty
   rather than everything. WP_Query drops a clause with an empty value, so
   "nobody matched" had to be spelled as a value that matches nobody. */
$_GET = array('bftd_tutor' => 5, 'bftd_student' => 12);
$q = new FakeQuery(array('post_type' => 'bftd_session'));
BFTD_CPT::session_query($q);
$mq = $q->get('meta_query');
check(array(0) === $mq[0]['value'], 'a tutor who does not have that child matches nothing, got ' . json_encode($mq[0]['value']));

$_GET = array();
$q = new FakeQuery(array('post_type' => 'bftd_session'));
BFTD_CPT::session_query($q);
check('' === $q->get('meta_query'), 'and with neither chosen, nothing is narrowed');

/* ---- narrowing by client ---- */
ob_start(); BFTD_CPT::session_filter('bftd_session'); $f3 = ob_get_clean();
check(false !== strpos($f3, 'name="bftd_client"'), 'the list can be narrowed to one client');
check(false !== strpos($f3, 'Laurel Sanders'), 'naming the clients somebody is actually named on');
check(false === strpos($f3, 'Unstaffed U'), 'and nobody who is not');

$_GET = array('bftd_client' => 20);
$q = new FakeQuery(array('post_type' => 'bftd_session'));
BFTD_CPT::session_query($q);
$mq = $q->get('meta_query');
check(!empty($mq) && array(11) === $mq[0]['value'], 'choosing a client narrows to the children they pay for, got ' . json_encode($mq));

/* All three narrow each other rather than contradicting. */
$_GET = array('bftd_client' => 20, 'bftd_tutor' => 6);
$q = new FakeQuery(array('post_type' => 'bftd_session'));
BFTD_CPT::session_query($q);
check(array(0) === $q->get('meta_query')[0]['value'],
  'a client and a tutor with no child between them match nothing');

/* ---- and what the rows are ordered by ---- */
/* Reading down a list of one family, the question changes: three children
   across two tutors is a conversation held a tutor at a time. Everywhere
   else the child is the thing being read down. */
$src = '';
foreach (token_get_all(file_get_contents(BFTD_PATH . 'includes/class-bftd-cpt.php')) as $t) {
  if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $src .= $t[1]; }
  else { $src .= $t; }
}
preg_match('/function session_grouping\(.*?\n\t\}/s', $src, $gg);
$gg = isset($gg[0]) ? $gg[0] : '';
check(false !== strpos($gg, 'bftd_tu.display_name'), 'a chosen client groups the rows by tutor');
check(false !== strpos($gg, "empty( \$_GET['bftd_client'] )"), 'and only then, got the branch');
/* Under ONLY_FULL_GROUP_BY, which is on by default, a bare column from a
   join that can match twice is refused outright. A student with two tutors
   is exactly that join. */
check(false !== strpos($gg, 'MIN(bftd_st.post_title)') && false !== strpos($gg, 'MAX(bftd_dm.meta_value)'),
  'with every column in that ordering aggregated, because a child can have two tutors');

$_GET = array();

/* ---- searching ---- */
/* Everything a session knows is in postmeta, so WordPress searching
   post_content and post_excerpt searched two columns that are always empty.
   Typing a parent's email address found nothing, and an email from a family
   is exactly what somebody has in front of them when they go looking. */
class FakeDb {
  public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta';
  public $cols = array();
  public function prepare($sql, ...$a) {
    foreach ($a as $v) {
      $sql = preg_replace('/%s/', is_numeric($v) ? $v : "'" . $v . "'", $sql, 1);
      $sql = preg_replace('/%d/', (int) $v, $sql, 1);
    }
    return $sql;
  }
  public function esc_like($s) { return addcslashes($s, '_%\\'); }
  public function get_col($sql) { $this->cols[] = $sql; return $GLOBALS['DBROWS'] ?? array(); }
}
$GLOBALS['wpdb'] = new FakeDb();

$GLOBALS['DBROWS'] = array(11);
$q = new FakeQuery(array('post_type' => 'bftd_session', 's' => 'rae@bftutoring.com'));
$sql = BFTD_CPT::session_search(' AND (((wp_posts.post_content LIKE ...)))', $q);
check(false === strpos($sql, 'post_content'), 'the search no longer looks in columns a session never fills');
check(false !== strpos($sql, 'post_title LIKE'), 'it still matches the generated title');
check(false !== strpos($sql, '_bftd_student_id'), 'and every session of a student the term found');
check(false !== strpos($sql, ' OR '), 'either one being a hit, not both, got ' . $sql);

/* It is the students the term found, not the term pasted into the query. */
$found = BFTD_CPT::students_matching('rae@bftutoring.com');
check(in_array(11, $found, true), 'a tutor\'s email finds the children on their list');
$joined = implode(' ', $GLOBALS['wpdb']->cols);
check(false !== strpos($joined, "p.post_type = 'bftd_student'"),
  'looked up through the posts table, because those two meta keys are on sessions and reports too');
check(false !== strpos($joined, "'_bftd_client_user_id'") && false !== strpos($joined, "'_bftd_assigned_staff'"),
  'family accounts and tutor accounts both, in one pass');

/* A term nobody answers to must not become a search for nothing at all. */
$GLOBALS['DBROWS'] = array();
$q = new FakeQuery(array('post_type' => 'bftd_session', 's' => 'zzzz'));
$sql = BFTD_CPT::session_search(' AND (1=1)', $q);
check(false !== strpos($sql, 'post_title LIKE'), 'a term matching nobody still searches the title');
check(false === strpos($sql, 'IN (  )'), 'and never builds an empty IN list');

/* Another post type's list is left exactly as WordPress built it. */
$q = new FakeQuery(array('post_type' => 'bftd_student', 's' => 'rae'));
check(' AND (1=1)' === BFTD_CPT::session_search(' AND (1=1)', $q), 'and no other list is touched');

/* ---- grouped by child, in SQL, because the list is paged ---- */
$src = '';
foreach (token_get_all(file_get_contents(BFTD_PATH . 'includes/class-bftd-cpt.php')) as $t) {
  if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $src .= $t[1]; }
  else { $src .= $t; }
}
preg_match('/function session_grouping\(.*?\n\t\}/s', $src, $g);
$g = isset($g[0]) ? $g[0] : '';
check('' !== $g, 'the grouping is done once, in the query');
check(false !== strpos($g, 'LEFT JOIN'),
  'joined LEFT, so a lesson with no student yet still appears rather than being hidden');
check(false === strpos($g, 'INNER JOIN'), 'and never INNER, which would hide exactly those');
check(false !== strpos($g, 'bftd_st.post_title ASC'), 'ordered by the child');
check(false !== strpos($g, 'bftd_dm.meta_value DESC'), 'then by the lesson date, newest first');
check(false !== strpos($g, '$wpdb->prepare'), 'with the meta keys passed as parameters, not pasted into SQL');
check(false !== strpos($g, "if ( \$query->get( 'orderby' ) ) return"),
  'and it stands aside the moment somebody sorts by a column themselves');
check(false !== strpos($g, 'groupby'), 'one row per lesson, however many meta rows the joins matched');

exit($fail ? 1 : 0);
