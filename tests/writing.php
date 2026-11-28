<?php
/*
 * Writing, section 6 of the diagnostic.
 *
 * Nothing here is counted, so there is no figure to type and no goal to type
 * it against. The card reports the two lists themselves: how many of the
 * things looked for were there, out of how many were looked at.
 *
 * Both figures come from one walk of the same rows, because counted
 * separately the numerator can end up out of a different set than the
 * denominator, and a card reading nine out of eight is the kind of thing
 * nobody notices until a parent does.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$s = BFTD_Schema::sections()['dxsec-6'];
$f = $s['fields'];
$k = array_keys($f);

/* ---- one fixed list: Writing conventions and grammar (Karl, 1.222.0) ---- */
foreach (array('conventions' => 11) as $key => $n) {
  check('checklist' === $f[$key]['type'], "$key is a fixed list");
  check($n === count($f[$key]['rows']), "with $n rows nobody retypes");
  foreach (array('' => 'Not assessed', 'yes' => 'Yes', 'no' => 'No', 'na' => 'N/A') as $ov => $ol) {
    check(isset($f[$key]['options'][$ov]) && $ol === $f[$key]['options'][$ov],
      "answered $ol, and nothing else: $key");
  }
  check(4 === count($f[$key]['options']), "four answers and no more: $key");
}
check(!isset($f['grammar']) && 'Writing conventions and grammar' === $f['conventions']['label'],
  'grammar is part of the one list, called Writing conventions and grammar');
check(array('indent', 'caps_start', 'caps_proper', 'end_punct', 'other_punct', 'spaces', 'complete', 'order', 'each_sound', 'misspelled', 'multisyll') === array_keys($f['conventions']['rows']),
  'in Karl\'s order');
check('Complete sentences (no run-on sentences or fragments)' === $f['conventions']['rows']['complete']
  && 0 === strpos($f['conventions']['rows']['order'], 'Overall flow: The writing sample is a paragraph of at least 4 sentences.')
  && 'Writing piece has no <strong>spelling errors</strong>' === $f['conventions']['rows']['misspelled']
  && '<strong>Other punctuation</strong> is appropriate for sentences' === $f['conventions']['rows']['other_punct'],
  'with the new wording');
check(array('other_punct' => 'Punctuation errors (marked in RED)', 'misspelled' => 'Misspelled words (highlighted in YELLOW)') === $f['conventions']['fixed_notes'],
  'and two notes that are always there');

/* The wording of a convention carries emphasis, and it has to survive into
   both the report and the screen a tutor answers it on. */
/* How the tutor marks the sample, said beside the row it belongs to (Karl, 1.151). */
foreach (array(array('conventions', 'multisyll', '(underlined in BLUE)')) as $m) {
  check(' ' . $m[2] === substr($f[$m[0]]['rows'][$m[1]], -strlen($m[2]) - 1), "$m[1] ends with $m[2]");
}
check(false !== strpos($f['conventions']['rows']['caps_start'], '<strong>capital letters</strong>'),
  'a convention keeps the emphasis the design gives it');
$fields_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/bftd-cl-item[^>]*>.{0,40}wp_kses_post\( \$label \)/', $fields_src),
  'and the editor renders it rather than printing the tags');

/* ---- the card counts the lists ---- */
check(!isset($s['benchmark']), 'the section states no goal of its own');
check('score_of' === $s['benchmark_field'], 'it measures against how many were answered');
check(isset($f['score']['derived']['count']), 'the figure is counted, not typed');
check(array('conventions') === $f['score']['derived']['count']['fields'],
  'across the list');
check('yes' === $f['score']['derived']['count']['value'], 'counting the ones marked yes');
check(isset($f['score_of']['derived']['count']) && !isset($f['score_of']['derived']['count']['value']),
  'and the denominator counts every row anybody answered');
check(!empty($f['score_of']['hidden']),
  'which is not drawn, because it answers a question nobody asked');

