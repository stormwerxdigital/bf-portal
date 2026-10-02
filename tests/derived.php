<?php
/*
 * The four tables a progress report builds rather than stores.
 *
 * Each of these used to be typed twice: a lesson was marked missed on the
 * lesson and the cancellation typed again into a table on the report; a text
 * was listed on the activity that uses it and typed again into the reading
 * log; the skills an activity teaches were written on the activity and the
 * grid underneath built from activity names instead. The copy a family read
 * was always the stale one, because it was the one nobody was looking at while
 * they worked.
 *
 * So what is checked here is not the drawing. It is that each table is the
 * answer the records already give, and that a section with no stored field of
 * its own is not reported to a tutor as empty.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['SESS']  = array();   // lesson id => field => value
$GLOBALS['ACTS']  = array();   // lesson id => list of activities
$GLOBALS['TEXTS'] = array();   // activity id => texts
$GLOBALS['SKILL'] = array();   // lesson id => skill id => skill
$GLOBALS['DX']    = array();   // diagnostics, oldest first
$GLOBALS['DXV']     = array();   // diagnostic id => section => values
$GLOBALS['STUDENT'] = array();   // the student record

class BFTD_Fields {
  /* The real one applies a field's derivation, and it can only do that when
     it has the field definition — which a bare get() call does not pass. So
     this refuses to answer for a derived key, the way the real one does: ask
     BFTD_Fields::get for "correct words per minute" and you get the empty box
     it is stored in, because it is not stored anywhere.

     A stub that answered anyway is how a chart that reads nothing on every
     real report passed its tests. */
  private static $derived = array('dxsec-4' => array('score'));

  public static function get($id, $sec, $key, $f = array()) {
    if ('student' === $sec) return $GLOBALS['STUDENT'][$key] ?? '';
    if (in_array($key, self::$derived[$sec] ?? array(), true)) return '';
    if (isset($GLOBALS['DXV'][$id][$sec][$key])) return $GLOBALS['DXV'][$id][$sec][$key];
    return $GLOBALS['SESS'][$id][$key] ?? '';
  }

  /* And this one does, because it walks the schema. */
  public static function get_section($id, $sec) {
    $v = $GLOBALS['DXV'][$id][$sec] ?? array();
    if ('dxsec-4' === $sec) {
      $w = trim((string) ($v['words'] ?? ''));
      $e = trim((string) ($v['errors'] ?? ''));
      $v['score'] = ('' !== $w) ? (string) ((int) $w - (int) $e) : (string) ($v['kinder_score'] ?? '');
    }
    return $v;
  }

  public static function has_value($v) { return '' !== trim((string) $v); }
}
class BFTD_CPT {
  const ASSESSMENT = 'bftd_assessment';
  public static function sessions_in_order($rid) { return array_keys($GLOBALS['SESS']); }
  public static function sessions_for($rid, $drafts = false) { return array_keys($GLOBALS['SESS']); }
  public static function student_id($rid) { return 7; }
  public static function diagnostics_for($sid) { return $GLOBALS['DX']; }
  /* The real rule, near enough: an id resolves to a name and the two words
     this field used to offer still read as something. A stub that echoed the
     stored value back would have let a report print "31" at a parent. */
  public static function cancelled_by_label($v, $student = 0) {
    if (ctype_digit((string) $v)) return $GLOBALS['PEOPLE'][(int) $v] ?? '';
    $was = array('family' => 'Family', 'tutor' => 'Brilliant Futures');
    return $was[$v] ?? '';
  }
}
$GLOBALS['PEOPLE'] = array(31 => 'Laurel Sanders (Client)', 5 => 'Rae T (Tutor)');
class BFTD_Activities {
  public static function for_session($sid) { return $GLOBALS['ACTS'][$sid] ?? array(); }
  public static function texts_of($aid) { return $GLOBALS['TEXTS'][$aid] ?? array(); }
}
class BFTD_Skills {
  public static function for_session($sid) { return $GLOBALS['SKILL'][$sid] ?? array(); }
}
function get_the_title($id) { return 'Lesson ' . $id; }

require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-derived.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ================================================================= */
/* Missed lessons                                                     */
/* ================================================================= */

