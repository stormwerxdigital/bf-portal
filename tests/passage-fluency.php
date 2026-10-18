<?php
/*
 * Reading a passage, section 4 of the diagnostic.
 *
 * Every other measured section has a goal that belongs to the section: forty
 * letters, thirty-six sounds, ten reading levels. This one does not. What
 * counts as fluent depends on the child's grade, so the tutor chooses the band
 * and the bar is worked out against that. It is the only place in this report
 * where the goal is a per-report value, which makes it the only place where
 * the bar can be drawn against the wrong thing and still look reasonable.
 *
 * Kindergarten has no published figure at all. A bar cannot be drawn
 * against nothing, and the temptation is to quietly substitute a number. That
 * would put a percentage on a parent's report that no published norm supports.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$s = BFTD_Schema::sections()['dxsec-4'];
$f = $s['fields'];
$k = array_keys($f);

check('Timed reading' === $s['label'], 'the section is called what it is');
check('correct words per minute' === $s['unit'], 'the unit is fixed, not typed per report');

/* ---- three counts, not a fraction ---- */
foreach (array('words' => 'Total words read', 'errors' => 'Errors') as $key => $label) {
  check(isset($f[$key]) && 'number' === $f[$key]['type'], "$label is a number the tutor enters");
  check($label === $f[$key]['label'], "and is labelled $label");
}

/* ---- the third figure is arithmetic, not a third question ----------------
   Correct words per minute is the words read less the errors. Asked for as a
   third box it is a figure that can disagree with the two above it: correct an
   error count afterwards and the typed answer keeps the old number, with
   nothing to say it is now wrong. */
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
check(isset($f['score']['derived']['minus']), 'correct words per minute is worked out');
check(array('words', 'errors') === $f['score']['derived']['minus'],
  'from the words read, less the errors');
check(!BFTD_Fields::may_edit($f['score']),
  'and nobody types it, so a posted figure is refused rather than merely hidden');

$GLOBALS['META'] = array();
if (!function_exists('get_post_meta')) {
  function get_post_meta($id, $k, $single = false) { return $GLOBALS['META'][$id][$k] ?? ($single ? '' : array()); }
}
$put = function ($words, $errors) {
  $GLOBALS['META'][3] = array();
  if (null !== $words)  $GLOBALS['META'][3][BFTD_Schema::meta_key('dxsec-4', 'words')]  = (string) $words;
  if (null !== $errors) $GLOBALS['META'][3][BFTD_Schema::meta_key('dxsec-4', 'errors')] = (string) $errors;
  return BFTD_Fields::get(3, 'dxsec-4', 'score', $GLOBALS['F4']);
};
$GLOBALS['F4'] = $f['score'];

check('14' === $put(20, 6), 'twenty read with six errors is fourteen');
check('12' === $put(17, 5), 'and seventeen with five is twelve');

/* Blank in, blank out: a card must not appear for a child nobody has timed. */
check('' === $put(20, null), 'with no error count there is no answer yet');
check('' === $put(null, 6),  'and none without the words read either');

/* More errors than words is a slip. A negative on a parent's report would be
   a finding about the child; it is held at zero and pointed out to the tutor. */
check('0' === $put(10, 14), 'more errors than words does not print a negative');
$GLOBALS['META'][3] = array(
  BFTD_Schema::meta_key('dxsec-4', 'words')  => '10',
  BFTD_Schema::meta_key('dxsec-4', 'errors') => '14',
);
check('' !== BFTD_Fields::derive_warning(3, 'dxsec-4', $f['score']),
  'and the tutor is told, rather than the slip going out silently');
$GLOBALS['META'][3] = array(
  BFTD_Schema::meta_key('dxsec-4', 'words')  => '20',
  BFTD_Schema::meta_key('dxsec-4', 'errors') => '6',
);
check('' === BFTD_Fields::derive_warning(3, 'dxsec-4', $f['score']),
  'while sensible figures are not complained about');

/* The screen shows it rather than offering an input that ignores what is
   typed into it, and works it out again while somebody is still typing. */
$fields_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/function render_derived\(/', $fields_src),
  'the editor draws it as a figure, not as a box');
check(0 === preg_match('/render_derived[\s\S]{0,900}<input/', $fields_src),
  'with no input anybody could type into');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(1 === preg_match("/\\\$\\( '\\.bftd-field-calc' \\)/", $js),
  'and it updates as the figures above it are typed');
