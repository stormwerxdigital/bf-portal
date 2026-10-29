<?php
/*
 * Strengths and weaknesses (Karl, 1.155): two lists of rows in the editor,
 * one table on the report, Strengths in column 1 and Weaknesses in column 2.
 * A report saved when they were bulleted editors reads its bullets as rows.
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
/* The title says what its two columns say (Laurel, 1.164). */
check('Strengths and weaknesses' === $sec['label'], 'the section is called Strengths and weaknesses, got ' . $sec['label']);
check(false === stripos(file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php'), 'growth areas'),
  'and the line introducing the full report calls it that too');
check('rows' === $f['strengths']['type'] && 'rows' === $f['growth']['type'], 'both are lists of rows');
check('Strengths' === $f['strengths']['label'] && 'Weaknesses' === $f['growth']['label'], 'called Strengths and Weaknesses');
check(array('strengths', 'growth') === $sec['pair_table'], 'Strengths first, Weaknesses second');
check('Add a strength' === $f['strengths']['add_label'] && 'Add a weakness' === $f['growth']['add_label'], 'each with its own add button');

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
  'strengths' => array(array('item' => 'Reads at Grade 9'), array('item' => 'Clear writing'), array('item' => '')),
  'growth'    => array(array('item' => 'Spelling <b>longer</b> words'), array('item' => 'Rate'), array('item' => 'Contractions')),
), $sec['pair_table']);
check(1 === preg_match('#<colgroup><col style="width:50%"><col style="width:50%"></colgroup><thead><tr><th>Strengths</th><th>Weaknesses</th></tr></thead>#', $html), 'two equal columns, headed Strengths then Weaknesses');
check(3 === substr_count($html, '<tr>') - 1, 'as many rows as the longer list');
check(false !== strpos($html, '<tr><td>Reads at Grade 9</td><td>Spelling &lt;b&gt;longer&lt;/b&gt; words</td></tr>'), 'paired row by row, escaped');
check(false !== strpos($html, '<tr><td></td><td>Contractions</td></tr>'), 'the shorter list leaves its cell blank');
$only = BFTD_Report_View::pair_table($sec, array('strengths' => array(), 'growth' => array(array('item' => 'Rate'))), $sec['pair_table']);
check(false === strpos($only, 'Strengths') && false !== strpos($only, 'Weaknesses'), 'an empty column is left out');
check('' === BFTD_Report_View::pair_table($sec, array(), $sec['pair_table']), 'and nothing at all is no table');
$rv = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($rv, 'if ( in_array( $key, $paired, true ) ) continue;'), 'and the two lists are not drawn again under it');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