$GLOBALS['SESS'] = array(
  801 => array('session_date' => '2026-07-07', 'status' => 'held'),
  802 => array('session_date' => '2026-08-06', 'status' => 'rescheduled',
               'missed_by' => '31', 'missed_why' => 'Family away',
               'missed_madeup' => '2026-08-08'),
  803 => array('session_date' => '2026-08-20', 'status' => 'missed',
               'missed_by' => 'tutor', 'missed_why' => 'Tutor unwell'),
  804 => array('session_date' => '2026-09-01', 'status' => 'held'),
);

$missed = BFTD_Derived::missed(1);
check(count($missed) === 2, 'only the lessons that did not happen, got ' . count($missed));
check($missed[0]['status'] === 'rescheduled' && $missed[1]['status'] === 'missed',
  'and a lesson that was moved is not recorded as one that was lost');
check($missed[0]['reason'] === 'Family away', 'the reason comes off the lesson it was given on');
check($missed[0]['made_up'] === '2026-08-08', 'and so does the day it was made up');
check($missed[0]['ts'] < $missed[1]['ts'], 'oldest first, the way the rest of the report reads');

/* Who cancelled it is a person, resolved here rather than at each drawing.
   "Family" told a practice nothing it could act on, and it was also what the
   record said when somebody picked the wrong one of two options. */
check($missed[0]['who_name'] === 'Laurel Sanders (Client)',
  'the row names the person who cancelled it, got ' . $missed[0]['who_name']);
check($missed[1]['who_name'] === 'Brilliant Futures',
  'and a record made before this asked for a name still reads as something');

/* Whether it counts is the status, not a box. There was a dropdown on every
   cancellation, answered by whoever wrote it up, and a practice cannot run a
   policy that is a different answer on each record. A cancellation spends the
   hour that was held; a reschedule is the same session on another day. */
check($missed[0]['counted'] === 'no', 'a rescheduled session is not spent');
check($missed[1]['counted'] === 'yes', 'and a cancelled one is');
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-derived.php');
check(false === strpos($src, 'missed_counts'),
  'and nothing reads the old box, which could only ever disagree with the status');

/* A lesson that went ahead is never in this table, whatever else is on it. A
   tutor who marked a lesson missed, wrote a reason, and then changed the
   status back to attended must not leave the reason behind on the report. */
$GLOBALS['SESS'][801]['missed_why'] = 'left over from an earlier mistake';
$again = BFTD_Derived::missed(1);
check(count($again) === 2, 'a leftover reason on an attended lesson does not put it in the table');

/* ================================================================= */
/* The reading log                                                    */
/* ================================================================= */

/* Texts are recorded on the LESSON, not on the activity.

   They were kept on the activity, on the reasoning that an activity always
   uses the same texts. It does not: the same activity is run with whatever the
   child is reading that week, so a text written onto an activity turned up in
   the reading log of every child who had ever done it, dated to their lesson,
   whether or not they had read it. */
$GLOBALS['SESS'] = array(
  801 => array('session_date' => '2026-07-21', 'texts' => array(
    array('title' => 'The Yucky Feeling', 'level' => 'Grade 1'))),
  802 => array('session_date' => '2026-07-23', 'texts' => array(
    array('title' => 'The Yucky Feeling', 'level' => 'Grade 1'),
    array('title' => '',                   'level' => 'Grade 2'))),
  803 => array('session_date' => '2026-08-11', 'texts' => array(
    array('title' => 'Rook of the Pines', 'level' => 'Grade 4'))),
);

$log = BFTD_Derived::texts(1);
check(count($log) === 2, 'one line per text, not one per reading, got ' . count($log));
check($log[0]['title'] === 'The Yucky Feeling' && $log[0]['date'] === '2026-07-21',
  'dated the lesson it was first read in');
check($log[0]['times'] === 2, 'with the number of lessons it came back in');
check($log[0]['level'] === 'Grade 1', 'and the level recorded with it');
check($log[1]['title'] === 'Rook of the Pines', 'every lesson contributes what it read');

/* A row with a level and no title is somebody halfway through typing. It is
   not a book, and a blank line on a family's reading log reads as a fault. */
check(count($log) === 2, 'a row with no title is not a text');

/* And an activity contributes nothing now, whatever is on it: the log asks the
   lessons and only the lessons. */
