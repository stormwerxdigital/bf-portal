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
check(false !== strpos($js, '$side.css( { top: ( bar + gap ) + \'px\', maxHeight:'), 'pinned under the toolbar, as tall as the window allows (1.155)');
check(false !== strpos($js, '$( window ).on( \'scroll\''), 'sized again as the page scrolls, so it fits before it reaches the top');
check(1 === preg_match('/#postbox-container-1\{position:sticky;overflow-y:auto;overflow-x:hidden/', $css), 'scrolling on its own, so no box is pushed out of sight under the page');
check(false === strpos($css, '#submitdiv{position:sticky') && false === strpos($css, '#bftd_preview{position:sticky'), 'and no box is laid over another');
check(false !== strpos($js, "$( '.bftd-top' )"), 'resting above the Back to top button');
check(false !== strpos($js, 'ResizeObserver'), 'and placed again when a box opens, closes or grows');
check(1 === preg_match('/@media \(min-width:851px\)\{\s*\.bftd-sticky-side #post-body\.columns-2 #postbox-container-1\{position:sticky/', $css),
  'sticky only while the column sits beside the report');
/* Its own block, not inside one that stops early: it sat in the results-table
   block, which returns on screens with no live table, so progress reports
   never got it (1.154). */
$at = strpos($js, "addClass( 'bftd-sticky-side' )");
$open = strrpos(substr($js, 0, $at), '( function ( $ ) {');
check(false === strpos(substr($js, $open, $at - $open), 'return;\n\n\tfunction val('), 'and runs in its own block, so it reaches progress reports');
check(false === strpos(substr($js, $open, $at - $open), "$( '.bftd-live-table' )"), 'not behind the live table check');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
