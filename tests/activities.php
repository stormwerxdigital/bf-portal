<?php
/*
 * The activity library, and the lesson that points at it.
 *
 * A tutor used to type the name of each activity into the lesson record. The
 * same activity then arrived as "blending 2 sounds", "Blending two sounds"
 * and "blend 2 snds" across three lessons, and on the family's report those
 * are three different skills: the grid meant to show a spiral showed a
 * scatter, and the "activities covered" count was whatever the typing
 * happened to produce. So an activity is a record now, and a lesson points at
 * it.
 *
 * The thing most worth guarding is the migration edge. Every lesson recorded
 * before this, and the sample report's own lessons, carry names and no ids.
 * They must still resolve, still group with themselves, and still render, or
 * a change of model quietly blanks a year of reports.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();

function get_posts($a) {
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (isset($a['post_status']) && $p->post_status !== $a['post_status']) continue;
    $out[] = $id;
  }
  return $out;
}
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ($s ? '' : array()); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function get_the_title($id) { return $GLOBALS['POSTS'][(int) $id]->post_title ?? ''; }
function register_post_type(...$a) {}
function add_meta_box(...$a) {}
function current_user_can(...$a) { return true; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function is_admin() { return true; }
function wp_unslash($v) { return $v; }
function wp_nonce_field(...$a) { echo '<input type="hidden" name="n" value="1">'; }
function wp_get_attachment_image_url($id, $size = '') { return 'https://example.test/' . (int) $id . '.jpg'; }

class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Roles { public static function can_manage_team($u = null) { return true; } }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Threads {}
function get_post_type($id) { return $GLOBALS['POSTS'][(int) $id]->post_type ?? ''; }
function wp_kses_post($v) { return $v; }
function esc_textarea($v) { return htmlspecialchars((string) $v); }

require BFTD_PATH . 'includes/class-bftd-library.php';
require BFTD_PATH . 'includes/class-bftd-skills.php';
require BFTD_PATH . 'includes/class-bftd-library-view.php';
if (!function_exists('get_post_type_object')) { function get_post_type_object($t){ return (object) array('cap' => (object) array('edit_others_posts' => 'edit_others_'.$t.'s')); } }
require BFTD_PATH . 'includes/class-bftd-activities.php';
/* The real field code, not a stand-in. The sanitiser is half of what makes an
 * activity a pointer rather than a typed word, and a stubbed one would have
 * let a row pointing at nothing through. */
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* $track left out on purpose for most of these: an activity written before
 * tracks existed has no track stored, and those must behave as Track 1. */
function activity($id, $n, $name, $body = '', $track = null) {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => 'bftd_activity', 'post_status' => 'publish', 'post_title' => $name, 'post_content' => $body);
  if ($n) $GLOBALS['META'][$id]['_bftd_activity_number'] = $n;
  if ($track) $GLOBALS['META'][$id]['_bftd_activity_track'] = $track;
  BFTD_Activities::flush();
}

activity(101, 12, 'Blending two sounds', '<p>The child hears two sounds and says the word.</p>');
activity(102, 3,  'Saying the sounds in a word');
activity(103, 140,'Multisyllable words');
activity(104, 0,  'An extra, unnumbered');

/* ---- the library reads as a sequence ---- */
$all = BFTD_Activities::all();
check(array(102, 101, 103, 104) === array_keys($all),
  'the library is in programme order, with the unnumbered one last');
check('Track 1 · 3 · Saying the sounds in a word' === BFTD_Activities::label(102),
  'an activity is labelled by track, number and name, which is what a tutor searches');
check('Track 1 · An extra, unnumbered' === BFTD_Activities::label(104),
  'and by track and name alone where there is no number yet');
check(4 === BFTD_Activities::total(), 'the programme is as long as the library');
check('' === BFTD_Activities::label(999), 'an activity that is not there has no label');

/* ---- a lesson points at one ---- */
$GLOBALS['META'][700]['_bftd_session__activities'] = array(
  array('id' => '101', 'note' => '/ee/'),
  array('id' => '103', 'note' => ''),
);
$got = BFTD_Activities::for_session(700);
check(2 === count($got), 'both activities come back');
check('Track 1 · 12 · Blending two sounds' === $got[0]['label'], 'named from the library, not from the lesson');
check('/ee/' === $got[0]['note'], 'with the note the tutor added');
check(false !== strpos($got[0]['about'], 'hears two sounds'),
  'and the description written once, in the library');
check(array('a101', 'a103') === array_column($got, 'key'),
  'each one keyed by what it is, so two lessons agree about the same skill');

/* Renaming the activity renames it everywhere, because there is one copy. */
$GLOBALS['POSTS'][101]->post_title = 'Blending two phonemes';
BFTD_Activities::flush();
$got = BFTD_Activities::for_session(700);
check('Track 1 · 12 · Blending two phonemes' === $got[0]['label'],
  'renaming an activity renames it on every report that used it');

/* An id pointing at nothing is not a skill. */
$GLOBALS['META'][701]['_bftd_session__activities'] = array(array('id' => '999', 'note' => 'gone'));
check(array() === BFTD_Activities::for_session(701), 'a deleted activity leaves no ghost row');

/* ---- lessons written before the library still read ---- */
$GLOBALS['META'][702]['_bftd_session__activities'] = array(
  array('name' => 'Homophones', 'new' => 'yes', 'about' => 'Words that sound alike.'),
  array('name' => 'Homophones'),
);
$old = BFTD_Activities::for_session(702);
check(2 === count($old), 'a lesson typed before the library still has its activities');
check('Homophones' === $old[0]['label'], 'under the name that was typed');
check($old[0]['key'] === $old[1]['key'], 'and the same typed name still groups with itself');
check(false !== strpos($old[0]['about'], 'sound alike'), 'keeping the explanation typed with it');
check(0 === $old[0]['number'], 'with no place in the sequence, which it never had');

/* Case and spacing are the thing that used to split a skill in two. */
$GLOBALS['META'][703]['_bftd_session__activities'] = array(array('name' => ' homophones '));
check(BFTD_Activities::for_session(703)[0]['key'] === $old[0]['key'],
  'the same name typed differently still lines up');

/* ---- the picker ---- */
$html = BFTD_Activities::picker('bftd_rows[session][activities][0][id]', 103);
check(false !== strpos($html, 'type="search"'), 'the picker has a box to search with');
check(false !== strpos($html, 'role="combobox"'), 'that behaves as one');

/* Chosen, and the search stands aside.
 *
 * A row that has an activity is the activity, with a way back to the list.
 * Leaving a search box sitting beside the thing it has already found is how
 * a tutor loses track of whether they have taken it or are still looking. */
check(false !== strpos($html, 'class="bftd-actpick is-chosen"'), 'a chosen picker says so');
check(1 === preg_match('/bftd-actpick-name">Track 1 · 140 · Multisyllable words</', $html),
  'and shows the activity by track, number and name');

/* The list and the chosen row are deliberately named differently: the list
   leaves the track off every line to keep the names readable, and the one that
   has been taken carries it, because it is on the lesson now and has to say
   which of two twelves it is. */
check(false !== strpos($html, '>140 · Multisyllable words<'),
  'while the option in the list is still just the number and the name');
check(1 === preg_match('/bftd-actpick-find" hidden/', $html), 'with the search put away');
check(false !== strpos($html, 'bftd-actpick-change'), 'and a way back to it');
check(false !== strpos($html, '<select'), 'the select is still there, because it is still the field');

