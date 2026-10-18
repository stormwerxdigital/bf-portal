<?php
/*
 * The right-hand column stays in view as a long record scrolls (Karl, 1.151),
 * so Publish, Update and Preview never mean a trip back to the top. Measured
 * on real WordPress in tests/real-wp; this guards the parts that make it.
 */
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$root = dirname(__DIR__);
$js  = file_get_contents("$root/assets/js/bftd-admin.js");
$css = file_get_contents("$root/assets/css/bftd-admin.css");
check(false !== strpos($js, "addClass( 'bftd-sticky-side' )"), 'our record screens mark themselves');
check(false !== strpos($js, "'1' !== String( BFTD.autosave )"), 'and only our record screens');
check(1 === preg_match('/tall > room \? bar \+ gap \+ room - tall : bar \+ gap/', $js), 'a tall column holds with its foot on screen, a short one at the top');
check(false !== strpos($js, "$( '.bftd-top' )"), 'resting above the Back to top button');
check(false !== strpos($js, 'ResizeObserver'), 'and placed again when a box opens, closes or grows');
check(1 === preg_match('/@media \(min-width:851px\)\{\s*\.bftd-sticky-side #post-body\.columns-2 #postbox-container-1\{position:sticky/', $css),
  'sticky only while the column sits beside the report');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
