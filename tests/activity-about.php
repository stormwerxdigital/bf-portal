<?php
/*
 * The 1.172 changes from Laurel's doc that live on the tutor's screens.
 *
 * Under each activity chosen on a session, what the library says about it,
 * so a tutor sees what the activity is without going to look it up. A note
 * opened full screen is not covered by the admin menu. And the sessions
 * bank has no "Booked ahead" tile, which only repeated Remaining.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
function wpautop($s) { return '<p>' . trim($s) . "</p>\n"; }
require BFTD_PATH . 'includes/class-bftd-activities.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

BFTD_Activities::use_fixture(array(
  7  => array('number' => 10, 'name' => 'Student Reads, Tutor Reads Back', 'track' => 't23', 'about' => 'The student reads a short passage <b>aloud</b>.'),
  8  => array('number' => 11, 'name' => 'Sound Lines', 'track' => 't1', 'about' => '   '),
));

/* ---- the description under a chosen activity ---- */
$box = BFTD_Activities::about_box(7);
check(false !== strpos($box, '<span class="bftd-actabout-k">About this activity</span>'), 'a chosen activity has its description under it, labelled the way the family reads it');
check(false !== strpos($box, 'The student reads a short passage <b>aloud</b>.'), 'in the library\'s own words, formatting kept');
check(false === strpos($box, ' hidden>About') && 0 === strpos($box, '<div class="bftd-actabout">'), 'shown');
check(false !== strpos(BFTD_Activities::about_box(8), 'No description in the activity library yet.'), 'an activity with no description says so rather than showing an empty box');
check(0 === strpos(BFTD_Activities::about_box(0), '<div class="bftd-actabout" hidden>'), 'a row with nothing chosen yet keeps the box hidden, ready to fill');
check(false === strpos($box, '<textarea') && false === strpos($box, '<input'), 'read only: the words are changed in the library');

$map = BFTD_Activities::about_map();
check(isset($map[7], $map[8]) && $map[7] === BFTD_Activities::about_html(7) && '' === $map[8], 'the script gets every activity\'s description, the same text the box draws');

$fields = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match("#BFTD_Activities::picker\( \\\$name, \(int\) \\\$val \);.*\n\s*echo BFTD_Activities::about_box\( \(int\) \\\$val \);#", $fields), 'every activity row draws the box straight after its picker');
$boot = file_get_contents(BFTD_PATH . 'bf-tutoring-dashboard.php');
check(false !== strpos($boot, "BFTD_CPT::SESSION === \$post->post_type && class_exists( 'BFTD_Activities' )") && false !== strpos($boot, 'window.BFTD_ACT_ABOUT = '), 'on a session screen only');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(false !== strpos($js, "closest( '.bftd-actpick' ).nextAll( '.bftd-actabout' ).first()") && false !== strpos($js, 'map[ id ]'), 'choosing or changing an activity fills its box');
check(false !== strpos($js, "'Show less' : 'Show all'"), 'a long description is shown in part, with Show all');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(false !== strpos($css, '.bftd-stackrow-lead:has(.bftd-actabout:not([hidden])){flex:1 1 auto;max-width:none}') && false !== strpos($css, '.bftd-actabout{max-width:760px;'), 'wide enough to read, not squeezed to the width of the activity name');
check(1 === preg_match('/\.bftd-actabout-body\{font-size:14px;line-height:1\.55;max-height:9\.3em;overflow:hidden\}/', $css), 'at a readable size, the first six lines or so');

/* ---- full screen is not covered by the admin menu ---- */
check(1 === preg_match('/html\.mce-fullscreen #adminmenumain,\s*html\.mce-fullscreen #wpadminbar\{display:none\}/', $css), 'a note opened full screen hides the admin menu and bar that covered its left edge');

/* ---- no Booked ahead ---- */
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false === strpos($mb, "array( 'booked',    'Booked ahead'"), 'the sessions bank has no Booked ahead tile');
check(false !== strpos($mb, "array( 'purchased', \$word,") && false !== strpos($mb, "? 'Approved' : 'Purchased'") && false !== strpos($mb, "array( 'remaining', 'Remaining',"), 'and keeps Purchased (Approved for a monthly school, 1.218), Used and Remaining');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