$unchosen = BFTD_Activities::picker('x', 0);
check(false === strpos($unchosen, 'is-chosen'), 'a picker with nothing taken is still searching');
check(1 === preg_match('/bftd-actpick-is" hidden/', $unchosen), 'with nothing to show yet');
check(0 === preg_match('/bftd-actpick-find" hidden/', $unchosen), 'and the search in front of you');

/* An author display rule beats the browser's own [hidden]{display:none}, and
 * this is exactly where that bit: the search box stayed on screen beside the
 * activity it had already found. */
$css2 = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/\.bftd-actpick-find\[hidden\][^{]*\{display:none\}/', $css2),
  'so hiding it is said again in the stylesheet, where a display rule would otherwise win');

/* Removing a row is not warning about one.
 *
 * The X sat beside Change, in the colour that marks a section as hidden from
 * the family, and read as one of a pair of ordinary controls. It is the one
 * thing on the row that throws work away, so it goes to the far edge, on its
 * own, in a colour nothing else uses. */
check(1 === preg_match('/--bftd-red:#[0-9A-Fa-f]{6}/', $css2), 'there is a colour for removing');
check(1 === preg_match('/\.bftd-row-rm\{[^}]*color:var\(--bftd-red\)/', $css2), 'and the X wears it');
check(0 === preg_match('/\.bftd-row-rm\{[^}]*color:var\(--bftd-clay\)/', $css2),
  'rather than the one that means "hidden from the family"');
check(1 === preg_match('/\.bftd-stackrow-h \.bftd-row-rm\{[^}]*margin-left:auto/', $css2),
  'and it is pushed to the far side of the row');
check(1 === preg_match('/<ul class="bftd-actpick-r"[^>]*hidden><\/ul>/', $html),
  'with an empty list for the matches, filled as somebody types');
check(0 === preg_match('/<ul class="bftd-actpick-r"[^>]*>\s*<li/', $html),
  'and not a second copy of the whole library rendered per row before anybody has searched');
check(false !== strpos($html, '<select'), 'and is a real select underneath, so it works without the script');
check(1 === preg_match('/value="103"[^>]*selected/', $html), 'the chosen activity is chosen');
check(substr_count($html, '<option') === 5, 'every activity is offered, plus the empty choice');
check(false !== strpos($html, '140 · Multisyllable words'),
  'labelled by number and name, so the box can search either');

$empty_lib = BFTD_Activities::picker('x', 0);
check(false === strpos($empty_lib, 'value="103" selected'), 'nothing is chosen when nothing was passed');

/* ---- the number is what a save keeps ---- */
$_POST = array('bftd_activity_number' => '  42 ', 'bftd_activity_nonce' => 'n');
function wp_verify_nonce(...$a) { return true; }
function wp_is_post_revision($id) { return false; }
BFTD_Activities::save(101, $GLOBALS['POSTS'][101]);
check(42 === (int) $GLOBALS['META'][101]['_bftd_activity_number'], 'a number typed is a number stored');
$_POST['bftd_activity_number'] = '';
BFTD_Activities::save(101, $GLOBALS['POSTS'][101]);
check(!isset($GLOBALS['META'][101]['_bftd_activity_number']),
  'and clearing it removes it rather than storing a zero that sorts first');

/* A save always writes a track, and always one of the two. A form posting
   something else must not invent a third track, and a form posting nothing
   must not leave the activity out of both filters. */
$_POST = array('bftd_activity_number' => '12', 'bftd_activity_track' => 't23', 'bftd_activity_nonce' => 'n');
BFTD_Activities::save(101, $GLOBALS['POSTS'][101]);
check('t23' === $GLOBALS['META'][101]['_bftd_activity_track'], 'a track chosen is a track stored');
$_POST['bftd_activity_track'] = 'made up';
BFTD_Activities::save(101, $GLOBALS['POSTS'][101]);
check('t1' === $GLOBALS['META'][101]['_bftd_activity_track'], 'and anything else falls back to Track 1');
unset($_POST['bftd_activity_track']);
BFTD_Activities::save(101, $GLOBALS['POSTS'][101]);
check('t1' === $GLOBALS['META'][101]['_bftd_activity_track'], 'as does a form that posted none');
BFTD_Activities::flush();

/* ---- the search box, as the browser will run it ---- */
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
$js = preg_replace('#/\*[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', $js);   // comments are not evidence
$block = substr($js, strpos($js, '.bftd-actpick-q') - 2600);

check(false !== strpos($block, '$sel.html( html )'),
  'the list is rebuilt rather than hidden in place, because option{display:none} is honoured by some browsers and ignored by others');
check(0 === preg_match('/option[^\n]*display:\s*none/', $js), 'nothing tries to hide an option with CSS');
check(false !== strpos($block, "o.value !== keep"),
  'whatever is already chosen stays in the list, so filtering never unpicks it');
check(false !== strpos($block, "if ( match ) hits++"),
  'and the count counts matches, not the entries kept alongside them');
check(false !== strpos($block, "Nothing matches"),
  'a search that finds nothing says so, rather than looking broken');

/* Searching is not choosing, however few are left.
 *
 * Narrowing to one and selecting it for the tutor reads as helpful and is
 * not: a lesson's notes and a child's work would then hang off an activity
 * nobody picked, and the tutor would find out on the report. An activity is
 * taken by clicking it, or by Enter on the one moved to, and by nothing else. */
check(0 === preg_match('/1 === hits[^;]*\$sel\.val/', $block),
  'narrowing to one does not choose it');
check(false === strpos($block, 'match, chosen'), 'and the count does not claim it has');
check(false !== strpos($block, ".trigger( 'change' )"),
  "taking one does fire the change, because the row's notes and work appear on that event and a value set in script does not raise it");

/* The list under the box shows every match, because records can share a name
   and no amount of typing tells those apart (browser/picker-search.mjs drives
   that). So it has to scroll rather than grow down the page. */
check(false === strpos($block, 'var SHOW = 8'), 'the list is not cut off at eight');
$acss = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/\.bftd-actpick-r\{[^}]*max-height:[^}]*overflow:auto/', $acss),
  'and it scrolls inside a set height');

/* The same holds for the suggestions under the Skills and Activities list
   search, which are one piece of code serving both lists. */
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
$lib = substr($js, strpos($js, 'Suggestions under the Skills and Activities search box'));
check(1 === preg_match('/hits = window\.BFTDMatch\.search\( pool, \$q\.val\(\) \);/', $lib),
  'the list search suggests every match, not the first eight');
check(1 === preg_match('/\.bftd-libsug\{[^}]*max-height:[^}]*overflow-y:auto/', $acss),
  'in a list that scrolls inside a set height');
check(false !== strpos($lib, "insertAfter( \$go )"), 'and sits in the filter row, on both lists alike');
check(false !== strpos($lib, "$( '#search-submit' ).addClass( 'screen-reader-text' )"), 'with no Search button, since the suggestions are the results');
/* Checked at the call site, not at the helper. The helper being present says
 * nothing about whether the list uses it, and an earlier version of this
 * check passed while the label went in raw. */
check(false !== strpos($block, 'esc( hits[ j ].label )'),
  'an activity name goes into the list as text, not as markup');
