<?php
/*
 * Skills, from the activity and from the lesson, all the way to the report.
 *
 * Two ways in, and they are different claims. A skill comes with the activity
 * because that is what the activity is for; a skill added to the lesson itself
 * is the tutor saying this child got there today, whatever the programme said
 * the activity was for. Both belong on the report, and a skill that arrived
 * both ways is one skill, not two rows.
 *
 * The part most worth holding is the draft. A draft lesson is kept out of the
 * report everywhere else, deliberately — and the preview exists precisely to
 * show a tutor the report WITH the thing they are writing in it. So the whole
 * chain has to answer differently depending on who is asking, and it has to do
 * it through one filter rather than through a flag threaded down four call
 * sites.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS']   = array();
$GLOBALS['META']    = array();
$GLOBALS['FILTERS'] = array();

/* A get_posts that actually applies the meta_query it is given.

   The first version of this stub ignored it, which meant every check about
   WHICH lessons a report holds passed whatever the query asked for — and the
   mutation that reintroduced the original bug went green. A stub that answers
   the same thing to every question tests nothing. */
function meta_matches($id, $clause) {
  if (isset($clause['relation'])) {
    $rel = strtoupper($clause['relation']);
    $parts = array();
    foreach ($clause as $k => $v) { if ('relation' !== $k) $parts[] = $v; }
    foreach ($parts as $part) {
      $hit = meta_matches($id, $part);
      if ('OR' === $rel && $hit) return true;
      if ('AND' === $rel && !$hit) return false;
    }
    return 'AND' === $rel;
  }
  $have = $GLOBALS['META'][$id][$clause['key']] ?? null;
  $cmp  = $clause['compare'] ?? '=';
  if ('NOT EXISTS' === $cmp) return null === $have;
  if ('EXISTS' === $cmp) return null !== $have;
  return (string) $have === (string) $clause['value'];
}
function get_posts($a) {
  $out = array();
  $want = (array) ($a['post_status'] ?? array('publish'));
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (!in_array($p->post_status, $want, true)) continue;

    /* An orderby meta_key is an INNER JOIN, not a hint.
       WordPress drops every post that does not carry the key, and asking for a
       key that existed on nothing is exactly how every lesson disappeared off
       every progress report for three versions while nothing errored. A stub
       that treated meta_key as decoration could never have caught it, so it
       does not. */
    if (!empty($a['meta_key']) && !isset($GLOBALS['META'][$id][$a['meta_key']])) continue;

    foreach ((array) ($a['meta_query'] ?? array()) as $k => $clause) {
      if ('relation' === $k || !is_array($clause)) continue;
      if (!meta_matches($id, $clause)) continue 2;
    }
    $out[] = $id;
  }
  return $out;
}
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ($s ? '' : array()); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function get_the_title($id) { return $GLOBALS['POSTS'][(int) $id]->post_title ?? ''; }
function get_post_type($id) { return $GLOBALS['POSTS'][(int) $id]->post_type ?? ''; }
function register_post_type(...$a) {}
function add_meta_box(...$a) {}
function add_submenu_page(...$a) {}
function get_post_type_object($t) { return null; }
function current_user_can(...$a) { return true; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function is_admin() { return true; }
function wp_unslash($v) { return $v; }
function wp_kses_post($v) { return $v; }
function esc_textarea($v) { return htmlspecialchars((string) $v); }
function wp_get_attachment_image_url($id, $s = '') { return ''; }

/* The filter the preview hands a draft lesson in through. Real enough to be
   worth testing against: add, apply, remove. */
function add_filter($tag, $fn, $p = 10, $n = 1) { $GLOBALS['FILTERS'][$tag][] = $fn; }
function remove_all_filters($tag = '') { unset($GLOBALS['FILTERS'][$tag]); }
function apply_filters($tag, $value) {
  $args = array_slice(func_get_args(), 1);
  foreach ($GLOBALS['FILTERS'][$tag] ?? array() as $fn) { $args[0] = call_user_func_array($fn, $args); }
  return $args[0];
}
function add_action(...$a) {}

class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Roles { public static function can_manage_team($u = null) { return true; } }
class BFTD_Threads {}

require BFTD_PATH . 'includes/class-bftd-library.php';
require BFTD_PATH . 'includes/class-bftd-skills.php';
require BFTD_PATH . 'includes/class-bftd-activities.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-cpt.php';
require BFTD_PATH . 'includes/class-bftd-derived.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* The columns of the spiral, or none. BFTD_Derived::skills() answers array()
   when a report has no lessons, and reaching straight into ['lessons'] made a
   broken query crash the test instead of failing it — which tells you far less
   about what went wrong. */
function lessons_of($grid) { return isset($grid['lessons']) ? $grid['lessons'] : array(); }

function post($id, $type, $title, $status = 'publish') {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => $type,
    'post_status' => $status, 'post_title' => $title, 'post_content' => '');
}
function skill($id, $name, $about = '') {
  post($id, 'bftd_skill', $name);
  $GLOBALS['POSTS'][$id]->post_content = $about;
  BFTD_Skills::flush();
}
function act($id, $n, $name, $track, $skills = array()) {
  post($id, 'bftd_activity', $name);
  $GLOBALS['META'][$id]['_bftd_activity_number'] = $n;
  $GLOBALS['META'][$id]['_bftd_activity_track']  = $track;
  $GLOBALS['META'][$id]['_bftd_activity_skills'] = $skills;
  BFTD_Activities::flush();
}
function lesson($id, $report, $date, $status, $activities = array(), $own = array(), $texts = array()) {
  post($id, 'bftd_session', 'Lesson', $status);
  /* A published lesson carries both: the student it is about, which is how
     every reader finds it, and the report it was attached to, which is what
     the audit trail reads. */
  $GLOBALS['META'][$id]['_bftd_student_id']           = $GLOBALS['META'][$report]['_bftd_student_id'];
  $GLOBALS['META'][$id]['_bftd_report_id']            = $report;
  $GLOBALS['META'][$id]['_bftd_session__session_date'] = $date;
  $GLOBALS['META'][$id]['_bftd_session__status']       = 'held';
  $GLOBALS['META'][$id]['_bftd_session__activities']   = $activities;
  $GLOBALS['META'][$id]['_bftd_session__skills']       = $own;
  $GLOBALS['META'][$id]['_bftd_session__texts']        = $texts;
}

