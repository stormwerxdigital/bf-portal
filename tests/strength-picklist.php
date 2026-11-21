<?php
/*
 * Strengths and Areas for growth pick lists (Karl, 1.207). Each row picks
 * from a list or takes the tutor's own words. The lists start as the demo
 * report's lines, made general, and are edited under Settings, Report
 * wording, beside the instructional plan's master list.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
class BFTD_Settings { public static $own = array();
  public static function standing_text($k = null) { return null === $k ? self::$own : (self::$own[$k] ?? ''); } }
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$sid = 'sec-strengths-areas-for-growth';
$f = BFTD_Schema::sections()[$sid]['fields'];

/* the starting lists */
$s = BFTD_Fields::pick_choices($sid, 'strengths');
$g = BFTD_Fields::pick_choices($sid, 'growth');
check(4 === count($s) && 'Cooperative, willing and fully engaged throughout the assessment' === $s[0], 'Strengths starts with the demo report\'s 4 lines');
check(5 === count($g) && 'Phonemic awareness, hearing individual sounds in sequence' === $g[0], 'Areas for growth starts with its 5 lines');
check(false === strpos(implode(' ', $s), ' he ') && false === strpos(implode(' ', $g), '20 of 36') && false === strpos(implode(' ', $g), 'Preprimer'),
  'with no demo student\'s pronoun or scores left in them');

/* edited under Settings, Report wording */
$st = BFTD_Schema::standing_text();
check(isset($st["$sid.strengths"], $st["$sid.growth"]), 'both lists are edited under Settings, Report wording');
check('Strengths and areas for growth: Strengths list' === $st["$sid.strengths"]['label'], 'named so they can be told apart there');
check(isset($st['sec-instructional-plan.lines']), 'beside the instructional plan\'s master list, which is still there');

/* the practice's own list wins */
BFTD_Settings::$own["$sid.strengths"] = '<ul><li>Reads with &amp; expression</li><li>Asks <strong>good</strong> questions</li><li>Asks good questions</li></ul>';
check(array('Reads with & expression', 'Asks good questions') === BFTD_Fields::pick_choices($sid, 'strengths'), 'a saved list replaces the starting one, as plain text, without repeats');
check(5 === count(BFTD_Fields::pick_choices($sid, 'growth')), 'and leaves the other list alone');

/* the editor: pick or type */
check(!empty($f['strengths']['columns']['item']['pick']) && !empty($f['growth']['columns']['item']['pick']), 'both columns pick from their list');
check('text' === $f['strengths']['columns']['item']['type'], 'and are still text, so a tutor can type their own');
$m = new ReflectionMethod('BFTD_Fields', 'render_rows'); $m->setAccessible(true);
ob_start(); $m->invoke(null, $sid, 'strengths', $f['strengths'], array(array('item' => 'My own words'))); $html = ob_get_clean();
check(1 === substr_count($html, '<datalist id="bftd-pick-sec-strengths-areas-for-growth-strengths">'), 'one list per table');
check(false !== strpos($html, '<option value="Reads with &amp; expression"></option>'), 'offering the saved choices, escaped');
check(2 === substr_count($html, 'list="bftd-pick-sec-strengths-areas-for-growth-strengths"'), 'tied to the saved row and to the template a new row is made from');
check(false !== strpos($html, 'value="My own words"'), 'a row in the tutor\'s own words stays as it was');
ob_start(); $m->invoke(null, 'sec-instructional-plan', 'other', BFTD_Schema::sections()['sec-instructional-plan']['fields']['other'], array()); $plain = ob_get_clean();
check(false === strpos($plain, '<datalist') && false === strpos($plain, ' list='), 'a rows table that does not pick has no list');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
