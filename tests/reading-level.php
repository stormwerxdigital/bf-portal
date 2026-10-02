<?php
/*
 * Reading grade level, section 3 of the diagnostic.
 *
 * This one is a level rather than a count, and that is the whole difficulty.
 * A level has no arithmetic of its own, so the bar cannot divide it by
 * anything: what it reads is the level's position in the list of ten. And a
 * level typed by hand produced "Preprimer", "pre-primer" and "PP" for the same
 * finding, so it is chosen from a list instead — which means the thing stored
 * is a key, and a key must never reach a parent.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
class BFTD_Fields { public static function has_value($v){ return '' !== trim((string) $v); } }
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$s = BFTD_Schema::sections()['dxsec-3'];
$f = $s['fields'];
$k = array_keys($f);

/* ---- what it is called ---- */
check('Reading grade level' === $s['label'],
  'the section is called what it reports, in the report and the editor alike');
check('San Diego Quick Assessment' === $s['summary'],
  'with the assessment named underneath');

/* ---- the score is chosen, not typed or divided ---- */
check('select' === $f['score']['type'], 'the score is chosen from a list');
check(!in_array('score_of', $k, true), 'there is no denominator, because a level is not a fraction');

$levels = BFTD_Schema::reading_levels();
check(10 === count($levels), 'ten levels');
check('Preprimer' === $levels['preprimer'] && 'Primer' === $levels['primer'],
  'starting at preprimer and primer');
check('Grade 1' === $levels['grade-1'] && 'Grade 8' === $levels['grade-8'],
  'then every grade from one to eight');
check(array_key_exists('', $f['score']['options']),
  'and "not assessed" is one of the choices, so a blank is deliberate');

/* One list, used three ways. Three lists is how a level comes to mean one
   thing on the card and another in the table. */
foreach ($levels as $key => $label) {
  check(isset($f['score']['options'][$key]) && $label === $f['score']['options'][$key],
    "the picker offers $label from that one list");
}

/* ---- the bar reads position, not value ---- */
check(10 === (int) $s['benchmark'], 'the bar is out of ten, because there are ten levels');
check('reading_level' === $s['score_rank'],
  'and the section says its score is a level, rather than leaving the card to guess');

foreach (array('preprimer' => 1, 'primer' => 2, 'grade-1' => 3, 'grade-8' => 10) as $v => $rank) {
  check($rank === BFTD_Schema::reading_level_rank($v), "$v is number $rank of ten");
}
check(10 === (int) round(BFTD_Schema::reading_level_rank('preprimer') / $s['benchmark'] * 100),
  'so preprimer fills a tenth of the bar');
check(100 === (int) round(BFTD_Schema::reading_level_rank('grade-8') / $s['benchmark'] * 100),
  'and grade 8 fills all of it');
check(0 === BFTD_Schema::reading_level_rank('something else'),
  'while a value that is not a level has no position at all');

/* The card must print the words, never the key it stored, and the bar must
   read the level's position rather than trying arithmetic on a key. One rule,
   in the schema, because the progress report now plots these same levels over
   time and a second copy of it would drift. */
$m = BFTD_Schema::measure('dxsec-3', array('score' => 'preprimer'));
check('Preprimer' === $m['shown'], 'the card resolves what to show');
check('preprimer' === $m['raw'], 'from the list the choice came from');
check(1.0 === $m['value'], 'and the bar is worked out from its position');
check(10.0 === $m['bench'], 'against the ten levels it is one of');

$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(1 === preg_match('/<span class="num[^"]*"><\?php echo esc_html\( \$shown \)/', $view),
  'and prints that, not the stored key');
check(1 === preg_match('/\$shown\s*=\s*\$m\[.shown.\];/', $view),
  'reading the resolved words from that one rule');

/* ---- what a tutor is no longer asked ---- */
foreach (array(
  'status'    => 'where it sits is said once, in strengths and growth areas',
  'meter_pct' => 'the bar is worked out, not typed',
  'baseline'  => 'the baseline is the ten levels themselves',
  'card_note' => 'the card keeps the one-liner the schema gives it',
  'unit'      => 'there is no unit to state',
) as $gone => $why) {
  check(!in_array($gone, $k, true), $why);
}

/* ---- the two tables ---- */

/* One explains how the levels are read and is the same for every child. The
   other is this child's, and is the only one with anything typed in it. */
check('score_table' === $f['levels_key']['type'], 'the key to the levels is drawn, not written');
check('score_table' === $f['levels']['type'],
  'and the result table is drawn too, from the three levels chosen above');

/* Three bands, three choices, one list. The table reports them; it does not
   store them a second time, which is what it used to do with three text boxes
   beside the dropdown that already held the first of them. */