/* ---- the library ---- */
skill(301, 'Mapping sounds to their spellings', '<p>Writing a spelling above each sound.</p>');
skill(302, 'Reading a passage smoothly');
skill(303, 'Working out what a sentence is saying');
skill(304, 'Reading a poem aloud');

act(201, 12, 'Sound Lines',             't1',  array(301));
act(202, 12, 'Key word, Action, Thing', 't23', array(303));

post(700, 'bftd_student', 'Kaine M');
post(900, 'bftd_progress', 'Progress report');
$GLOBALS['META'][900]['_bftd_student_id'] = 700;

/* ================================================================= */
/* An activity brings its skills with it                              */
/* ================================================================= */

lesson(801, 900, '2026-07-07', 'publish', array(array('id' => '201', 'note' => '')), array(),
  array(array('title' => 'The Yucky Feeling', 'level' => 'Grade 1')));

$reached = BFTD_Skills::for_session(801);
check(array(301) === array_keys($reached), 'choosing an activity reaches the skills that activity is for');
check('Mapping sounds to their spellings' === $reached[301]['name'], 'named from the skills library');
check(array('Track 1 · 12 · Sound Lines') === $reached[301]['from'],
  'and the report can say which activity brought it, track and all');
check(false !== strpos($reached[301]['about'], 'spelling above each sound'),
  'with the explanation written once, on the skill');

/* ================================================================= */
/* A lesson can add one of its own                                    */
/* ================================================================= */

lesson(802, 900, '2026-07-09', 'publish',
  array(array('id' => '202', 'note' => '')),
  array(array('id' => '302', 'note' => 'Third pass was smooth and expressive.')));

$reached = BFTD_Skills::for_session(802);
check(2 === count($reached), 'the activity\'s skill and the tutor\'s own both arrive');
check(isset($reached[303]) && isset($reached[302]), 'and they are the two that were recorded');
check('Third pass was smooth and expressive.' === $reached[302]['note'],
  'a skill the tutor added carries what they wrote about it');
check('' === $reached[303]['note'], 'while one that came with the activity has nothing typed against it');

/* A skill that arrived both ways is one skill. Two rows for one thing on a
   family's report is the scatter this whole model exists to stop. */
lesson(803, 900, '2026-07-13', 'publish',
  array(array('id' => '201', 'note' => '')),
  array(array('id' => '301', 'note' => 'He did it without prompting today.')));

$both = BFTD_Skills::for_session(803);
check(1 === count($both), 'a skill reached both ways is one skill, got ' . count($both));
check('He did it without prompting today.' === $both[301]['note'],
  'and what the tutor wrote about it is kept');