check(1 === preg_match('/Math\.max\( 0, out \)/', $js), 'holding at zero there too');
check(!in_array('score_of', $k, true), 'there is no denominator, because this is not a fraction');
check(!isset($f['score']['pair']), 'and the score is not drawn as one');

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

/* ---- the goal comes from the child, not the section ---- */
check(!isset($s['benchmark']), 'the section states no goal of its own');
check('norm' === $s['benchmark_field'], 'it names the field the goal is read from');
check('select' === $f['norm']['type'], 'and that field is chosen from the published bands');

$norms = BFTD_Schema::fluency_norms();
check(13 === count($norms), 'thirteen bands, Kindergarten through Grade 12, each grade on its own (1.151)');
check(!isset($norms['grade-9-12']), 'the combined "Grades 9 to 12" band is gone');
foreach (array('grade-9', 'grade-10', 'grade-11', 'grade-12') as $band) {
  check(151 === BFTD_Schema::fluency_norm_wcpm($band), "$band is measured against the Grade 8 figure, 151");
}
foreach (array('grade-1' => 60, 'grade-2' => 100, 'grade-3' => 112, 'grade-4' => 133,
               'grade-5' => 146, 'grade-6' => 146, 'grade-7' => 150, 'grade-8' => 151) as $band => $wcpm) {
  check($wcpm === BFTD_Schema::fluency_norm_wcpm($band), "$band is measured against $wcpm");
}

/* The two that have no figure must not acquire one. */
foreach (array('k' => 'Kindergarten') as $band => $what) {
  check(null === $norms[$band]['wcpm'], "$what has no published figure");
  check(0 === BFTD_Schema::fluency_norm_wcpm($band), 'and none is invented for it');
  check(!empty($norms[$band]['note']), 'the report says why instead');
}
check(0 === BFTD_Schema::fluency_norm_wcpm('not-a-band'),
  'and a band nobody chose has no figure either');

/* The card reads the chosen band, rather than a goal fixed on the section. */
check(100.0 === BFTD_Schema::measure('dxsec-4', array('score' => '88', 'norm' => 'grade-2'))['bench'],
  'the bar is worked out against the band the tutor chose');
check(0.0 === BFTD_Schema::measure('dxsec-4', array('score' => '30', 'norm' => 'k'))['bench'],
  'and a band with no published norm gives the bar nothing to divide by');
$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($view, 'BFTD_Schema::measure( $id, $v )'),
  'which is the one rule the card and the progress chart both read');
check(false !== strpos($view, 'BFTD_Schema::goal_line( $id, $v )'),
  'and the line beside the bar is the schema\'s, from the same lists');
check('Grade 4 norm, 133 correct words per minute' === BFTD_Schema::goal_line('dxsec-4', array('form' => 'passage', 'norm' => 'grade-4')),
  'a band with a figure names the grade as well as the number');
check('Kindergarten, no published norm' === BFTD_Schema::goal_line('dxsec-4', array('form' => 'passage', 'norm' => 'k')),
  'and a band with no figure is said in words beside the bar');

/* ---- one list of norms, used everywhere ---- */
$opts = BFTD_Schema::fluency_norm_options();
check(14 === count($opts), 'the picker offers all thirteen, plus "not chosen"');
check('Grade 10, 151 words per minute' === $opts['grade-10'], 'Grade 10 is a choice of its own at 151');
check(false !== strpos($opts['grade-8'], '151'), 'each choice shows the figure it is measured against');
check(false !== strpos($opts['k'], 'no published norm'), 'and says so where there is none');

/* ======================================================================
   Two assessments, and a student does one.
   From the tutor training document: "4 Timed Passage Reading" and
   "4 Kindergarten Word Fluency Assessment".
   ==================================================================== */
check('select' === $f['form']['type'] && 'form' === $s['form_field'], 'the tutor chooses which assessment was done');
check(array('', 'passage', 'kinder') === array_keys($f['form']['options']), 'a passage or the Kindergarten word list');
check(!empty($f['form']['card']), 'the choice itself is never printed on the report');

