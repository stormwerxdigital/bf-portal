<?php
/*
 * The two tables in Writing the alphabet.
 *
 * One is arithmetic: how many letters were right, how many were written, and
 * the goal. Every figure in it was entered somewhere else, so nobody types it
 * twice and it cannot disagree with the card above it.
 *
 * The other is how the task is marked. Those rules are the same for every
 * child, because they are the marking scheme rather than an opinion about one
 * student. What changes report to report is whether anything needs saying
 * about a rule, so that is the only thing a tutor fills in.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['META'] = array();
function get_post_meta($id,$k,$s=false){ return $GLOBALS['META'][$id][$k] ?? ($s?'':array()); }

class BFTD_Items { const PRIORITY='priority'; const REVIEW='review'; }
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$f = BFTD_Schema::section('dxsec-1')['fields'];

/* ---- the results table is arithmetic, not typing ---- */
check(isset($f['score_table']) && 'score_table' === $f['score_table']['type'], 'the section declares a results table');
check('' === BFTD_Fields::sanitize_value('anything posted', $f['score_table']),
  'and it stores nothing, because there is nothing to store');

$html = BFTD_Schema::score_table_html('dxsec-1', array('score' => '25', 'score_of' => '26'));
check(false !== strpos($html, '<td>25</td>'), 'the numerator is the letters produced correctly');
check(false !== strpos($html, '<td>26</td>'), 'the denominator is the total letters written');
check(false !== strpos($html, '40 correct lowercase letters per minute'), 'and the goal is fixed');
check(3 === substr_count($html, '<tr><td>'), 'three rows of figures');
check(2 === substr_count($html, '<tr><th'), 'under a title and a header row');

/* ---- which row the design bands -------------------------------------------
   The design bands the first row of the table body and leaves whatever is
   above it plain. So where a head row is column labels it belongs in the
   thead, and where a head row is itself a finding it belongs in the body,
   because that is the row meant to be banded.

   This is invisible until you see it and then obvious: with the head in the
   wrong place the band lands on the second finding, so a table of three bands
   looks like it is highlighting the middle one. It shipped that way. */

/* Column labels stay above the body. */
check(1 === preg_match('#<thead>.*?<tr><th><strong>Score</strong></th>#s', $html),
  'a head row of column labels is drawn above the body');

/* A head row carrying a figure is the first row of the body. */
$seg = BFTD_Schema::score_table_html('dxsec-2', array('score' => 16));
check(1 === preg_match('#<tbody>\s*<tr><td>Score</td><td>16 / 36</td>#s', $seg),
  'while a head row that reports a figure is the first row of the body');
check(0 === preg_match('#<th><strong>16 / 36</strong>#', $seg),
  'and is not also drawn as a column label');

/* Both shapes still carry their title above everything. */
foreach (array('dxsec-1' => array('score' => 25, 'score_of' => 26), 'dxsec-2' => array('score' => 16)) as $sid => $v) {
  $h = BFTD_Schema::score_table_html($sid, $v);
  check(1 === preg_match('#<thead>\s*<tr><th colspan#s', $h), "$sid keeps its title above the table");
}
check(false !== strpos($html, 'class="tablewrap"'), 'drawn in the design\'s scroll wrapper');
check(false !== strpos($html, 'table class="doc"'), 'as one of the design\'s tables');

/* The figures come from the score. Change the score, the table changes. */
$other = BFTD_Schema::score_table_html('dxsec-1', array('score' => '31', 'score_of' => '33'));
check(false !== strpos($other, '<td>31</td>') && false === strpos($other, '<td>25</td>'),
  'it follows the score rather than remembering an old one');

/* A row whose figure is missing is not printed as a blank. */
$half = BFTD_Schema::score_table_html('dxsec-1', array('score' => '25', 'score_of' => ''));
check(false === strpos($half, 'Total letters written'), 'a figure nobody entered is left out, not shown empty');
check(false !== strpos($half, 'Letters produced correctly'), 'while the ones that were entered still show');

/* ---- the marking rules are fixed ---- */
$m = $f['marking'];
check('criteria' === $m['type'], 'the marking scheme is its own kind of field');
check(isset($m['groups']['correct'], $m['groups']['incorrect']), 'with a correct half and an incorrect half');
check('sage' === $m['groups']['correct']['tone'] && 'clay' === $m['groups']['incorrect']['tone'],
  'told apart by the report\'s own two tones');
check(3 === count($m['groups']['correct']['rows']), 'three rules count as correct');
check(1 === count($m['groups']['incorrect']['rows']), 'and one counts as incorrect');
check(false !== strpos($m['footer']['value'], 'a b c d'), 'the alphabet is printed underneath');

/* Only a note is stored, and only against a rule the schema knows. */
$saved = BFTD_Fields::sanitize_value(array(
  'lowercase'   => '  Reversed m and n.  ',
  'bottom_up'   => '',
  'not_a_rule'  => 'should never be kept',
), $m);
check(array('lowercase' => 'Reversed m and n.') === $saved,
  'a blank note is dropped, whitespace trimmed, and an unknown row refused');

/* Notes hang off a row's id, not its wording, so rewording a rule keeps them. */
foreach ($m['groups'] as $g) {
  foreach (array_keys($g['rows']) as $rid) {
    check(1 === preg_match('/^[a-z_]+$/', $rid), "the rule id $rid is an id, not a sentence");
  }
}

/* ---- what a family sees ---- */
$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($view, 'function criteria_table('), 'the report draws the marking table');

/* A rule left alone shows a tick and nothing else on the page: a column of
   the same three words repeated is noise a sighted reader looks past. Read
   aloud, though, an unlabelled tick is silence, so the words stay for anybody
   who needs them. */
