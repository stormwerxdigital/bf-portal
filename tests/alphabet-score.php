<?php
/*
 * Writing the alphabet, which is measured rather than judged.
 *
 * A one-minute timing produces one fact: how many letters were right, out of
 * how many were written. Everything else about that card is already known.
 * The unit is always letters in 60 seconds. The goal is always 40, which is
 * the published automaticity benchmark rather than a per-child target. The
 * bar is the first against the second.
 *
 * The trap this guards against is a percentage somebody maintains by hand,
 * which disagrees with the number printed beside it the first time either one
 * changes, and nothing errors when it does.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

class BFTD_Items { const PRIORITY='priority'; const REVIEW='review'; }
class BFTD_Fields {
  public static function has_value($v){ return '' !== trim((string) $v); }
  public static function get($p,$s,$k,$f=array()){ return ''; }
}
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$sections = BFTD_Schema::sections();
$dx1 = $sections['dxsec-1'];

/* ---- the section knows its own goal and its own unit ---- */
check(40 === $dx1['benchmark'], 'the goal is fixed at 40');
check('letters in 60 seconds' === $dx1['unit'], 'and the unit is fixed too');

/* ---- what a tutor is asked for, and what they are not ---- */
$keys = array_keys($dx1['fields']);
check(in_array('score', $keys, true) && in_array('score_of', $keys, true), 'the score is a fraction of two numbers');
foreach (array('unit', 'status', 'meter_pct', 'baseline', 'card_note') as $gone) {
  check(!in_array($gone, $keys, true), "$gone is not something anybody types here");
}
// The line under the card's title is fixed, because what is being measured is
// the same for every child. A second sentence per report was a summary of a
// summary, so there is one line and the schema owns it.
check(!empty($dx1['summary']), 'the card keeps the one-liner the schema gives it');
check('number' === $dx1['fields']['score']['type'], 'both halves are numbers');
check('number' === $dx1['fields']['score_of']['type'], 'and so is the second');

/* The two are one answer, drawn on one line with a slash between. */
check(isset($dx1['fields']['score']['pair']['key']) && 'score_of' === $dx1['fields']['score']['pair']['key'],
  'the two are paired');
check('/' === $dx1['fields']['score']['pair']['sep'], 'with a slash between them');
check(!empty($dx1['fields']['score_of']['in_pair']), 'and the second is not drawn again on its own');

$fields = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/function render_field\(.{0,200}in_pair.{0,40}return;/s', $fields),
  'the renderer skips the far half');
check(false !== strpos($fields, 'function render_pair('), 'and draws the pair itself');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/\.bftd-pair\{[^}]*display:flex/', $css), 'side by side, not stacked');

/* ---- nothing else was touched ---- */
/* The areas are being reviewed one at a time, so a change to one must not
   reach the ones that have not been looked at yet. Section 2 has since been
   reviewed and has its own checks below; the four after it are still on the
   generated field set and must stay there until they are each gone through. */
/* Every one of the six has now been reviewed and declares its own fields, so
   there is nothing left for this guard to hold. What replaces it is that none
   of them carries the generated set any more. */
foreach (array('dxsec-1','dxsec-2','dxsec-3','dxsec-4','dxsec-5','dxsec-6') as $sid) {
  $k = array_keys($sections[$sid]['fields']);
  foreach (array('status','meter_pct','unit','card_note','baseline') as $gone) {
    check(!in_array($gone, $k, true), "$sid no longer asks for $gone");
  }
  check(!empty($sections[$sid]['own_order']), "$sid states its own field order");
}

/* ---- the card ---- */
$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');

/* A score out of a total is a fraction, and a slash is how one is written. */
check(false === strpos($view, "'of ' . \$v['score_of']"), "the card no longer says \"of\"");
check(false !== strpos($view, "'/ ' . \$v['score_of']"), 'it writes the fraction with a slash');

/* The bar is worked out, not typed. */
check(false !== strpos($view, 'function meter_tone('), 'the colour is decided from the score');
check(1 === preg_match('/\$ratio\s*=\s*\$measure\s*\/\s*\$bench/', $view),
  'and the fill is the score against the goal, not a typed percentage');

/* What the bar divides is worked out once, by the schema, because the chart of
   these same scores over time has to reach the identical number. A counted
   score is the count; a named level is its place in the list. */
