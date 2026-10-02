<?php
/*
 * A save writes what arrived. This is about what it does with what did not.
 *
 * Every field a group knows about is rewritten on every save, so a field absent
 * from the request becomes an empty string on the record. That is right when
 * somebody cleared a text box and catastrophic when the request never carried
 * the field at all, and there are two ways a request arrives short:
 *
 *   The form never had the group. BFTD_Items::save read an absent 'bftd_items'
 *   as an empty list and wrote it, which deleted every priority item and every
 *   For review item on a student. The guard against that existed on the report
 *   branch and not on the student branch. One of the two was always going to be
 *   forgotten, and it was.
 *
 *   PHP stopped reading. Past max_input_vars it takes no more variables and
 *   says nothing: $_POST simply ends. The tail of a long report is then absent,
 *   a save turns absent into empty, and the bigger the record the likelier it
 *   is — which is why it looks like data vanishing from exactly the records
 *   that matter most. The screen sends the number of fields it sent so the
 *   server can tell a short request from a small one.
 */
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

define('BFTD_PATH', dirname(__DIR__).'/');

/* Only the counting and the completeness rule are exercised here: they are the
   whole decision, and reaching them does not need a database. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');

function body_of($src, $name) {
  $start = strpos($src, 'function ' . $name . '(');
  if (false === $start) return '';
  $next = strpos($src, "\n\tpublic static function ", $start + 10);
  $alt  = strpos($src, "\n\tprivate static function ", $start + 10);
  if (false !== $alt && (false === $next || $alt < $next)) $next = $alt;
  return false === $next ? substr($src, $start) : substr($src, $start, $next - $start);
}

/* ---- the items guard, which is the bug that was live ---- */
echo "The lists are only written by a form that was carrying them:\n";
$student_branch = body_of($src, 'save_fields');
check(false === strpos($student_branch, 'BFTD_Items::save( $post_id )'),
  'the student branch no longer calls BFTD_Items::save directly');
check(false !== strpos($student_branch, 'save_items_for_student'),
  'it goes through the guarded path instead');
$guard = body_of($src, 'save_items_for_student');
check('' !== $guard, 'and that path exists');
check(1 === preg_match("/if \( ! isset\( \\\$_POST\['bftd_items'\] \) \) return 0;/", $guard),
  'which refuses to write when the request carried no lists at all');
$report_branch = body_of($src, 'save_items_for_report');
check(false !== strpos($report_branch, 'save_items_for_student'),
  'and the report branch uses the same one, so there is one guard and not two');

/* ---- a whole group absent is not a group of empties ---- */
echo "\nA field group missing entirely is not every field cleared:\n";
$flat = body_of($src, 'save_flat');
check(false !== strpos($flat, 'if ( ! array_key_exists( $key, $posted ) ) {'),
  'save_flat leaves a FIELD alone when the request never carried it');
check(false !== strpos($flat, '$after[ $key ] = $before[ $key ];'),
  'and keeps what was already on the record rather than writing anything');
$rowsfn = body_of($src, 'save_rows_field');
check(false !== strpos($rowsfn, 'return 0;'),
  'and a rows field the request never carried is left alone too');
$sched = $student_branch;
check(1 === preg_match("/isset\( \\\$_POST\['bftd_sched'\] \)\s*\n?\s*&& BFTD_Schedule::save_rules/", $sched),
  'and the schedule is only written when the request carried it');

/* ---- counting, which is how a short request is noticed ---- */
echo "\nCounting what arrived:\n";
require_once BFTD_PATH . 'includes/class-bftd-metaboxes.php';

/* The real method, asked about a real $_POST. Counting it a second time here
   would be testing this file's arithmetic rather than the plugin's, and the two
   agreeing would prove nothing about the one that runs. */
$count = function ($post) {
  return BFTD_MetaBoxes::count_posted_fields($post);
};
check(0 === $count(array()), 'nothing carried counts as nothing');
check(2 === $count(array('bftd' => array('student' => array('school' => 'a', 'grade' => '4')))),
  'two fields in one group count as two');
check(3 === $count(array(
  'bftd'       => array('session' => array('notes' => 'n')),
  'bftd_rows'  => array('session' => array('skills' => array(array('id' => 1), array('id' => 2)))),
)), 'and rows count as the leaves they are, not as one');
check(1 === $count(array('bftd' => array('x' => array('y' => '')), 'post_title' => 'ignored')),
  'post_title is not one of ours, so it is not counted on either side');

/* ---- the rule itself ---- */
echo "\nWhether a request is complete:\n";
/* Also the real method. It reads $_POST, so $_POST is what the test sets: the
   arrived count is made out of real posted fields rather than asserted. */
function complete($sent, $arrived) {
  $_POST = array();
  if (null !== $sent) $_POST['bftd_sent'] = $sent;
  $fields = array();
  for ($i = 0; $i < (int) $arrived; $i++) $fields['f' . $i] = 'v';
  if ($fields) $_POST['bftd'] = array('student' => $fields);
  return BFTD_MetaBoxes::posted_is_complete();
}
check(true  === complete(null, 3),  'a screen that sends no count is believed, not refused');
check(true  === complete(0, 3),     'and a count of zero is treated the same way');
check(true  === complete(40, 40),   'all forty arriving is complete');
check(true  === complete(40, 41),   'more arriving than were sent is not a truncation');
check(false === complete(40, 39),   'one short is a truncation');
check(false === complete(40, 1),    'and a request cut off near the start certainly is');