check(false !== strpos($block, 'esc( hits[ j ].value )'), 'and so does its id');
check(false !== strpos($block, "on( 'mousedown', '.bftd-actpick-r li[data-value]'"),
  'a match is picked on mousedown, because blur fires first on a click and would close the list out from under the pointer');
foreach (array('40 === e.which' => 'arrow keys move down the list',
               '13 === e.which' => 'enter takes the highlighted one',
               '27 === e.which' => 'escape puts the list away') as $needle => $why) {
  check(false !== strpos($block, $needle), $why);
}
check(false !== strpos($block, "aria-expanded"), 'and the box says whether the list is open');
/* Checked by what settle DOES, not that it exists. A function that is there
 * and does nothing passes the first kind of check and fails the tutor. */
preg_match('/function settle\( \$wrap \) \{.*?\n\t\}/s', $block, $sm);
$settle = isset($sm[0]) ? $sm[0] : '';
check('' !== $settle, 'taking one settles the row into the activity');
foreach (array(
  "toggleClass( 'is-chosen'"          => 'the row is marked as having one',
  ".bftd-actpick-name' ).text( label" => 'the activity is named',
  ".bftd-actpick-find' ).prop( 'hidden'" => 'and the search is put away',
) as $needle => $why) {
  check(false !== strpos($settle, $needle), $why);
}
check(false !== strpos($block, "on( 'click', '.bftd-actpick-change'"), 'and Change goes back to the list');
check(false !== strpos($block, "on( 'change', '.bftd-actpick-s'"),
  'while the select on its own settles it the same way, for anyone who uses that instead');
check(false !== strpos($block, "removeData( 'bftdAll' )"),
  'a row added after the page loaded does not inherit the template row\'s cached options');
check(false !== strpos($block, "$( '<div>' ).text( o.label ).html()"),
  'an activity name goes back into the list as text, not as markup');

/* ---- and the lesson screen asks for the picker ---- */
$mb = '';
foreach (token_get_all(file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php')) as $t) {
  if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $mb .= $t[1]; }
  else { $mb .= $t; }
}
check(1 === preg_match("/'id'\s*=>\s*array\(\s*'type'\s*=>\s*'activity'/", $mb),
  'the lesson records an activity as a pointer, not as typed words');
check(0 === preg_match("/'about'\s*=>\s*array\(\s*'type'\s*=>\s*'textarea'/", $mb),
  'and no longer asks a tutor to re-explain an activity on every lesson');
check(1 === preg_match("/'session_time'\s*=>\s*array\(\s*'type'\s*=>\s*'time'/", $mb),
  'a lesson has a time as well as a date');
check(1 === preg_match("/'held'\s*=>\s*'Attended'/", $mb),
  'the status reads Attended');
/* The label changed; the stored value did not. Every lesson already recorded
 * holds 'held', and so does the figure the paid-for bank is counted from. */
$sched = file_get_contents(BFTD_PATH . 'includes/class-bftd-schedule.php');
check(1 === preg_match("/'held'\s*!==\s*\\\$status/", $sched),
  'the lessons-used count still recognises what an attended lesson is stored as');
check(0 === preg_match("/'attended'\s*=>/i", $mb),
  'so nothing was renamed underneath it');

/* The order a tutor fills it in, which is also the order it becomes a report. */
$order = array();
if (preg_match('/function box_session.*?function box_resource/s', $mb, $m)) {
  preg_match_all("/\\\$draw\( array\( ([^)]*) \) \);|render_field\( \\\$post->ID, 'session', 'activities'/", $m[0], $hits, PREG_SET_ORDER);
  foreach ($hits as $h) { $order[] = isset($h[1]) && '' !== $h[1] ? $h[1] : 'activities'; }
}
$flat = implode(' | ', $order);
check(false !== strpos($flat, "'notes'"), 'the lesson notes are on the screen');
$pos = function ($needle) use ($flat) { return strpos($flat, $needle); };
check($pos("'notes'") < $pos('activities'), 'notes come before the activities');
check($pos('activities') < $pos("'samples'"), 'and the activities before the work samples');
check($pos("'samples'") < $pos("'homework'"), 'and the work samples before the homework');
check($pos("'session_date'") < $pos("'notes'"), 'with when and whether it happened first of all');

/* ---- what a save is allowed to keep ---- */
$cols = array(
  'id'   => array('type' => 'activity', 'label' => 'Activity'),
  'note' => array('type' => 'text',     'label' => 'Note'),
);

check('101' === BFTD_Fields::sanitize_value('101', $cols['id']), 'an activity that exists is kept');
check('' === BFTD_Fields::sanitize_value('999', $cols['id']),
  'an id pointing at nothing is not an activity, whatever was posted');
check('' === BFTD_Fields::sanitize_value('700', $cols['id']),
  'and neither is the id of a lesson, or of any other kind of post');

$saved = BFTD_Fields::sanitize_rows(array(
  array('id' => '101', 'note' => '/ee/'),
  array('id' => '',    'note' => 'a note about nothing'),
  array('id' => '999', 'note' => 'points at a deleted activity'),
  array('id' => '103', 'note' => ''),
), $cols);
check(2 === count($saved), 'only the rows that name a real activity survive a save');
check('101' === $saved[0]['id'] && '/ee/' === $saved[0]['note'], 'with their notes');
check('103' === $saved[1]['id'], 'and in the order they were entered');

/* ---- activities are counted, not measured against a total ---- */

/* The activity tile used to read "N of 280". Skills are a set length — there
   is a fixed list of things this practice claims to teach, so a child is
   somewhere along it — but the activity library is the WAYS of teaching them,
   and no child is meant to go through all of it. "2 of 280" told a family
   their child was under one per cent of the way through a programme nobody
   completes. */
$rv = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false === strpos($rv, 'bftd_programme_activities'),
  'nothing invents a programme length any more');
check(0 === preg_match('/self::of\( \$done/', $rv),
  'and the activity tile is a plain count');
check(1 === preg_match('/self::of\( \$skills, \$skills_total \)/', $rv),
  'while the skills tile is a count out of the library, which does have a length');
check(false !== strpos($rv, "skills we\\'re building"), 'and is labelled as what it is');

/* ================================================================== */
/* An activity, its notes, and the child's work from it                */
/* ================================================================== */
/*
 * A row here is not three values side by side. It is an activity with a
 * paragraph and some photographs attached, and it is built in that order:
 * which activity, what happened in it, the work that came out of it, then
 * the next activity.
 */
$cols2 = BFTD_MetaBoxes_activity_columns();
function BFTD_MetaBoxes_activity_columns() {
  return array(
    'id'      => array('type' => 'activity', 'label' => 'Activity'),
    'note'    => array('type' => 'rich',     'label' => 'Notes'),
    'samples' => array('type' => 'gallery',  'label' => 'Work samples'),
  );
}

$render = new ReflectionMethod('BFTD_Fields', 'render_row');
$render->setAccessible(true);
function row_html($cols, $row, $i, $stack) {
  global $render;
  ob_start();
  $render->invoke(null, 'session', 'activities', $cols, $row, $i, $stack);
  return ob_get_clean();
}

/* A row with nothing in it: both panels shut, both still postable. */
$blank = row_html($cols2, array(), 0, true);
check(1 === preg_match('/class="bftd-row bftd-stackrow[ "]/', $blank), 'an activity is a block, not a table line');