$GLOBALS['ACTS'] = array(801 => array(array('id' => 11, 'label' => 'Read, Read Back, Read Again')));
$GLOBALS['TEXTS'] = array(11 => array(array('title' => 'From the activity', 'level' => '')));
$again = BFTD_Derived::texts(1);
check(count($again) === 2, 'an activity puts nothing into the reading log');
foreach ($again as $one) check('From the activity' !== $one['title'], 'not even when it still carries texts');
$GLOBALS['ACTS'] = array(); $GLOBALS['TEXTS'] = array();

/* ================================================================= */
/* The spiral                                                         */
/* ================================================================= */

$skill = function ($id, $name) { return array('id' => $id, 'name' => $name, 'from' => array(), 'note' => '', 'about' => ''); };
$GLOBALS['SESS'] = array(
  801 => array('session_date' => '2026-07-07'),
  802 => array('session_date' => '2026-07-09'),
  803 => array('session_date' => '2026-07-13'),   // nothing reached
  804 => array('session_date' => '2026-07-14'),
);
$GLOBALS['SKILL'] = array(
  801 => array(5 => $skill(5, 'Mapping sounds'), 9 => $skill(9, 'Forming letters')),
  802 => array(5 => $skill(5, 'Mapping sounds'), 7 => $skill(7, 'Reading a pattern')),
  803 => array(),
  804 => array(5 => $skill(5, 'Mapping sounds')),
);

$grid = BFTD_Derived::skills(1);
check(count($grid['lessons']) === 3, 'a lesson that reached nothing is not a column');
check(count($grid['rows']) === 3, 'one row per skill, got ' . count($grid['rows']));

/* The whole reason this is skills and not activities: one skill reached by two
   different activities is one row with two marks, and that is the spiral. */
check($grid['rows'][5] === array(0 => 'new', 1 => 'again', 2 => 'again'),
  'a skill reached again is the same row, marked again');
check($grid['rows'][9] === array(0 => 'new'), 'a skill reached once is marked once');
check($grid['rows'][7] === array(1 => 'new'),
  'and a skill first reached in a later lesson is introduced there, not in lesson one');

/* In the order the child met them. Alphabetical would scatter the diagonal
   that makes a spiral look like one. */
check(array_keys($grid['rows']) === array(5, 9, 7), 'rows in the order they were first reached');

/* ================================================================= */
/* The shape of a programme, and the journey through it               */
/* ================================================================= */

$GLOBALS['DX'] = array(
  array('id' => 901, 'on' => '2026-07-05', 'ts' => BFTD_Time::stamp('2026-07-05')),
  array('id' => 902, 'on' => '2026-08-04', 'ts' => BFTD_Time::stamp('2026-08-04')),
  array('id' => 903, 'on' => '2026-09-01', 'ts' => BFTD_Time::stamp('2026-09-01')),
);
$GLOBALS['DXV'] = array(
  901 => array('sec-assessment-overview' => array('kind' => 'initial'),
               'dxsec-3' => array('score' => 'preprimer')),
  902 => array('sec-assessment-overview' => array('kind' => 'interim'),
               'dxsec-3' => array('score' => 'primer')),
  903 => array('sec-assessment-overview' => array('kind' => 'middle'),
               'dxsec-3' => array('score' => 'grade-2')),
);
$GLOBALS['STUDENT'] = array('grade' => '8');

/* ---- which of the four an assessment is ---- */
check('initial' === BFTD_Derived::kind_of(901), 'an assessment says which of the four it is');
check('interim' === BFTD_Derived::kind_of(902), 'including an interim, which can happen any time');
$GLOBALS['DXV'][902]['sec-assessment-overview']['kind'] = 'invented';
check('initial' === BFTD_Derived::kind_of(902), 'and a kind nobody offers is an initial, not a fifth kind');
$GLOBALS['DXV'][902]['sec-assessment-overview']['kind'] = 'interim';

/* ---- the three milestones ---- */
$m = BFTD_Derived::milestones(1);
check(array('initial', 'middle', 'final') === array_keys($m),
  'three milestones, in the order a programme happens');
check($m['initial']['done'] && $m['middle']['done'], 'the two that have been done say so');
check(!$m['final']['done'], 'and the one still ahead says so rather than being left out');
check($m['initial']['on'] === '2026-07-05', 'a done milestone carries its date');
check($m['final']['on'] === '', 'and one to come carries none, because there is none');

