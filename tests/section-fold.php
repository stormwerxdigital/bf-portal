<?php
/*
 * Folding a report section away in the editor.
 *
 * A reading diagnostic is six panels and each one is a screenful. Folding one
 * is a convenience, and the danger with a convenience is that it quietly costs
 * something: a field that leaves the form when its panel is shut is a field
 * that stops being saved, and neither the tutor nor the screen would say so.
 *
 * So what is held here is the part that would be silent when broken. The
 * fields stay inside the form. The control is a real button rather than a
 * clickable div. The visibility checkbox, which decides something about the
 * family's report rather than about this screen, cannot be swallowed by the
 * thing that folds the panel. And storage, which is a convenience on top of a
 * convenience, can fail without taking the screen with it.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$php = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
$js  = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
$box = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');

/* ---- the markup ---- */

check(1 === preg_match('/class="bftd-section-toggle"/', $php),
  'the section header carries a toggle');
check(1 === preg_match('/<button type="button" class="bftd-section-toggle"/', $php),
  'and it is a button, so it is reachable by keyboard without being made one');
check(1 === preg_match('/aria-expanded="true"/', $php),
  'which says whether the panel is open');
check(1 === preg_match('/aria-controls="bftd-body-/', $php),
  'and names the panel it controls');
check(1 === preg_match('/class="bftd-section-body" id="bftd-body-/', $php),
  'that panel is a container of its own');

/* A <button> may only hold phrasing content, so the heading cannot be inside
   it. Getting this backwards produces markup browsers tolerate and assistive
   technology reads unpredictably. */
check(1 === preg_match('/<h3>\s*<button type="button" class="bftd-section-toggle"/s', $php),
  'the button sits inside the heading, not the heading inside the button');

/* ---- nothing leaves the form ---- */

/* Every field is drawn inside the container that gets hidden, and the fields
   are inside a form, so a hidden panel still posts. What must never happen is
   the panel being emptied or its inputs disabled. */
$body_start = strpos($php, 'class="bftd-section-body"');
$body_end   = strpos($php, 'render_thread_note', $body_start);
$inside     = substr($php, $body_start, $body_end - $body_start);
check(false !== strpos($inside, 'self::render_field('),
  'the fields are drawn inside that container');
check(0 === preg_match('/\.bftd-section-body[^{]*\{[^}]*visibility\s*:\s*hidden/', $css),
  'and it is hidden by display, not left occupying the page');
check(0 === preg_match('/is-shut[\s\S]{0,400}(\.remove\(\)|\.empty\(\)|disabled)/', $js),
  'folding a panel never removes, empties or disables anything in it');

/* ---- the checkbox is not part of the control ---- */

check(1 === preg_match('/bftd-section-meta[\s\S]{0,600}bftd_visible\[/', $php),
  "the family-visibility checkbox lives in the header's right hand side");
check(1 === preg_match('/closest\( .\.bftd-section-meta. \)\.length \) return;/', $js),
  'and a click there is not a click on the fold');

/* ---- the CSS hides the body only ---- */

check(1 === preg_match('/\.bftd-section\.is-shut \.bftd-section-body\{display:none\}/', $css),
  'a shut panel hides its body');
check(0 === preg_match('/\.bftd-section\.is-shut\{[^}]*display:none/', $css),
  'and never its own header, which is what is left to click');

/* ---- remembering, without depending on it ---- */

check(1 === preg_match('/localStorage/', $js), 'the screen remembers what was folded');
check(2 <= preg_match_all('/catch \( e \) \{/', $js),
  'and every read and write of that is guarded, because storage can be off or full');
check(1 === preg_match('/function remembered\(\)[\s\S]{0,260}return \[\];/', $js),
  'a storage failure leaves every panel open rather than stopping the screen');

/* ---- the defaults ---- */

/* Starting them closed would open a half-written report looking empty. */
check(0 === preg_match('/class="bftd-section [^"]*is-shut/', $php),
  'panels start open, so nothing a tutor was working on is hidden from them');
check(false !== strpos($box, 'bftd-secall-shut') && false !== strpos($box, 'bftd-secall-open'),
  'with one control to fold them all, and one to open them again');

/* A jump link that lands on a folded panel shows a heading with nothing under
   it, which reads as broken rather than as folded. */
check(1 === preg_match('/bftd-jump a[\s\S]{0,260}setOpen\( \$sec, true \)/', $js),
  'and a jump link opens what it lands on');

/* ------------------------------------------------------------------ */
/* The "shown to the family" pill, once it is out of date              */
/* ------------------------------------------------------------------ */

/* Each section header states whether the family will see it. That is worked
   out on the server as the page is drawn, so the moment somebody types into
   the section it describes the last save rather than the screen.

   It read "Empty, so not shown to the family" beside a figure that had just
   been entered, which looks exactly like the report dropping the section, and
   was reported as one. */
check(1 === preg_match('/bftd-pill[\s\S]{0,400}Not saved yet/', $js),
  'a pill stops claiming to know once the section has been edited');
check(1 === preg_match("/\\\$box\\.on\\( 'input change', '\\.bftd-section :input'/", $js),
  'which it notices from any field in that section');
check(1 === preg_match('/bftdPill/', $js),
  'including the editors, whose typing never reaches the form');

/* It must not try to work the answer out again. Whether a section counts as
   empty turns on which text is the practice's boilerplate and which a tutor
   wrote, and a second copy of that rule in the browser would drift from the
   one that decides what a family actually sees. */
/* Read from the code, not the comments. The comment above that handler quotes
   the wording it exists because of, and a check across the whole file finds the
   quotation and passes whatever the code does. That has now happened three
   times in this suite. */
$js_code = preg_replace('#/\*[\s\S]*?\*/#', '', $js);
$js_code = preg_replace('#(^|\n)\s*//[^\n]*#', '$1', $js_code);
check(0 === preg_match('/Empty, so not shown/', $js_code),
  'and never recomputes the answer the server owns');
check(0 === preg_match('/Live for the family/', $js_code),
  'in either direction');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
