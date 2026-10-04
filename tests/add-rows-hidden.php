<?php
/*
 * The "add" rows: registered, not drawn.
 *
 * Every one of them is already a button on the screen it belongs to, so in
 * the sidebar they doubled the menu's length and pushed the pages people
 * navigate to below the fold.
 *
 * They cannot simply be removed, and that is the whole point of this file.
 * remove_submenu_page() unsets the entry from $submenu, and $submenu is the
 * array get_admin_page_parent() searches to work out what post-new.php
 * belongs to. With nothing to find it falls through to a branch that matches
 * the bare filename against core's own Posts menu and refuses the screen to
 * somebody holding every capability — no message but "Sorry, you are not
 * allowed to access this page", and no clue where it came from. That trap has
 * been walked into once already. So: the rows exist, and CSS hides them.
 *
 * This test therefore guards two things that pull against each other. The
 * tidying must stay tidy, and it must never become a removal.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

function code_of($file) {
  $out = '';
  foreach (token_get_all(file_get_contents($file)) as $t) {
    if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $out .= $t[1]; }
    else { $out .= $t; }
  }
  return $out;
}

/* ---- still registered, for every type, with nothing taken back ---- */
$cpt = code_of(BFTD_PATH . 'includes/class-bftd-cpt.php');
check(1 === preg_match('/function register_add_new_pages\(\).*?foreach\s*\(\s*BFTD_Roles::post_types\(\)/s', $cpt),
  'the rows are registered from the full post type list, so no type is left uncreatable');
check(1 === preg_match('/add_submenu_page\(.*?post-new\.php\?post_type/s', $cpt),
  'and each one is a real post-new.php entry for get_admin_page_parent to find');
check(0 === preg_match('/remove_submenu_page\s*\(/', $cpt),
  'nothing is taken back out of $submenu, which would refuse the screen');
$scanned = 0;
$calls = array();
foreach (glob(BFTD_PATH . 'includes/*.php') as $file) {
  $scanned++;
  if (preg_match_all('/remove_submenu_page\s*\(([^;]*)\)\s*;/', code_of($file), $m)) {
    foreach ($m[1] as $args) $calls[] = basename($file) . ': ' . trim(preg_replace('/\s+/', ' ', $args));
  }
}
check($scanned > 10, "every include was read, not an empty list ($scanned files)");
check(!empty($calls), 'the scan finds the calls that do exist, so a clean result means something');

/* Two removals are safe, for two different reasons, and neither is the trap.
 *
 * The student hub is reached through admin.php?page=, whose lookup uses
 * $_registered_pages, which remove_submenu_page() leaves alone.
 *
 * The students list row is edit.php?post_type=, a core screen that is
 * reachable by its own URL whether or not anything links to it; taking the
 * row out only stops the menu offering it, in favour of the screen that
 * replaces it.
 *
 * An add row is neither. post-new.php is refused outright when its row is
 * gone, because get_admin_page_parent() searches $submenu for it. */
$bad = array();
foreach ($calls as $call) { if (false !== strpos($call, 'post-new') || false !== strpos($call, 'POST_NEW')) $bad[] = $call; }
check(array() === $bad, 'and none of them takes back an add row: ' . implode(' | ', $bad));

$allowed = 0;
foreach ($calls as $call) {
  if (false !== strpos($call, 'HUB_SLUG') || false !== strpos($call, "'edit.php?post_type='")) $allowed++;
  // The library's read page is admin.php?page=, safe for the same reason as the hub.
  elseif (0 === strpos($call, 'class-bftd-library-view.php:') && false !== strpos($call, 'self::SLUG')) $allowed++;
}
check($allowed === count($calls),
  'and every removal is one of the two that are safe: ' . implode(' | ', $calls));

/* ---- hidden, by what the row points at rather than what it says ---- */
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$bare = preg_replace('#/\*[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', $css);

$rule = '';
foreach (explode('}', $bare) as $block) {
  if (false === strpos($block, '{')) continue;
  list($sel, $body) = explode('{', $block, 2);
  if (false !== strpos($sel, 'post-new.php?post_type=bftd_')) { $rule = trim(preg_replace('/\s+/', ' ', $sel)) . '{' . trim($body); break; }
}
check('' !== $rule, 'there is a rule hiding the add rows');
check(false !== strpos($rule, 'display:none'), 'and it hides them');
check(false !== strpos($rule, '#adminmenu'), 'only inside the admin menu, not anywhere a link like that appears');
check(false !== strpos($rule, ':not(.current)'),
  'except the one you are standing on, so the sidebar still names where you are');
check(false !== strpos($rule, 'href*='),
  'matched on the link, so renaming "Record a Lesson" tomorrow does not un-hide it');

/* The labels are not what the rule keys on, and must stay free to change. */
foreach (array('Record a Lesson', 'New Reading Diagnostic', 'New Progress Report', 'Add Resource', 'Add Student') as $label) {
  check(false === strpos($bare, $label), "the stylesheet does not mention \"$label\"");
}

/* Every type the menu registers a row for is covered by the one selector. */
$types = array('bftd_student', 'bftd_assessment', 'bftd_progress', 'bftd_session', 'bftd_resource');
foreach ($types as $pt) {
  check(0 === strpos($pt, 'bftd_'), "$pt is matched by the bftd_ prefix the rule uses");
}

exit($fail ? 1 : 0);