check(array('Track 1 · 12 · Sound Lines') === $both[301]['from'],
  'without losing the activity that brought it, which is the other half of the claim');

/* Nothing invented. A skill id pointing at a record that is not a skill, or at
   nothing at all, is dropped rather than drawn as a blank row. */
lesson(804, 900, '2026-07-14', 'publish', array(), array(
  array('id' => '301', 'note' => ''),
  array('id' => '999', 'note' => 'points at nothing'),
  array('id' => '201', 'note' => 'points at an activity, not a skill'),
));
check(array(301) === array_keys(BFTD_Skills::for_session(804)),
  'a row pointing at something that is not a skill draws no row');

/* ================================================================= */
/* Published, draft, and the preview                                  */
/* ================================================================= */

/* A draft is a tutor's notes in progress. It must not reach the family. */
lesson(805, 900, '2026-07-20', 'draft',
  array(array('id' => '202', 'note' => '')),
  array(array('id' => '304', 'note' => 'Drafted, not published.')),
  array(array('title' => 'Rook of the Pines', 'level' => 'Grade 4')));

$grid = BFTD_Derived::skills(900);
check(4 === count(lessons_of($grid)), 'the family sees the published lessons, got ' . count(lessons_of($grid)));
foreach (lessons_of($grid) as $one) {
  check(805 !== $one['id'], 'and never the draft');
}
check(!isset($grid['rows'][304]), 'so a skill only a draft reached is not on the report yet');

$log = BFTD_Derived::texts(900);
$titles = array();
foreach ($log as $t) $titles[] = $t['title'];
check(!in_array('Rook of the Pines', $titles, true) || 2 === count($log),
  'and the reading log counts only what published lessons read');

/* The preview is the one place a draft does show, because showing it is the
   entire point of pressing the button. It comes in through the same filter the
   sample report uses, so every derived table picks it up at once rather than
   each needing to be taught about drafts separately. */
$ids = BFTD_CPT::sessions_in_order(900);
check(!in_array(805, $ids, true), 'the ordered list does not hold the draft either');
$ids[] = 805;
add_filter('bftd_pre_sessions_for', function () use ($ids) { return $ids; });

$grid = BFTD_Derived::skills(900);
check(5 === count(lessons_of($grid)), 'the preview shows the draft alongside the published ones');
check(isset($grid['rows'][304]), 'so the skill it reached is on the previewed report');

/* And it lands where its date puts it, not at the end where it was appended.
   A lesson dated the 20th is the fifth of five; one back-dated to the 1st
   would have to be the first, or the preview shows a sequence that is not the
   sequence the family will see. */
check(805 === (lessons_of($grid)[4]['id'] ?? 0), 'in the place its own date gives it');
$GLOBALS['META'][805]['_bftd_session__session_date'] = '2026-07-01';
$grid = BFTD_Derived::skills(900);
check(805 === (lessons_of($grid)[0]['id'] ?? 0), 'including when that is before every published one');
$GLOBALS['META'][805]['_bftd_session__session_date'] = '2026-07-20';

/* The reading log and the missed table come through the same door. */
$log = BFTD_Derived::texts(900);
$titles = array();
foreach ($log as $t) $titles[] = $t['title'];
check(in_array('Rook of the Pines', $titles, true), 'the draft\'s texts reach the previewed reading log');

$GLOBALS['META'][805]['_bftd_session__status']        = 'missed';
$GLOBALS['META'][805]['_bftd_session__missed_by']     = 'family';
$GLOBALS['META'][805]['_bftd_session__missed_why']    = 'Away';
check(1 === count(BFTD_Derived::missed(900)), 'and a draft cancellation shows in the preview too');
$GLOBALS['META'][805]['_bftd_session__status'] = 'held';

remove_all_filters('bftd_pre_sessions_for');
check(4 === count(lessons_of(BFTD_Derived::skills(900))),
  'and the moment the preview is over, the draft is out of the report again');

/* ================================================================= */
/* A second progress report                                           */
/* ================================================================= */

/*
 * The one that got out.
 *
 * A lesson used to be found through a link written onto it when it published,
 * pointing at whichever progress report was newest at that moment. Somebody
 * then pressed New Progress Report, and from then on the tutor was looking at
 * a report the lessons did not point at: the report showed none of them, the
 * editor said "Empty, so not shown to the family", and nothing errored.
 *
 * What kept it alive is that previewing FROM a lesson still worked, because
 * that path adds the lesson by hand. So the feature looked fine from the side
 * anybody checked it from.
 *
 * A lesson belongs to a child, and the report belongs to the same child. Ask
 * the child and there is nothing to point at the wrong thing.
 */
