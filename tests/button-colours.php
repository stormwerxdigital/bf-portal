<?php
/*
 * Primary buttons are white text on the deep green.
 *
 * Update, Publish and the preview button were olive with dark text, then, once
 * made green, came out with purple text: a general rule giving every .button
 * purple text sat later in the stylesheet, and a primary button is a .button
 * too. Read as CSS, in file order, because which rule wins is about order.
 */
define('BFTD_PATH', dirname(__DIR__) . '/');
$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$css = preg_replace('#/\*[\s\S]*?\*/#', '', $css);

/* The last colour each selector is given, in file order. */
$last = array();
foreach (explode('}', $css) as $at => $block) {
  if (false === strpos($block, '{')) continue;
  list($sel, $body) = explode('{', $block, 2);
  if (!preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/', $body, $m)) continue;
  foreach (explode(',', $sel) as $one) $last[trim(preg_replace('/\s+/', ' ', $one))] = array(trim($m[1]), $at);
}

check(isset($last['.wp-core-ui .button.button-primary']) && '#fff' === strtolower($last['.wp-core-ui .button.button-primary'][0]),
  'a primary button has white text');
check(isset($last['.wp-core-ui .button']) && $last['.wp-core-ui .button.button-primary'][1] > $last['.wp-core-ui .button'][1],
  'set after the general button colour, so it is the one that wins');
check(1 === preg_match('/\.wp-core-ui \.button-primary\{[^}]*background:var\(--bftd-sage-deep\)/', $css),
  'on the deep green');

exit($fail ? 1 : 0);
