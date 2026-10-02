<?php
/*
 * Three layers of the same paragraph.
 *
 * The plugin ships wording. The practice changes it under Settings, once, for
 * every report. A tutor manager and above can still override it on one report
 * where a particular child needs something said differently.
 *
 * The rule that matters is which one wins and when, because getting it wrong
 * is invisible: a practice that reworded its explanation and saw it appear on
 * new reports only would have no way to tell that the old wording was still
 * going out on every report written before that afternoon.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['META'] = array(); $GLOBALS['OPT'] = array(); $GLOBALS['RANK'] = 4;
function get_post_meta($id,$k,$s=false){ return $GLOBALS['META'][$id][$k] ?? ($s?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function get_option($k,$d=false){ return $GLOBALS['OPT'][$k] ?? $d; }
function update_option($k,$v,$a=null){ $GLOBALS['OPT'][$k]=$v; return true; }
function get_current_user_id(){ return 7; }

class BFTD_Roles { public static function rank($u){ return $GLOBALS['RANK']; } public static function tiers(){ return array(); } }
class BFTD_Items { const PRIORITY='priority'; const REVIEW='review'; }
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

// Only the two methods the schema reaches for, so this test is about the
// layering rather than about the whole settings screen.
class BFTD_Settings {
  const OPTION_TEXT = 'bftd_standing_text';
  public static function standing_text($key = null){
    $all = get_option(self::OPTION_TEXT, array());
    $all = is_array($all) ? $all : array();
    if (null === $key) return $all;
    return isset($all[$key]) ? (string) $all[$key] : '';
  }
  public static function may_edit_text(){ return BFTD_Roles::rank(get_current_user_id()) >= 3; }
}

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$f       = BFTD_Schema::sections()['dxsec-1']['fields']['body'];
$shipped = $f['shipped'];
$key     = 'dxsec-1.body';
$meta    = BFTD_Schema::meta_key('dxsec-1', 'body');

/* ---- what the practice is offered ---- */
$items = BFTD_Schema::standing_text();
check(isset($items[$key]), 'the intro is offered as report wording');
check(isset($items['dxsec-1.method']), 'and so is the standing answer');
check(false !== strpos($items[$key]['label'], 'Writing the alphabet'),
  'each one says which section it belongs to');
check($items[$key]['shipped'] === $shipped, 'and carries what the plugin shipped');

/* The list comes from the schema, so wording can never be offered for a field
   no report reads. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-schema.php');
check(1 === preg_match('/function standing_text\(\)[\s\S]{0,300}foreach \( self::sections\(\)/', $src),
  'the list is built from the sections themselves');

/* ---- layer one: what the plugin shipped ---- */
$GLOBALS['OPT'] = array(); $GLOBALS['META'] = array();
check($shipped === BFTD_Fields::get(9, 'dxsec-1', 'body', $f), 'with nothing set anywhere, the shipped wording shows');

/* ---- layer two: the practice's own ---- */
$GLOBALS['OPT']['bftd_standing_text'] = array($key => '<p>How Brilliant Futures says it.</p>');
check('<p>How Brilliant Futures says it.</p>' === BFTD_Fields::get(9, 'dxsec-1', 'body', $f),
  'the practice\'s wording replaces it');

/* And on every report, not only ones made afterwards. This is the point of
   reading it through the setting rather than copying it into each report. */
$GLOBALS['META'][11][$meta] = '';
check('<p>How Brilliant Futures says it.</p>' === BFTD_Fields::get(11, 'dxsec-1', 'body', $f),
  'including reports written before the change');

/* ---- layer three: one report says it differently ---- */
$GLOBALS['META'][9][$meta] = '<p>Said differently for this child.</p>';
check('<p>Said differently for this child.</p>' === BFTD_Fields::get(9, 'dxsec-1', 'body', $f),
  'a report can still override it');
check('<p>How Brilliant Futures says it.</p>' === BFTD_Fields::get(11, 'dxsec-1', 'body', $f),
  'without touching any other report');

/* An override cleared falls back through the layers again. */
$GLOBALS['META'][9][$meta] = '<p></p>';
check('<p>How Brilliant Futures says it.</p>' === BFTD_Fields::get(9, 'dxsec-1', 'body', $f),
  'and clearing it goes back to the practice\'s wording');
$GLOBALS['OPT'] = array();
check($shipped === BFTD_Fields::get(9, 'dxsec-1', 'body', $f),
  'and clearing that goes back to what shipped');

$settings = file_get_contents(BFTD_PATH . 'includes/class-bftd-settings.php');

/* ---- nothing stores a copy of wording it did not write ---- */
/* Store the shipped text in the option and a later improvement reaches
   nobody. Store it in a report and it freezes there. */
check(1 === preg_match('/if \( trim\( \$val \) === trim\( \$item\[.shipped.\] \) \) continue;/', $settings),
  'wording saved back unchanged is not stored as the practice\'s own');
check(1 === preg_match('/if \( ! empty\( \$reset\[ \$key \] \) \) continue;/', $settings),
  'and there is a way back to what shipped');

$fields = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/if \( null !== \$fallback && \$after\[ \$key \] === \$fallback \)/', $fields),
  'and a report saved with the standing wording untouched stores nothing');

/* ---- who may change it ---- */
/* The same bar as the per-report field. A tutor manager who cannot reword one
   report must not be able to reword all of them from another screen. */
/* Read out of the real file rather than the stub above: a stub that agrees
   with the code it is standing in for proves nothing, and this project has
   already shipped two tests that passed for exactly that reason. */
preg_match('/function may_edit_text\(\)[\s\S]{0,160}?>=\s*(\d+)/', $settings, $bar);
check(isset($bar[1]), 'the real bar is a rank comparison');
$bar = isset($bar[1]) ? (int) $bar[1] : -1;
check(3 === $bar, 'set at senior manager, the same bar the per-report field uses, got ' . $bar);

$per_report = BFTD_Schema::sections()['dxsec-1']['fields']['body']['edit_rank'];
check($per_report === $bar,
  'so a tutor manager who cannot reword one report cannot reword all of them from another screen');

/* The save refuses as well as the screen, because a screen is not a lock. */
check(1 === preg_match('/function save_reports\(\)[\s\S]{0,300}if \( ! self::may_edit_text\(\) \)[\s\S]{0,200}return false;/', $settings),
  'the save refuses it too, not only the screen');
check(1 === preg_match('/case .reports.: if \( ! self::save_reports\(\) \) return; break;/', $settings),
  'and a refused save does not report itself as saved');

/* ---- it is findable ---- */
check(1 === preg_match("/'reports' *=> *'Report wording'/", $settings), 'there is a tab for it');
check(false !== strpos($settings, 'including ones already written'),
  'which says plainly that a change reaches reports already written');
check(1 === preg_match('/function save_reports[\s\S]{0,1800}self::log_change\( .Report wording./', $settings),
  'and every change is written to the activity log');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
