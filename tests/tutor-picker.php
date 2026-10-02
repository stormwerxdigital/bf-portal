<?php
/*
 * The per slot tutor picker.
 *
 * Two things have to be true at once. The slot must follow the student's
 * assigned tutor by default, so a reassignment carries every slot with it and
 * nobody has to remember to update a second place. And it must be possible to
 * override one slot for a sick day or a temporary tutor without disturbing
 * who the student belongs to.
 *
 * That is why the default stores a zero rather than the tutor's id: storing
 * the id would freeze the slot to whoever happened to be assigned on the day
 * it was created.
 */
require __DIR__ . '/wp-stubs.php';
if (!function_exists('wp_rand'))  { function wp_rand($a=0,$b=0){ return 1234; } }
if (!function_exists('selected')) { function selected($a,$b=true,$e=true){ $r=$a==$b?' selected':''; if($e) echo $r; return $r; } }
function get_userdata($id){ return $GLOBALS['USERS'][$id] ?? null; }

class TU { public $ID,$display_name,$user_email,$user_login;
  function __construct($i,$n,$e){ $this->ID=$i; $this->display_name=$n; $this->user_email=$e; $this->user_login=$n; } }
$GLOBALS['USERS'] = array(
  3 => new TU(3,'Laurel Sanders','laurel@bftutoring.com'),
  5 => new TU(5,'Dana Cole','dana@bftutoring.com'),
);
class BFTD_Schedule { public static function effective_tutor($sid,$r=0){ return $r ? $r : ($GLOBALS['ASSIGNED'] ?? 0); } }
class BFTD_Roles { public static function role_name($id){ return 'Tutor'; } }

$src = file_get_contents(dirname(__DIR__).'/includes/class-bftd-metaboxes.php');
$a = strpos($src, "\tpublic static function tutor_picker(");
$b = strpos($src, "\tpublic static function box_priority(", $a);
if ($a === false || $b === false) { echo "FAIL: tutor_picker() is not in the file\n"; exit(1); }
eval('class MB { ' . substr($src,$a,$b-$a) . ' }');

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
/* What the hidden input actually posts. Matching on value="0" anywhere in
   the markup is not good enough: every option carries data-value="0" or
   similar, and the substring would match those instead, so the assertion
   would pass no matter what the field held. */
function posts($h) {
  return preg_match('/<input type="hidden"[^>]*\bvalue="([^"]*)"/', $h, $m) ? $m[1] : null;
}

function render($selected, $assigned = 3) {
  $GLOBALS['ASSIGNED'] = $assigned;
  ob_start();
  MB::tutor_picker('bftd_sched[0][tutor]', array_values($GLOBALS['USERS']), $selected, 39);
  return ob_get_clean();
}

/* --- on the default --- */
$h = render(0);
check(posts($h) === '0', 'a slot on the default stores a zero, not a tutor id, got ' . var_export(posts($h), true));
check(strpos($h,'Assigned (Laurel Sanders)') !== false, 'and names who that currently resolves to');
check(strpos($h,'name="bftd_sched[0][tutor]"') !== false, 'a hidden field is what posts, so saving does not need the widget');
check(strpos($h,'bftd-s-tutor') !== false, 'and it keeps the class the schedule preview reads');
check(strpos($h,'is-set') === false, 'a default slot is not marked as overridden');
check(preg_match('/bftd-pick-x"[^>]*\bhidden/', $h) === 1, 'with nothing to clear');

/* Searchable by name and by email, which is the whole point of the control. */
check(strpos($h,'data-search="laurel sanders laurel@bftutoring.com laurel sanders"') !== false, 'every tutor is searchable by name and email');
check(substr_count($h,'bftd-pick-opt') === 3, 'the list holds the default plus every tutor');
check(strpos($h,'dana@bftutoring.com') !== false, 'and shows the email, so two people with one name are told apart');

/* --- overridden --- */
$h = render(5);
check(posts($h) === '5', 'an overridden slot stores the tutor it was given');
check(strpos($h,'value="Dana Cole"') !== false, 'and shows that name rather than the default');
check(strpos($h,'is-set') !== false, 'marked as overridden, so a table of slots can be scanned');
check(preg_match('/bftd-pick-x"[^>]*\bhidden/', $h) === 0, 'and offers a way back to the default');

/* The default option is still there, because that is how an override is undone. */
check(strpos($h,'Assigned (Laurel Sanders)') !== false, 'the default is still offered on an overridden slot');

/* --- the default is never frozen --- */
$h = render(0, 5);
check(strpos($h,'Assigned (Dana Cole)') !== false, 'reassigning the student changes what the default means');
check(posts($h) === '0', 'without changing what the slot stores');

/* --- nobody assigned yet --- */
$h = render(0, 0);
check(strpos($h,'>Assigned<') !== false || strpos($h,'value="Assigned"') !== false,
  'a student with no tutor yet gets the plain wording, not an empty bracket');
check(strpos($h,'assigned ()') === false, 'and definitely not an empty bracket');

/* ---------------------------------------------------------------- */
/* The same control with no default, which is how a report field uses */
/* it: "Assessed by" is either somebody or nobody.                    */
/* ---------------------------------------------------------------- */

ob_start();
MB::person_picker('bftd[sec][assessed_by]', array_values($GLOBALS['USERS']), 0, array(
  'aria' => 'Assessed by', 'placeholder' => 'Search tutors by name or email',
));
$h = ob_get_clean();

check(strpos($h,'data-value="0"') === false, 'with no default label there is no default option to pick');
check(posts($h) === '0', 'and an unset field posts nothing');
check(strpos($h,'value=""') !== false, 'showing the placeholder rather than a word');
check(strpos($h,'placeholder="Search tutors by name or email"') !== false, 'and saying what can be searched');
check(substr_count($h,'bftd-pick-opt') === 2, 'the list is just the people');

ob_start();
MB::person_picker('bftd[sec][assessed_by]', array_values($GLOBALS['USERS']), 5, array('aria' => 'Assessed by'));
$h = ob_get_clean();
check(posts($h) === '5', 'a chosen person is stored as an id, not as their name');
check(strpos($h,'value="Dana Cole"') !== false, 'and shown by name');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
