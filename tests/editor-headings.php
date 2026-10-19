<?php
/*
 * The edit screen's section headings match the report's (Karl, 1.152/1.153):
 * heading font, large, heavy, a purple bar, so every part of a section is easy
 * to find. And the method heading reads the same on both sides.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
require_once BFTD_PATH . 'includes/class-bftd-fields.php';
require BFTD_PATH . 'includes/class-bftd-schema.php';
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(1 === preg_match('/\.bftd-section-body \.bftd-label\{font-family:var\(--bftd-d\);font-weight:800;font-size:(\d+)px[^}]*border-left:4px solid var\(--bftd-purple\)/', $css, $m) && (int) $m[1] >= 18,
  'a field heading inside a report section is heading font, heavy, at least 18px, with the purple bar');
check(false !== strpos($css, '.bftd-section-body .bftd-field .bftd-field .bftd-label'), 'while a label nested inside a field stays small');
check(0 === preg_match('/^\.bftd-label\{[^}]*font-size:(19|2\d)px/m', $css), 'and labels outside report sections (the student record) are unchanged');

$n = 0;
foreach (BFTD_Schema::sections() as $sid => $sec) foreach ((array) ($sec['fields'] ?? array()) as $k => $f) {
  if (isset($f['heading']) && BFTD_Schema::METHOD_HEADING === $f['heading']) {
    $n++;
    check(BFTD_Schema::METHOD_HEADING === $f['label'], "the editor calls $sid.$k what the report calls it");
  }
}
check($n >= 7, "every method answer is covered ($n)");
$st = BFTD_Schema::standing_text();
check($st['dxsec-4.passage_method']['label'] !== $st['dxsec-4.kinder_method']['label'], 'Settings still tells the two timed reading answers apart');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
