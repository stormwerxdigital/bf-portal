<?php
/*
 * Strengths and areas for growth on the report. Since 1.215 they come from
 * tick lists (Karl's "NEW Portal Updates" document): each column the items
 * ticked, under the headings they belong to, then anything typed on the
 * report before the lists, so an older report still says what it said.
 * A report saved when they were bulleted editors (before 1.155) reads its
 * bullets as rows.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$sec = BFTD_Schema::sections()['sec-strengths-areas-for-growth'];
$f = $sec['fields'];
/* Strengths and areas for growth (Laurel, 1.166: "weak" can sting). */
check('Strengths and areas for growth' === $sec['label'], 'the section is called Strengths and areas for growth, got ' . $sec['label']);
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php'), "strengths and
			areas for growth, the recommended"),
  'and the line introducing the full report calls it that too');
check('picks' === $f['strengths_picks']['type'] && 'picks' === $f['growth_picks']['type'], 'both are tick lists');
check('Strengths' === $f['strengths_picks']['label'] && 'Areas for growth' === $f['growth_picks']['label'], 'called Strengths and Areas for growth (Laurel, 1.172)');
check(array('strengths_picks', 'growth_picks') === $sec['pair_table'], 'Strengths first, Areas for growth second');
check('strengths' === $f['strengths_picks']['legacy_of'] && 'growth' === $f['growth_picks']['legacy_of'] && empty($f['strengths']['legacy']) && empty($f['growth']['legacy']),
  'what was typed before the lists is kept, as Other, which is always offered now (1.222.0)');
$k = array_keys($f);
check(array('strengths_picks', 'strengths', 'growth_picks', 'growth') === array_values(array_intersect($k, array('strengths_picks', 'strengths', 'growth_picks', 'growth'))) && 'Other strengths' === $f['strengths']['label'] && 'Other areas for growth' === $f['growth']['label'],
  'each Other comes straight after its own list');
check(!isset($f['strengths']['master']) && !isset($f['growth']['master']), 'and the 1.207 pick lists are gone, so Settings offers only the tick lists');

/* old bulleted lists read as rows */
$rows = BFTD_Fields::list_to_rows('<ul><li>handwriting automaticity:&nbsp; 52 letters</li><li> Reads at <strong>Grade 9</strong> </li><li></li></ul>', 'item');
check(array(array('item' => 'handwriting automaticity: 52 letters'), array('item' => 'Reads at Grade 9')) === $rows, 'bullets become rows, plain text, empty ones dropped');
check(array(array('item' => 'One'), array('item' => 'Two')) === BFTD_Fields::list_to_rows("<p>One</p><p>Two</p>", 'item'), 'paragraphs too');
check(array() === BFTD_Fields::list_to_rows('<p></p>', 'item'), 'an empty editor is no rows');
$GLOBALS['META'][5] = array(BFTD_Schema::meta_key('sec-strengths-areas-for-growth', 'growth') => '<ul><li>Spelling</li></ul>');
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ''; } }
check(array(array('item' => 'Spelling')) === BFTD_Fields::get(5, 'sec-strengths-areas-for-growth', 'growth', $f['growth']), 'a report saved before reads its old list as rows');

/* the report table */
class BFTD_CPT { public static function staff_ids($id) { return array(); } }
require BFTD_PATH . 'includes/class-bftd-report-view.php';
$html = BFTD_Report_View::pair_table($sec, array(
  'strengths_picks' => array(array('h' => 'Handwriting', 't' => 'Prints all letters in lowercase'), array('h' => 'Personal', 't' => 'Polite and respectful'), array('h' => 'Personal', 't' => 'Positive, upbeat attitude')),
  'growth_picks'    => array(array('h' => 'Spelling', 't' => 'Spelling word endings — e.g., <ed>, <tion>')),
  'growth'          => array(array('item' => 'Typed <b>before</b>'), array('item' => '')),
), $sec['pair_table']);
check(1 === preg_match('#<colgroup><col style="width:50%"><col style="width:50%"></colgroup><thead><tr><th>Strengths</th><th>Areas for growth</th></tr></thead>#', $html), 'two equal columns, headed Strengths then Areas for growth');
check(false !== strpos($html, '<p class="pick-h"><strong>Handwriting</strong></p><ul class="pick-list"><li>Prints all letters in lowercase</li></ul><p class="pick-h"><strong>Personal</strong></p><ul class="pick-list"><li>Polite and respectful</li><li>Positive, upbeat attitude</li></ul>'),
  'the ticked items under their headings, each heading once');
check(false !== strpos($html, '<li>Spelling word endings — e.g., &lt;ed&gt;, &lt;tion&gt;</li>') && false !== strpos($html, '<li>Typed &lt;b&gt;before&lt;/b&gt;</li>'), 'escaped, with what was typed before the lists after them');
check(false === strpos($html, 'Reading Fluency'), 'a heading with nothing ticked under it is not shown');
$only = BFTD_Report_View::pair_table($sec, array('strengths_picks' => array(), 'growth' => array(array('item' => 'Rate'))), $sec['pair_table']);
check(false === strpos($only, '<th>Strengths</th>') && false !== strpos($only, '<li>Rate</li>'), 'an empty column is left out, and an older report still shows what it typed');
check('' === BFTD_Report_View::pair_table($sec, array(), $sec['pair_table']), 'and nothing at all is no table');
$rv = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($rv, 'if ( in_array( $key, $paired, true ) ) continue;') && false !== strpos($rv, "\$paired[] = \$section['fields'][ \$pk ]['legacy_of'];"), 'and neither the lists nor the older rows are drawn again under it');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