/* Which one, for every report, including ones written before there was a choice. */
check('passage' === BFTD_Schema::active_form('dxsec-4', array('form' => 'passage', 'kinder_score' => '12')), 'the choice wins over leftover figures');
check('kinder'  === BFTD_Schema::active_form('dxsec-4', array('form' => 'kinder', 'words' => '40')), 'in both directions');
check('kinder'  === BFTD_Schema::active_form('dxsec-4', array('kinder_score' => '12', 'words' => '40')), 'an older report with a word list figure is the word list, as it always was');
check('passage' === BFTD_Schema::active_form('dxsec-4', array('words' => '40')), 'and one with passage figures is the passage');
check(''        === BFTD_Schema::active_form('dxsec-4', array()), 'and nothing chosen or filled in is no answer');
check(''        === BFTD_Schema::active_form('dxsec-4', array('form' => 'nonsense')), 'a value that is not a choice is not taken as one');

/* The card's figure follows the choice, not whichever boxes happen to be full. */
$GLOBALS['META'][5] = array(
  BFTD_Schema::meta_key('dxsec-4', 'form')         => 'kinder',
  BFTD_Schema::meta_key('dxsec-4', 'words')        => '17',
  BFTD_Schema::meta_key('dxsec-4', 'errors')       => '5',
  BFTD_Schema::meta_key('dxsec-4', 'kinder_score') => '9',
);
check('9' === BFTD_Fields::get(5, 'dxsec-4', 'score', $f['score']), 'the word list chosen: the card reports the word list figure');
$GLOBALS['META'][5][BFTD_Schema::meta_key('dxsec-4', 'form')] = 'passage';
check('12' === BFTD_Fields::get(5, 'dxsec-4', 'score', $f['score']), 'the passage chosen: words less errors, never the word list figure');
unset($GLOBALS['META'][5][BFTD_Schema::meta_key('dxsec-4', 'words')]);
check('' === BFTD_Fields::get(5, 'dxsec-4', 'score', $f['score']), 'and with the passage half filled in, no figure rather than the word list one');
$GLOBALS['META'][4] = array(BFTD_Schema::meta_key('dxsec-4', 'kinder_score') => '12');
check('12' === BFTD_Fields::get(4, 'dxsec-4', 'score', $f['score']), 'an older word list report still reaches the card');
$GLOBALS['META'][4] = array(
  BFTD_Schema::meta_key('dxsec-4', 'words')        => '17',
  BFTD_Schema::meta_key('dxsec-4', 'errors')       => '5',
  BFTD_Schema::meta_key('dxsec-4', 'kinder_score') => '99',
);
check('99' === BFTD_Fields::get(4, 'dxsec-4', 'score', $f['score']), 'and an older report with both filled reads as the word list, which is what it showed before');

/* Every field that belongs to one assessment says which. */
foreach (array('words','errors','score','norm','passage_intro','passage_norms','passage_results','passage_note','passage_method') as $key) {
  check(isset($f[$key]) && 'passage' === $f[$key]['form'], "$key belongs to the passage");
}
foreach (array('kinder_score','min_grade','kinder_intro','kinder_minimums','kinder_results','kinder_method') as $key) {
  check(isset($f[$key]) && 'kinder' === $f[$key]['form'], "$key belongs to the word list");
}
foreach (array('images','body_after') as $key) {
  check(isset($f[$key]) && empty($f[$key]['form']), "$key is shared by both");
}
check(!isset($f['results']) && !isset($f['norms_key']) && !isset($f['body']) && !isset($f['method']),
  'the old single section\'s figures, benchmarks, intro and answer are gone');

/* ---- Timed passage reading ---- */
$passage = array('form' => 'passage', 'words' => 49, 'errors' => 9, 'score' => 40, 'norm' => 'grade-4');
$kinder  = array('form' => 'kinder', 'kinder_score' => 12, 'score' => 12, 'min_grade' => 'grade-8');

