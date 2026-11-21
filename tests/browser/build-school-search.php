<?php
/*
 * A student's School box, drawn by the real BFTD_Schools::render_field(),
 * with the real admin script and styles. The server is a stub answering the
 * search with made-up schools.
 */
require dirname(__DIR__) . '/wp-stubs.php';
define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
if (!function_exists('add_action')) { function add_action(...$a) {} }
function get_post_meta($id, $k = '', $s = false) { return ''; }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields { public static function get($id, $s, $k) { return ''; } }
require BFTD_PATH . 'includes/class-bftd-schools.php';

ob_start();
BFTD_Schools::render_field(5, array('label' => 'School'));
$field = ob_get_clean();

$answers = array(
  'bow' => array('ghl' => array(array('ghl_id' => 'cbr', 'name' => 'Bow River Elementary', 'list_id' => 0)), 'list' => array(), 'exact' => null, 'near' => array(), 'ghl_error' => ''),
  'foot' => array('ghl' => array(array('ghl_id' => 'cfe', 'name' => 'Foothills Elementary School', 'list_id' => 3)),
    'list' => array(array('id' => 3, 'name' => 'Foothills Elementary School', 'ghl' => true), array('id' => 4, 'name' => 'Foothills Middle', 'ghl' => false)), 'exact' => null, 'near' => array(), 'ghl_error' => ''),
  'prairie sky' => array('ghl' => array(), 'list' => array(), 'exact' => null, 'near' => array(), 'ghl_error' => ''),
  'fothills middle' => array('ghl' => array(), 'list' => array(), 'exact' => null, 'near' => array(array('pick' => 'list:4', 'name' => 'Foothills Middle')), 'ghl_error' => ''),
);
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
file_put_contents(__DIR__ . '/school-search.html', '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui,sans-serif;margin:0;padding:20px;background:#f0f0f1}' . $css . '</style>'
  . '<script>' . file_get_contents(__DIR__ . '/jquery.js') . '</script>'
  . '<script>window.BFTD = { ajax_url: "/ajax", nonce: "n" }; jQuery.fn.sortable = function () { return this; }; window.__asked = [];'
  . 'var A = ' . json_encode($answers) . ';'
  . 'jQuery.post = function (u, d) { window.__asked.push(d); var a = A[String(d.q || "").toLowerCase()] || { ghl: [], list: [], exact: null, near: [] };'
  . ' return jQuery.Deferred().resolve({ success: true, data: a }).promise(); };</script>'
  . '</head><body class="wp-admin wp-core-ui"><div style="max-width:420px">' . $field . '</div>'
  . '<script>' . file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js') . '</script></body></html>');
echo __DIR__ . '/school-search.html' . "\n";
