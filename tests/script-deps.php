<?php
/*
 * A script that uses jQuery says so when it is enqueued.
 *
 * WordPress only loads jQuery on a front-end page when something asks for it,
 * and a theme is not obliged to. The portal script used jQuery without asking,
 * so on a page where nothing else had loaded it, the folded descriptions and
 * the work lightbox did nothing and no error reached anybody.
 */
define('BFTD_PATH', dirname(__DIR__) . '/');
$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

$php = '';
foreach (glob(BFTD_PATH . 'includes/*.php') as $f) $php .= file_get_contents($f);
$php .= file_get_contents(BFTD_PATH . 'bf-tutoring-dashboard.php');

preg_match_all("#wp_enqueue_script\(\s*'([a-z-]+)',\s*BFTD_URL\s*\.\s*'(assets/js/[a-z-]+\.js)',\s*(array\([^)]*\))#", $php, $m, PREG_SET_ORDER);
check(count($m) >= 3, 'the plugin\'s own scripts are all found, got ' . count($m));

foreach ($m as $one) {
  list(, $handle, $file, $deps) = $one;
  $js = file_get_contents(BFTD_PATH . $file);
  // Comments are not code.
  $code = preg_replace('#^\s*//[^\n]*$#m', '', preg_replace('#/\*.*?\*/#s', '', $js));
  $uses = (bool) preg_match('/\bjQuery\s*\(|\$\s*\(\s*function|\bjQuery\./', $code);
  if (!$uses) { check(true, "$handle does not use jQuery"); continue; }
  check(false !== strpos($deps, "'jquery'"), "$handle uses jQuery and asks for it, deps $deps");
}

exit($fail ? 1 : 0);