$norms_html = BFTD_Schema::score_table_html('dxsec-4', $passage, 'passage_norms');
check(false !== strpos($norms_html, 'Passage reading norms for oral reading fluency'), 'the passage norms are titled as the document titles them');
check(false !== strpos($norms_html, '50th percentile (spring) WCPM'), 'as the 50th percentile, spring');
foreach (array('Grade 1' => '60 WCPM', 'Grade 4' => '133 WCPM', 'Grade 8' => '151 WCPM') as $g => $w) {
  check(false !== strpos($norms_html, "<td>$g</td><td>$w"), "$g reads $w");
}
check(false !== strpos($norms_html, 'not a typo'), 'Grade 6 keeps its note about the middle-school slump');
check(2 === substr_count($norms_html, '(2006 norms)'), 'Grades 7 and 8 say they are the 2006 norms');
check(false !== strpos($norms_html, 'No ORF norm (pre-reading skills assessed instead)'), 'Kindergarten says there is no ORF norm');
check(false === strpos($norms_html, 'Grades 9 to 12'), 'the combined high school row is gone');
foreach (array(9, 10, 11, 12) as $g) {
  check(false !== strpos($norms_html, "<td>Grade $g</td><td>151 WCPM (reading rate plateaus after Grade 8; no published norms beyond Grade 8)</td>"),
    "Grade $g has its own row at 151, saying why");
}

$res = BFTD_Schema::score_table_html('dxsec-4', $passage, 'passage_results');
check(false !== strpos($res, '1-minute timed reading, unpracticed grade-level passage'), 'the results say what was read');
foreach (array('Total words read','Errors','WCPM (words correct per minute)','End of year ORF norm for enrolled grade') as $h) {
  check(false !== strpos($res, "<strong>$h</strong>"), "with a column for $h");
}
check(false !== strpos($res, '<td>49</td><td>9</td><td>40 WCPM</td><td>133 WCPM, Grade 4</td>'), 'and the document\'s example reads 49, 9, 40 WCPM, 133 WCPM Grade 4');
check(false !== strpos(BFTD_Schema::score_table_html('dxsec-4', array('form'=>'passage','words'=>49,'errors'=>9,'score'=>40), 'passage_results'), '<td>40 WCPM</td>'),
  'a reading with no norm chosen yet still shows the reading');
check('' === BFTD_Schema::score_table_html('dxsec-4', array('form'=>'passage','norm'=>'grade-4'), 'passage_results'),
  'and a norm chosen with nothing read shows nothing');
check('' === BFTD_Schema::score_table_html('dxsec-4', $kinder, 'passage_results')
   && '' === BFTD_Schema::score_table_html('dxsec-4', $kinder, 'passage_norms'),
  'none of it is drawn when the word list was done');

$note = BFTD_Schema::passage_norm_note();
check('reference' === $f['passage_note']['type'] && $note === $f['passage_note']['html'], 'the note on the norm is fixed reference wording');
foreach (array('50th percentile spring benchmark', 'Hasbrouck &amp; Tindal 2017', 'below 140 WCPM', 'middle-school slump',
               'https://www.readingrockets.org/sites/default/files/2023-08/2017_ORF_NORMS.pdf',
               'https://www.aimpa.org/uploaded/pdfs/Hasbrouck-ORF_NATIONAL_NORMS_Full_and_50ile.pdf') as $bit) {
  check(false !== strpos($note, $bit), "with $bit");
}

/* ---- Kindergarten word fluency ---- */
$mins = BFTD_Schema::word_minimums();
check(10 === count($mins), 'ten minimum speeds, Pre-Kindergarten to Grade 8');
foreach (array('pre-k'=>20,'k'=>30,'grade-1'=>40,'grade-2'=>55,'grade-3'=>70,'grade-4'=>85,'grade-5'=>100,'grade-6'=>115,'grade-7'=>130,'grade-8'=>130) as $key => $wpm) {
  check($wpm === BFTD_Schema::word_minimum_wpm($key), "$key is $wpm words per minute");
}
check('select' === $f['min_grade']['type'] && 11 === count($f['min_grade']['options']), 'the enrolled grade is chosen from that same list');

$mt = BFTD_Schema::score_table_html('dxsec-4', $kinder, 'kinder_minimums');
check(false !== strpos($mt, '<strong>Minimum speed benchmarks</strong>'), 'the minimums table is headed as the document heads it');
check(false !== strpos($mt, '<td>Pre-Kindergarten</td><td>20 words per minute</td>'), 'from Pre-Kindergarten');
check(false !== strpos($mt, '<td>Grade 8</td><td>130 words per minute</td>'), 'to Grade 8');

