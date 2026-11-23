<?php
/*
 * History's Attending from, drawn by the real BFTD_Start_Appt::render_field()
 * in a four-column grid like the History box, with the real script and
 * styles. Made-up appointments only.
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
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields { public static function get($id, $s, $k) { return 'attend_from' === $k ? '2026-06-02' : ''; } }
class BFTD_Calendar_View { public static function tutors_for($u) { return array(5 => 'Rae Tutor', 6 => 'Sam Tutor'); } }
class BFTD_Session_Appt { public static function label($o) { return BFTD_Time::day($o['date']) . ', ' . $o['time'] . ' to 4:50 pm, Rae Tutor'; } }
class BFTD_Schedule { public static function occurrences($s, $f, $t, $l = 200) {
  return array(array('key' => '2026-11-23 16:00', 'date' => '2026-11-23', 'time' => '16:00', 'status' => 'booked', 'type' => 'tutoring'),
               array('key' => '2026-11-26 16:00', 'date' => '2026-11-26', 'time' => '16:00', 'status' => 'booked', 'type' => 'tutoring')); } }
require BFTD_PATH . 'includes/class-bftd-start-appt.php';
ob_start(); BFTD_Start_Appt::render_field((object) array('ID' => 9), array('label' => 'Attending from')); $field = ob_get_clean();
$cell = function ($label) { return '<div class="bftd-field"><label class="bftd-label">' . $label . '</label><input type="text" style="width:100%;box-sizing:border-box"></div>'; };
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
file_put_contents(__DIR__ . '/start-field.html', '<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px/1.5 system-ui,sans-serif;margin:0;padding:20px}' . $css
  . '.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px 24px;width:1120px}</style>'
  . '<script>' . file_get_contents(__DIR__ . '/jquery.js') . '</script><script>window.BFTD = { ajax_url: "/ajax", nonce: "n" }; jQuery.fn.sortable = function () { return this; };</script>'
  . '</head><body class="wp-admin wp-core-ui"><div class="grid4">' . $cell('School') . $cell('Status') . '<div id="cell">' . $field . '</div>' . '<div class="bftd-field"><label class="bftd-label" for="bftd_student_attend_to">Attending to</label><input type="date" id="bftd_student_attend_to" name="bftd[student][attend_to]" value=""></div>' . '</div>'
  . '<input type="number" id="bftd_student_lessons_bank" value="10">'
  // A stand-in for the server: the forecast is ten weeks after the start, and says what it was asked.
  . '<script>window.ASKED = []; jQuery.post = function (u, d) { window.ASKED.push(d); var dt = new Date(d.from + "T12:00:00Z"); dt.setUTCDate(dt.getUTCDate() + 70); var y = dt.toISOString().slice(0, 10); return jQuery.Deferred().resolve({ success: true, data: { date: y, label: y } }).promise(); };</script>'
  . '<script>' . file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js') . '</script></body></html>');
echo __DIR__ . '/start-field.html' . "\n";