/* Nothing hangs off a row until it has a subject. Offering a tutor somewhere
 * to type before they have chosen an activity offers them somewhere the save
 * will throw away, because a row with no activity is not a record of
 * anything and sanitize_rows drops it, notes and all. */
check(false !== strpos($blank, 'is-blank'), 'an activity not yet chosen holds nothing back');
check(false !== strpos($blank, 'data-needs-lead="1"'), 'and says so, so the script can follow the choice');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/\.bftd-stackrow\.is-blank \.bftd-stackrow-part\{display:none\}/', $css),
  'so the notes and the work are not offered yet');
check(2 === preg_match_all('/class="bftd-rowpanel[ "]/', $blank), 'with a panel for the notes and one for the work');
check(2 === preg_match_all('/bftd-rowpanel-t"\s+aria-expanded="false"/', $blank),
  'both shut to begin with');
check(false !== strpos($blank, 'Add notes') && false !== strpos($blank, 'Add work samples'),
  'and named by what they will hold');
check(false !== strpos($blank, 'name="bftd_rows[session][activities][0][note]"'),
  'the notes still post, open or not');
check(false !== strpos($blank, 'name="bftd_rows[session][activities][0][samples]"'),
  'and so do the work samples');
check(false !== strpos($blank, '<textarea'),
  'a blocked script leaves a textarea somebody can still type into');

/* A row with something in it opens on arrival, or the tutor would have to
 * hunt for their own writing. */
$full = row_html($cols2, array('id' => '101', 'note' => '<p>Five words.</p>', 'samples' => array(7, 8)), 1, true);
check(false === strpos($full, 'is-blank'), 'a row with an activity on it offers both');
check(2 === preg_match_all('/bftd-rowpanel-t"\s+aria-expanded="true"/', $full),
  'a row with notes and work opens both');
check(false !== strpos($full, 'Five words.'), 'showing what is there');
$half = row_html($cols2, array('id' => '101', 'note' => '<p></p>', 'samples' => array()), 2, true);
check(0 === preg_match_all('/bftd-rowpanel-t"\s+aria-expanded="true"/', $half),
  'while markup with no words in it is not notes, and stays shut');

/* Every id unique, and never the same one twice in a row. */
preg_match_all('/(?:id|data-target)="(bftd_rc[^"]*)"/', $full, $m);
check(2 === count(array_unique($m[1])), 'the two panels each carry an id of their own, and only two ids between them');
preg_match_all('/data-target="([^"]*)"/', $full, $dt);
foreach ($dt[1] as $target) {
  check(false !== strpos($full, 'id="' . $target . '"'),
    'a gallery points at a field that is really there: ' . $target);
}

/* The template leaves both for the script to fill, separately. */
$tpl = row_html($cols2, array(), '__i__', true);
preg_match_all('/__uid[a-z0-9]+__/', $tpl, $ph);
check(2 === count(array_unique($ph[0])),
  'the template carries one placeholder name per panel, so a new row gets two ids and not one');
check(3 === count($ph[0]),
  'and the gallery writes its own twice, on the field and on the wrapper that finds it');

/* ---- the lesson screen asks for all of it ---- */
$mb2 = '';
foreach (token_get_all(file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php')) as $t) {
  if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $mb2 .= $t[1]; }
  else { $mb2 .= $t; }
}
/* Scoped to the activity columns. The lesson has a work-samples gallery of
 * its own further up the same file, so an unscoped match would have said yes
 * about the per-activity one while it was not there at all. */
preg_match('/function activity_columns\(\).*?\n\t\}/s', $mb2, $ac);
$ac = isset($ac[0]) ? $ac[0] : '';
check('' !== $ac, 'the activity columns are declared in one place');
check(1 === preg_match("/'note'\s*=>\s*array\(\s*'type'\s*=>\s*'rich'/", $ac), 'the note is rich text');
check(1 === preg_match("/'samples'\s*=>\s*array\(\s*'type'\s*=>\s*'gallery'/", $ac), 'the work is a gallery');
check(1 === preg_match("/'id'\s*=>\s*array\(\s*'type'\s*=>\s*'activity'/", $ac), 'and the activity itself leads the row');
check(1 === preg_match("/'add_label'\s*=>\s*'Add activity'/", $mb2), 'the lesson screen asks for the button to say what it adds');

/* And the renderer honours it, rather than the field config being a note to
 * nobody. */
$rr = new ReflectionMethod('BFTD_Fields', 'render_rows');
$rr->setAccessible(true);
ob_start();
$rr->invoke(null, 'session', 'activities', array(
  'type' => 'rows', 'label' => 'Activities', 'layout' => 'stack',
  'add_label' => 'Add activity', 'columns' => $cols2,
), array());
$table = ob_get_clean();
check(false !== strpos($table, '>Add activity</button>'), 'and the button really says it');
check(false === strpos($table, 'Add a row'), 'with the generic wording gone');
check(false !== strpos($table, 'bftd-rows-body'), 'the rows sit in a body the script can find');
check(false === strpos($table, '<table'), 'and a stacked field draws no table at all');

ob_start();
$rr->invoke(null, 'session', 'texts', array(
  'type' => 'rows', 'label' => 'Texts', 'columns' => array('title' => array('type' => 'text', 'label' => 'Title')),
), array());
$plain = ob_get_clean();
check(false !== strpos($plain, '>Add a row</button>'), 'a field that asks for nothing still gets the plain wording');
check(false !== strpos($plain, '<table'), 'and still draws a table');
check(1 === preg_match("/'layout'\s*=>\s*'stack'/", $mb2), 'and the activities stack');

/* The texts moved ONTO the lesson and OFF the activity.

   They were kept on the activity, on the reasoning that an activity always
   uses the same texts. It does not: the same activity is run with whatever the
   child is reading that week, so a text written onto an activity turned up in
   the reading log of every child who had ever done it, dated to their lesson,
   whether or not they had read it. */
check(1 === preg_match("/Texts read today/", $mb2), 'the lesson asks what was read in it');
check(1 === preg_match("/'session',\s*'texts',\s*self::text_columns\(\)/", $mb2), 'and saves it');
$act_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-activities.php');
check(false === strpos($act_src, 'TEXTS_KEY'), 'while an activity no longer stores texts at all');
check(false === strpos($act_src, 'function texts_of('), 'nor answers for them');
check(false === strpos($act_src, 'box_texts'), 'nor asks for them');

/* ---- what the family's report is handed ---- */
$GLOBALS['META'][710]['_bftd_session__activities'] = array(
  array('id' => '101', 'note' => '<p>Five words.</p>', 'samples' => array('7', 0, '8')),
);
$one = BFTD_Activities::for_session(710)[0];
check('<p>Five words.</p>' === $one['note'], 'the note travels with the activity');
check(array(7, 8) === $one['samples'], 'and so does the work, as ids, with the empties dropped');

/* ---- and the script builds the editor rather than cloning one ---- */
$js2 = preg_replace('#/\*[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js'));
check(false !== strpos($js2, 'wp.editor.initialize'), 'an editor is built, not cloned');
check(false !== strpos($js2, 'if ( ! seen[ tag ] ) seen[ tag ]'),
  'each placeholder NAME gets one number, so a gallery\'s two writes agree and the editor beside it differs');
