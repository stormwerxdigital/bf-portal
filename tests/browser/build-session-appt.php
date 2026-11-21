<?php
/*
 * A new session screen's Appointment box, drawn by the real
 * BFTD_Session_Appt::render(), with the real admin script. The server is a
 * stub: student 501 has two appointments, 502 has one, and 501 has a No show
 * marked on the Calendar for the first. Made-up names only.
 */
require dirname(__DIR__) . '/wp-stubs.php';
define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
if (!function_exists('add_action')) { function add_action(...$a) {} }
function get_post_meta($id, $k = '', $s = false) { return ''; }
function get_transient($k) { return false; }
function delete_transient($k) { return true; }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('selected')) { function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected'; } }
function get_userdata($id) { return (object) array('ID' => $id, 'display_name' => 'Rae Tutor'); }
class BFTD_CPT { const SESSION = 'bftd_session'; const PROGRESS = 'bftd_progress';
  public static function student_id($id) { return 0; } public static function report_for($s, $t) { return 0; } public static function sessions_for($r, $d = false) { return array(); } }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields { public static function get($id, $s, $k) { return ''; } }
class BFTD_Calendar_View { public static function tutors_for($u) { return array(5 => 'Rae Tutor', 6 => 'Sam Tutor'); } }
$T = BFTD_Time::today();
$A = BFTD_Time::add_days($T, -2); $B = BFTD_Time::add_days($T, 5);
class BFTD_Schedule {
  public static function occurrences($s, $f, $t, $l = 200) {
    global $A, $B;
    if (501 !== (int) $s) return array();
    return array(array('key' => "$A 10:00", 'date' => $A, 'time' => '10:00', 'minutes' => 50, 'tutor' => 5, 'status' => 'booked', 'type' => 'tutoring'),
      array('key' => "$B 10:00", 'date' => $B, 'time' => '10:00', 'minutes' => 50, 'tutor' => 5, 'status' => 'booked', 'type' => 'tutoring'));
  }
  public static function minutes_of($t) { $p = explode(':', $t); return $p[0] * 60 + $p[1]; }
  public static function pretty_time($t) { return $t; }
}
require BFTD_PATH . 'includes/class-bftd-session-appt.php';
$_GET = array('student' => 501);
ob_start();
BFTD_Session_Appt::render((object) array('ID' => 0, 'post_status' => 'auto-draft'));
$box = ob_get_clean();

$C = BFTD_Time::add_days($T, -1);
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
file_put_contents(__DIR__ . '/session-appt.html', '<!doctype html><html><head><meta charset="utf-8">'
  . '<style>body{font:14px/1.5 system-ui,sans-serif;margin:0;padding:20px}' . $css . '</style>'
  . '<script>' . file_get_contents(__DIR__ . '/jquery.js') . '</script>'
  . '<script>window.BFTD = { ajax_url: "/ajax", nonce: "n" }; jQuery.fn.sortable = function () { return this; }; window.__asked = [];'
  . 'jQuery.post = function (u, d) { window.__asked.push(d); var r = {};'
  . ' if (d.action === "bftd_session_appts") r = { appts: String(d.student_id) === "502" ? [ { key: "' . $C . ' 15:00", date: "' . $C . '", time: "15:00", label: "Sam\'s lesson", mine: false } ] : [] };'
  . ' if (d.action === "bftd_attendance_mark") r = { mark: (String(d.student_id) === "501" && d.date === "' . $A . '") ? { status: "noshow", label: "No show" } : null };'
  . ' return jQuery.Deferred().resolve({ success: true, data: r }).promise(); };</script>'
  . '</head><body class="wp-admin wp-core-ui post-new-php">'
  . '<select id="bftd_student" name="bftd_student"><option value="0">Choose a student</option><option value="501" selected>Pat Example</option><option value="502">Sam Other</option></select>'
  . $box
  . '<select id="bftd_session_status" name="bftd[session][status]"><option value="held">Attended</option><option value="missed">Missed</option><option value="noshow">No show</option></select>'
  . '<script>' . file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js') . '</script></body></html>');
echo __DIR__ . '/session-appt.html' . "\n";
