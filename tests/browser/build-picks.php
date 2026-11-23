<?php
/*
 * The Strengths tick list on a diagnostic, drawn by the real
 * BFTD_Picks::render() from the shipped list, with the real admin script.
 */
require dirname(__DIR__) . '/wp-stubs.php';
define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $s = false) { return ''; } }
if (!function_exists('checked')) { function checked($a, $b = true) { if ((bool) $a === (bool) $b) echo ' checked'; } }
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
$S = 'sec-strengths-areas-for-growth';
$f = BFTD_Schema::sections()[$S]['fields']['strengths_picks'];
ob_start(); BFTD_Picks::render(7, $S, 'strengths_picks', $f); $box = ob_get_clean();
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
file_put_contents(__DIR__ . '/picks.html', '<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px/1.5 system-ui,sans-serif;margin:0;padding:20px}' . $css . '</style>'
  . '<script>' . file_get_contents(__DIR__ . '/jquery.js') . '</script><script>window.BFTD = { ajax_url: "/ajax", nonce: "n" }; jQuery.fn.sortable = function () { return this; };</script>'
  . '</head><body class="wp-admin wp-core-ui">' . $box . '<script>' . file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js') . '</script></body></html>');
echo __DIR__ . '/picks.html' . "\n";