$m1 = BFTD_Schema::measure('dxsec-1', array('score' => '23', 'score_of' => '26'));
check(23.0 === $m1['value'], 'where a counted score is the count itself');
check(40.0 === $m1['bench'], 'measured against the section\'s own fixed goal');
check(null === BFTD_Schema::measure('dxsec-1', array('score' => '')), 'an unscored section measures nothing');

$m3 = BFTD_Schema::measure('dxsec-3', array('score' => 'grade-1'));
check(3.0 === $m3['value'], 'and a named reading level is its place in the list');
check('Grade 1' === $m3['shown'], 'while the family reads the words, never the key');

$m4 = BFTD_Schema::measure('dxsec-4', array('score' => '88', 'norm' => 'grade-2'));
check(100.0 === $m4['bench'], 'a chosen band stands in for its published figure');

$m5 = BFTD_Schema::measure('dxsec-5', array('score' => '30', 'score_of' => '44'));
check(44.0 === $m5['bench'], 'and a marked-out-of total is the goal where the section has none');

check(false !== strpos($view, 'BFTD_Schema::measure( $id, $v )'),
  'and the card reads that one rule rather than working it out again');

/* The three bands, at the exact edges. */
$src = $view;
$a = strpos($src, "\tprivate static function meter_tone(");
$b = strpos($src, "\t/**\n\t * The four figures across the top");
eval('class ToneProbe {' . substr($src, $a, $b - $a) . '}');
$m = new ReflectionMethod('ToneProbe', 'meter_tone'); $m->setAccessible(true);
$tone = function ($n) use ($m) { return $m->invoke(null, $n / 40); };

check('clay'   === $tone(0),  'nothing yet is a focus area');
check('clay'   === $tone(19), 'and so is just under half');
check('purple' === $tone(20), 'half is where it turns');
check('purple' === $tone(39), 'right up to the goal');
check('sage'   === $tone(40), 'reaching the goal is met');
check('sage'   === $tone(55), 'and beating it is no more than met');

/* The bar cannot run past the end of its track. */
check(1 === preg_match('/min\(\s*100\s*,\s*round\(\s*\$ratio\s*\*\s*100\s*\)\s*\)/', $view),
  'a score past the goal fills the bar and stops');

/* Karl's three colours, and only here. */
$rcss = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(false !== strpos($rcss, '--bf-meter-met:#A2A55A'), 'a met goal is the green that was asked for');
check(false !== strpos($rcss, '--bf-clay:#B85C2E'), 'under half is the clay that was asked for');
check(false !== strpos($rcss, '--bf-purple:#70567F'), 'and between them the purple');
check(1 === preg_match('/\.fill\.sage\{background:var\(--bf-meter-met\)/', $rcss), 'the met colour is what the bar uses');
check(false !== strpos($rcss, '.bf-report .fill.purple{'), 'and the middle band has a rule of its own');

/* Colour is never the only signal. */
check(false !== strpos($view, "\$meta = 'Goal '"), 'the goal is printed beside the bar, not only shown as a colour');

/* A measured card carries no verdict pill, because the strengths and growth
   areas section is where that is said.

   And a card whose section no longer asks the question must not answer it
   either. One band of the fluency norms has no published figure, so that card
   has no goal to measure against, and it fell straight through to a default of
   "Developing" — a verdict on a child that nobody chose, on a section where
   that question had been removed on purpose. */
check(1 === preg_match('/if\s*\(\s*\$bench\s*<=\s*0\s*&&\s*\$may_judge\s*\)\s*:\s*\?>\s*<span class="pill/s', $view),
  'a section with a goal shows no verdict pill');
check(1 === preg_match('/\$may_judge\s*=\s*isset\(\s*\$section\[.fields.\]\[.status.\]\s*\);/', $view),
  'and one that no longer asks where a result sits does not answer it either');

/* ---- the sample says what it measured ---- */
require BFTD_PATH . 'includes/class-bftd-sample.php';
$v = BFTD_Sample::get('diagnostic')['fields']['dxsec-1'];
check('25' === $v['score'] && '26' === $v['score_of'], 'the sample is 25 of the 26 letters he wrote');
foreach (array('unit','status','meter_pct','baseline','card_note') as $gone) {
  check(!isset($v[$gone]), "the sample carries no $gone either");
}

/* ---- the overview leads with the sentence that sums it up ---- */
/* Writing three paragraphs and then being asked for the headline is the wrong
   way round. The sentence is the thing to decide first. */
$ov = array_keys($sections['sec-assessment-overview']['fields']);
check(array_search('headline', $ov, true) < array_search('overview', $ov, true),
  'the headline finding comes before the paragraphs');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
