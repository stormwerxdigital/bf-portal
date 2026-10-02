<?php
/*
 * Spelling, section 5 of the diagnostic.
 *
 * Three measures that look alike and are not. A word list is a level. Correct
 * spellings counts sounds. The spelling score counts whole words. All a tutor
 * reads off one sheet, which is the only thing they have in common, and the
 * reason they belong in a table rather than in a row of tiles that would imply
 * they were comparable.
 *
 * The bar is the one place in this report where the goal is simply the other
 * half of the fraction beside it: three of twenty-five is twelve per cent, and
 * nobody types that.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$s = BFTD_Schema::sections()['dxsec-5'];
$f = $s['fields'];
$k = array_keys($f);

/* ---- the three measures ---- */
check('select' === $f['word_list']['type'], 'the word list is chosen, not typed');
$lists = BFTD_Schema::spelling_lists();
check(isset($lists['k']) && 'Kindergarten' === $lists['k'], 'from Kindergarten');
check(isset($lists['grade-12']) && 'Grade 12' === $lists['grade-12'], 'through to Grade 12');
check(14 === count($lists), 'which is thirteen levels, plus not chosen');

foreach (array(
  'spellings' => array('spellings_of', 'Correct spellings'),
  'score'     => array('score_of',     'Spelling score'),
) as $key => $pair) {
  check('number' === $f[$key]['type'], "$pair[1] is a number");
  check($pair[0] === $f[$key]['pair']['key'], "paired with its own denominator");
  check(!empty($f[$pair[0]]['in_pair']), "which is not drawn again on its own: $pair[0]");
  check($pair[1] === $f[$key]['label'], "labelled $pair[1]");
}

/* Two fractions in one section, counting two different things. Confusing the
   two would report eighty-nine words on a twenty-five word test. */
check(false !== strpos($f['spellings']['help'], 'sounds rather than whole words'),
  'and the screen says which of them counts sounds');

/* ---- what a tutor is no longer asked ---- */
foreach (array(
  'unit'      => 'the unit shown to parents',
  'status'    => 'where it sits',
  'baseline'  => 'the baseline marker',
  'card_note' => 'the one-line card summary',
  'meter_pct' => 'the meter percentage',
) as $gone => $what) {
  check(!in_array($gone, $k, true), "$what is not something anybody types here");
}

/* ---- the bar ---- */
check(!isset($s['benchmark']), 'the section states no goal of its own');
check('score_of' === $s['benchmark_field'],
  'because the goal is the other half of the spelling score');
check(empty($s['benchmark_lookup']),
  'read as a figure directly, with no band to look it up in');
check('words correct' === $s['unit'], 'and the unit is fixed');

$m = BFTD_Schema::measure('dxsec-5', array('score' => '3', 'score_of' => '25'));
check(25.0 === $m['bench'], 'a section can take its goal straight from a field');
check(3.0 === $m['value'], 'with the score as the count it is');
check(112.0 === BFTD_Schema::measure('dxsec-4', array('score' => '64', 'norm' => 'grade-3'))['bench'],
  'while one that needs a lookup says which lookup, and gets the figure that band stands for');

/* ---- the results table ---- */
$html = BFTD_Schema::score_table_html('dxsec-5', array(
  'word_list' => 'grade-1', 'spellings' => 57, 'spellings_of' => 89,
  'score' => 3, 'score_of' => 25,
), 'results');
check(false !== strpos($html, 'Grade 1'), 'the table names the word list in words');
check(false === strpos($html, 'grade-1'), 'never the key that was stored');
check(false !== strpos($html, '57 of 89 individual spellings'), 'counts the individual spellings');
check(false !== strpos($html, '3 of 25 words correct'), 'and the whole words');
check(1 === preg_match('#<thead>.*?<tr><th><strong>Measure</strong>#s', $html),
  'under column labels, which stay above the body');

/* ---- the challenges ---- */
check('Challenges' === $f['challenges']['title'],
  'the pattern list is headed, rather than left to explain itself');

/* And the heading is drawn, not merely declared. Read from the code with the
   comments stripped, because the comment above it names the class it emits and
   a check across the file would find that and pass whatever the code did. */
