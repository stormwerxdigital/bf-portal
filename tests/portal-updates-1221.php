<?php
/*
 * Karl's "NEW Portal Updates" doc (1.221.0): the Kindergarten word list card,
 * the grade level read on a timed passage, the Reading grade level and
 * Writing introductions, Run-on sentences and the Conclusion section taken
 * out, and School year on the student record. Made-up values only.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
class BFTD_Fields { public static function has_value($v){ return '' !== trim((string) $v); } }
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$tr = BFTD_Schema::section('dxsec-4');

echo "Timed reading card, by assessment:\n";
$k = BFTD_Schema::card_section('dxsec-4', $tr, array('form' => 'kinder', 'kinder_score' => '13'));
check('Kindergarten word list, easyCBM' === $k['summary'], 'the word list says Kindergarten word list, easyCBM, got ' . $k['summary']);
check('words per minute' === $k['unit'], 'and its figure is words per minute, not correct words per minute');
$k2 = BFTD_Schema::card_section('dxsec-4', $tr, array('kinder_score' => '13'));
check('Kindergarten word list, easyCBM' === $k2['summary'], 'also on a report from before the choice, read from what was filled in');
$p = BFTD_Schema::card_section('dxsec-4', $tr, array('form' => 'passage', 'words' => '55', 'errors' => '3', 'grade_read' => 'grade-3'));
check('Grade 3 passage, easyCBM' === $p['summary'] && 'correct words per minute' === $p['unit'], 'a passage says the grade read under the title, got ' . $p['summary']);
$p2 = BFTD_Schema::card_section('dxsec-4', $tr, array('form' => 'passage', 'words' => '55'));
check('Oral reading fluency, easyCBM' === $p2['summary'], 'with no grade chosen it reads as before');
$rv = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(1 === preg_match('/get_section\( \$report, \$id \);\s*\$section = BFTD_Schema::card_section\( \$id, \$section, \$v \);/', $rv), 'the family\'s card is drawn with it');
$o = BFTD_Schema::section('dxsec-3');
check($o === BFTD_Schema::card_section('dxsec-3', $o, array()), 'other sections are untouched');

echo "\nGrade level read:\n";
$f = $tr['fields']['grade_read'] ?? null;
check($f && 'select' === $f['type'] && 'passage' === $f['form'] && 'Grade level read' === $f['label'], 'a dropdown on the timed passage');
$norm = array_keys(BFTD_Schema::fluency_norm_options());
check(array_keys($f['options']) === $norm, 'with the same grades as the end of year norm');
check('Grade 3' === $f['options']['grade-3'] && 'Kindergarten' === $f['options']['k'], 'named without the words per minute figure');
$keys = array_keys($tr['fields']);
check(array_search('grade_read', $keys) === array_search('form', $keys) + 1 && array_search('grade_read', $keys) < array_search('words', $keys), 'between Which assessment and Total words read');

echo "\nIntroductions:\n";
$rl = BFTD_Schema::wordreading_intro();
check(false !== strpos($rl, 'One grade level below a student&#8217;s enrolled grade is considered <em>below grade level</em> and two or more grade levels below are considered <em>well below grade level</em>.'), 'Reading grade level adds below and well below grade level');
check(0 === strpos($rl, '<p>The San Diego Quick Assessment is used'), 'after the sentence it had');
$w = BFTD_Schema::writing_intro();
check(false !== strpos($w, 'If they were hesitant, the assessor helped them choose a topic') && false !== strpos($w, 'Our goal is always to build confidence, not pressure.'), 'Writing has Karl\'s wording (1.222.0)');
check(false === strpos($w, 'We chose the topic together'), 'and not the old');

echo "\nTaken out:\n";
$g = BFTD_Schema::section('dxsec-6')['fields']['conventions']['rows'];
check(!isset($g['run_on']) && isset($g['complete']), 'Run-on sentences is not a row of its own');
check(null === BFTD_Schema::section('sec-conclusion'), 'the Conclusion section is gone');
check(false === strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-sample.php'), 'sec-conclusion'), 'from the sample report too');

echo "\nSchool year on the student record:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "'school_year'  => array( 'type' => 'select', 'label' => 'School year', 'options' => self::school_year_options() )"), 'a School year dropdown (its choices are years since 1.222.0, see portal-updates-1222.php)');
check(false !== strpos($mb, "return array( 'grade', 'school_year', 'mode' );"), 'drawn between Grade and Sessions are');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
