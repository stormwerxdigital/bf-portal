<?php
/*
 * Report wording from Karl's "NEW Portal Updates" document (1.214): the
 * letter names paragraph, the directionality row, the revised reversals
 * statement, and the revised "How our method helps" for reading grade level.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
require_once BFTD_PATH . 'includes/class-bftd-schema.php';
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$a = BFTD_Schema::alphabet_intro();
check(1 === substr_count($a, 'This is the only activity that uses letter names.') && false !== strpos($a, 'It lightens the mental load.</p>'), 'Writing the alphabet explains letter names, once');
check(strpos($a, 'posture and pencil grip') < strpos($a, 'only activity that uses letter names'), 'after the paragraph on posture and pencil grip');

$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-schema.php');
check(false !== strpos($src, "'direction' => 'Directionality issue: the shape is right but the strokes went the wrong way',"), 'Counted as correct has the directionality row');
check(false !== strpos($src, "'label' => 'Reversals: the final letter is mirrored or flipped. Common: b/d, p/q. Less common: n/u, s, z, j, J, N. Least common: e, g, k, a',"), 'reversals are described as the document revises them');
check(false === strpos($src, 'Examples of reversals are b for d'), 'and the old wording is gone');

$m = BFTD_Schema::wordreading_method();
check(false !== strpos($m, 'by building the skills that matter most for real reading:</p>') && 4 === substr_count($m, '<li>'), 'How our method helps lists the four skills');
check(false !== strpos($m, 'Instead of inching through levels or rule-based sequences') && false !== strpos($m, 'a spiral staircase that keeps climbing, with review built in.'), 'and says how, in the revised words');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
