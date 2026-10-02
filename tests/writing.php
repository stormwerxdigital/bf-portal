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

/* ---- two fixed lists ---- */
foreach (array('conventions' => 8, 'grammar' => 8) as $key => $n) {
  check('checklist' === $f[$key]['type'], "$key is a fixed list");
  check($n === count($f[$key]['rows']), "with $n rows nobody retypes");
  foreach (array('' => 'Not assessed', 'yes' => 'Yes', 'no' => 'No', 'na' => 'N/A') as $ov => $ol) {
    check(isset($f[$key]['options'][$ov]) && $ol === $f[$key]['options'][$ov],
      "answered $ol, and nothing else: $key");
  }
  check(4 === count($f[$key]['options']), "four answers and no more: $key");
}
check('Grammar and word usage are correct' === $f['grammar']['title'],
  'the second list keeps the heading it had when it was a divider inside the first');

/* The wording of a convention carries emphasis, and it has to survive into
   both the report and the screen a tutor answers it on. */
check(false !== strpos($f['conventions']['rows']['caps_start'], '<strong>capital letters</strong>'),
  'a convention keeps the emphasis the design gives it');
$fields_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/bftd-cl-item[^>]*>.{0,40}wp_kses_post\( \$label \)/', $fields_src),
  'and the editor renders it rather than printing the tags');

/* ---- the card counts the lists ---- */
check(!isset($s['benchmark']), 'the section states no goal of its own');
check('score_of' === $s['benchmark_field'], 'it measures against how many were answered');
check(isset($f['score']['derived']['count']), 'the figure is counted, not typed');
check(array('conventions', 'grammar') === $f['score']['derived']['count']['fields'],
  'across both lists');
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
  array('flow' => array('value' => 'yes'))
), 'two of three answered were yes');

/* A row nobody assessed is in neither figure, so a part-finished section is
   not a child scored on questions nobody asked. */
check(array('1', '2') === $put(
  array('indent' => array('value' => 'yes'), 'spaces' => array('value' => '', 'note' => 'x')),
  array('flow' => array('value' => 'na'))
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
check(array_search('grammar', $k, true) < array_search('images', $k, true),
  'which come after both lists');
check('Notes' === $f['body_after']['heading'] && 'Notes' === $f['body_after']['label'],
  'the notes are called Notes on both sides');
check('method' === end($k), 'and the standing answer closes the section');

/* ---- the standing wording ---- */
foreach (array('body' => 'Intro', 'method' => 'How the method addresses this') as $key => $label) {
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
check(false !== strpos(BFTD_Schema::writing_intro(), 'The student was encouraged'),
  'so this one says the student');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
