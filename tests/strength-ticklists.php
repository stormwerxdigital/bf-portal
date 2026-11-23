<?php
/*
 * Strengths and Areas for growth tick lists (Karl, 1.215, "NEW Portal
 * Updates"): "We want all the strengths and weaknesses to be shown to start,
 * check box style. Then they check off what is observed." Every item under
 * its heading, tutor guidance the family never sees, Pick one groups, and
 * the list edited under Settings. Replaces the 1.207 pick-list test.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
$META = array(); $OPT_TEXT = array();
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ''; } }
if (!function_exists('get_post_status')) { function get_post_status($id) { return 'draft'; } }
if (!function_exists('checked')) { function checked($a, $b = true) { if ((bool) $a === (bool) $b) echo ' checked'; } }
class BFTD_Settings { public static function standing_text($k = null) { return $GLOBALS['OPT_TEXT'][$k] ?? ''; } }
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$S = 'sec-strengths-areas-for-growth';
$f = BFTD_Schema::sections()[$S]['fields'];

echo "The lists, from the document:\n";
$st = BFTD_Picks::catalog($S, 'strengths_picks');
$gr = BFTD_Picks::catalog($S, 'growth_picks');
check(array('Handwriting', 'Phonemic Awareness', 'Word Reading Level (San Diego Quick Assessment)', 'Reading Fluency', 'Spelling', 'Writing', 'Personal') === array_column($st, 'heading'), 'Strengths under its seven headings');
check(43 === array_sum(array_map(function ($h) { return count($h['items']); }, $st)), 'every strength, 43 of them');
check(8 === count($gr) && 'Reading Accuracy' === $gr[3]['heading'], 'Areas for growth under its eight headings, Reading Accuracy among them');
check(51 === array_sum(array_map(function ($h) { return count($h['items']); }, $gr)), 'every area for growth, 51 of them');
$hw = $st[0]['items'];
check('Handwriting automaticity exceeds benchmark' === $hw[0]['text'] && 'Timed letter-writing task above benchmark.' === $hw[0]['guide'], 'each with what the family reads, and the tutor guidance apart from it');
check('Reading word endings — e.g., <ed>, <tion>, <ous>, <ate>' === $gr[3]['items'][2]['text'], 'angle brackets read as written');

echo "\nPick one:\n";
$pa = $st[1]['items'];
check($pa[0]['group'] && $pa[0]['group'] === $pa[1]['group'] && $pa[1]['group'] === $pa[2]['group'] && 0 === $pa[3]['group'], 'the first three phonemic items are one group, the blends item is not');
$sp = $st[4]['items'];
check($sp[0]['group'] === $sp[1]['group'] && $sp[7]['group'] === $sp[8]['group'] && $sp[0]['group'] !== $sp[7]['group'], 'two separate groups under Spelling: CVC accuracy, and attempts');
$field = $f['strengths_picks']; $field['stored'] = array();
$saved = BFTD_Picks::sanitize(array('on' => 1, 'items' => array($pa[1]['text'], $pa[0]['text'], 'Polite and respectful', 'Something made up')), $field);
check(array(array('h' => 'Phonemic Awareness', 't' => $pa[0]['text']), array('h' => 'Personal', 't' => 'Polite and respectful')) === $saved,
  'one per group is kept, in the list\'s order, and nothing the list does not offer');
check(array() === BFTD_Picks::sanitize(array('on' => 1), $field), 'unticking everything saves none');

echo "\nEdited under Settings:\n";
$keys = array_keys(BFTD_Schema::standing_text());
check(in_array($S . '.strengths_picks', $keys, true) && in_array($S . '.growth_picks', $keys, true), 'both lists are under Settings, Report wording');
check(!in_array($S . '.strengths', $keys, true) && !in_array($S . '.growth', $keys, true), 'and the 1.207 pick lists are not');
$OPT_TEXT[$S . '.strengths_picks'] = '<ul><li>Reading<ul><li>Reads with joy | Only if they smiled</li><li>Reads aloud | Pick one.</li><li>Reads silently | Pick one.</li></ul></li></ul>';
$mine = BFTD_Picks::catalog($S, 'strengths_picks');
check(1 === count($mine) && 'Reading' === $mine[0]['heading'] && 'Only if they smiled' === $mine[0]['items'][0]['guide'] && $mine[0]['items'][1]['group'] === $mine[0]['items'][2]['group'] && $mine[0]['items'][1]['group'],
  'the practice\'s own list is used once saved: headings, guidance and Pick one groups from the bullets');
$field['stored'] = $saved;
$kept = BFTD_Picks::sanitize(array('on' => 1, 'items' => array('Polite and respectful', 'Reads aloud')), $field);
check(array(array('h' => 'Reading', 't' => 'Reads aloud'), array('h' => 'Personal', 't' => 'Polite and respectful')) === $kept, 'an item ticked before the list changed is kept while it stays ticked');
unset($OPT_TEXT[$S . '.strengths_picks']);

echo "\nThe edit screen:\n";
ob_start(); BFTD_Picks::render(7, $S, 'strengths_picks', $f['strengths_picks']); $h = ob_get_clean();
check(43 === substr_count($h, 'type="checkbox"') && false !== strpos($h, '>Handwriting</th>') && false !== strpos($h, 'Tutor guidance'), 'every item shown, with its heading and guidance');
check(false !== strpos($h, 'The goal is 6-8 strengths.') && false !== strpos($h, 'data-goal="6 to 8"'), 'with the tutor instructions and the goal');
check(false !== strpos($h, 'name="bftd[' . $S . '][strengths_picks][on]"'), 'and always posts, so unticking all of them saves');
check(false === strpos(strtolower($f['strengths']['help'] ?? ''), 'pick from'), 'the older rows no longer offer the 1.207 pick list');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(false !== strpos($js, "if ( g && this.checked ) \$box.find( 'input[data-group=\"' + g + '\"]' ).not( this ).prop( 'checked', false );"), 'ticking a Pick one item unticks the rest of its group');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