check(1 === preg_match('/<\/svg><span class="crit-said">Nothing to report<\/span>/', $view),
  'the tick still says something to a screen reader');
$rcss = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(1 === preg_match('/\.crit-said\{[^}]*clip-path:inset\(50%\)/', $rcss),
  'while the page shows only the tick');

/* A rule about mistakes cannot use a tick: it would be ambiguous between
   "there were none" and "nobody checked". That one names its own wording. */
$rev = $m['groups']['incorrect']['rows']['reversals'];
check(is_array($rev) && 'No reversals' === $rev['empty'], 'a blank reversals row reads "No reversals"');
check(false !== strpos($view, "} elseif ( '' !== \$blank ) {") || false !== strpos($view, "elseif ( '' !== \$blank ) :"),
  'and the renderer prefers a rule\'s own wording over the tick');

/* The editor shows the same words, so a tutor leaving a row alone knows what
   the family ends up reading. */
check(1 === preg_match('/placeholder="<\?php echo esc_attr\( \$blank \)/', $fields_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php')),
  'the editor shows what a blank row will say');

/* A table of ticks on a section nobody scored would be claiming an assessment
   happened. It waits for the score, the way every other field waits. */
check(1 === preg_match('/criteria.{0,600}has_value\(\s*isset\(\s*\$values\[.score.\]/s', $view),
  'the marking table waits until there is a score');

/* ---- one renderer, two places ---- */
/* The admin preview and the family's copy are the same markup from the same
   figures. Two renderers is how a preview starts quietly disagreeing with the
   thing it previews. */
$fields = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(2 === substr_count($view . $fields, 'BFTD_Schema::score_table_html('),
  'both the editor and the report ask the same builder for that table');

/* ---- nothing here reached the areas not yet reviewed ---- */
$all = BFTD_Schema::sections();

/* Section 2 has since been reviewed and has a results table of its own. The
   marking scheme is not shared with it: those rules are how the alphabet task
   is marked, and they are about that task only. */
$k2 = array_keys($all['dxsec-2']['fields']);
check(in_array('score_table', $k2, true), 'section 2 has a results table of its own');
check(!in_array('marking', $k2, true),
  "and not the alphabet's marking scheme, which is about that task only");

foreach (array('dxsec-3','dxsec-4','dxsec-5','dxsec-6') as $sid) {
  $k = array_keys($all[$sid]['fields']);
  check(!in_array('score_table', $k, true) && !in_array('marking', $k, true),
    "$sid was left alone");
}

/* ---- the sample shows both, including a rule with nothing to report ---- */
class BFTD_Fields_Stub {}
require BFTD_PATH . 'includes/class-bftd-sample.php';
$v = BFTD_Sample::get('diagnostic')['fields']['dxsec-1'];
check(!empty($v['marking']), 'the sample has notes against the marking rules');
check(!isset($v['marking']['reversals']), 'and leaves the reversals row blank, so its default shows');

/* ---- the alphabet is set the way a child is taught to print it ---- */
/* What matters is the single-storey a and g: a circle and a stick, the way a
   hand makes them, rather than the two-storey shapes most typefaces use and
   no child ever writes. It is the letterforms the assessment is about. */
check(1 === preg_match('/--font-abc:[^;]*Century Gothic/', $rcss), 'Century Gothic where it is installed');
check(1 === preg_match('/--font-abc:[^;]*Didact Gothic/', $rcss), 'and a webfont with the same letterforms where it is not');
check(1 !== preg_match('/--font-abc:[^;]*(Arial|Helvetica|Trebuchet|Verdana)/', $rcss),
  'and nothing in the fallback that would draw a two-storey a');
check(1 === preg_match('/\.crit-foot \.abc\{[^}]*font-family:var\(--font-abc\)/', $rcss), 'the alphabet row uses it');
/* The face has to reach every surface that draws these letters, and there are
   three of them. They used to each carry their own font list, which is how the
   editor ended up asking for a face the report used and the portal did not; the
   list now has one definition and each surface asks for that. So the check is
   on the list itself, and on each surface using it, rather than on the literal
   text happening to appear in one file. */
if (!class_exists('BFTD_Report_View')) require BFTD_PATH . 'includes/class-bftd-report-view.php';
check(false !== strpos(BFTD_Report_View::fonts_url(), 'Didact+Gothic'),
  'and the font is in the list the report is drawn with');
foreach (array('class-bftd-preview.php'   => 'the preview',
               'class-bftd-dashboard.php' => 'the family portal') as $f => $where) {
  check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/' . $f), 'fonts_url()'),
    "and $where loads that list");
}

/* The editor shows the same letters the same way, or a tutor is proofing
   something the family will not be shown. */
$acss = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/--bftd-abc:[^;]*Century Gothic/', $acss), 'the editor uses the same stack');
check(1 === preg_match('/--bftd-abc:[^;]*Didact Gothic/', $acss), 'with the same fallback');
check(1 === preg_match('/\.bftd-crit-foot span\{[^}]*font-family:var\(--bftd-abc\)/', $acss),
  'on its own alphabet row');
check(1 !== preg_match('/\.bftd-crit-foot span\{[^}]*monospace/', $acss),
  'and not the monospace it started as');
$boot = file_get_contents(BFTD_PATH . 'bf-tutoring-dashboard.php');
check(false !== strpos($boot, 'fonts_url()'), 'and wp-admin loads that same list too');
check(!isset($v['body']), 'its introduction is the schema\'s standing wording, not the sample\'s');
check(false === strpos($v['body_after'], '<table'), 'the notes carry no table by hand');
check(false !== strpos($v['body_after'], 'Kaine willingly completed'),
  'and are what the tutor actually wrote about this child');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
