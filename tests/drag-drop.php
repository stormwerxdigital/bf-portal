<?php
/*
 * Dropping a file into a report.
 *
 * A tutor writing up an assessment has the child's work on their desktop. The
 * media library route is four steps for something already under the cursor,
 * so both places that take a file take a dropped one: the writing itself, and
 * the work samples box.
 *
 * WordPress gives the main post editor drag and drop for free and gives an
 * editor inside a meta box nothing, which is every editor on these screens.
 * What is checked here is the contract that makes the wiring safe rather than
 * the drag itself, which is checked in a browser: the upload carries the
 * media library's own nonce, it can only run once that nonce exists, and a
 * file dropped anywhere else on the page does not navigate away from a
 * half-written report.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$js  = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$php = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');

/* ---- nothing uploads without the media library's own nonce ---- */
/* Rolling our own endpoint would mean rolling our own capability check and
   our own mime filtering, and getting either of those wrong is how a plugin
   becomes an upload hole. This goes through the same door Add Media does. */
check(false !== strpos($js, '_wpPluploadSettings'), 'the uploader reads the media library settings');
check(false !== strpos($js, "'upload-attachment'"), 'and posts to the media library endpoint');
check(1 === preg_match('/if\s*\(\s*!\s*uploadParams\(\).*?\)\s*return;/s', $js),
  'and does nothing at all when those settings are absent');
check(false === strpos($js, 'admin-ajax.php?action=bftd'), 'there is no endpoint of our own to get wrong');

/* The nonce is whatever the media library says it is, never a literal. */
check(1 !== preg_match('/_wpnonce\s*[:=]\s*[\'"][a-z0-9]{6,}[\'"]/i', $js), 'no nonce is hardcoded');

/* ---- a drop that misses does not throw the report away ---- */
/* A file dropped on the page rather than on a box opens in the browser, and
   the half-written report goes with it. */
check(1 === preg_match('/\$\(\s*document\s*\)\.on\(\s*[\'"]dragover drop[\'"]/', $js),
  'a file dropped anywhere else is caught');
foreach (array('.wp-editor-wrap', '.bftd-gallery', '.media-modal') as $safe) {
  check(false !== strpos($js, $safe . ','), "$safe is left to handle its own drops");
}

/* ---- both places that take a file say so ---- */
check(false !== strpos($php, 'Drag files to attach'), 'the work samples box says what it takes');
check(false !== strpos($php, 'bftd-gallery-box'), 'and is a box rather than a button on its own');
check(false !== strpos($php, 'bftd-gallery-pick'), 'with the button kept, because a keyboard cannot drag');
check(false !== strpos($css, '.bftd-gallery-box{'), 'the box is styled as a target');
check(1 === preg_match('/\.bftd-gallery-box\{[^}]*min-height:\s*(\d+)px/', $css, $m) && (int) $m[1] >= 80,
  'and is big enough to aim at, got ' . (isset($m[1]) ? $m[1] . 'px' : 'no height'));

/* The editor hint is written by the script, so it cannot promise something
   the page turns out not to be able to do. */
check(false === strpos($php, 'Drag a photo or a file straight into'), 'the editor hint is not printed unconditionally');
check(false !== strpos($js, 'Drag a photo or a file straight into'), 'it appears only once the uploader is there');

/* ---- what lands in the writing ---- */
/* An image is the evidence for the paragraph above it. Anything else is worth
   attaching but cannot be shown, so it goes in as a link people can open. */
check(1 === preg_match('/if\s*\(\s*[\'"]image[\'"]\s*===\s*att\.type\s*\)/', $js), 'an image goes in as an image');
check(false !== strpos($js, 'rel="noopener"'), 'and anything else as a link that opens safely');
check(false !== strpos($js, 'wp.media.editor.insert'), 'inserted where the person was typing');
check(false !== strpos($js, 'window.wpActiveEditor = editorId'),
  'into the editor it was dropped on, not whichever was last touched');

/* A drop of twenty photos from a phone must not fire twenty uploads at a
   shared host at once, or arrive in a jumbled order. */
