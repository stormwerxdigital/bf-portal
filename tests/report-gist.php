<?php
/*
 * The one sentence a family sees of an activity description, with the rest a
 * click away. A session with six activities used to open with six paragraphs of
 * curriculum before the first word about their own child.
 *
 * Being roughly right is enough, because the whole description is one click
 * from the reader. Roughly right still has to be a sentence: a full stop after
 * an abbreviation is not an ending, and cutting there hands a parent the word
 * "Mrs." The first version of this did exactly that, with a comment above it
 * claiming it did not.
 */
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

if ( ! function_exists('wp_strip_all_tags') ) { function wp_strip_all_tags($s){ return strip_tags((string)$s); } }
if ( ! function_exists('wp_get_attachment_image') ) { function wp_get_attachment_image($id,$s=null,$i=false,$a=array()){ return $GLOBALS['IMG'] ?? ''; } }
if ( ! function_exists('wp_get_attachment_image_url') ) { function wp_get_attachment_image_url($id,$s=null){ return $GLOBALS['FULL'] ?? ''; } }
if ( ! function_exists('get_post_meta') ) { function get_post_meta($id,$k,$one=false){ return $GLOBALS['ALT'] ?? ''; } }

define('BFTD_PATH', dirname(__DIR__).'/');

/* Only the two pure helpers are wanted, and the class they live on pulls in the
   whole portal. Read and evaluated on its own so the test stays a unit test. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
preg_match('/\/\*\* Words that end in a stop.*?\n\t\}\n/s', $src, $a);
preg_match('/\/\*\*\s*\n\s*\* The first sentence of some text\..*?\n\t\}\n/s', $src, $b);
preg_match('/\/\*\* A gist longer than.*?\n\t\}\n/s', $src, $c);
preg_match('/\/\*\*\s*\n\s*\* A piece of a child\'s work.*?\n\t\}\n/s', $src, $d);
check($a && $b && $c && $d, 'the helpers were found in the source');
eval('class G { ' . $a[0] . $b[0] . $c[0] . $d[0] . ' }');

echo "\nOne sentence, and which one:\n";
check('Sound Lines builds the link between a sound and its spelling.'
  === G::first_sentence('<p>Sound Lines builds the link between a sound and its spelling. The child says the word, then writes each sound.</p>'),
  'the first sentence, without the second');
check('One sentence only.' === G::first_sentence('<p>One sentence only.</p>'),
  'a description that is one sentence is left whole');
check('A description with no stop at all' === G::first_sentence('A description with no stop at all'),
  'and so is one with no ending punctuation');
check('' === G::first_sentence(''), 'nothing in, nothing out');
check('' === G::first_sentence('<p></p>'), 'and empty markup counts as nothing');

echo "\nA stop that is not an ending:\n";
check('Mrs. Pike reads with him first.' === G::first_sentence('<p>Mrs. Pike reads with him first. Then he reads alone.</p>'),
  'an abbreviation does not end the sentence');
check('Dr. Shanahan wrote the sequence this follows.'
  === G::first_sentence('<p>Dr. Shanahan wrote the sequence this follows. It runs to a hundred and forty.</p>'),
  'and nor does a title');
check(0 === strpos(G::first_sentence('<p>We use e.g. three cards at a time. Then four.</p>'), 'We use e.g. three cards'),
  'nor an abbreviation in the middle of a clause');

echo "\nA question or an exclamation does end one:\n";
check('What sound does it start with?' === G::first_sentence('<p>What sound does it start with? The child answers aloud.</p>'),
  'a question mark ends a sentence');
check('Say it, then write it!' === G::first_sentence('<p>Say it, then write it! That is the whole activity.</p>'),
  'and so does an exclamation');

echo "\nA sentence too long to be a gist:\n";
$long = str_repeat('a very long clause that keeps going and going ', 12) . '. And then a second.';
$out  = G::first_sentence('<p>' . $long . '</p>');
check(mb_strlen($out) <= 210, 'is cut on length, got ' . mb_strlen($out));
check('…' === mb_substr($out, -1), 'and says it was cut');

echo "\nWork images open at full size:\n";
$GLOBALS['IMG']  = '<img src="medium.jpg" alt="">';
$GLOBALS['FULL'] = 'https://example.test/full.jpg';
$GLOBALS['ALT']  = 'Kaine\'s spelling page';
$shot = G::work_shot(7);
check(false !== strpos($shot, 'class="shot-open"'), 'the image is wrapped in a link');
check(false !== strpos($shot, 'https://example.test/full.jpg'), 'pointing at the full size');
check(false !== strpos($shot, 'Open Kaine'), 'and the link says what it opens, for a screen reader');
check(false !== strpos($shot, '<figure class="shot">'), 'still inside the figure the layout expects');

/* No full size available is not a reason to draw nothing. */
$GLOBALS['FULL'] = '';
$plain = G::work_shot(7);
check(false !== strpos($plain, '<figure class="shot">') && false === strpos($plain, 'shot-open'),
  'an image with no full size is drawn without a link rather than dropped');

$GLOBALS['IMG'] = '';
check('' === G::work_shot(7), 'and an attachment that is not an image draws nothing');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