foreach (array('score' => 'independent', 'instructional' => 'instructional', 'frustration' => 'frustration') as $key => $band) {
  check(isset($f[$key]) && 'select' === $f[$key]['type'], "the $band level is chosen from a list");
  check($f[$key]['options'] === $f['score']['options'], "from the same ten levels as the others: $band");
}
check(!empty($f['instructional']['card']) && !empty($f['frustration']['card']),
  'and neither is printed again as a loose row under the table that reports it');

/* The two extra choices sit with the first, because a tutor reads all three
   off one scoring sheet in one go. */
check(array_search('instructional', $k, true) === array_search('score', $k, true) + 1,
  'the instructional level comes straight after the independent one');
check(array_search('frustration', $k, true) === array_search('instructional', $k, true) + 1,
  'and the frustration level after that');

/* Every band is reported, and a band nobody assessed is left out. */
$all = BFTD_Schema::score_table_html('dxsec-3',
  array('score' => 'preprimer', 'instructional' => 'primer', 'frustration' => 'grade-1'), 'levels');
foreach (array('Preprimer', 'Primer', 'Grade 1') as $level) {
  check(false !== strpos($all, $level), "the table reports the $level band");
}
check(false === strpos($all, 'preprimer'),
  'in the words a parent reads, never the key that was stored');

$one = BFTD_Schema::score_table_html('dxsec-3', array('score' => 'grade-2'), 'levels');
check(false !== strpos($one, 'Grade 2'),
  'a table whose only assessed band is the first is still drawn');
check(false === strpos($one, 'Instructional level'),
  'with the bands nobody assessed left out');
check('' === BFTD_Schema::score_table_html('dxsec-3', array('score' => ''), 'levels'),
  'and nothing at all before anybody has been assessed');

/* The explanation is drawn whether or not this child has been assessed; the
   result table is not, because an empty one reads as a finding. */
$key_html = BFTD_Schema::score_table_html('dxsec-3', array('score' => '', 'score_of' => ''), 'levels_key');
check(false !== strpos($key_html, 'Reads comfortably'),
  'the explanation shows before anybody has been assessed');
check(false !== strpos($key_html, 'Too difficult even with support'),
  'with all three bands described');

/* A section may now hold more than one drawn table, so the builder is told
   which one to draw. Naming one that is not there has to draw nothing: falling
   back to "the first one" would put a table on the page that the section never
   asked for, which is worse than a missing one because it looks deliberate. */
check('' === BFTD_Schema::score_table_html('dxsec-3', array('score' => 'preprimer'), 'body'),
  'asking for a field that is not a drawn table returns nothing');
check('' === BFTD_Schema::score_table_html('dxsec-3', array('score' => 'preprimer'), 'no-such-field'),
  'and so does asking for one that does not exist');
check(false === strpos(BFTD_Schema::score_table_html('dxsec-3', array('score' => 'preprimer'), 'levels'), 'Reads comfortably'),
  'and each of this section\'s two tables draws only itself');
check(false !== strpos(BFTD_Schema::score_table_html('dxsec-3', array('score' => 'preprimer'), 'levels_key'), 'Reads comfortably'),
  'while asking for the one that is there draws it');

/* ---- the standing wording ---- */
foreach (array('body' => 'the introduction', 'method' => 'the standing answer') as $key => $what) {
  check(!empty($f[$key]['shipped']), "$what is wording the plugin ships");
  check(3 === (int) $f[$key]['edit_rank'], "and only a senior manager may change $what");
}
check('Intro' === $f['body']['label'], 'the introduction is labelled Intro');
check(false !== strpos($f['method']['heading'], 'word reading'),
  'and the standing answer is headed with the question it answers');
$standing = BFTD_Schema::standing_text();
foreach (array('dxsec-3.body', 'dxsec-3.method') as $id) {
  check(isset($standing[$id]), "$id can be set portal-wide from Settings");
}
check(false !== strpos(BFTD_Schema::wordreading_intro(), 'San Diego Quick Assessment'),
  'the introduction is the wording the practice supplied');
check(false !== strpos(BFTD_Schema::wordreading_method(), 'flexible word solving'),
  'and so is the standing answer');

/* ---- the order of the section ---- */
check(array_search('body', $k, true) < array_search('levels_key', $k, true),
  'the introduction comes before the key to the levels');
check(array_search('levels_key', $k, true) < array_search('levels', $k, true),
  'the key comes before this child\'s result');
check(array_search('images', $k, true) < array_search('body_after', $k, true),
  'work samples come before the notes');
check(array_search('method', $k, true) === count($k) - 1,
  'and the standing answer closes the section');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
