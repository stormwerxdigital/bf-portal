<?php
/*
 * Laurel's October 31 feedback, the wording half (1.166).
 *
 * The overview and the recommended approach are the practice's standing
 * wording on every new diagnostic, changed only by a senior manager or an
 * administrator; the reading grade card's range runs to Grade 11.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$s = BFTD_Schema::sections();

foreach (array('sec-assessment-overview' => 'overview', 'sec-recommended-approach' => 'body') as $sid => $key) {
  $f = $s[$sid]['fields'][$key];
  check(!empty($f['shipped']), "$sid carries standing wording");
  check(3 === ($f['edit_rank'] ?? 0), 'that only a senior manager or an administrator may change');
  check(isset(BFTD_Schema::standing_text()["$sid.$key"]), 'and that is edited under Settings, Report wording');
}

$ov = BFTD_Schema::overview_text();
check(0 === strpos($ov, '<p>Our Reading Diagnostic Assessment sets a baseline across six core literacy areas. It shows'),
  'the overview starts as Laurel wrote it, without the stray sentence pasted into the middle');
foreach (array('Set realistic expectations', 'Why?', 'What this means', 'IEW (Institute for Excellence in Writing)', 'Audio Books + Eyes on Print') as $bit) {
  check(false !== strpos($ov, $bit), "it includes \"$bit\"");
}
$ap = BFTD_Schema::approach_text();
check(0 === strpos($ap, '<p>There is a clear starting place for literacy skills: instruction built on sound.</p>'), 'the approach starts as she wrote it');
check(4 === substr_count($ap, '<li>'), 'with its four points');
check(false === stripos($ap . $ov, 'lesson'), 'saying session rather than lesson');
check(false === strpos($ap . $ov, '—'), 'and no em dashes');

check('Preprimer to Grade 11' === $s['dxsec-3']['meter_note'], 'the reading grade card reads Preprimer to Grade 11');

/* ---- the rest of 1.166 that has no screen of its own to test ---- */
check(false !== strpos(BFTD_Fields::toolbars()['toolbar1'], ',fullscreen'), 'every note can open to the full window and back');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(false !== strpos($js, 'var DASH = String.fromCharCode( 8212 );') && false !== strpos($js, "'--' !== el.value.substr( at - 2, 2 )"),
  'two hyphens typed into a note become a dash, rich or plain');
check(1 === preg_match("#\\.bftd-gallery-prev' \\)\\.sortable\\(.*?data\\( 'id' \\).*?join\\( ',' \\)#s", $js),
  'work samples are dragged into order, and the order is what is saved');
check(false !== strpos($js, 'inst._isFloating = function () { return true; };'),
  'and the row of thumbnails is sorted across, not down: in a flex row jQuery UI would read it as a column and never move anything');
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php'), '<span class="bftd-thumb" data-id="'), 'each thumbnail knows its sample');

class BFTD_CPT { public static function staff_ids($id) { return array(); } }
require BFTD_PATH . 'includes/class-bftd-report-view.php';
$sp = $s['dxsec-5'];
check(1 === preg_match('#<span class="skill-also"><b>50</b> / 72 individual spellings correct</span>#',
  BFTD_Report_View::card_also($sp, array('spellings' => '50', 'spellings_of' => '72'))), 'the spelling card gives the individual spellings too');
check('' === BFTD_Report_View::card_also($sp, array('spellings' => '50', 'spellings_of' => '')), 'but not half a figure');
check('' === BFTD_Report_View::card_also($s['dxsec-1'], array('spellings' => '50', 'spellings_of' => '72')), 'and no other card does');

/* A morphology base is written in angle brackets, and must survive a save. */
check('<struct>' === BFTD_Fields::sanitize_value("  <struct>\n", array('type' => 'literal')), 'a matrix is kept as typed, angle brackets and all');
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php'), "'matrix' => array( 'type' => 'literal'"), 'and the matrix column is saved that way');
$cols = array('matrix' => array('type' => 'literal'));
check(array(array('matrix' => '<struct>'), array('matrix' => 'port')) === BFTD_Fields::sanitize_rows(array(array('matrix' => '<struct>'), array('matrix' => ' '), array('matrix' => 'port')), $cols),
  'a row holding only <struct> is kept, not taken for an empty row; a blank one is still dropped');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