/* ---- and the refusal is recorded and reported ---- */
echo "\nWhat a refusal does:\n";
check(false !== strpos($src, 'log_refused_save'), 'a refusal is written to the activity log');
$logger = body_of($src, 'log_refused_save');
check(false !== strpos($logger, 'BFTD_Audit::$quiet = false'),
  'even while an autosave has silenced routine logging, which is the only time it happens');
check(false !== strpos($logger, 'Nothing was written, so nothing was lost'),
  'and says plainly that nothing was lost');

$auto = file_get_contents(BFTD_PATH . 'includes/class-bftd-autosave.php');
check(2 === substr_count($auto, 'BFTD_MetaBoxes::posted_is_complete()'),
  'both autosave paths check it: the one that writes the record and the one that keeps a copy');
check(false !== strpos($auto, '413'),
  'and answer the screen with a refusal rather than reporting a save that did not happen');

$audit = file_get_contents(BFTD_PATH . 'includes/class-bftd-audit.php');
check(false !== strpos($audit, "'save_refused'"),
  'the event is registered, so it has a name in the log rather than falling through');

/* ------------------------------------------------------------------------ */
/* A select whose stored value is not one of its options                     */
/* ------------------------------------------------------------------------ */
/*
 * The second way a draft loses data, and the nastier one, because it does not
 * blank a field: it replaces it with a different value that looks right.
 *
 * A select can only offer the options it is given. A stored value that is not
 * among them matches nothing, so the browser selects the FIRST option, and on a
 * draft the autosave writes that first option over the record within seconds of
 * somebody opening it. A session stored as a status this build no longer offers
 * came back as the first status in the list, silently, and the record then said
 * something untrue about a child's attendance.
 *
 * An option key being renamed or retired is an ordinary thing to do. It must
 * not cost data.
 */
echo "\nA select whose stored value it cannot offer:\n";

$fsrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
$render = body_of($fsrc, 'render');
if ('' === $render) $render = $fsrc;   // the renderer may be one big switch

check(1 === preg_match('/!\s*array_key_exists\(\s*\(string\)\s*\$value,\s*\$opts\s*\)/', $fsrc),
  'the renderer notices a stored value its options do not contain');
check(false !== strpos($fsrc, 'not one of the current choices'),
  'and keeps it as a selected option, labelled so a person can see it is unexpected');

$san = body_of($fsrc, 'sanitize_value');
check(false !== strpos($san, "isset( \$field['stored'] )"),
  'and the sanitiser lets that same value back in, or the round trip would still lose it');

$msrc = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($msrc, "\$field['stored'] = \$before[ \$key ];"),
  'the flat save hands the stored value over, so that branch is reachable rather than dead');
check(false !== strpos($fsrc, "\$field['stored'] = \$before[ \$key ];"),
  'and so does the section save, because both write selects');

/* And it must not become a way to post anything you like. */
check(false !== strpos($fsrc, '=== $raw ) return $raw;'),
  'only the value already on the record is let through, not any unrecognised one');

/* ------------------------------------------------------------------------ */
/* The WordPress idiom that makes absence unambiguous                        */
/* ------------------------------------------------------------------------ */
/*
 * Merge is only correct if a field the user cleared still arrives. Every input
 * type here already posts when empty except one: an unticked checkbox posts
 * nothing. So the visibility checkbox has a hidden partner carrying 0, which
 * always posts and which the checkbox overrides when ticked. Absence then means
 * the form did not have the field, and nothing else.
 */
echo "\nAbsence is unambiguous because every field posts:\n";

check(1 === preg_match('/<input type="hidden" name="bftd_visible\[/', $fsrc),
  'the visibility checkbox has a hidden partner that always posts');
check(1 === preg_match('/bftd_visible\[[^\]]*\]"\s*value="0">\s*\n?\s*<label class="bftd-vis"><input type="checkbox"/s', $fsrc),
  'and the partner comes first, so a tick overrides it rather than the other way round');

$savepost = body_of($fsrc, 'save_post');
check(false !== strpos($savepost, 'array_key_exists( $section_id, $posted_vis )'),
  'visibility is only written when the form carried it');
check(1 === preg_match('/if \( ! \$carried \) \{\s*\n\s*\$after\[ \$key \] = \$before\[ \$key \];\s*\n\s*continue;/', $savepost),
  'and a field the form did not carry is kept as it was, not merely noticed');
check(false !== strpos($savepost, 'isset( $posted_rows[ $section_id ][ $key ] )'),
  'with rows asked about where rows actually live');

/* The counter has to count what both sides agree on. Entries and names differ
   the moment one name appears twice, which the hidden partner guarantees. */
echo "\nAnd the completeness count agrees with how PHP collapses names:\n";
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(false !== strpos($js, 'Object.keys( names ).length'),
  'the browser counts distinct field names, not form entries');
check(false === strpos($js, 'if ( groups.test( data[ i ].name ) ) n++;'),
  'because counting entries refused every save on any form with a paired checkbox');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