/* An interim is not one of the three. It is a real reading and it is not the
   mid-programme reassessment, and a card saying "Middle · 4 Aug" because
   somebody reassessed in August would be telling a family the programme was
   halfway when it was not. */
foreach ($m as $one) check('interim' !== $one['kind'], 'an interim is not one of the three milestones');

/* Two of the same kind describe one moment, and the card is about when that
   moment happened, so the earlier one is what dates it. */
$GLOBALS['DX'][] = array('id' => 904, 'on' => '2026-10-01', 'ts' => BFTD_Time::stamp('2026-10-01'));
$GLOBALS['DXV'][904] = array('sec-assessment-overview' => array('kind' => 'middle'),
                             'dxsec-3' => array('score' => 'grade-3'));
check('2026-09-01' === BFTD_Derived::milestones(1)['middle']['on'],
  'a second assessment of the same kind does not re-date the milestone');
array_pop($GLOBALS['DX']);
unset($GLOBALS['DXV'][904]);

/* ---- the journey ---- */
$j = BFTD_Derived::grade_journey(1);
check(count($j['points']) === 3, 'every assessment that scored a reading level is a point, got ' . count($j['points']));
check($j['points'][0]['level'] === 'Preprimer', 'starting where they started');
check($j['points'][2]['level'] === 'Grade 2', 'and ending where they are');
check($j['points'][0]['when'] === 'Initial' && $j['points'][1]['when'] === 'Interim',
  'each point named by which assessment it was');

/* The target is the reading level of the child\'s own grade. A Grade 8 reading
   at preprimer is not aiming at "better", they are aiming at Grade 8, and the
   distance between those two is what the chart exists to show. */
check($j['target']['level'] === 'Grade 8', 'the target is the grade they are in');
check($j['target']['rank'] === 10, 'as a place on the same scale the readings use');

$GLOBALS['STUDENT']['grade'] = '3';
check(BFTD_Derived::grade_journey(1)['target']['level'] === 'Grade 3', 'a Grade 3 child targets Grade 3');
$GLOBALS['STUDENT']['grade'] = '0';
check(BFTD_Derived::grade_journey(1)['target']['level'] === 'Primer', 'and Kindergarten targets Primer');
$GLOBALS['STUDENT']['grade'] = '11';
check(BFTD_Derived::grade_journey(1)['target']['level'] === 'Grade 8',
  'a grade past eight targets Grade 8, because that is where these levels stop');
$GLOBALS['STUDENT']['grade'] = 'adult';
check(BFTD_Derived::grade_journey(1)['target']['level'] === 'Grade 8', 'and so does an adult learner');

/* No grade on the record means no target, and a chart whose whole point is the
   distance to somewhere cannot be drawn without the somewhere. */
$GLOBALS['STUDENT']['grade'] = '';
check(array() === BFTD_Derived::grade_journey(1), 'a student with no grade on file draws nothing');
$GLOBALS['STUDENT']['grade'] = '8';

/* An assessment that did not score this measure is not a point on it. */
$GLOBALS['DXV'][902]['dxsec-3'] = array('score' => '');
check(count(BFTD_Derived::grade_journey(1)['points']) === 2,
  'an assessment that did not reach this measure is left off rather than plotted at zero');
$GLOBALS['DXV'][902]['dxsec-3'] = array('score' => 'primer');

/* ================================================================= */
/* What the tutor's pill says                                         */
/* ================================================================= */

/* A section with no stored field of its own was being called empty, so the
   pill told a tutor their family could not see a table the family could
   plainly see. */
check(BFTD_Derived::is_derived('sec-sessions'), 'Every lesson is built, not filled in');
foreach (array('sec-attendance', 'sec-fluency', 'sec-texts-read', 'sec-activity-map', 'sec-progress-overview') as $id) {
  check(BFTD_Derived::is_derived($id), "$id is built, not filled in");
}
check(!BFTD_Derived::is_derived('sec-welcome'), 'while a written section still reads its own fields');

$GLOBALS['SESS'] = array();
check(!BFTD_Derived::has('sec-sessions', 1), 'a report with no lessons has no Every lesson section');
$GLOBALS['SESS'] = array(801 => array('session_date' => '2026-07-07', 'status' => 'held'));
check(BFTD_Derived::has('sec-sessions', 1), 'and one with a lesson does');
check(BFTD_Derived::has('sec-attendance', 1), 'and a report with one has an attendance panel');

