<?php
/*
 * Families with more than one student (Karl, 1.158). Two or more: an overview
 * card per student (name, tutor, attendance, sessions purchased and left, last
 * session, next session, schedule link), and a student is picked to see their
 * reports. One student: straight to that student, as before.
 */
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$root = dirname(__DIR__);
$d = file_get_contents("$root/includes/class-bftd-dashboard.php");
check(false !== strpos($d, "if ( count( \$students ) > 1 && ! \$picked ) {\n\t\t\treturn self::family_overview( \$students, \$family_id, \$user_id );"), 'two or more students and none picked: the overview');
check(false !== strpos($d, "\$picked     = isset( \$_GET['student'] ) ? (int) \$_GET['student'] : 0;"), 'picking a student opens that student');
check(false !== strpos($d, "\$student_id = (int) \$students[0]; \$picked = 0; }"), 'a student not in the family falls back to the overview, not someone else\'s portal');
foreach (array('<dt>Tutor</dt>', '<dt>Attendance</dt>', '<dt>Sessions</dt>', '<dt>Last session</dt>', '<dt>Next session</dt>', '>See reports</a>', '>Schedule</a>') as $bit) {
  check(false !== strpos($d, $bit), "each card shows $bit");
}
check(false !== strpos($d, "BFTD_Derived::attendance( \$report )") && false !== strpos($d, 'BFTD_Schedule::next_lesson_label( $student_id )')
  && false !== strpos($d, 'BFTD_Schedule::bank( $student_id )') && false !== strpos($d, 'BFTD_Schedule::taught_sessions( $student_id )'),
  'every figure is read from the records the rest of the portal uses');
check(false !== strpos($d, "case 'schedule':                    self::screen_schedule( \$student_id ); break;"), 'a student has a read-only schedule screen');
check(false !== strpos($d, "in_array( \$screen, array( 'messages', 'schedule' ), true )"), 'which the portal accepts as a screen');
check(false !== strpos($d, 'class="bf-allkids"'), 'and a family with several students can get back to all of them');
check(false === strpos(substr($d, strpos($d, 'private static function screen_schedule'), 2500), 'Reschedule</button>'), 'the family schedule offers no staff controls');
$c = file_get_contents("$root/assets/css/bftd-portal.css");
check(false !== strpos($c, '.bf-kids{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,340px),1fr))'), 'cards flow as many across as fit, one column on a phone');
echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
