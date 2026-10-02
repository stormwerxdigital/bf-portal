<?php
/*
 * Getting back to the top of something long.
 *
 * A reading diagnostic is six sections of writing. The edit screen for one is
 * several thousand pixels and the report a family reads is not much shorter,
 * so the trip back to the summary at the top is one somebody makes dozens of
 * times a day.
 *
 * The things worth holding still are the ones that are easy to get wrong and
 * invisible when they are: a button that is always there on a screen with
 * nowhere to scroll, an animation forced on somebody who asked their system
 * not to animate, and a page that moves while the keyboard stays at the
 * bottom, so the next Tab carries on from the footer.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$pairs = array(
  'the tutor\'s edit screen' => array(
    'js'  => file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js'),
    'css' => file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css'),
    'cls' => 'bftd-top',
  ),
  'the family\'s report' => array(
    'js'  => file_get_contents(BFTD_PATH . 'assets/js/bftd-report.js'),
    'css' => file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css'),
    'cls' => 'to-top',
  ),
);

foreach ($pairs as $where => $p) {
  $js = $p['js']; $css = $p['css']; $cls = $p['cls'];

  check(false !== strpos($js, $cls), "$where has a way back to the top");
  check(false !== strpos($js, 'Back to top'), 'labelled in words, not as a bare arrow');

  /* It shows up only once there is somewhere to go back to. */
  check(1 === preg_match('/(pageYOffset|scrollTop)[^;]{0,120}>\s*\d{3}/', $js),
    'and appears only after a real scroll');
  check(false !== strpos($js, 'hidden'), 'starting out of the page entirely');

  /* Scroll fires on every pixel. Reading layout on each one is how a long
     page starts to feel heavy on an older laptop. */
  check(false !== strpos($js, 'requestAnimationFrame'), 'the scroll handler does its work once a frame');

  /* Smooth, unless somebody has said they do not want that. */
  check(false !== strpos($js, "behavior:"), 'the scroll is smooth');
  check(false !== strpos($js, 'prefers-reduced-motion'), 'except for anybody who has asked it not to be');
  check(false !== strpos($css, 'prefers-reduced-motion'), 'and the button does not animate in for them either');

  /* The page moves; the keyboard has to move with it. */
  check(1 === preg_match('/tabindex[\'"\s,]*.{0,4}-1/', $js), 'focus goes back to the top as well as the page');

  /* A fixed button sits over the content, so it needs its own stacking and
     to get out of the way on a phone. */
  check(1 === preg_match('/\.' . preg_quote($cls, '/') . '\{[^}]*position:fixed/', $css), 'it is pinned to the corner');
  check(1 === preg_match('/\.' . preg_quote($cls, '/') . '\{[^}]*z-index/', $css), 'above what it sits over');
  check(false !== strpos($css, ':focus-visible'), 'with a focus ring for the keyboard');
}

/* On paper there is no scrolling and no button. */
$rcss = $pairs["the family's report"]['css'];
check(1 === preg_match('/@media print\{[^}]*\.bf-report \.to-top\{display:none\}/', $rcss),
  'a printed report does not carry a button that cannot be pressed');

/* It belongs to our screens, not to the whole of wp-admin. */
$ajs = $pairs["the tutor's edit screen"]['js'];
check(1 === preg_match('/if\s*\(\s*!\s*\$\(\s*[\'"][^\'"]*bftd-[^\'"]*[\'"]\s*\)\.length\s*\)\s*return;/', $ajs),
  'and only appears on the screens this plugin draws');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