$GLOBALS['META'] = array();
if (!function_exists('get_post_meta')) {
  function get_post_meta($id, $k, $single = false) { return $GLOBALS['META'][$id][$k] ?? ($single ? '' : array()); }
}
$put = function ($conv, $gram) use ($f) {
  $GLOBALS['META'][8] = array(
    BFTD_Schema::meta_key('dxsec-6', 'conventions') => $conv,
    BFTD_Schema::meta_key('dxsec-6', 'grammar')     => $gram,
  );
  return array(
    BFTD_Fields::get(8, 'dxsec-6', 'score', $f['score']),
    BFTD_Fields::get(8, 'dxsec-6', 'score_of', $f['score_of']),
  );
};

check(array('2', '3') === $put(
  array('indent' => array('value' => 'yes'), 'spaces' => array('value' => 'no')),
  array('order' => array('value' => 'yes'))
), 'two of three answered were yes');

/* A row nobody assessed is in neither figure, so a part-finished section is
   not a child scored on questions nobody asked. */
check(array('1', '2') === $put(
  array('indent' => array('value' => 'yes'), 'spaces' => array('value' => '', 'note' => 'x')),
  array('order' => array('value' => 'na'))
), 'an unanswered row counts in neither figure');

/* Nothing answered is no figure at all, rather than nought out of nought. */
check(array('', '') === $put(array(), array()),
  'and a section nobody has filled in reports nothing, not a score of zero');

/* The editor has to be able to draw a counted figure. It only knew how to draw
   a subtraction, and handed a counted one it read a key that was not there and
   took the whole edit screen down: a fatal error on a page nobody can get
   past, not a wrong number. It passed every test, because no test rendered
   that field. */
check(1 === preg_match("/empty\\( \\\$field\\['derived'\\]\\['minus'\\] \\)[\\s\\S]{0,700}elseif[\\s\\S]{0,120}derived.\\]\\['count'\\]/", $fields_src),
  'the editor draws a counted figure as well as a subtracted one');
check(0 === preg_match("/array_map\\([^;]{0,200}\\\$field\\['derived'\\]\\['minus'\\] \\);/", $fields_src),
  'and never reaches for a rule the field does not have');

/* ---- the card reads as a fraction ---- */
check('' === $s['unit'], 'there is no unit, because the two figures are the sentence');
check(!empty($s['meter_note']), 'the line beside the bar says what the fraction counts');
check(false !== strpos($s['meter_note'], 'out of the points looked at'),
  'in words rather than by repeating a unit twice');

/* ---- what a tutor is no longer asked ---- */
foreach (array('unit', 'status', 'meter_pct', 'baseline', 'card_note') as $gone) {
  check(!in_array($gone, $k, true), "$gone is not something anybody types here");
}

/* ---- the order of the section ---- */
check(array_search('images', $k, true) < array_search('body_after', $k, true),
  'work samples come before the notes');
check(array_search('conventions', $k, true) < array_search('images', $k, true),
  'which come after the list');
check('Notes' === $f['body_after']['heading'] && 'Notes' === $f['body_after']['label'],
  'the notes are called Notes on both sides');
check('method' === end($k), 'and the standing answer closes the section');

/* ---- the standing wording ---- */
foreach (array('body' => 'Intro', 'method' => 'How our method helps') as $key => $label) {
  check(!empty($f[$key]['shipped']), "$label is wording the plugin ships");
  check(3 === (int) $f[$key]['edit_rank'], "and only a senior manager may change it: $label");
  check(isset(BFTD_Schema::standing_text()['dxsec-6.' . $key]), "$label can be set portal-wide");
}
check(false !== strpos(BFTD_Schema::writing_method(), 'not saved for the end'),
  'the standing answer is the wording the practice supplied');

/* Standing wording appears on every report, so it cannot name one child. This
   was settled once already, on the alphabet section. */
foreach (BFTD_Schema::standing_text() as $id => $item) {
  foreach (array('Kaine', 'Jot') as $name) {
    check(false === strpos($item['shipped'], $name),
      "no standing wording names a particular child: $id");
  }
}
check(false !== strpos(BFTD_Schema::writing_intro(), 'the student was asked to write'),
  'so this one says the student');