check(false !== strpos($js2, "wp.editor.remove"), 'removing a row tears its editor down');
check(false !== strpos($js2, "'sortstart'"), 'a drag saves and closes it, because a moved iframe comes back blank');
check(1 === preg_match("/on\( 'change', '\.bftd-stackrow \.bftd-actpick-s'/", $js2),
  'and choosing an activity is what brings its notes and work out');
check(1 === preg_match("/\\$\( '#post' \)\.on\( 'submit'/", $js2),
  'and submitting writes back every editor added after the page loaded');

/* ================================================================= */
/* Two tracks                                                         */
/* ================================================================= */

/*
 * The programme is two of them. Track 1 counts its hundred and forty as
 * lessons; Tracks 2 and 3 count theirs as skills. Both count from one, so
 * there is a 12 in each and they are different activities.
 *
 * The trap is not the storing. It is that everything already in the library
 * predates this and has no track stored at all, and every one of those is a
 * Track 1 activity. Treating a missing track as "no track" would drop the
 * whole existing library out of both filters at once.
 */
/* The save checks above deliberately cleared 101's number, so it is given
   back here: what this section is about is two tracks each holding a twelve. */
$GLOBALS['META'][101]['_bftd_activity_number'] = 12;
activity(201, 12, 'Sound Search in a real text', '', 't23');
activity(202, 1,  'Reading for meaning', '', 't23');

check('t1'  === BFTD_Activities::track_of(101), 'an activity with no track stored is a Track 1 activity');
check('t23' === BFTD_Activities::track_of(201), 'and one written since says which track it is in');
$GLOBALS['META'][201]['_bftd_activity_track'] = 'made up';
BFTD_Activities::flush();
check('t1' === BFTD_Activities::track_of(201), 'a track nobody offers is not a third track');
$GLOBALS['META'][201]['_bftd_activity_track'] = 't23';
BFTD_Activities::flush();

/* The two twelves are two activities, and a label has to say which is which. */
check('Track 1 · 12 · Blending two phonemes' === BFTD_Activities::label(101),
  'the Track 1 twelve says it is Track 1');
check('Track 2 & 3 · 12 · Sound Search in a real text' === BFTD_Activities::label(201),
  'and the other twelve says it is the other track');
check(BFTD_Activities::label(101) !== BFTD_Activities::label(201),
  'so no report can print two different activities under one name');

/* The library is two sequences, not one interleaved run of two hundred and
   eighty. Ordering on the number alone put the two twelves side by side. */
$keys = array_keys(BFTD_Activities::all());
check(array(102, 101, 103, 104, 202, 201) === $keys,
  'Track 1 in its own order, then Track 2 & 3 in its own, got ' . implode(',', $keys));

/* ---- what the picker offers ---- */
$html = BFTD_Activities::picker('bftd_rows[session][activities][0][id]', 0);

foreach (BFTD_Activities::tracks() as $key => $label) {
  check(false !== strpos($html, 'data-bucket="' . $key . '"'), "the picker can be narrowed to $label");
}
check(false !== strpos($html, '>Track 1<'), 'and names Track 1');
check(false !== strpos($html, '>Track 2 &amp; 3<'), 'and names Track 2 & 3');
check(1 === preg_match('/data-bucket=""[^>]*aria-pressed="true"/', $html),
  'with every track on until somebody narrows it, so a tutor who does not know which track can still search');
check(3 === substr_count($html, 'class="bftd-actpick-t'), 'three buttons: all, and one per track');

/* Each option carries its own track, so the script filters on the key the
   server wrote rather than on the words in an optgroup label. */
check(1 === preg_match('/<option value="101"[^>]*data-bucket="t1"/', $html),
  'a Track 1 option says so');
check(1 === preg_match('/<option value="201"[^>]*data-bucket="t23"/', $html),
  'and a Track 2 & 3 option says so');

/* With the script blocked the select is the whole field, so the tracks are
   optgroups: still one list, but one a browser can be walked through. */
check(2 === substr_count($html, '<optgroup'), 'the plain select is sectioned by track');
check(substr_count($html, '<optgroup') === substr_count($html, '</optgroup>'), 'and the sections are closed');

/* The list itself does not repeat the track on every line: the buttons above
   the box say it once, and a hundred and forty rows each starting "Track 1 ·"
   push the name a tutor is reading for off the end. */
check(false === strpos($html, '>Track 1 · 12 · Blending two phonemes<'),
  'the option text is the number and the name, not the track as well');

/* ---- the library list ---- */
$clause = BFTD_Activities::track_clause('t1');
check(isset($clause['relation']) && 'OR' === $clause['relation'],
  'filtering to Track 1 also asks for everything with no track stored');
$has_missing = false;
foreach ($clause as $part) {
  if (is_array($part) && isset($part['compare']) && 'NOT EXISTS' === $part['compare']) $has_missing = true;
}
check($has_missing, 'which is what stops the existing library vanishing the first time anybody filters');

$other = BFTD_Activities::track_clause('t23');
check(isset($other['value']) && 't23' === $other['value'], 'the other track asks for itself and nothing else');
check(!isset($other['relation']), 'and does not sweep up the untracked ones');
check(null === BFTD_Activities::track_clause(''), 'no filter asks for no narrowing');
check(null === BFTD_Activities::track_clause('made up'), 'and neither does a track nobody offers');

/* One method owns the list query. Two hooks each calling set('meta_query')
   means the second wins and the first silently does nothing. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-activities.php');
check(1 === preg_match('/function order_admin_list\([\s\S]{0,2600}track_clause\(/', $src),
  'so ordering and filtering are set together, in the one method that writes meta_query');
check(1 === substr_count($src, "\$query->set( 'meta_query'"),
  'and nothing else writes it');

/* Which activities and in what order are two jobs, and they no longer share a
   variable. The order is SQL now, because most of the library has no track
   stored and an ordered meta clause drops the rows that do not have the key. */
check(1 === preg_match('/function admin_list_order\([\s\S]{0,1600}LEFT JOIN/', $src),
  'the order is worked out in the query itself');
check(1 === preg_match('/admin_list_order\([\s\S]{0,2000}COALESCE\( bftd_tk\.meta_value/', $src),
  'and an activity with no track stored is ordered as the Track 1 activity it is');
check(false === strpos($src, "\$query->set( 'orderby'"),
  'so nothing sets an orderby that would fight it');

/* ---- a skill writes itself into the activity's description ---- */

/* The skills box is printed between the title and the editor, not registered
   as a metabox, because choosing a skill fills the editor underneath it. A box
   below the editor would be filling something already scrolled past. */
$src2 = file_get_contents(BFTD_PATH . 'includes/class-bftd-activities.php');
check(1 === preg_match("/add_action\( 'edit_form_after_title', array\( __CLASS__, 'above_the_editor' \) \)/", $src2),
  'the skills box is printed above the editor');
check(1 === preg_match('/function above_the_editor\([\s\S]{0,700}self::box_skills\( \$post \)/', $src2),
  'and it is the same box, not a second one to keep in step');
check(0 === preg_match("/add_meta_box\( 'bftd_activity_skills'/", $src2),
  'so it is no longer registered below as well');

/* Only this box does it. The same picker chooses activities on a lesson, and a
   lesson's notes are not somewhere a library description belongs. */
$skillbox = BFTD_Activities::box_skills_html($GLOBALS['POSTS'][101]);
check(false !== strpos($skillbox, 'bftd-skillpull'), 'the box says what it is');
$actpicker = BFTD_Activities::picker('x');
check(false === strpos($actpicker, 'bftd-skillpull'), 'and an activity picker does not');