$kr = BFTD_Schema::score_table_html('dxsec-4', $kinder, 'kinder_results');
check(false !== strpos($kr, '1-minute timed reading, unpracticed Kindergarten word list'), 'the word list results say what was read');
foreach (array('Total WPM','Minimum WPM for Kindergarten','Minimum WPM for enrolled grade') as $h) {
  check(false !== strpos($kr, "<strong>$h</strong>"), "with a column for $h");
}
check(false !== strpos($kr, '<td>12</td><td>30 WPM</td><td>130 WPM, Grade 8</td>'), 'and the document\'s example reads 12, 30 WPM, 130 WPM Grade 8');
check(false !== strpos($kr, 'WPM: words per minute'), 'with WPM spelled out');
check('' === BFTD_Schema::score_table_html('dxsec-4', array('form'=>'kinder','min_grade'=>'grade-8'), 'kinder_results'),
  'a grade chosen with nothing read shows nothing');
check('' === BFTD_Schema::score_table_html('dxsec-4', $passage, 'kinder_results')
   && '' === BFTD_Schema::score_table_html('dxsec-4', $passage, 'kinder_minimums'),
  'and none of it is drawn when the passage was done');

/* The word list's goal on the card is the enrolled grade's minimum. */
check(130.0 === BFTD_Schema::measure('dxsec-4', $kinder)['bench'], 'the word list bar is against the enrolled grade\'s minimum');
check('Grade 8 minimum, 130 words per minute' === BFTD_Schema::goal_line('dxsec-4', $kinder), 'and says so beside the bar');
check(133.0 === BFTD_Schema::measure('dxsec-4', $passage)['bench'], 'while the passage bar stays against its norm');

/* ---- the standing wording: two intros and two answers, held by a senior manager ---- */
$texts = BFTD_Schema::standing_text();
foreach (array(
  'passage_intro'  => 'Oral reading fluency isn&#8217;t just speed.',
  'passage_method' => 'EBLI builds fluency from the inside out.',
  'kinder_intro'   => 'we skip the paragraph reading',
  'kinder_method'  => 'EBLI raises reading grade level',
) as $key => $phrase) {
  check(false !== strpos($f[$key]['shipped'], $phrase), "$key is the wording from the training document");
  check(3 === (int) $f[$key]['edit_rank'], "$key is changed only by a senior manager or an administrator");
  check(isset($texts['dxsec-4.' . $key]), "$key can be set portal-wide in Settings");
  check(false === strpos($f[$key]['shipped'], "\xE2\x80\x94"), "$key has no em dash");
}
check('How our method helps' === $f['passage_method']['heading']
   && 'How our method helps' === $f['kinder_method']['heading'], 'both answers carry the standard heading, How our method helps (1.151)');
check('Kindergarten word fluency assessment' === $f['kinder_intro']['heading'], 'and the word list intro its title');
check(false !== strpos($f['kinder_intro']['shipped'], 'https://www.donpotter.net/pdf/flesch_exercises.pdf'), 'the word list intro links the Victory Drill Book');
check(!isset($texts['dxsec-4.body']) && !isset($texts['dxsec-4.method']), 'the old single intro and answer are no longer offered in Settings');

/* ---- the order of the section ---- */
$at = function ($key) use ($k) { return array_search($key, $k, true); };
check($at('form') === 0, 'the choice comes first, as a tutor fills the section in');
check($at('min_grade') < $at('passage_intro'), 'then the figures, before the prose');
check($at('passage_intro') < $at('passage_norms') && $at('passage_norms') < $at('passage_results') && $at('passage_results') < $at('passage_note'),
  'the passage reads intro, norms, results, then the note on the norm');
check($at('kinder_intro') < $at('kinder_minimums') && $at('kinder_minimums') < $at('kinder_results'),
  'the word list reads intro, minimums, then results');
check($at('images') < $at('body_after') && $at('body_after') < $at('passage_method'), 'work samples, then notes, then how the method helps');

/* ---- the report draws one assessment, the editor shows the one chosen ---- */
$view_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(1 === preg_match('/shows_for_form\( \$id, \$field, \$values \)/', $view_src), 'the report body leaves out the assessment not done');
$fsrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/bftd-form-part.*data-form/s', $fsrc), 'the editor wraps each field in the assessment it belongs to');
check(1 === preg_match("/\\\$form !== \\\$field\['form'\] \) \? ' hidden'/", $fsrc), 'and the server decides which start hidden');
check(1 === preg_match("/bftd-form-part/", $js) && 1 === preg_match("/\]\[form\]\"\]' \)\.on\( 'change'/", $js), 'the script follows a change of choice');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
