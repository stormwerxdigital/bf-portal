<?php
/*
 * The portal's fonts come from the plugin, not Google (Karl, 1.220.1: "why
 * google fonts" ... "yes"), so opening a page sends nobody's address to
 * Google and the privacy policy need not name it.
 */
$root = dirname(__DIR__);
$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? '  ok ' : 'FAIL') . "  $msg\n"; if (!$ok) $fail++; }

$hits = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/includes'));
$files = iterator_to_array($it);
foreach (array('/bf-tutoring-dashboard.php') as $f) $files[] = new SplFileInfo($root . $f);
foreach (glob($root . '/assets/{css,js}/*.{css,js}', GLOB_BRACE) as $f) $files[] = new SplFileInfo($f);
foreach ($files as $f) {
  if (!$f->isFile()) continue;
  $s = file_get_contents($f->getPathname());
  if (preg_match('#fonts\.(googleapis|gstatic)\.com#', $s)) $hits[] = basename($f->getPathname());
}
check(!$hits, 'nothing asks Google for fonts' . ($hits ? ': ' . implode(', ', $hits) : ''));

define('BFTD_URL', 'https://example.test/bf/'); define('BFTD_VERSION', '9');
$view = file_get_contents($root . '/includes/class-bftd-report-view.php');
$a11y = file_get_contents($root . '/includes/class-bftd-accessibility.php');
check(false !== strpos($view, "BFTD_URL . 'assets/fonts/fonts.css"), 'the report fonts load from the plugin');
check(false !== strpos($a11y, "BFTD_URL . 'assets/fonts/atkinson.css"), 'the accessibility font loads from the plugin');

foreach (array('fonts.css' => array('Raleway', 'Source Sans 3', 'IBM Plex Mono', 'Didact Gothic'), 'atkinson.css' => array('Atkinson Hyperlegible')) as $css => $families) {
  $s = file_get_contents($root . '/assets/fonts/' . $css);
  foreach ($families as $fam) check(false !== strpos($s, "font-family: '$fam'"), "$css has $fam");
  preg_match_all('#url\(([^)]+)\)#', $s, $m);
  $missing = array_filter(array_unique($m[1]), function ($f) use ($root) {
    $p = $root . '/assets/fonts/' . $f;
    return !is_file($p) || 'wOF2' !== file_get_contents($p, false, null, 0, 4);
  });
  check($m[1] && !$missing, "every file $css points to is a woff2 in the plugin" . ($missing ? ': ' . implode(', ', $missing) : ''));
  check(false === strpos($s, 'http'), "$css points nowhere else");
}
// Both weights the report uses for Raleway headings, and the italic body.
$s = file_get_contents($root . '/assets/fonts/fonts.css');
check(1 === preg_match("#font-family: 'Source Sans 3';\s*font-style: italic#", $s), 'the italic body face is there');
check(1 === preg_match("#font-family: 'Raleway';\s*font-style: normal;\s*font-weight: 800#", $s), 'the heavy display weight is there');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