/* ---- the library list reads as two programmes ---- */

/* The Track column is gone. It repeated the same word down a hundred and forty
   rows to tell somebody what they already knew. What replaces it is a heading
   where one programme ends and the other begins. */
$cols = BFTD_Activities::columns(array('cb' => 'x', 'date' => 'Date'));
check(!isset($cols['bftd_track']), 'there is no Track column');
check(isset($cols['bftd_number']) && isset($cols['title']), 'the number and the name are still there');

/* And the name column is headed Name. The screen is already headed Activities
   and every row on it is one, so a column headed Activity was spending its
   width repeating the page title. */
check('Name' === $cols['title'], 'the name column is headed Name, got ' . $cols['title']);
check(array('cb', 'bftd_number', 'title', 'date') === array_keys($cols),
  'in the order a curriculum reads: which one it is, what it is called, when it was written');

/* The track rides on the row instead, which is what the headings are worked
   out from. */
$cls = BFTD_Activities::row_class(array('post'), '', 201);
check(in_array('bftd-track-t23', $cls, true), 'a row says which track it is in');
check(in_array('bftd-track-t1', BFTD_Activities::row_class(array(), '', 101), true),
  'including one with no track stored, which is a Track 1 activity');
check(array('x') === BFTD_Activities::row_class(array('x'), '', 301),
  'and a post that is not an activity is left alone');

/* The heading is drawn from the same list of tracks the picker offers, so the
   two cannot come to disagree about what a track is called. */
$adminjs = preg_replace('#/\*[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js'));
check(false !== strpos($adminjs, "names: 'tracks'"), 'the activity list is headed from the tracks the server states');
check(false !== strpos($adminjs, "names: 'groups'"), 'and the skills list from its own groups, by the same routine');
check(1 === preg_match('/bftd-trackhead/', $adminjs), 'and a heading row is inserted');
check(false !== strpos($adminjs, 'bftd-track-'), 'read off the class the row carries');
$boot = file_get_contents(BFTD_PATH . 'bf-tutoring-dashboard.php');
check(1 === preg_match('/.tracks.\s*=>\s*class_exists\( .BFTD_Activities. \) \? BFTD_Activities::tracks\(\)/', $boot),
  'and the server states them rather than the script keeping its own copy');

/* A heading is a heading, not a shout. */
$acss = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/\.bftd-trackhead th\{/', $acss), 'the heading row has a rule of its own');
check(0 === preg_match('/\.bftd-trackhead th\{[^}]*text-transform:uppercase/', $acss),
  'and does not shout');

/* ================================================================= */
/* Skills: one library, two groups                                    */
/* ================================================================= */

/*
 * A Wordwall entry is a word a child can read on sight. It is a skill in the
 * sense that matters, and it is not the same kind of thing as "hearing the
 * middle sound in a word" — so the library is read as two lists, the same way
 * the activity programme is read as two tracks.
 *
 * Same trap as the tracks: everything already in the library predates this and
 * has no group stored, and every one of those is an ordinary skill.
 */
function skillpost($id, $name, $group = null) {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => 'bftd_skill',
    'post_status' => 'publish', 'post_title' => $name, 'post_content' => '');
  if ($group) $GLOBALS['META'][$id]['_bftd_skill_group'] = $group;
  BFTD_Skills::flush();
}
skillpost(401, 'Hearing the middle sound in a word');
skillpost(402, 'Mapping sounds to their spellings');
skillpost(403, 'said',  'wordwall');
skillpost(404, 'could', 'wordwall');

check('skill'    === BFTD_Skills::group_of(401), 'a skill with no group stored is an ordinary skill');
check('wordwall' === BFTD_Skills::group_of(403), 'and one written since says which group it is in');
$GLOBALS['META'][403]['_bftd_skill_group'] = 'invented';
BFTD_Skills::flush();
check('skill' === BFTD_Skills::group_of(403), 'a group nobody offers is not a third group');
$GLOBALS['META'][403]['_bftd_skill_group'] = 'wordwall';
BFTD_Skills::flush();

/* The list is two lists, not one alphabetical run with Wordwall words
   scattered through it. With nothing numbered and nothing tracked yet, every
   skill is in the default track, so the group is what is left to order by. */
check(array(401, 402, 404, 403) === array_keys(BFTD_Skills::all()),
  'skills first, then Wordwall, each by name, got ' . implode(',', array_keys(BFTD_Skills::all())));
check(is_array(BFTD_Skills::all()[401]) && isset(BFTD_Skills::all()[401]['name']),
  'all() now carries a row per skill rather than a bare name');
check(BFTD_Skills::labels() === array_map(function($r){ return $r['name']; }, BFTD_Skills::all()),
  'and labels() is that same list with just the names');

/* ---- numbers and tracks on skills ----
 *
 * The same shape the activity library has, because a student's starting point
 * is a number in one track's sequence and the report counts from it. */
check('t23' === BFTD_Skills::track_of(401), 'a skill with no track stored is a Tracks 2 and 3 skill');
check(0 === BFTD_Skills::number_of(401), 'and an unnumbered one answers zero rather than guessing');

$GLOBALS['META'][401]['_bftd_skill_number'] = 2;
$GLOBALS['META'][402]['_bftd_skill_number'] = 1;
$GLOBALS['META'][401]['_bftd_skill_track']  = 't1';
$GLOBALS['META'][402]['_bftd_skill_track']  = 't1';
$GLOBALS['META'][404]['_bftd_skill_track']  = 't23';
$GLOBALS['META'][404]['_bftd_skill_number'] = 1;
$GLOBALS['META'][403]['_bftd_skill_track']  = 't23';
BFTD_Skills::flush();

check('t23' === BFTD_Skills::track_of(404), 'and one written since says which track it is in');
$GLOBALS['META'][404]['_bftd_skill_track'] = 'invented';
BFTD_Skills::flush();
check('t23' === BFTD_Skills::track_of(404), 'a track nobody offers is not a third track, it is the default');
$GLOBALS['META'][404]['_bftd_skill_track'] = 't23';
BFTD_Skills::flush();

/* Track first, then the number inside it, and the unnumbered one last in its
   own track rather than first. */
check(array(402, 401, 404, 403) === array_keys(BFTD_Skills::all()),
  'Track 1 by number, then Tracks 2 and 3 by number, got ' . implode(',', array_keys(BFTD_Skills::all())));

check(2 === BFTD_Skills::count_in_track('t1'), 'Track 1 has two numbered skills');
check(1 === BFTD_Skills::count_in_track('t23'), 'and Tracks 2 and 3 have one');
check(array(1 => 402, 2 => 401) === BFTD_Skills::sequence('t1'),
  'the sequence is number => id, in order, numbered only');
check(array(1 => 404) === BFTD_Skills::sequence('t23'), 'per track, counting from one in each');
check('1 · could' === BFTD_Skills::numbered_label(404), 'a numbered label says which number it is');
check('said' === BFTD_Skills::numbered_label(403), 'and an unnumbered one is just its name');

