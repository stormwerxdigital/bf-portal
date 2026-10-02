<?php
/*
 * Hearing sounds in words, section 2 of the diagnostic.
 *
 * The same shape as the alphabet, and reviewed the same way: the task is
 * fixed, so most of what the generated field set offers is a question this
 * section already knows the answer to. What is worth holding still is the
 * part that is arithmetic — a target nobody retypes, a bar nobody works out
 * by hand — and the part that is the practice's standing wording rather than
 * one tutor's, because both are silently wrong when they drift.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$sections = BFTD_Schema::sections();
$s  = $sections['dxsec-2'];
$f  = $s['fields'];
$k  = array_keys($f);

/* ---- what the section knows for itself ---- */

check(36 === (int) $s['benchmark'], 'the target is fixed at 36');
check(!empty($s['unit']), 'and the unit is fixed too, rather than typed per report');
check(!empty($s['own_order']), 'the section states its own field order');

/* Four of the generated fields are answers this section already has. Asking
   for them again is asking somebody to keep two copies of one fact. */
foreach (array(
  'unit'      => 'the unit is not something anybody types here',
  'status'    => 'where it sits is said once, in strengths and growth areas',
  'meter_pct' => 'the bar is worked out, not typed',
  'baseline'  => 'the baseline is the fixed 36',
  'card_note' => 'the card keeps the one-liner the schema gives it',
) as $gone => $why) {
  check(!in_array($gone, $k, true), $why);
}

/* ---- the fraction ---- */

check('number' === $f['score']['type'] && 'number' === $f['score_of']['type'],
  'both halves of the fraction are numbers');
check(isset($f['score']['pair']['key']) && 'score_of' === $f['score']['pair']['key'],
  'the two are paired, so they sit on one line');
check('/' === $f['score']['pair']['sep'], 'with a slash between them');
check(!empty($f['score_of']['in_pair']), 'and the second is not drawn again on its own');
check(36 === (int) $f['score_of']['fixed'],
  'the denominator is fixed at 36, which is what the task is out of');
check(!BFTD_Fields::may_edit($f['score_of']),
  'and nobody may type over it, whatever their rank');

/* The guarantee is not that the input is hidden, it is that the value does not
   come from the record. A report saved before the field was fixed still holds
   an old number in its meta, and that number must not come back. */
$GLOBALS['META'] = array(7 => array(BFTD_Schema::meta_key('dxsec-2', 'score_of') => '30'));
if (!function_exists('get_post_meta')) {
  function get_post_meta($id, $k, $single = false) { return $GLOBALS['META'][$id][$k] ?? ($single ? '' : array()); }
}
check('36' === (string) BFTD_Fields::get(7, 'dxsec-2', 'score_of', $f['score_of']),
  'a figure left in the record from before is not read back');

/* ---- the order of the section ---- */

/* The work the score was counted from belongs with the result it is evidence
   for, and ahead of the notes written about it. */
check(array_search('images', $k, true) < array_search('body_after', $k, true),
  'work samples come before the notes');
check(array_search('images', $k, true) > array_search('score_table', $k, true),
  'and after the scoring');
check(array_search('body', $k, true) < array_search('score_table', $k, true),
  'the introduction comes before the results table');
check(array_search('method', $k, true) === count($k) - 1,
  'and how the method addresses it closes the section');

/* ---- the standing wording ---- */

foreach (array('body' => 'the introduction', 'method' => 'the method answer') as $key => $what) {
  check(!empty($f[$key]['shipped']), "$what is standing wording the plugin ships");
  check(3 === (int) $f[$key]['edit_rank'],
    "and only a senior manager or an administrator may change $what");
}
check('Intro' === $f['body']['label'], 'the introduction is labelled Intro, as the report calls it');
check(false !== strpos($f['method']['heading'], 'phonemic awareness'),
  'the method answer is headed with the question it answers');

/* Practice-wide wording has to be reachable from Settings, or a change means
   editing every report by hand. The registry is built by scanning, so this is
   really a check that nothing about these two fields opts them out. */
$standing = BFTD_Schema::standing_text();
foreach (array('dxsec-2.body', 'dxsec-2.method') as $id) {
  check(isset($standing[$id]), "$id can be set portal-wide from Settings");
}

/* The wording itself, because it was given rather than drafted. */
check(false !== strpos(BFTD_Schema::segmenting_intro(), 'phoneme segmentation task'),
  'the introduction is the wording the practice supplied');
check(false !== strpos(BFTD_Schema::segmenting_method(), 'Short term memory'),
  'and so is the method answer');
foreach (array(BFTD_Schema::segmenting_intro(), BFTD_Schema::segmenting_method()) as $text) {
  check(false === strpos($text, '—'), 'no em dashes in wording a parent reads');
}

/* ---- the results table ---- */

$t = $f['score_table'];
check('score_table' === $t['type'], 'the results table is derived, not typed');
check('Phoneme segmentation assessment' === $t['title'], 'it is titled as the assessment');

$html = BFTD_Schema::score_table_html('dxsec-2', array('score' => 16, 'score_of' => 36));
check(false !== strpos($html, '16 / 36'), 'the score row is the fraction the tutor entered');

/* The denominator is the section's, not the caller's. This table is drawn in
   two places — the tutor's screen and the family's report — and if the figure
   had to be handed in, one of the two would eventually hand in nothing and
   show a fraction with no bottom half. */
check(false !== strpos(BFTD_Schema::score_table_html('dxsec-2', array('score' => 16)), '16 / 36'),
  'and the fixed 36 is supplied by the table itself, not by whoever asked for it');
check(false === strpos($html, '16 of 36'), 'written with a slash, not the word "of"');
check(false !== strpos($html, '100 per cent, for students of every age'),
  'the target row is fixed, because it is the same for every child');
check(false !== strpos($html, 'does not need to be retested'),
  'and the note under it is carried too');

/* A section nobody has reached yet must not show a table of its fixed rows
   alone. A parent reading a target with no score beside it reads a finding. */
check('' === BFTD_Schema::score_table_html('dxsec-2', array('score' => '', 'score_of' => '')),
  'and none of it is drawn before anything has been counted');

/* The same guard must not have broken the section it was written for. */
$a = BFTD_Schema::score_table_html('dxsec-1', array('score' => 25, 'score_of' => 26));
check(false !== strpos($a, '25') && false !== strpos($a, '40 correct lowercase letters'),
  'the alphabet table still draws as it did');
check('' === BFTD_Schema::score_table_html('dxsec-1', array('score' => '', 'score_of' => '')),
  'and it too waits for a score');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
