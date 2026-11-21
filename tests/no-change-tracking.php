<?php
/*
 * No change tracking for telling families (Karl, 1.207.2: "we are no longer
 * tracking changes for notifications for students or reports"). Saving a
 * student, report or session flags nothing, and the edit screen shows no
 * "Nothing has gone to the family... changes are saved here and waiting"
 * notice and no review bar, even for changes flagged before.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
$META = array(); $LOG = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { global $META; unset($META[$id][$k]); return true; }
function get_post_status($id) { return 'publish'; }
function current_user_can($c, $id = 0) { return true; }
function wp_strip_all_tags($s) { return strip_tags($s); }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars($s); } }
class BFTD_CPT { const STUDENT = 'bftd_student'; const ASSESSMENT = 'bftd_assessment'; const PROGRESS = 'bftd_progress'; const SESSION = 'bftd_session';
  public static function student_id($id) { return $id; } }
class BFTD_Audit { public static function log($e, $a) { global $LOG; $LOG[] = $e; } }
require BFTD_PATH . 'includes/class-bftd-change-notify.php';
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

check(false === BFTD_Change_Notify::tracking(), 'changes are not tracked for telling families');

foreach (array('report', 'lesson', 'items', 'messages') as $t) BFTD_Change_Notify::flag(7, $t, 'Something changed');
check(array() === BFTD_Change_Notify::pending(7), 'a save flags nothing, whatever kind of change');
check(!in_array('change_flagged', $LOG, true), 'and logs no change as waiting');

// Changes flagged before 1.207.2, on a draft and on a published record.
$META[9]['_bftd_pending_report'] = array(array('summary' => 'Old change', 'at' => '2026-11-01 10:00:00'));
ob_start(); BFTD_Change_Notify::render_bar((object) array('ID' => 9, 'post_type' => 'bftd_student')); $bar = ob_get_clean();
check('' === $bar, 'the edit screen shows no waiting notice or review bar, even for older flags');

$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-change-notify.php');
check(false !== strpos($src, "public static function render_bar( \$post ) {\n\t\tif ( ! self::tracking() ) return;"), 'the bar checks first, before anything is drawn');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