$scol = BFTD_Skills::columns(array('cb' => 'x', 'title' => 'Title', 'date' => 'Date'));
check(isset($scol['bftd_number']), 'the library list has a number column');
check(isset(BFTD_Skills::sortable(array())['bftd_number']), 'and it sorts by it');
$stc = BFTD_Skills::track_clause('t23');
check(isset($stc['relation']) && 'OR' === $stc['relation'],
  'filtering skills to Tracks 2 and 3 also asks for everything with no track stored');
check(isset(BFTD_Skills::track_clause('t1')['value']), 'while Track 1 asks for itself alone');
check(null === BFTD_Skills::track_clause(''), 'and no filter asks for no narrowing');

/* Karl asked for one word. */
check('Wordwall' === BFTD_Skills::group_label('wordwall'), 'Wordwall is one word');

/* ---- what the skill picker offers ----
 *
 * Narrowed by TRACK, not by group. Skills are numbered within a track now and
 * the track is the division a tutor is working inside; Skills against Wordwall
 * is the library's own housekeeping. Only one row of buttons fits above the
 * box, so the track has it, and the group filter lives on the library list
 * screen where there is room for both. */
$sk = BFTD_Skills::picker('bftd_rows[activity][skills][0][id]', 0);
check(false !== strpos($sk, 'data-bucket="t1"'), 'the skill picker can be narrowed to Track 1');
check(false !== strpos($sk, 'data-bucket="t23"'), 'and to Tracks 2 and 3');
check(3 === substr_count($sk, 'class="bftd-actpick-t'), 'three buttons: all, and one per track');
check(1 === preg_match('/<option value="404"[^>]*data-bucket="t23"/', $sk), 'each option says which track it is in');
check(2 === substr_count($sk, '<optgroup'), 'and the plain select is sectioned by track');
check(false !== strpos($sk, 'Search by number or name'), 'and says numbers are searchable');

/* ---- the library list ---- */
$g = BFTD_Skills::group_clause('skill');
check(isset($g['relation']) && 'OR' === $g['relation'],
  'filtering to Skills also asks for everything with no group stored');
check(isset(BFTD_Skills::group_clause('wordwall')['value']), 'while Wordwall asks for itself alone');
check(null === BFTD_Skills::group_clause(''), 'no filter asks for no narrowing');

$scols = BFTD_Skills::columns(array('cb' => 'x', 'title' => 'Title', 'date' => 'Date'));
check(!isset($scols['bftd_group']), 'there is no Group column, for the reason there is no Track column');
check(in_array('bftd-group-wordwall', BFTD_Skills::row_class(array(), '', 403), true),
  'the group rides on the row, which is what the heading is worked out from');
check(in_array('bftd-group-skill', BFTD_Skills::row_class(array(), '', 401), true),
  'including one with no group stored');
check(array('x') === BFTD_Skills::row_class(array('x'), '', 101), 'and an activity row is left alone');

$sk_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-skills.php');
check(1 === preg_match('/function admin_list_order\([\s\S]{0,1400}COALESCE\( bftd_gp\.meta_value/', $sk_src),
  'a skill with no group stored is ordered as the ordinary skill it is');
check(1 === substr_count($sk_src, "\$query->set( 'meta_query'"),
  'and one method writes the meta_query, so nothing silently undoes it');

/* ================================================================= */
/* A row pointing at something that was deleted                       */
/* ================================================================= */

/*
 * Trashing an activity leaves every lesson that used it holding its id.
 *
 * It is already gone from the family's report: resolve() answers nothing for
 * an activity the library does not have. So what the tutor's screen showed was
 * a row describing something nobody can see, with an empty picker and "Add
 * notes" and "Add work samples" under it — an invitation to write about an
 * activity that is not there.
 *
 * The row is not drawn now. It is not dropped either, and that is the harder
 * half: trash can be undone, and the notes and work samples hanging off the
 * row have to still be there when it is. So the values ride back as hidden
 * inputs, and the save keeps a pointer at a trashed activity rather than
 * reading it as a note against nothing.
 */
$rowm = new ReflectionMethod('BFTD_Fields', 'render_row');
$rowm->setAccessible(true);
$acols = array(
  'id'   => array('type' => 'activity', 'label' => 'Activity'),
  'note' => array('type' => 'text',     'label' => 'Notes'),
);

ob_start(); $rowm->invoke(null, 'session', 'activities', $acols, array(), 0, true);
$r_empty = ob_get_clean();
check(false !== strpos($r_empty, 'is-blank'), 'a row with nothing chosen is blank, so nothing hangs off it');
check(false === strpos($r_empty, 'bftd-rowgone'), 'and says nothing was removed, because nothing was');

ob_start(); $rowm->invoke(null, 'session', 'activities', $acols, array('id' => '101'), 0, true);
$r_real = ob_get_clean();
check(false === strpos($r_real, 'is-blank'), 'a row with a real activity is not blank');
check(false === strpos($r_real, 'bftd-rowgone'), 'and nothing is missing');

/* 9999 is in the trash: a real activity post, not in the library. 4242 is
   nothing at all, which is what emptying the trash leaves behind. */
$GLOBALS['POSTS'][9999] = (object) array('ID' => 9999, 'post_type' => 'bftd_activity',
  'post_status' => 'trash', 'post_title' => 'Retired activity', 'post_content' => '');
BFTD_Activities::flush();
check(null === BFTD_Activities::get(9999), 'a trashed activity is not in the library');
check(BFTD_Activities::exists(9999), 'but it is still there to be put back');
check(!BFTD_Activities::exists(4242), 'while an id with nothing behind it is not');
/* Post ids are one sequence across every type, so a stale activity id can
   land on a lesson that was created later. Keeping that would make a lesson
   an activity on the next save. */
$GLOBALS['POSTS'][4300] = (object) array('ID' => 4300, 'post_type' => 'bftd_session',
  'post_status' => 'publish', 'post_title' => 'A lesson', 'post_content' => '');
check(!BFTD_Activities::exists(4300), 'and neither is an id that landed on a post of another kind');
check('' === BFTD_Fields::sanitize_value('4300', array('type' => 'activity')),
  'so a save does not keep it either');

$acols3 = $acols + array('samples' => array('type' => 'gallery', 'label' => 'Work samples'));
ob_start(); $rowm->invoke(null, 'session', 'activities', $acols3,
  array('id' => '9999', 'note' => 'He read it twice', 'samples' => array(4, 5)), 0, true);
$r_gone = ob_get_clean();

check(false === strpos($r_gone, 'bftd-stackrow'), 'a row whose activity is in the trash is not drawn at all');
/* It is still A ROW, though. The script renumbers by walking .bftd-row, and a
   set of inputs outside that walk keeps the index it was printed with: add a
   row and two of them post under the same index, so PHP reads one over the
   other and one is lost. */
check(1 === preg_match('/class="bftd-row bftd-rowkept"/', $r_gone),
  'but it still counts as a row, so adding or dragging one does not collide with it');
check(false === strpos($r_gone, 'Add notes') && false === strpos($r_gone, 'Add work samples'),
  'so nothing offers a tutor somewhere to write about it');
check(false === strpos($r_gone, 'bftd-actpick'), 'and there is no empty picker sitting where it was');

/* But every value it holds comes back, or the save that follows is the thing
   that loses the work. */
check(1 === preg_match('/name="bftd_rows\[session\]\[activities\]\[0\]\[id\]" value="9999"/', $r_gone),
  'the activity it points at is posted back untouched');