check(false !== strpos($js, 'MAX_AT_ONCE'), 'there is a ceiling on one drop');
check(false !== strpos($js, 'Promise.resolve()'), 'and the files upload one after another');

/* A rejected file has to say why. "Nothing happened" is the worst answer a
   tutor can get after waiting for an upload. */
check(false !== strpos($js, "! res.success"), 'a refusal is noticed even though the request succeeded');
check(false !== strpos($js, 'Could not add'), 'and is reported by name');

/* ---- a screenshot on the clipboard ---- */
/* The most common attachment a tutor has is a snip: an image on the clipboard
   and nothing on disk. Asking them to save it somewhere first, then find it
   again, is the step worth removing. */
check(false !== strpos($js, 'function pastedImages('), 'a pasted image is picked up');
check(false !== strpos($js, "'paste'"), 'and paste is listened for');

/* All three places a file can go take one. */
foreach (array(
  '$doc.on( \'paste\''                   => 'the visual editor, which is an iframe of its own',
  '$wrap.on( \'paste.bftd\', \'textarea\'' => 'the code view, which is a plain textarea',
  "'.media-modal', function"              => "WordPress's own media window",
) as $needle => $what) {
  check(false !== strpos($js, $needle), "paste works in $what");
}
check(1 === preg_match('/paste[\s\S]{0,400}galleryFiles\(/', $js), 'and in the work samples box');

/* Pasting text has to keep pasting text. A clipboard can carry an image and
   its own formatting at once, which is what copying out of a document gives
   you, and taking that over would throw away what somebody meant to keep. */
check(false !== strpos($js, 'function pasteIsFileOnly('), 'a paste carrying text is left alone');
check(false !== strpos($js, "getData( 'text/plain' )"), 'which is decided by looking at the clipboard, not the target');
check(1 === preg_match('/<img\\\\b.*?return false/s', $js) || false !== strpos($js, '/<img\b/i'),
  'while an image copied from a web page is taken, so it lands in this media library');

/* A screenshot arrives unnamed or as image.png, every single time. */
check(false !== strpos($js, 'function renamed('), 'a pasted file is given a name');
check(1 === preg_match('/\/\^\(image\|screenshot\|clipboard\)/', $js), 'including the generic names browsers hand out');
check(false !== strpos($js, 'labelFor(') && false !== strpos($js, 'galleryLabel('),
  'named after the section it was pasted into');

/* The filename is a timestamp, so it must never become what a screen reader
   reads out. */
check(false === strpos($js, 'att.alt || att.title'), 'a generated filename is never used as alt text');
check(false !== strpos($js, "att.alt || 'Work sample'"), 'a plain description is used instead');

/* The media window is WordPress's and says nothing about pasting, so its own
   wording is replaced rather than a line being added after the fact: a string
   rewritten by script flashes the original first. */
$exp = file_get_contents(BFTD_PATH . 'includes/class-bftd-admin-experience.php');
check(false !== strpos($exp, "'Drop files to upload'"), 'the media window drops its own wording');
check(false !== strpos($exp, 'copy and paste images'), 'for wording that mentions pasting');
check(false !== strpos($exp, "if ( 'default' !== \$domain ) return \$translated;"),
  'and only ever touches WordPress core strings, never another plugin\'s');
check(false !== strpos($js, 'wp.media.attachment('), 'a pasted upload is handed back to the window');
check(false !== strpos($js, "props.set( { ignore:"), 'so its grid stops showing a stale list');

/* The empty white pane core draws reads as a page that has not finished
   loading. A dashed outline makes the target the shape it actually is. */
check(1 === preg_match('/\.uploader-inline-content\{[^}]*border:\s*2px dashed/', $css),
  'the drop area is outlined');
check(false !== strpos($css, '.media-modal.drag-over .uploader-inline-content'),
  'and says so while a file is over it');

/* The box can be reached and pasted into without a mouse. */
check(false !== strpos($php, 'tabindex="0"'), 'the work samples box can be focused from the keyboard');
check(false !== strpos($php, 'paste a screenshot'), 'and says that pasting works');
check(false !== strpos($css, '.bftd-gallery-box:focus'), 'with a focus ring, so it is visible where you are');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
