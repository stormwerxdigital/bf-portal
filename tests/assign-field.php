<?php
/*
 * The assignment picker must arrive finished.
 *
 * The bug this guards against is not a wrong result, it is a visible one: if
 * PHP sends an empty chip list and a bare multi-select, the browser paints a
 * tall listbox and then JavaScript rearranges it into chips a moment later.
 * Whoever opened the page sees the box change shape under them. So the
 * assertions here are all about what the FIRST byte says, before any script
 * has run.
 */
require __DIR__ . '/wp-stubs.php';
if (!function_exists('wp_rand'))  { function wp_rand($a=0,$b=0){ return 1234; } }
if (!function_exists('selected')) { function selected($a,$b=true,$e=true){ return $a==$b?' selected':''; } }

class TestUser {
  public $ID, $display_name, $user_email, $user_login;
  function __construct($i,$n,$e){ $this->ID=$i; $this->display_name=$n; $this->user_email=$e; $this->user_login=$n; }
}

/* Only the renderer is under test, so it is lifted out rather than booting
   the plugin, which would want a database. */
$src = file_get_contents(dirname(__DIR__).'/includes/class-bftd-metaboxes.php');
$a = strpos($src, "\tpublic static function assign_field(");
$b = strpos($src, "\t/* ------------------------------------------------------------------ */\n\t/* Student", $a);
if ($a === false || $b === false) { echo "FAIL: assign_field() is not in the file at all\n"; exit(1); }
eval('class AF { ' . substr($src, $a, $b - $a) . ' }');

$users = array(
  new TestUser(1,'Laurel Sanders','laurel@bftutoring.com'),
  new TestUser(2,'karlsanders','karl@stormwerxdigital.com'),
  new TestUser(3,'Dana Cole','dana@bftutoring.com'),
);

function render($sel, $editable = true, $empty = 'Nobody linked yet.') {
  global $users;
  ob_start();
  AF::assign_field('bftd_staff', $users, $sel, $empty, 'Some help.', $editable, function($u){ return 'Tutor'; });
  return ob_get_clean();
}

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* --- two people already assigned --- */
$h = render(array(1,2));

/* The noscript rule is printed once per request, so it can only be looked
   for in the first field rendered. */
check(substr_count($h,'<noscript>') === 1, 'a noscript rule restores the select when scripts are off');
check(strpos($h,'.bftd-chips') !== false, 'and it hides the chips, which do nothing without script');

check(substr_count($h,'class="bftd-chip"') === 2, 'both chips are in the HTML the server sends');
check(strpos($h,'Laurel Sanders') !== false && strpos($h,'karlsanders') !== false, 'and they carry the right names');
check(substr_count($h,'bftd-chip-x') === 2, 'each has a remove button');
check(strpos($h,'bftd-chip-none') === false, 'the empty message is not sent when somebody is assigned');

/* The one that matters: the select is hidden before the browser sees it. */
check(preg_match('/class="[^"]*bftd-assign-select[^"]*bftd-assign-hidden/', $h) === 1,
  'the select is already hidden in the markup, not hidden later by script');

/* It still has to be the thing that posts. */
check(strpos($h,'name="bftd_staff[]"') !== false, 'the select still posts the value');
check(substr_count($h,'<option') === 3, 'every candidate is still an option');
check(substr_count($h,' selected') === 2, 'the right two options are selected');

/* --- nobody assigned --- */
$h = render(array(), true, 'Nobody yet. Whoever saves this first is added automatically.');
check(strpos($h,'Nobody yet. Whoever saves this first') !== false, 'the empty message is server rendered too');
check(strpos($h,'class="bftd-chip"') === false, 'and no chips are sent with it');
check(strpos($h,'data-empty="Nobody yet.') !== false, 'the wording is on the wrapper for the script to reuse');

/* --- read only --- */
$h = render(array(3), false);
check(strpos($h,'bftd-chip-x') === false, 'a read only field sends no remove buttons');
check(strpos($h,'disabled') !== false, 'and its select is disabled, so saving cannot strip the list');
check(strpos($h,'bftd-assign-in') === false, 'and there is no search box to tease with');

/* --- a field that holds one person --- */
/* The client is one person, and the picker is the same widget. If a second
   name can ride along, the field becomes a caregiver list with a different
   label and the save takes whichever is first — which looks like nothing
   going wrong at all. */
function render_one($sel) {
  global $users;
  ob_start();
  AF::assign_field('bftd_client_primary', $users, $sel, 'Nobody yet.', '', true, null, 1);
  return ob_get_clean();
}
$h = render_one(array(1, 3));
check(substr_count($h, ' selected') === 1, 'a one-person field arrives holding one person, got ' . substr_count($h, ' selected'));
check(strpos($h, 'class="bftd-chip"') !== false && substr_count($h, 'class="bftd-chip"') === 1, 'and draws one chip');
check(strpos($h, 'data-max="1"') !== false, 'saying so on the wrapper, which is what stops the script adding a second');
check(strpos(render(array(1, 3)), 'data-max="0"') !== false, 'while the lists that hold several say otherwise');

/* --- and only once --- */
check(strpos(render(array(2)),'<noscript>') === false, 'and it is not repeated on every field');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
