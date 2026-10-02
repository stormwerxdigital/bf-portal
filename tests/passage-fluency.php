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
 * Two of the ten bands have no published figure at all. A bar cannot be drawn
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
check(10 === count($norms), 'ten bands, kindergarten through grades nine to twelve');
foreach (array('grade-1' => 60, 'grade-2' => 100, 'grade-3' => 112, 'grade-4' => 133,
               'grade-5' => 146, 'grade-6' => 146, 'grade-7' => 150, 'grade-8' => 151) as $band => $wcpm) {
  check($wcpm === BFTD_Schema::fluency_norm_wcpm($band), "$band is measured against $wcpm");
}

/* The two that have no figure must not acquire one. */
foreach (array('k' => 'Kindergarten', 'grade-9-12' => 'Grades nine to twelve') as $band => $what) {
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
check(1 === preg_match('/no published norm/', $view),
  'and a band with no figure is said in words beside the bar');
check(1 === preg_match('/norm, .\s*\.\s*\$norms\[ \$chosen \]\[.wcpm.\]/', $view),
  'while a band with one names the grade as well as the number');

/* ---- one list of norms, used everywhere ---- */
$opts = BFTD_Schema::fluency_norm_options();
check(11 === count($opts), 'the picker offers all ten, plus "not chosen"');
check(false !== strpos($opts['grade-8'], '151'), 'each choice shows the figure it is measured against');
check(false !== strpos($opts['k'], 'no published norm'), 'and says so where there is none');

$rows = BFTD_Schema::fluency_norm_rows();
check(10 === count($rows), 'the reference table is drawn from that same list');
check('60' === $rows[1][1], 'so a figure cannot differ between the picker and the table');
check(false !== strpos($rows[0][1], 'Pre-reading skills'),
  'and a band with no figure explains itself there too');

/* ---- the two tables ---- */
/* A single result in a one row table is a number floating at the far right of
   a wide band with its label at the far left and nothing joining them. These
   are shown as the design shows figures, using its own tiles rather than a
   second thing invented for the purpose. */
check('figures' === $f['results']['type'], 'this child\'s results are drawn as figures, not as a table');
check(4 === count($f['results']['tiles']), 'four of them for a passage');
check('{score}' === $f['results']['tiles'][0]['value'],
  'led by the figure the whole section is about');
check('score_table' === $f['norms_key']['type'], 'and so are the published norms');
check('Benchmarks' === $f['norms_key']['title'],
  'under a heading, so a table of ten grades is not left to explain itself');
check(false !== strpos(BFTD_Schema::score_table_html('dxsec-4', array(), 'norms_key'), 'Benchmarks'),
  'which is drawn above it');

$html = BFTD_Schema::figures_html('dxsec-4',
  array('words' => 17, 'errors' => 5, 'score' => 12, 'norm' => 'grade-1'), 'results');
check(false !== strpos($html, '>12<'), 'the figures report the correct words per minute');
check(false !== strpos($html, '>17<'), 'the total read');
check(false !== strpos($html, '>5<'),  'and the errors');
/* The band is split into the two parts a tile needs. Its whole label set at
   tile size shouted over the score it was there to be compared with; a bare 60
   beside a bare 12 is the comparison. */
check(false !== strpos($html, '>60<'), 'the norm is shown as the figure to compare against');
check(false !== strpos($html, 'End of year norm, Grade 1'), 'with the band named under it');
check(false === strpos($html, 'grade-1'), 'never the key that was stored');

/* A band with no published figure has nothing to compare against, so it has no
   tile. The card already says so in words. */
check(false === strpos(BFTD_Schema::figures_html('dxsec-4', array('kinder_score' => 12, 'norm' => 'k'), 'kinder_results'), 'End of year norm'),
  'and a band with no published figure shows no comparison at all');
check('' === BFTD_Schema::figures_html('dxsec-4', array(), 'results'),
  'and nothing at all before anybody has been timed');

/* Drawn with the design's own tiles, and with as many columns as there are
   figures, so one result does not stretch across a grid built for four. */
check(false !== strpos($html, 'class="kpis n4"'), 'four figures use four columns');
check(1 === preg_match('/class="kpis n2"/',
  BFTD_Schema::figures_html('dxsec-4', array('kinder_score' => 10, 'norm' => 'grade-1'), 'kinder_results')),
  'and two use two');
check(1 === preg_match('/class="kpis n1"/',
  BFTD_Schema::figures_html('dxsec-4', array('kinder_score' => 10), 'kinder_results')),
  'while one on its own uses one');
check(4 === substr_count($html, 'class="kpit"'), 'each figure is one of the design\'s tiles');

/* Which of the two assessments the child did, on a line of its own. Run into
   the title it read as one long sentence, and the part saying what they
   actually did is the part a parent needs to pick out. */
check('One minute timed reading assessment' === $f['results']['title']
   && 'One minute timed reading assessment' === $f['kinder_results']['title'],
  'both assessments are titled the same, because they are the same minute');
check('Reading a paragraph' === $f['results']['sub'], 'one says it was a paragraph');
check('Kindergarten level words' === $f['kinder_results']['sub'], 'the other that it was the word list');
check(1 === preg_match('#figs-t">One minute timed reading assessment</h4>\s*<p class="figs-s">Reading a paragraph</p>#s', $html),
  'drawn under the title rather than run into it');
$rcss = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(1 === preg_match('/\.figs-s\{[^}]*color:var\(--bf-purple\)/', $rcss),
  'and in the accent the rest of the report uses for that job, not as more heading');

/* ---- the Kindergarten word list ------------------------------------------
   A student reading at Kindergarten level or lower does a sixty word list
   rather than a passage. It is the same minute of reading reported a different
   way, so the section shows one account of it or the other, never both. */

check(isset($f['kinder_score']) && 'number' === $f['kinder_score']['type'],
  'the Kindergarten word list has a figure of its own');
check('figures' === $f['kinder_results']['type'], 'and figures of its own');

/* The card must still have a figure to report, or a child who was assessed
   drops off the summary cards entirely. */
check('kinder_score' === $f['score']['derived']['else'],
  'the card falls back to the word list figure when there is no passage');
$GLOBALS['META'][4] = array(BFTD_Schema::meta_key('dxsec-4', 'kinder_score') => '12');
check('12' === BFTD_Fields::get(4, 'dxsec-4', 'score', $f['score']),
  'so a word list reading still reaches the card');
$GLOBALS['META'][4] = array(
  BFTD_Schema::meta_key('dxsec-4', 'words')        => '17',
  BFTD_Schema::meta_key('dxsec-4', 'errors')       => '5',
  BFTD_Schema::meta_key('dxsec-4', 'kinder_score') => '99',
);
check('12' === BFTD_Fields::get(4, 'dxsec-4', 'score', $f['score']),
  'while a passage reading is still the sum, not the fallback');

/* One account of the minute, not two. */
$passage = array('words' => 17, 'errors' => 5, 'score' => 12, 'norm' => 'grade-1');
$kinder  = array('kinder_score' => 12, 'score' => 12, 'norm' => 'grade-1');
$both    = array_merge($passage, array('kinder_score' => 12));

check('' !== BFTD_Schema::figures_html('dxsec-4', $passage, 'results'),
  'a passage draws the passage figures');
check('' === BFTD_Schema::figures_html('dxsec-4', $passage, 'kinder_results'),
  'and not the word list ones');
check('' !== BFTD_Schema::figures_html('dxsec-4', $kinder, 'kinder_results'),
  'a word list draws the word list figures');
check('' === BFTD_Schema::figures_html('dxsec-4', $kinder, 'results'),
  'and not the passage ones');
check('kinder_score' === $f['results']['unless'],
  'the passage figures are the ones that stand aside');
check('' === BFTD_Schema::figures_html('dxsec-4', $both, 'results'),
  'so filling both in shows the word list, rather than two accounts of one minute');

/* The bug this rule was written after: a table drew because its other rows had
   values, with the figure it exists to report left blank. Picking a norm was
   enough to put an empty Kindergarten table on every report. */
check(false === strpos(BFTD_Schema::figures_html('dxsec-4', array('norm' => 'grade-1'), 'kinder_results'), 'kpit'),
  'choosing a norm alone does not report a reading nobody did');
check(false === strpos(BFTD_Schema::figures_html('dxsec-4', array('norm' => 'grade-1'), 'results'), 'Correct words'),
  'in either direction');

/* The explanation of the word list is part of the introduction, which every
   report carries, rather than a block of its own that appeared only on the
   reports where the word list was used. That conditional block, and the
   machinery behind it, are gone: a rule nothing exercises is a rule nobody can
   trust when something finally does. */
check(!isset($f['kinder_intro']), 'the word list has no separate explanation of its own');
$view_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(0 === preg_match('/only_when/', $view_src),
  'and the machinery that showed one conditionally went with it');
foreach (array(
  '60 word Kindergarten fluency assessment' => 'the word list is explained in the introduction',
  '60-Kindergarten-word fluency assessment' => 'in both of the forms the practice supplied',
  'smoothly, evenly, at a reasonable speed' => 'under what oral reading fluency is for',
) as $phrase => $why) {
  check(false !== strpos(BFTD_Schema::passage_intro(), $phrase), $why);
}

/* ---- the standing wording ---- */
/* Two blocks at the end of a section is two rich text editors side by side for
   what reads as one passage, and two entries in Settings for wording nobody
   would want to change half of. There is one, and it carries its own headings
   because it answers two questions and a field is given only one. */
check(!isset($f['what']), 'there is one block at the end of the section, not two');
check(!isset($f['method']['heading']),
  'and it is not given a heading, because its wording carries two of its own');
foreach (array('What is oral reading fluency?',
               'How does Evidence-Based Literacy Instruction build oral reading fluency?') as $h) {
  check(false !== strpos(BFTD_Schema::passage_method(), '<h3>' . $h . '</h3>'),
    "with \"$h\" among them");
}

foreach (array('body' => 'Intro', 'method' => 'How the method addresses this') as $key => $label) {
  check(!empty($f[$key]['shipped']), "$label is wording the plugin ships");
  check(3 === (int) $f[$key]['edit_rank'], "and only a senior manager may change it: $label");
  check($label === $f[$key]['label'], "labelled $label");
  check(isset(BFTD_Schema::standing_text()['dxsec-4.' . $key]), "$label can be set portal-wide");
}
check(false !== strpos(BFTD_Schema::passage_intro(), '60 word Kindergarten fluency assessment'),
  'the introduction is the wording the practice supplied');
check(false !== strpos(BFTD_Schema::passage_method(), 'from the inside out'),
  'and so is the standing answer');

/* ---- the order of the section ---- */
check(array_search('images', $k, true) < array_search('body_after', $k, true),
  'work samples come before the notes');
check(array_search('body_after', $k, true) < array_search('method', $k, true),
  'the notes come before the standing answer');
check(array_search('method', $k, true) === count($k) - 1,
  'which closes the section');
check(array_search('norm', $k, true) < array_search('body', $k, true),
  'and the figures are asked for before the prose, as a tutor fills them in');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