$dash = preg_replace('#/\*[\s\S]*?\*/#', '', file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php'));
$dash = preg_replace('#(^|\n)\s*//[^\n]*#', '$1', $dash);
check(1 === preg_match("/function conv_list\\([\\s\\S]{0,700}\\\$field\\['title'\\][\\s\\S]{0,200}figs-t/", $dash),
  'and the checklist renderer draws it above the list');
/* The six challenges are the marking scheme for this test, not a list a tutor
   writes out each time. Typed from memory they came out worded differently on
   every report, and two reports for the same child could not be read side by
   side. So the wording is the schema's and only the answer is the tutor's. */
check('checklist' === $f['challenges']['type'], 'the challenges are a fixed list');
check('Challenges' === $f['challenges']['headings']['item'],
  'headed Challenges rather than Pattern');
$rows = $f['challenges']['rows'];
check(6 === count($rows), 'six of them');
foreach (array('b/d reversals', 'omits spellings', 'puts blends together',
               'trouble with 2,3,4 letter spellings', 'inserts spellings',
               'spellings out of order') as $label) {
  check(in_array($label, $rows, true), "including \"$label\"");
}

$opts = $f['challenges']['options'];
foreach (array('yes' => 'Yes', 'no' => 'No', 'na' => 'N/A') as $key => $label) {
  check(isset($opts[$key]) && $label === $opts[$key], "a row can be answered $label");
}
check(isset($opts['']), 'or left unassessed, which keeps it out of the family\'s report');

/* Nothing posted can add a finding to a family's report, or answer one with a
   word nobody offered. */
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
$clean = BFTD_Fields::sanitize_value(array(
  'omits'    => array('value' => 'yes', 'note' => '  Omitted the n in junk.  '),
  'blends'   => array('value' => 'maybe'),
  'invented' => array('value' => 'yes'),
  'inserts'  => array('value' => '', 'note' => ''),
), $f['challenges']);
check(array('omits' => array('value' => 'yes', 'note' => 'Omitted the n in junk.')) === $clean,
  'a made-up row is refused, a made-up answer is dropped, an empty row is left out and the example is trimmed');

/* The report draws it, and draws it as the design's own list rather than as a
   second component that looks nearly the same. Read from the code with the
   comments stripped, because the comment above that branch names both the type
   and the renderer and would satisfy a looser check on its own. */
$dash_code = preg_replace('#/\*[\s\S]*?\*/#', '', file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php'));
$dash_code = preg_replace('#(^|\n)\s*//[^\n]*#', '$1', $dash_code);
check(1 === preg_match("/'checklist' === \\\$field\\['type'\\][\\s\\S]{0,900}self::conv_list\\(/", $dash_code),
  'the report draws the fixed list through the design\'s own checklist renderer');
check(1 === preg_match("/if \\( empty\\( \\\$row\\['value'\\] \\) \\) continue;/", $dash_code),
  'leaving out any challenge that was not answered');

/* ---- the order of the section ---- */
check(array_search('images', $k, true) < array_search('body_after', $k, true),
  'work samples come before the notes');
check(array_search('challenges', $k, true) < array_search('images', $k, true),
  'which come after the findings');
check('Notes' === $f['body_after']['heading'], 'the notes are headed Notes');
check('Notes' === $f['body_after']['label'], 'and called that on the tutor\'s screen too');
check(array_search('method', $k, true) === count($k) - 1,
  'and the standing answer closes the section');

/* The generated field set used to put "After the checklist" back over this,
   because that loop ran after the sections that declare their own fields. */
$schema_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-schema.php');
check(0 === preg_match("/foreach \\( array\\( 'dxsec-5', 'dxsec-6' \\) as \\\$sid \\)/", $schema_src),
  'and nothing puts the generated version back over it afterwards');

/* ---- the standing wording ---- */
foreach (array('body' => 'Intro', 'method' => 'How the method addresses this') as $key => $label) {
  check(!empty($f[$key]['shipped']), "$label is wording the plugin ships");
  check(3 === (int) $f[$key]['edit_rank'], "and only a senior manager may change it: $label");
  check($label === $f[$key]['label'], "labelled $label");
  check(isset(BFTD_Schema::standing_text()['dxsec-5.' . $key]), "$label can be set portal-wide");
}
foreach (array(
  'not to find out how many words a child can spell correctly' => 'the introduction is the wording the practice supplied',
  'The reading and spelling connection'                        => 'with its own sub-headings',
  'Why spelling is harder than reading'                        => 'both of them',
) as $phrase => $why) {
  check(false !== strpos(BFTD_Schema::spelling_intro(), $phrase), $why);
}
check(false !== strpos(BFTD_Schema::spelling_method(), 'no lists to learn by Friday'),
  'and the standing answer is the one already in this report');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
