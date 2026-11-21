<?php
/*
 * Each staff member's Calendar colour can be set on their profile (Karl,
 * 1.209.1: "Laurels color is purple, Candace will be green", then "We need to
 * be able to set the color per staff member for Sr Managers and Admins").
 * A set colour wins over the colour for their place in the tutor list; only a
 * senior manager or administrator sees the field or can save it.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$UMETA = array(); $ADMIN = true; $STAFF = array(11 => true, 12 => true, 13 => true, 40 => false);
if (!function_exists('add_action')) { function add_action(...$a) {} }
function get_user_meta($id, $k, $s = false) { global $UMETA; return $UMETA[$id][$k] ?? ''; }
function update_user_meta($id, $k, $v) { global $UMETA; $UMETA[$id][$k] = $v; return true; }
function delete_user_meta($id, $k) { global $UMETA; unset($UMETA[$id][$k]); return true; }
if (!function_exists('current_user_can')) { function current_user_can($c, $id = 0) { return true; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return $s; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s); } }
if (!function_exists('selected')) { function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected'; } }
class BFTD_Roles {
  public static function can_administer($u = null) { return $GLOBALS['ADMIN']; }
  public static function is_staff($u = null) { return !empty($GLOBALS['STAFF'][$u]); }
}
require BFTD_PATH . 'includes/class-bftd-calendar-view.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$save = function ($uid, $v) { $_POST = array('bftd_calendar_colour' => $v); BFTD_Calendar_View::save_colour($uid); };

// Candice 11, Karl 12, Laurel 13, in the side panel's order.
$order = array(11, 12, 13);
check(array(11 => 0, 12 => 1, 13 => 2) === BFTD_Calendar_View::colour_map($order), 'with nothing set, colours go by place in the list, as before');

echo "\nA senior manager or administrator sets them:\n";
$save(13, '0'); $save(11, '2');
check(array(11 => 2, 12 => 1, 13 => 0) === BFTD_Calendar_View::colour_map($order), 'Laurel purple, Candice green, the rest by place');
$save(11, '');
check(!isset($UMETA[11]['bftd_calendar_colour']), 'choosing "By place in the tutor list" clears it');
$save(12, '9'); $save(12, 'red');
check(!isset($UMETA[12]['bftd_calendar_colour']), 'a colour that does not exist is not saved');
$save(40, '3');
check(!isset($UMETA[40]['bftd_calendar_colour']), 'nor one for someone who is not staff');
$UMETA[12]['bftd_calendar_colour'] = 'junk';
check(1 === BFTD_Calendar_View::colour_map($order)[12], 'a bad stored value falls back to place in the list');
unset($UMETA[12]);

ob_start(); BFTD_Calendar_View::colour_field((object) array('ID' => 13)); $f = ob_get_clean();
check(false !== strpos($f, 'Calendar colour') && 8 === substr_count($f, '<option value="') - 1, 'the profile shows Calendar colour with the eight colours');
check(1 === preg_match('#<option value="0" selected>Purple#', $f), 'with the one set picked');

echo "\nAnyone else cannot:\n";
$ADMIN = false;
$save(13, '5');
check(0 === $UMETA[13]['bftd_calendar_colour'], 'a tutor or manager saving a profile leaves the colour as it was');
ob_start(); BFTD_Calendar_View::colour_field((object) array('ID' => 13)); $f = ob_get_clean();
check('' === $f, 'and is not shown the field');

echo "\nThe Calendar uses them:\n";
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
check(false !== strpos($src, "'colours' => self::colour_map( array_keys( \$tutors ) ),"), 'bookings are coloured from the map');
check(false !== strpos($src, 'data-t="<?php echo (int) ( $config[\'colours\'][ (int) $uid ] ?? 0 ); ?>"'), 'and so is each swatch, so the two always match');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