check(1 === preg_match('/name="bftd_rows\[session\]\[activities\]\[0\]\[note\]" value="He read it twice"/', $r_gone),
  'and the note written against it');
check(1 === preg_match('/name="bftd_rows\[session\]\[activities\]\[0\]\[samples\]" value="4,5"/', $r_gone),
  'and the work samples, as a list rather than the word Array');
check(3 === substr_count($r_gone, '<input type="hidden"'), 'three values, three inputs, nothing else');

/* And the save keeps them. It used to ask the library whether the activity
   was there; the library is publish-only, so trashing an activity and saving
   any lesson that used it emptied the id, sanitize_rows read that as a note
   against no activity, and the row went. Untrashing brought back nothing. */
check('9999' === BFTD_Fields::sanitize_value('9999', array('type' => 'activity')),
  'a save keeps a pointer at a trashed activity');
check('101' === BFTD_Fields::sanitize_value('101', array('type' => 'activity')),
  'and at a published one');
check('' === BFTD_Fields::sanitize_value('4242', array('type' => 'activity')),
  'while an id with no activity behind it at all is still dropped');
$kept = BFTD_Fields::sanitize_rows(
  array(array('id' => '9999', 'note' => 'He read it twice')),
  array('id' => array('type' => 'activity'), 'note' => array('type' => 'text'))
);
check(1 === count($kept) && 'He read it twice' === $kept[0]['note'],
  'so the whole row survives the save, notes and all');

/* Meanwhile the family sees none of it, which is the thing Karl asked for and
   the thing that was already true: the report resolves rows through the
   library, and the library is publish-only. */
$GLOBALS['META'][704]['_bftd_session__activities'] = array(array('id' => '9999', 'note' => 'He read it twice'));
check(array() === BFTD_Activities::for_session(704), 'a trashed activity is off the report entirely');

/* ---- the search itself ---- */
/* The track is a second thing to say, so the script has to test both. One
   rule, asked by the select filter and by the list of matches, because two
   copies is how the short list comes to disagree with the field under it. */
$pick = preg_replace('#/\*[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js'));
check(1 === preg_match('/function rank\( o, term, buck, all \)[\s\S]{0,300}o\.bucket !== buck/', $pick),
  'an option has to match the words and the track');
check(1 === preg_match('/function hit\( o, term, buck, all \)[\s\S]{0,120}rank\( o, term, buck, all \)/', $pick),
  'and asking whether it matches at all is asking the same question');
/* Both lists go through the one ranking function. Whether they AGREE is
   checked by driving them, in browser/picker-search.mjs; what is checked
   here is that there is only one of it to disagree with. */
check(1 === preg_match('/function filter\( \$wrap \)[\s\S]{0,900}hit\( o, term, buck, all \)/', $pick),
  'the field under the box asks that one question');
check(1 === preg_match('/function suggest\( \$wrap \)[\s\S]{0,1400}rank\( all\[ i \], term, buck, all \)/', $pick),
  'and so does the short list above it, rather than keeping a rule of its own');
check(1 === preg_match("/on\( 'click', '\.bftd-actpick-t'/", $pick), 'switching track is a click');

/* The match count belongs to the search box, not to the picker. Appended to
   the picker it outlived the search: once an activity was taken the box went
   away and "140 matches" stayed on screen under the chosen activity,
   describing a search nobody was running. */
check(false !== strpos($pick, "appendTo( \$wrap.find( '.bftd-actpick-find' ) )"),
  'the match count lives inside the search box, so it goes away with it');
check(1 === preg_match("/bftd-actpick-t'[\s\S]{0,420}filter\( \\\$wrap \);[\s\S]{0,60}suggest\( \\\$wrap \);/", $pick),
  'which redraws both of them');
check(1 === preg_match("/bftd-actpick-t'[\s\S]{0,500}removeClass\( 'is-on' \)/", $pick),
  'one track at a time, so there is never a state with every track off');

/* And what is taken carries its track, read off the button holding the same
   key the option does, so the row on the lesson says which twelve it is. */
check(1 === preg_match('/function fullLabel\( \$wrap, \$opt \)[\s\S]{0,420}bftd-actpick-t\[data-bucket=/', $pick),
  'the settled name is built from the option and its track');
check(1 === preg_match('/function settle\( \$wrap \)[\s\S]{0,320}fullLabel\( \$wrap,/', $pick),
  'and settling uses it rather than the bare option text');

/* The filter rebuilds the select's whole contents on every keystroke, so it
   has to write the track back onto each option.

   It did not, and everything still looked right: the narrowing worked, because
   it reads a cached list rather than the DOM. What broke was only visible
   after typing and then choosing — the taken activity could no longer say
   which track it came from, which is the one thing all of this exists to fix.
   Nothing errored. It was found by picking one in a browser. */
check(1 === preg_match('#function filter\( \$wrap \)[\s\S]{0,2200}<option value=[\s\S]{0,200}data-bucket=#', $pick),
  'rebuilding the list keeps each option\'s track on it');


/* ---- somewhere to actually set the track ---- */

/* The box holding the track and the number was deleted along with the texts
   box that used to sit beside it. The add_meta_box line registering it stayed.
   A registered callback that is not a method is a fatal the moment the screen
   draws, so the activity editor died part-drawn: no Publish button, nothing
   below it, and no track selector anywhere. And because save() reads the track
   off a form field that was no longer being printed, every save of a Track 2 &
   3 activity quietly moved it to Track 1.

   Two checks, because there are two ways to lose this: the method going away,
   and the field names drifting apart from the ones the save reads. */
$GLOBALS['META'][201]['_bftd_activity_track'] = 't23';
$post = (object) array('ID' => 201, 'post_type' => 'bftd_activity', 'post_title' => 'Homophones');
check(method_exists('BFTD_Activities', 'box_number'), 'the box the screen registers is a method that exists');

ob_start(); BFTD_Activities::box_number($post); $box = ob_get_clean();

check(1 === preg_match('/<select name="bftd_activity_track"/', $box), 'and it offers a track');
foreach (BFTD_Activities::tracks() as $key => $label) {
  check(false !== strpos($box, 'value="' . $key . '"'), "including $label");
}
check(1 === preg_match('/<option value="t23"\s+selected/', $box),
  'with the track this activity is already in chosen, so opening and saving does not move it');
check(1 === preg_match('/<input type="number"[^>]*name="bftd_activity_number"/', $box), 'and a number in that track');

/* Every field the save reads has to be one the screen prints. The nonce is the
   one exception: the skills box above the editor prints it, once, for both. */
$save = '';
if (preg_match('/function save\( \$post_id, \$post \) \{[\s\S]*?\n\t\}/', $act_src, $m)) $save = $m[0];
check('' !== $save, 'the save is readable');
$nonce_here = 1 === preg_match("/wp_nonce_field\([^)]*'bftd_activity_nonce'/", $act_src);
foreach (array_unique(preg_split('/\s+/', trim(implode(' ', (function ($s) {
  preg_match_all("/\\\$_POST\['(bftd_activity_\w+)'\]/", $s, $mm);
  return $mm[1];
})($save))))) as $key) {
  if ('' === $key) continue;
  if ('bftd_activity_nonce' === $key) { check($nonce_here, 'the nonce the save wants is printed'); continue; }
  check(false !== strpos($box, 'name="' . $key . '"'), "the save reads $key, and the screen prints it");
}

exit($fail ? 1 : 0);