/* The renderer and the pill must agree, or a tutor is told a section is live
   and the family sees nothing. */
$fields = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/function section_has_content\([\s\S]{0,700}BFTD_Derived::has\(/', $fields),
  'so the pill asks the thing that builds the section');

/* ================================================================= */
/* A fixture answers for the keys it carries, and only those          */
/* ================================================================= */

/* The sample report mounts a fixture for the tables it cannot build, because
   Jot has no lessons in the database to build them from. It does have lessons
   in the fixture, though, and the reading log is built from lessons — so that
   one the sample CAN build, and building it is the point of showing it.

   A mounted fixture used to answer for every key, present or not: an absent
   key returned an empty table instead of letting the derivation run. The
   sample's reading log came out empty, on the one report whose whole job is to
   show a family what a report looks like. */
$GLOBALS['SESS'] = array(
  801 => array('session_date' => '2026-07-07', 'status' => 'held',
    'texts' => array(array('title' => 'Rook of the Pines', 'level' => 'Grade 4'))),
);

BFTD_Derived::use_fixture(array(
  'missed' => array(array('id' => 9, 'status' => 'rescheduled', 'date' => '2026-08-06', 'ts' => 0)),
));

$m = BFTD_Derived::missed(1);
check(1 === count($m) && 9 === $m[0]['id'], 'a key the fixture carries is the fixture\'s answer');

$t = BFTD_Derived::texts(1);
check(1 === count($t) && 'Rook of the Pines' === $t[0]['title'],
  'and a key it does not carry is derived for real, so the sample\'s reading log is its own lessons');

BFTD_Derived::clear_fixture();
check(1 === count(BFTD_Derived::texts(1)), 'with no fixture at all, nothing changes');

/* An empty fixture is the same as no fixture: it carries no keys. */
BFTD_Derived::use_fixture(array());
check(1 === count(BFTD_Derived::texts(1)), 'and mounting an empty fixture hides nothing');
BFTD_Derived::use_fixture(array('texts' => array()));
check(array() === BFTD_Derived::texts(1),
  'while a fixture that deliberately carries an empty log gets the empty log it asked for');
BFTD_Derived::clear_fixture();

/* ================================================================= */
/* Words per minute                                                   */
/* ================================================================= */

/* The target is the benchmark for the grade the child is IN, not the band a
   tutor picked on the diagnostic. A Grade 8 reading at fourteen words a minute
   is aiming at 151, and a tutor measuring this term against Grade 5 on the
   card does not change where the child is supposed to get to. A target
   somebody can quietly move is not a target. */
BFTD_Derived::clear_fixture();
$GLOBALS['STUDENT'] = array('grade' => '8');
$GLOBALS['DX'] = array(
  array('id' => 91, 'on' => '2026-07-05', 'ts' => BFTD_Time::stamp('2026-07-05')),
  array('id' => 92, 'on' => '2026-09-01', 'ts' => BFTD_Time::stamp('2026-09-01')),
);
/* Words read and errors, which is what a tutor types. The figure the chart
   plots is worked out from them and stored nowhere. */
$GLOBALS['DXV'][91]['dxsec-4'] = array('words' => '20', 'errors' => '6', 'norm' => 'grade-5');
$GLOBALS['DXV'][92]['dxsec-4'] = array('words' => '71', 'errors' => '7', 'norm' => 'grade-5');

$f = BFTD_Derived::fluency_journey(1);
check(151 === $f['target']['rank'], 'a Grade 8 aims at the Grade 8 benchmark, got ' . $f['target']['rank']);
check('151 wpm' === $f['target']['level'], 'said in the unit the chart is in');
check(2 === count($f['points']), 'every timed reading is a point, got ' . count($f['points']));
check(14 === $f['points'][0]['rank'] && '14 wpm' === $f['points'][0]['level'],
  'the first reading, worked out from the words and the errors rather than read off a box nobody fills in');
check(64 === $f['points'][1]['rank'], 'and the second, got ' . $f['points'][1]['rank']);
check('Initial' === $f['points'][0]['when'], 'named by which assessment it was');

/* A diagnostic with no timing on it is not a nought. Plotting it as one would
   draw a child's reading falling off a cliff on a day nobody timed them. */
$GLOBALS['DXV'][92]['dxsec-4'] = array('norm' => 'grade-5');
check(1 === count(BFTD_Derived::fluency_journey(1)['points']), 'an untimed assessment is not a reading of nought');

/* A child who read the Kindergarten word list instead of a passage has no
   words and no errors, and a figure all the same. */
$GLOBALS['DXV'][92]['dxsec-4'] = array('kinder_score' => '31', 'norm' => 'grade-5');
check(31 === BFTD_Derived::fluency_journey(1)['points'][1]['rank'], 'the word list counts as the reading where it was used');
$GLOBALS['DXV'][92]['dxsec-4'] = array('words' => '71', 'errors' => '7', 'norm' => 'grade-5');

/* Kindergarten and the grades past eight have no published benchmark, so
   there is nothing to aim at and no chart to draw. */
$GLOBALS['STUDENT'] = array('grade' => '0');
check(array() === BFTD_Derived::fluency_journey(1), 'no published benchmark, no chart');
$GLOBALS['STUDENT'] = array('grade' => '11');
check(151 === BFTD_Derived::fluency_journey(1)['target']['rank'], 'a Grade 11 aims at where the norms stop');
$GLOBALS['STUDENT'] = array('grade' => '3');
check(112 === BFTD_Derived::fluency_journey(1)['target']['rank'], 'and a Grade 3 at their own, got ' . BFTD_Derived::fluency_journey(1)['target']['rank']);

/* ================================================================= */
/* Attendance                                                         */
/* ================================================================= */

/* Everything here could be counted off the session list on the report, and no
   parent was going to. The figure that changes what a family does is the gap
   since the last session, and a list sorted newest first is the one
   arrangement in which a gap cannot be seen: the most recent session sits at
   the top looking current however old it is. */
BFTD_Derived::clear_fixture();

$today = BFTD_Time::today();
$ago = function ($days) use ($today) { return date('Y-m-d', BFTD_Time::stamp($today) - $days * 86400); };
$ahead = function ($days) use ($today) { return date('Y-m-d', BFTD_Time::stamp($today) + $days * 86400); };

$GLOBALS['SESS'] = array();
check(array() === BFTD_Derived::attendance(1), 'a report with no sessions has no attendance to report');

$GLOBALS['SESS'] = array(
  801 => array('session_date' => $ago(21), 'status' => 'held'),
  802 => array('session_date' => $ago(14), 'status' => 'rescheduled'),
  803 => array('session_date' => $ago(10), 'status' => 'held'),
  804 => array('session_date' => $ago(7),  'status' => 'missed'),
  805 => array('session_date' => $ahead(3),'status' => 'held'),
);
$a = BFTD_Derived::attendance(1);

/* A session in the diary is not attendance. Counting it made the figures say
   a child had been four times when they had been twice, and reset the gap to
   zero for a family who had not been for a fortnight. */
check(2 === $a['held'], 'only the sessions that have happened are counted, got ' . $a['held']);
check(1 === $a['rescheduled'], 'one moved');
check(1 === $a['missed'], 'one lost');

/* Since the last one ATTENDED, not the last one recorded. A run of
   cancellations is exactly the case this exists to show, and measuring from
   the newest record would hide it behind the cancellation. */
check(10 === $a['days_since'], 'ten days since the last session attended, got ' . var_export($a['days_since'], true));
check(1 === $a['weeks_missed'], 'which is one whole week missed, got ' . $a['weeks_missed']);

/* A week is the expected gap, so a week is not an alarm. An alarm that is
   always on is not an alarm. */
$GLOBALS['SESS'] = array(801 => array('session_date' => $ago(6), 'status' => 'held'));
check(0 === BFTD_Derived::attendance(1)['weeks_missed'], 'six days is not a gap worth raising');
$GLOBALS['SESS'] = array(801 => array('session_date' => $ago(7), 'status' => 'held'));
check(0 === BFTD_Derived::attendance(1)['weeks_missed'], 'nor is a week exactly, which is the schedule being kept');
$GLOBALS['SESS'] = array(801 => array('session_date' => $ago(8), 'status' => 'held'));
check(1 === BFTD_Derived::attendance(1)['weeks_missed'], 'a day past it is a week missed');
$GLOBALS['SESS'] = array(801 => array('session_date' => $ago(14), 'status' => 'held'));
check(2 === BFTD_Derived::attendance(1)['weeks_missed'], 'and a fortnight is two');

/* Moving a session more than once in a month.
 *
 * The total says nothing about this. Six moves across a year is a family with
 * a busy year; two inside one month is a fortnight with no reading in it, and
 * a programme built on coming back weekly does not survive the second. A
 * running total cannot show when something happened, so it is counted by
 * calendar month. */
$GLOBALS['SESS'] = array(
  801 => array('session_date' => '2026-05-04', 'status' => 'rescheduled'),
  802 => array('session_date' => '2026-06-02', 'status' => 'rescheduled'),
  803 => array('session_date' => '2026-06-16', 'status' => 'rescheduled'),
  804 => array('session_date' => '2026-06-23', 'status' => 'rescheduled'),
  805 => array('session_date' => '2026-07-07', 'status' => 'held'),
);
$a = BFTD_Derived::attendance(1);
check(4 === $a['rescheduled'], 'every move is counted, got ' . $a['rescheduled']);
check(array('2026-05' => 1, '2026-06' => 3) === $a['resched_months'],
  'and counted into the month it happened in, got ' . json_encode($a['resched_months']));

/* One in a month is a life happening, and is not named. */
check(1 === count($a['busy_months']), 'only the months that went past one are flagged, got ' . count($a['busy_months']));
check('2026-06' === $a['busy_months'][0]['month'], 'which is the one with three');
check(3 === $a['busy_months'][0]['times'], 'with how many, so the family can place it');
check('June 2026' === $a['busy_months'][0]['label'], 'named in words rather than as a key');

/* Newest first: the month a family can still do something about comes first. */
$GLOBALS['SESS'] = array(
  801 => array('session_date' => '2026-05-04', 'status' => 'rescheduled'),
  802 => array('session_date' => '2026-05-18', 'status' => 'rescheduled'),
  803 => array('session_date' => '2026-08-03', 'status' => 'rescheduled'),
  804 => array('session_date' => '2026-08-17', 'status' => 'rescheduled'),
);
$b = BFTD_Derived::attendance(1);
check(2 === count($b['busy_months']), 'both months are flagged');
check('2026-08' === $b['busy_months'][0]['month'], 'newest first, got ' . $b['busy_months'][0]['month']);

/* A move with no date on it cannot belong to a month, and must not invent
   one: it still counts towards the total. */
$GLOBALS['SESS'] = array(
  801 => array('session_date' => '', 'status' => 'rescheduled'),
  802 => array('session_date' => '2026-05-04', 'status' => 'rescheduled'),
);
$c = BFTD_Derived::attendance(1);
check(2 === $c['rescheduled'], 'an undated move still counts');
check(array('2026-05' => 1) === $c['resched_months'], 'but lands in no month, got ' . json_encode($c['resched_months']));
check(array() === $c['busy_months'], 'so it cannot make a month look crowded on its own');

/* Today is nought days ago, not one. */
$GLOBALS['SESS'] = array(801 => array('session_date' => $today, 'status' => 'held'));
check(0 === BFTD_Derived::attendance(1)['days_since'], 'a session today is nought days since');

/* Nothing attended at all: still worth a panel, because a family who has
   cancelled twice and attended nothing is the one most worth telling. */
$GLOBALS['SESS'] = array(
  801 => array('session_date' => $ago(9), 'status' => 'missed'),
  802 => array('session_date' => $ago(2), 'status' => 'rescheduled'),
);
$none = BFTD_Derived::attendance(1);
check($none !== array(), 'a report where nothing was attended still has something to say');

/* Each missed session knows its place in the sequence, counted off the same
   walk the table is built from. Asking BFTD_CPT per row starts from the
   session and finds its way back to the report, which is two lookups a row
   for a number this loop is already holding, and answers nothing on a sample. */
$m = BFTD_Derived::missed(1);
check(2 === count($m), 'both are listed');
check(1 === $m[0]['number'] && 2 === $m[1]['number'],
  'and each is numbered by where it falls, got ' . $m[0]['number'] . ' and ' . $m[1]['number']);
check(0 === $none['held'] && null === $none['days_since'], 'and no last session to count from');
check(0 === $none['weeks_missed'], 'so no week count either, rather than a count from nowhere');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
