<?php
/*
 * Karl's second "new updates" doc (1.222.0): School year as years, the
 * alphabet, hearing sounds, reading level and writing wording, the alphabet
 * marking table, sounds notes ticked from a list, the grade read beside the
 * WCPM, one Writing conventions and grammar list with notes that are always
 * there, and Other after both strengths and areas for growth. Made-up values.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
$GLOBALS['META'] = array(); $GLOBALS['DELETED'] = array();
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ($s ? '' : array()); }
function delete_post_meta($id, $k) { $GLOBALS['DELETED'][] = $k; unset($GLOBALS['META'][$id][$k]); return true; }
if (!function_exists('checked')) { function checked($a, $b, $e = true) { $r = (string) $a === (string) $b ? ' checked' : ''; if ($e) echo $r; return $r; } }
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$src = function ($f) { return file_get_contents(BFTD_PATH . 'includes/' . $f); };

echo "School year:\n";
$mb = $src('class-bftd-metaboxes.php');
preg_match('/public static function school_year_options\( \$now = null \) \{(.*?)\n\t\}/s', $mb, $m);
$fn = function ($now) use ($m) { return eval(str_replace('$now', var_export($now, true), $m[1])); };
$o = $fn(gmmktime(12, 0, 0, 11, 27, 2026));
check('Not set' === $o[''] && '2024-25' === array_keys($o)[1] && '2026-27' === $o['2026-27'] && '2028-29' === array_key_last($o), 'school years, 2024-25 to two years ahead, got ' . implode(', ', array_keys($o)));
$o2 = $fn(gmmktime(12, 0, 0, 1, 5, 2029));
check('2031-32' === array_key_last($o2), 'and the list grows with the calendar');

echo "\nWriting the alphabet:\n";
$a = BFTD_Schema::section('dxsec-1');
$intro = BFTD_Schema::alphabet_intro();
check(0 === strpos($intro, '<p>In this one-minute check, your child writes the lowercase alphabet in order') && 3 === substr_count($intro, '<p>') && false !== strpos($intro, 'Spelling supports reading. Reading supports spelling.'), 'the new introduction, in three paragraphs');
$meth = BFTD_Schema::alphabet_method();
foreach (array('<h3>What practice looks like</h3>', '<h3>Measuring growth</h3>', '<h3>Why letter names show up here &#8212; and only here!</h3>', 'href="https://bftutoring.com/18-benefits-of-handwriting/"', 'Five minutes a day at home speeds this up considerably.') as $w) {
  check(false !== strpos($meth, $w), "How our method helps has: " . strip_tags(html_entity_decode($w)));
}
check(3 === substr_count($meth, '<li>'), 'and what practice looks like as three points');
$g = $a['fields']['marking']['groups'];
check(array('yes') === $g['correct']['marks'] && array('no') === $g['incorrect']['marks'], 'counted as correct is ticked, counted as incorrect is crossed');
$GLOBALS['META'][3] = array(BFTD_Schema::meta_key('dxsec-1', 'marking') => array('lowercase' => array('mark' => 'yes', 'note' => ''), 'reversals' => array('mark' => 'no', 'note' => 'b/d')));
ob_start(); BFTD_Fields::render_field(3, 'dxsec-1', 'marking', $a['fields']['marking']); $ed = ob_get_clean();
$row = function ($rid) use ($ed) { $p = strpos($ed, "[$rid][mark]"); $b = strrpos(substr($ed, 0, $p), 'bftd-crit-mark'); $e = strpos($ed, '</span>' . "\n\t\t\t\t\t\t\t\t<input", $p); return substr($ed, $b, ($e ?: $p + 2000) - $b); };
$lc = $row('lowercase'); $rv = $row('reversals');
check(false !== strpos($lc, 'value="yes" checked') && false === strpos($lc, 'value="no"') && false !== strpos($lc, '>N/A<'), 'a correct rule offers the tick and N/A, no cross');
check(false !== strpos($rv, 'value="no" checked') && false === strpos($rv, 'value="yes"') && false !== strpos($rv, '>N/A<'), 'an incorrect rule offers the cross and N/A, no tick');
check(false === strpos($ed, '&ndash;'), 'the dash reads N/A');
$GLOBALS['META'][4] = array(BFTD_Schema::meta_key('dxsec-1', 'marking') => array('capitals' => array('mark' => 'no', 'note' => 'old')));
ob_start(); BFTD_Fields::render_field(4, 'dxsec-1', 'marking', $a['fields']['marking']); $ed4 = ob_get_clean();
check(1 === preg_match('/\[capitals\]\[mark\]" value="no" checked/', $ed4), 'a cross saved before on a correct rule is still shown, not lost on the next save');

echo "\nHearing sounds in words:\n";
$h = BFTD_Schema::section('dxsec-2');
check(false !== strpos(BFTD_Schema::segmenting_intro(), 'which means breaking a spoken word into its individual sounds, or phonemes') && 4 === substr_count(BFTD_Schema::segmenting_intro(), '<p>'), 'the new introduction');
check(false !== strpos(BFTD_Schema::segmenting_method(), 'Difficulty with sound awareness is common') && false !== strpos(BFTD_Schema::segmenting_method(), 'Short-term memory holds only a few seconds'), 'and How our method helps');
$e = $h['fields']['errors_seen'] ?? null;
check($e && 'multi' === $e['type'] && 'Notes' === $e['heading'] && array('adds' => 'Adds a sound', 'omits' => 'Omits a sound', 'blends' => 'Blends two sounds into one', 'letter_sound' => 'Evidence of letter-sound confusion') === $e['options'], 'Notes are ticked from four choices');
check(!empty($h['fields']['body_after']['legacy']) && 'Notes' === $h['fields']['body_after']['heading'], 'the typed notes box is gone, kept only where a report already has some');
$k = array_keys($h['fields']);
check(array_search('errors_seen', $k) === array_search('body_after', $k) - 1, 'ticked notes first, then any typed before');
check(array('omits', 'letter_sound') === BFTD_Fields::sanitize_value(array('on' => '1', 'picked' => array('letter_sound', 'omits', 'made-up')), $e), 'saved in the list\'s order, and only its choices');
check(array() === BFTD_Fields::sanitize_value(array('on' => '1'), $e), 'ticking none saves none');
$GLOBALS['META'][5] = array(BFTD_Schema::meta_key('dxsec-2', 'errors_seen') => array('blends'));
ob_start(); BFTD_Fields::render_field(5, 'dxsec-2', 'errors_seen', $e); $he = ob_get_clean();
check(4 === substr_count($he, 'type="checkbox"') && 1 === preg_match('/value="blends" checked/', $he) && false !== strpos($he, 'name="bftd[dxsec-2][errors_seen][on]"'), 'the editor shows four tick boxes, and posts even with none ticked');
$db = $src('class-bftd-dashboard.php');
check(false !== strpos($db, "if ( 'multi' === \$type ) {") && false !== strpos($db, "\$items[] = '<li>' . esc_html( \$field['options'][ \$k ] ) . '</li>';"), 'the family\'s report lists what was ticked');
$rv = $src('class-bftd-report-view.php');
check(false !== strpos($rv, "if ( ! empty( \$field['heading'] ) && \$field['heading'] !== \$said ) {"), 'under one Notes heading, even with typed notes too');

echo "\nReading grade level and timed reading:\n";
check(false !== strpos(BFTD_Schema::wordreading_intro(), 'two or more grade levels below are considered'), 'are considered, not is');
$tr = BFTD_Schema::section('dxsec-4');
$tbl = BFTD_Schema::score_table_html('dxsec-4', array('form' => 'passage', 'words' => '36', 'errors' => '0', 'score' => '36', 'grade_read' => 'grade-3', 'norm' => 'grade-8'), 'passage_results');
check(false !== strpos($tbl, '36 WCPM, Grade 3'), 'the results table says the grade read beside the WCPM');
$tbl2 = BFTD_Schema::score_table_html('dxsec-4', array('form' => 'passage', 'words' => '36', 'errors' => '0', 'score' => '36', 'norm' => 'grade-8'), 'passage_results');
check(false !== strpos($tbl2, '36 WCPM<') && false === strpos($tbl2, '36 WCPM,'), 'and nothing extra when no grade was chosen');

echo "\nWriting:\n";
$w = BFTD_Schema::writing_intro();
check(0 === strpos($w, '<p>During this step of the diagnostic, the student was asked to write') && false !== strpos($w, '<p>Our goal is always to build confidence, not pressure.</p>') && false === strpos($w, 'only time a student'), 'the second version of the introduction');
$cv = BFTD_Schema::section('dxsec-6')['fields']['conventions'];
$ck = BFTD_Schema::meta_key('dxsec-6', 'conventions'); $gk = BFTD_Schema::meta_key('dxsec-6', 'grammar');
$GLOBALS['META'][6] = array($ck => array('indent' => array('value' => 'yes')), $gk => array('complete' => array('value' => 'no', 'note' => 'n'), 'vivid' => array('value' => 'yes'), 'order' => array('value' => 'yes')));
$v = BFTD_Fields::get(6, 'dxsec-6', 'conventions', $cv);
check(array('indent', 'complete', 'order') === array_keys($v) && 'n' === $v['complete']['note'], 'answers saved in the old grammar list are read into the one list; rows it no longer has are not');
$GLOBALS['META'][7] = array($ck => array('complete' => array('value' => 'yes')), $gk => array('complete' => array('value' => 'no')));
check('yes' === BFTD_Fields::get(7, 'dxsec-6', 'conventions', $cv)['complete']['value'], 'an answer in the one list wins over the old one');
$fs = $src('class-bftd-fields.php');
check(false !== strpos($fs, "if ( ! empty( \$field['merge_from'] ) ) delete_post_meta( \$post_id, BFTD_Schema::meta_key( \$section_id, \$field['merge_from'] ) );"), 'saving the report removes the old list, so a row set back to Not assessed stays that way');
$GLOBALS['META'][8] = array($ck => array('other_punct' => array('value' => 'no', 'note' => 'Two commas missing.')));
ob_start(); BFTD_Fields::render_field(8, 'dxsec-6', 'conventions', $cv); $we = ob_get_clean();
$op = substr($we, strpos($we, 'Other punctuation'), 2500);
check(false !== strpos($op, '<span class="bftd-cl-fixed">Punctuation errors (marked in RED)</span>') && false !== strpos($op, 'value="Two commas missing."') && false !== strpos($op, 'placeholder="Your note (optional)"'), 'the editor shows the note that is always there, and the tutor\'s own beside it');
$ms = substr($we, strpos($we, 'spelling errors'), 2500);
check(false !== strpos($ms, 'Misspelled words (highlighted in YELLOW)'), 'on the spelling row too');
check(false !== strpos($db, "'note'  => self::checklist_note( \$field, \$rid,") && false !== strpos($db, "return esc_html( \$fixed ) . ( '' !== trim( \$note ) ? '<br>' . esc_html( trim( \$note ) ) : '' );"), 'the report prints it, then the tutor\'s note on the next line');

echo "\nStrengths and areas for growth:\n";
$sf = BFTD_Schema::section('sec-strengths-areas-for-growth')['fields'];
check(empty($sf['strengths']['legacy']) && empty($sf['growth']['legacy']), 'Other is always offered for both');
check('Line' === $sf['strengths']['columns']['item']['label'] && 'Add a line' === $sf['strengths']['add_label'], 'as the instructional plan\'s Other is');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
