<?php
/*
 * The instructional plan as lines from a master list (Laurel, 1.165).
 *
 * Laurel's master list is shipped and edited under Settings, Report wording.
 * Each report ticks the lines that apply and adds its own under Other; the
 * report draws the two as one list. What a report stores is the wording of
 * the lines ticked, so rewording the master list later does not rewrite a
 * plan a family has already read.
 */
$GLOBALS['STATUS'] = array();
$GLOBALS['META']   = array();
function get_post_status($id) { return $GLOBALS['STATUS'][$id] ?? 'publish'; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ''; }
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

/* The practice's own list, where one has been saved. */
class BFTD_Settings {
  public static $text = array();
  public static function standing_text($key = null) { return null === $key ? self::$text : (self::$text[$key] ?? ''); }
}
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
class BFTD_CPT { public static function staff_ids($id) { return array(); } }
require BFTD_PATH . 'includes/class-bftd-report-view.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$sid = 'sec-instructional-plan';
$sec = BFTD_Schema::sections()[$sid];
$f   = $sec['fields'];

/* ---- the fields ---- */
check('ticklist' === ($f['lines']['type'] ?? ''), 'the plan is a tick list');
check('rows' === ($f['other']['type'] ?? '') && 'Other' === $f['other']['label'], 'with Other lines of the tutor\'s own');
check(array('lines', 'other', 'body', 'frequency', 'duration') === array_keys($f), 'above the free text, which stays for old reports and anything else');
check(array('lines', 'other') === ($sec['plan_list'] ?? null), 'and the report draws the two as one list');

/* ---- the master list ---- */
$lines = BFTD_Schema::master_lines($sid, 'lines');
check(14 === count($lines), 'Laurel\'s list is fourteen lines, got ' . count($lines));
check(0 === strpos($lines[0], 'Provide consistent EBLI-based instruction'), 'in her order');
check(4 === substr_count($lines[1], '<li>') && false !== strpos($lines[1], 'Reinforce the core elements'),
  'a line with points under it keeps them, got ' . $lines[1]);
check(false !== strpos($lines[1], '1–4 letters'), 'and its wording');

$text = BFTD_Schema::standing_text();
check(isset($text["$sid.lines"]) && false !== strpos($text["$sid.lines"]['shipped'], '<li>Explicit writing instruction.</li>'),
  'the list is edited with the rest of the report wording under Settings');
check('Instructional plan: Master list' === $text["$sid.lines"]['label'], 'under a name that says what it is');
check('' !== $text["$sid.lines"]['note'], 'with a note on how it works');

BFTD_Settings::$text["$sid.lines"] = '<ul><li>One</li><li><strong>Two</strong></li><li>  </li></ul>';
check(array('One', '<strong>Two</strong>') === BFTD_Schema::master_lines($sid, 'lines'), 'the practice\'s own list is used where one is saved');
BFTD_Settings::$text = array();

/* ---- what a save keeps ---- */
$field = $f['lines'];
$field['stored'] = '';
$posted = array('on' => '1', 'lines' => array($lines[2], '<script>x</script>Made up', $lines[0]));
$kept = BFTD_Fields::sanitize_value($posted, $field);
check(array($lines[2], $lines[0]) === $kept, 'a save keeps the lines ticked, in the order posted, and nothing posted that the list does not offer');
check(array() === BFTD_Fields::sanitize_value(array('on' => '1'), $field), 'unticking every line saves none');

$field['stored'] = array('An older wording of a line');
$kept = BFTD_Fields::sanitize_value(array('on' => '1', 'lines' => array('An older wording of a line')), $field);
check(array('An older wording of a line') === $kept, 'a line the report already had stays when the master list has since changed');

/* ---- the editor ---- */
$GLOBALS['STATUS'][501] = 'auto-draft';
ob_start(); BFTD_Fields::render_field(501, $sid, 'lines', $f['lines']); $new = ob_get_clean();
check(14 === substr_count($new, 'type="checkbox"') && 14 === substr_count($new, ' checked'), 'a new report starts with every line ticked');
check(false !== strpos($new, 'type="hidden" name="bftd[sec-instructional-plan][lines][on]"'), 'and always posts, so none ticked is saved as none');

ob_start(); BFTD_Fields::render_field(502, $sid, 'lines', $f['lines']); $old = ob_get_clean();
check(14 === substr_count($old, 'type="checkbox"') && 0 === substr_count($old, ' checked'),
  'a report saved before the list existed starts with none, so an approved plan does not grow lines');

$GLOBALS['META'][503][BFTD_Schema::meta_key($sid, 'lines')] = array($lines[3], 'An older wording of a line');
ob_start(); BFTD_Fields::render_field(503, $sid, 'lines', $f['lines']); $mid = ob_get_clean();
check(2 === substr_count($mid, ' checked') && 15 === substr_count($mid, 'type="checkbox"'), 'a saved report has its own lines ticked');
check(false !== strpos($mid, 'no longer on the master list'), 'with a line since dropped from the list still offered, and said so');

/* ---- the report ---- */
$html = BFTD_Report_View::plan_list(array(
  'lines' => array($lines[0], $lines[1]),
  'other' => array(array('item' => 'Weekly <reading> log'), array('item' => '')),
), $sec['plan_list']);
check(0 === strpos($html, '<ul class="plan-list"><li>Provide consistent'), 'the report lists the ticked lines');
check(false !== strpos($html, '<li>Weekly &lt;reading&gt; log</li></ul>'), 'then the tutor\'s own, escaped, blank ones left out');
check(1 === substr_count($html, '<ul class="plan-list">') && 2 === substr_count($html, '</li><li>Weekly') + substr_count($html, '</li><li>Reinforce'),
  'in one list of three');
check('' === BFTD_Report_View::plan_list(array('lines' => array(), 'other' => array()), $sec['plan_list']), 'and nothing at all is no list');
$rv = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($rv, 'if ( $planned ) echo self::plan_list( $values, $planned );')
  && false !== strpos($rv, '$paired = array_merge( $paired, $planned );'),
  'the section draws that list, and does not draw the two fields again under it');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
