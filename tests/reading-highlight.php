<?php
/*
 * The reading level beside the student's name on a diagnostic (Karl, 1.170).
 *
 * One real result, in words, with a scale behind it and the student's own
 * grade marked on the same scale. It is read through the same rule as the
 * card, so the two cannot disagree, and it is not drawn until there is a
 * level to draw.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
class BFTD_CPT { public static function staff_ids($id) { return array(); } }
require BFTD_PATH . 'includes/class-bftd-report-view.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$h = BFTD_Schema::reading_highlight(array('score' => 'grade-1'), '3');
check('Grade 1' === $h['level'], 'the level is given in words, not as its stored key');
check('Preprimer' === $h['from'] && 'Grade 11' === $h['to'], 'on a scale from Preprimer to Grade 11');
check(23 === $h['pct'], 'at the same place the card bar fills to, got ' . $h['pct']);
$m = BFTD_Schema::measure('dxsec-3', array('score' => 'grade-1'));
check($h['pct'] === (int) round($m['value'] / $m['bench'] * 100), 'worked out the same way as the card');
check('Grade 3' === $h['own'] && 38 === $h['own_pct'], 'with the student\'s own grade marked, got ' . $h['own_pct']);

/* The ends of the scale. */
check(100 === BFTD_Schema::reading_highlight(array('score' => 'grade-11'))['pct'], 'Grade 11 is the end of the scale');
$c = BFTD_Schema::reading_highlight(array('score' => 'college'), '12');
check(100 === $c['pct'] && 'College' === $c['level'], 'College sits at the end of it and is still named');
check('Grade 12' === $c['own'] && 100 === $c['own_pct'], 'and so does Grade 12');

/* Who has no second mark. */
check('' === BFTD_Schema::reading_highlight(array('score' => 'grade-1'), 'adult')['own'], 'an adult learner has no grade to compare with');
check('' === BFTD_Schema::reading_highlight(array('score' => 'grade-1'), '')['own'], 'nor does a student with no grade set');
check('Kindergarten' === BFTD_Schema::reading_highlight(array('score' => 'primer'), '0')['own'], 'Kindergarten is marked at Primer');

/* Nothing to show. */
check(null === BFTD_Schema::reading_highlight(array('score' => ''), '3'), 'no level entered, no highlight');
check(null === BFTD_Schema::reading_highlight(array('score' => 'not a level'), '3'), 'nor for a value that is not a level');

/* What is drawn. */
$html = BFTD_Report_View::reading_highlight_html($h);
check(false !== strpos($html, 'Reading at <b>Grade 1</b>'), 'the headline names the level');
check(false !== strpos($html, 'Independent reading level'), 'and says which level it is');
check(false !== strpos($html, 'aria-label="Reading at Grade 1, on a scale from Preprimer to Grade 11. In Grade 3 at school."'), 'with the whole picture in words for a screen reader');
check(false !== strpos($html, 'hero-mark is-now" style="left:23%') && false !== strpos($html, 'hero-mark is-own" style="left:38%'), 'both marks are placed');
check(false !== strpos($html, 'In Grade 3 at school</p>'), 'and the second one is labelled');
$adult = BFTD_Report_View::reading_highlight_html(BFTD_Schema::reading_highlight(array('score' => 'grade-1'), 'adult'));
check(false === strpos($adult, 'is-own') && false === strpos($adult, 'at school'), 'no second mark means no label for one');
check('' === BFTD_Report_View::reading_highlight_html(null), 'and no highlight means nothing at all');

$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($view, "BFTD_Fields::get_section( \$report, 'dxsec-3' ),\n\t\t\tBFTD_Fields::get( \$student_id, 'student', 'grade' )"),
  'the diagnostic reads the level off the report and the grade off the student');
check(1 === substr_count($view, 'self::reading_highlight_html( $reading )'), 'and draws it once');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(1 === preg_match('/@media \(max-width:720px\)\{\s*\.bf-report \.hero-report\.has-level\{display:block\}/', $css), 'stacked under the name on a phone');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
