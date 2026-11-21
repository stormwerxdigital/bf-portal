<?php
/*
 * The Calendar screen, drawn by the real render(), with the real script and
 * styles, and the server answered by a stub: one draft session a manager can
 * edit. Made-up names only.
 */
require dirname(__DIR__) . '/wp-stubs.php';
define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');

function get_current_user_id() { return 1; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function selected($a, $b) { if ((string) $a === (string) $b) echo ' selected'; }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!function_exists('get_user_meta')) { function get_user_meta($id, $k = '', $s = false) { return ''; } }
function get_userdata($id) { return (object) array('ID' => $id, 'display_name' => 'Test Tutor'); }

class BFTD_Roles {
  const STAFF_CAP = 'read';
  public static function is_staff($u = 0) { return true; }
  public static function can_manage($u = 0) { return true; }
  public static function staff_users() { return array((object) array('ID' => 1, 'display_name' => 'Test Tutor'), (object) array('ID' => 2, 'display_name' => 'Other Tutor')); }
}
class BFTD_CPT { const STUDENT = 'bftd_student'; }
if (!function_exists('get_posts')) { function get_posts($a) { return array(501); } }
if (!function_exists('user_can')) { function user_can($u, $c, $id = 0) { return true; } }
if (!function_exists('get_post_field')) { function get_post_field($f, $id) { return 'Pat Example'; } }
if (!function_exists('get_post_status')) { function get_post_status($id) { return 'publish'; } }
class BFTD_Schedule {
  public static function types() { return array('tutoring' => 'Tutoring session', 'assessment' => 'Reading assessment'); }
}
require BFTD_PATH . 'includes/class-bftd-calendar-view.php';

ob_start();
BFTD_Calendar_View::render();
$html = ob_get_clean();

$event = array(
  'kind' => 'session', 'id' => 'x1', 'key' => 'k1', 'student_id' => 501, 'student' => 'Pat Example',
  'title' => 'Pat Example', 'date' => BFTD_Time::today(), 'start' => '15:00', 'end' => '15:50', 'tutor' => 1,
  'status' => 'booked', 'draft' => true, 'type' => 'tutoring', 'type_label' => 'Tutoring session',
  'can_edit' => true, 'url' => '#student-501',
);
$stub = '<script>window.fetch = function () { return Promise.resolve({ json: function () { return Promise.resolve({ success: true, data: { events: ' . json_encode(array($event, array_merge($event, array("id" => "x2", "key" => "k2", "start" => "16:00", "end" => "16:50", "status" => "held", "draft" => false, "can_edit" => false)), array_merge($event, array("id" => "x3", "key" => "k3", "start" => "17:00", "end" => "17:50", "status" => "noshow", "title" => "Sam Other", "student" => "Sam Other", "draft" => false, "can_edit" => false)))) . ', closed: {} } }); } }); };'
  . 'try { localStorage.clear(); } catch (e) {}</script>';

$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$js  = file_get_contents(BFTD_PATH . 'assets/js/bftd-calendar.js');
file_put_contents(__DIR__ . '/calendar-pop.html',
  '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 -apple-system,system-ui,sans-serif;background:#F0F0F1;margin:0;padding:20px}' . $css . '</style>'
  . $stub . '</head><body class="wp-core-ui">' . $html . '<script>' . $js . '</script></body></html>');
echo __DIR__ . '/calendar-pop.html' . "\n";