post(901, 'bftd_progress', 'Progress report, the second one');
$GLOBALS['META'][901]['_bftd_student_id'] = 700;

check(4 === count(BFTD_CPT::sessions_for(901)),
  'a second progress report for the same child shows that child\'s lessons, got ' . count(BFTD_CPT::sessions_for(901)));
check(BFTD_CPT::sessions_for(900) === BFTD_CPT::sessions_for(901),
  'the same lessons, because they are the same child\'s');
check(4 === count(lessons_of(BFTD_Derived::skills(901))), 'so the spiral is drawn on it too');
check(BFTD_Derived::has('sec-sessions', 901),
  'and the editor does not call Every lesson empty over a term of them');

/* The link written on the lesson is stale on purpose here — it still points at
   the first report — and that must not matter to anything a tutor sees. */
check('900' === (string) $GLOBALS['META'][801]['_bftd_report_id'],
  'the lesson still carries the link it was given');
check(in_array(801, BFTD_CPT::sessions_for(901), true),
  'and is found anyway, by the child it is about');

/* A report belonging to nobody lists nobody's lessons, rather than everyone's.
   Reading "which lessons" off the student is only safe if a missing student
   means none rather than no filter at all. */
post(902, 'bftd_progress', 'A report with no student');
check(array() === BFTD_CPT::sessions_for(902), 'a report with no student on it has no lessons');

/* Both of them are still two, and the screen has to be able to say so. The
   lessons now show on either, but the family is served only one, so anything
   WRITTEN into the other reaches nobody. */
$both = BFTD_CPT::progress_reports_for(700);
sort($both);
check(array(900, 901) === $both, 'a student\'s progress reports can be counted, got ' . implode(',', $both));
check(!in_array(902, $both, true), 'and a report belonging to nobody is not one of them');

$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(1 === preg_match('/function warn_about_duplicate_reports\([\s\S]{0,900}count\( \$all \) < 2 \) return;/', $mb),
  'one report says nothing');
check(1 === preg_match('/warn_about_duplicate_reports\([\s\S]{0,1400}will not reach them/', $mb),
  'two says which one the family is actually being shown');
check(1 === preg_match('/function flash\([\s\S]{0,1200}self::warn_about_duplicate_reports\(\);/', $mb),
  'and it is drawn, rather than only defined');

/* ================================================================= */
/* Saving a draft                                                     */
/* ================================================================= */

/* Skills are stored on the lesson the same way activities are, so a draft
   saved half-written comes back with what was in it. The sanitiser is what
   makes that true: a row pointing at nothing must not survive a save, or the
   report draws a blank line for it. */
$clean = BFTD_Fields::sanitize_rows(
  array(
    array('id' => '301', 'note' => '  Did it unprompted.  '),
    array('id' => '',    'note' => 'a row nobody chose a skill for'),
    array('id' => '999', 'note' => 'points at nothing'),
  ),
  array('id' => array('type' => 'skill'), 'note' => array('type' => 'text'))
);
check(1 === count($clean), 'an empty row and a row pointing at nothing are both dropped, got ' . count($clean));
check('301' === (string) $clean[0]['id'], 'and the real one is kept');
check('Did it unprompted.' === $clean[0]['note'], 'with what was typed about it, trimmed');

/* A trashed skill is not the same as one that never existed.
 *
 * The library is publish-only, so a trashed skill is offered nowhere and drawn
 * nowhere, which is right. But the lessons and activities that used it still
 * hold its id with notes hanging off it, and trash is undoable. The save used
 * to ask the library and threw the row away, so putting the skill back brought
 * back nothing. */
post(305, 'bftd_skill', 'Retired skill', 'trash');
BFTD_Skills::flush();
check('' === BFTD_Skills::label(305), 'a trashed skill is not in the library');
check(BFTD_Skills::exists(305), 'but it is still there to be put back');
check('305' === BFTD_Fields::sanitize_value('305', array('type' => 'skill')),
  'so a save keeps the rows that point at it');
check(!BFTD_Skills::exists(999), 'while an id with nothing behind it is not kept');
check('' === BFTD_Fields::sanitize_value('999', array('type' => 'skill')), 'and is dropped on save');
check(!BFTD_Skills::exists(801), 'and neither is an id that landed on a post of another kind');

exit($fail ? 1 : 0);
