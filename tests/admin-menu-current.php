<?php
/*
 * The sidebar names the page you are on, once.
 *
 * WordPress paints the first submenu item as the highlight, because in a core
 * menu that item is a duplicate of the parent and highlighting it is the same
 * as highlighting the parent. Ours is not a duplicate: the first item under
 * Brilliant Futures is Students, a page of its own. So standing on Lessons lit
 * Students purple and left Lessons as plain white text, and the sidebar named
 * the wrong page.
 *
 * Read as CSS rather than as intent: the selectors carrying the highlight are
 * checked against a list, because "the highlight follows .current" is exactly
 * the kind of rule that is true in the comment and false in the file.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$css = preg_replace('#/\*[^*]*\*+(?:[^/*][^*]*\*+)*/#', '', $css);   // comments are not evidence

/* Every rule that paints the menu highlight, by its selectors. */
$highlight = array();
foreach (explode('}', $css) as $block) {
  if (false === strpos($block, '{')) continue;
  list($sel, $body) = explode('{', $block, 2);
  if (false === strpos($sel, '#adminmenu')) continue;
  if (false === strpos($body, 'background:var(--bftd-purple)')) continue;
  foreach (explode(',', $sel) as $one) { $highlight[] = trim(preg_replace('/\s+/', ' ', $one)); }
}
check(!empty($highlight), 'the menu has a highlight colour at all');

/* Nothing is highlighted for being first in the list. */
foreach ($highlight as $sel) {
  check(false === strpos($sel, 'wp-first-item'),
    'the highlight is not given by position: ' . $sel);
}

/* The submenu highlight is given to the current item, and it carries both the
 * background and the white text a person is looking for. */
$current = '';
foreach (explode('}', $css) as $block) {
  if (false === strpos($block, '{')) continue;
  list($sel, $body) = explode('{', $block, 2);
  foreach (explode(',', $sel) as $one) {
    if ('#adminmenu .wp-submenu li.current>a' === trim(preg_replace('/\s+/', ' ', $one))) { $current = $body; break 2; }
  }
}
check('' !== $current, 'the current submenu item has a rule of its own');
check(false !== strpos($current, 'background:var(--bftd-purple)'), 'it carries the purple background');
check(false !== strpos($current, 'color:#fff'), 'and the white text, so the two travel together');

/* The parent is the one place two highlights are right at once. */
$parent_lit = false;
foreach ($highlight as $sel) {
  if (false !== strpos($sel, 'wp-has-current-submenu a.wp-has-current-submenu')) $parent_lit = true;
}
check($parent_lit, 'the parent menu link keeps its highlight while a child is open');

/* And what core paints by position is put back, or core wins and we are where
 * we started. Specificity, counted rather than assumed. */
$reset = '';
foreach (explode('}', $css) as $block) {
  if (false === strpos($block, '{')) continue;
  list($sel, $body) = explode('{', $block, 2);
  if (false !== strpos($sel, 'wp-first-item:not(.current)')) { $reset = trim(preg_replace('/\s+/', ' ', $sel)); break; }
}
check('' !== $reset, 'the first item is explicitly put back to nothing');
$mine = substr_count($reset, '.') + substr_count($reset, '#') * 100;
$core = substr_count('#adminmenu .wp-has-current-submenu .wp-submenu .wp-first-item a', '.') + 100;
check($mine > $core, 'and the rule outweighs the core one it is undoing');

exit($fail ? 1 : 0);
