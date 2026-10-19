<?php
/*
 * The portal page is full width, whatever the theme (Karl, 1.156): the plugin
 * serves its own page template there, edge to edge, with wp_head, wp_body_open
 * and wp_footer kept so the toolbar, the view-as bar and the assets still work.
 */
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$root = dirname(__DIR__);
$d = file_get_contents("$root/includes/class-bftd-dashboard.php");
$t = file_get_contents("$root/templates/portal-page.php");
$c = file_get_contents("$root/assets/css/bftd-portal.css");
check(false !== strpos($d, "add_filter( 'template_include', array( __CLASS__, 'full_width_template' ), 99 );"), 'the plugin picks the portal page template');
check(1 === preg_match("/function full_width_template\\( \\\$template \\) \\{\\s*if \\( ! self::is_portal_page\\(\\) \\) return \\\$template;/", $d), 'only on the portal page; every other page keeps the theme');
foreach (array('wp_head()', 'wp_body_open()', 'wp_footer()', 'the_content()', "body_class( 'bftd-portal-page' )", 'name="viewport"') as $must) {
  check(false !== strpos($t, $must), "the template keeps $must");
}
check(false === strpos($t, 'get_header') && false === strpos($t, 'the_title'), 'and has no theme header or page title');
check(false !== strpos($c, '.bftd-portal-page .bf-portal{border-radius:0;min-height:100vh}'), 'the portal fills the window, square edged');
check(false !== strpos($c, 'body.bftd-portal-page{margin:0;'), 'with no margin round the page');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