/* Writing answers are marks: green tick, red cross, N/A in words (Karl, 1.155). */
check(!empty($f['conventions']['marks']), 'the writing checklist is answered with marks');
$mc = BFTD_Fields::mark_choices($f['conventions']['options']);
check(array('yes', 'no', 'na', '') === array_map('strval', array_keys($mc)), 'tick, cross, N/A, then not assessed');
check('&#10003;' === $mc['yes']['show'] && '&#10007;' === $mc['no']['show'] && 'N/A' === $mc['na']['show'], 'drawn as a tick, a cross and the letters N/A');
$fsrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(false !== strpos($fsrc, '<?php if ( ! empty( $field[\'marks\'] ) ) : // Tick, cross, N/A or nothing'), 'the editor draws them in place of the dropdown');
$dsrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
check(false !== strpos($dsrc, 'if ( ! empty( $field[\'marks\'] ) && in_array( $val, array( \'yes\', \'no\' ), true ) ) {'), 'and the report shows a tick or a cross, N/A staying in words');

/* ---- misspelled words: no is the good answer (Karl, 1.221.1) ---- */
$cv = $f['conventions'];
check(array('misspelled') === $cv['invert'], 'the misspelled words row is the one where No is good');
check(BFTD_Fields::inverted($cv, 'misspelled') && !BFTD_Fields::inverted($cv, 'spaces') && !BFTD_Fields::inverted(array('rows' => array()), 'misspelled'), 'and only that row');
check('no' === BFTD_Fields::mark_value($cv, 'misspelled', 'yes') && 'yes' === BFTD_Fields::mark_value($cv, 'misspelled', 'no') && 'na' === BFTD_Fields::mark_value($cv, 'misspelled', 'na'), 'its tick stores No and its cross Yes; N/A is N/A');
check('yes' === BFTD_Fields::mark_value($cv, 'spaces', 'yes'), 'other rows are as they were');
check(array('2', '2') === $put(
  array('spaces' => array('value' => 'yes'), 'misspelled' => array('value' => 'no', 'note' => 'No misspelled words.')),
  array()
), 'no misspelled words is a point in the score');
check(array('1', '2') === $put(
  array('spaces' => array('value' => 'yes'), 'misspelled' => array('value' => 'yes')),
  array()
), 'some misspelled words is not');

// The tutor's buttons: on that row the tick (first, green) is the one that stores No.
$GLOBALS['META'][9] = array(BFTD_Schema::meta_key('dxsec-6', 'conventions') => array('misspelled' => array('value' => 'no', 'note' => 'No misspelled words.')));
foreach (array('checked', 'wp_kses_post', 'wp_strip_all_tags') as $fn) if (!function_exists($fn)) {
  if ('checked' === $fn) { function checked($a, $b, $e = true) { $r = (string) $a === (string) $b ? ' checked' : ''; if ($e) echo $r; return $r; } }
}
ob_start(); BFTD_Fields::render_field(9, 'dxsec-6', 'conventions', $cv); $ed = ob_get_clean();
$row = substr($ed, strpos($ed, 'spelling errors'));
preg_match_all('/bftd-crit-opt-(\w+)".*?value="(\w*)"( checked)?/s', substr($row, 0, 3000), $m, PREG_SET_ORDER);
check(isset($m[0], $m[1]) && 'yes' === $m[0][1] && 'no' === $m[0][2] && ' checked' === ($m[0][3] ?? '') && 'no' === $m[1][1] && 'yes' === $m[1][2],
  'in the editor the tick is on the left, stores No and is the one chosen for "No misspelled words." (the row now reads "has no spelling errors")');

check(false !== strpos($dsrc, "'mark'  => BFTD_Fields::mark_value( \$field, \$rid, (string) \$row['value'] ),")
  && false !== strpos($dsrc, "\$path = 'yes' === \$mark ?") && false !== strpos($dsrc, "conv-mark ' . esc_attr( \$mark )"),
  'and the family\'s report draws the green tick for it');

echo "\n";

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
