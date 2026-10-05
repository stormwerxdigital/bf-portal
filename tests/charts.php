<?php
/*
 * The drawn parts of a progress report.
 *
 * What is easy to get wrong here is not the drawing, it is the refusing: a
 * line through one point, or a grid built from no lessons, is an empty frame
 * that reads as a broken page rather than as a programme that has not started.
 *
 * The other trap is the six measures. They are counted in six different units
 * — letters a minute, out of thirty six, reading levels, words a minute,
 * sounds correct, words written — so one pair of axes would mean either a
 * second y scale or six lines indexed to percentages of themselves. Each panel
 * keeps its own scale, and these checks hold that: a measure's axis is built
 * from that measure's own figures and goal, and nothing is ever plotted
 * outside its own panel.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['JOURNEY'] = array();
$GLOBALS['WPM']     = array();
$GLOBALS['MILES']   = array();
$GLOBALS['GRID']    = array();
$GLOBALS['LIB']     = array();

class BFTD_Derived {
  public static function grade_journey($rid){ return $GLOBALS['JOURNEY']; }
  public static function fluency_journey($rid){ return $GLOBALS['WPM'] ?? array(); }
  public static function milestones($rid){ return $GLOBALS['MILES'] ?? array(); }
  public static function skills($rid){ return $GLOBALS['GRID']; }
  public static function missed($rid){ return $GLOBALS['MISSED'] ?? array(); }
  public static function attendance($rid){ return $GLOBALS['ATTEND'] ?? array(); }
  public static function texts($rid){ return $GLOBALS['TEXTS'] ?? array(); }
  public static function word($g,$k){
    $w = array('status'=>array('missed'=>'Missed','rescheduled'=>'Rescheduled'),
               'who'=>array('family'=>'Family','tutor'=>'Brilliant Futures'),
               'counted'=>array('yes'=>'Yes','no'=>'No'));
    return $w[$g][$k] ?? '';
  }
}
/* The library the list is drawn against. A skills section that only knows what
   has been reached can say "five things"; against the library it can say where
   a child is, which is the question. */
class BFTD_Skills {
  /* The fixture is id => name, which is what this chart wants, so all() and
     labels() are the same thing here. On the real class all() carries the
     number and the track as well and labels() is derived from it. */
  public static function all(){ return $GLOBALS['LIB'] ?? array(); }
  public static function labels(){ return $GLOBALS['LIB'] ?? array(); }
}
class BFTD_Schema {
  public static function reading_levels(){
    $l = array('preprimer'=>'Preprimer','primer'=>'Primer');
    for ($g=1; $g<=8; $g++) $l['grade-'.$g] = 'Grade '.$g;
    return $l;
  }
}
function get_the_title($id){ return 'Lesson '.$id; }
function get_userdata($id){ return (5 == $id) ? (object) array('display_name' => 'Laurel Sanders') : null; }

require BFTD_PATH . 'includes/class-bftd-charts.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) { echo "  ok  $msg\n"; } else { echo "FAIL  $msg\n"; $fail++; } }

/* One reading, in the shape BFTD_Derived hands over. */
function point($when, $on, $rank, $level, $kind = 'interim') {
  return array('report' => 900, 'kind' => $kind, 'when' => $when, 'on' => $on,
               'ts' => BFTD_Time::stamp($on), 'rank' => $rank, 'level' => $level);
}

/* ---- refusing to draw ---- */
$GLOBALS['JOURNEY'] = array();
check(BFTD_Charts::journey(1) === '', 'no target and no readings draws nothing');

check(BFTD_Charts::coverage(1) === '', 'no lessons draws no skills grid');
check(BFTD_Charts::wpm(1) === '', 'no timed reading draws no speed chart');
check(BFTD_Charts::texts(1) === '', 'no text read draws no reading log');

/* ---- where they are, and where they are going ---- */
$GLOBALS['JOURNEY'] = array(
  'points' => array(
    point('Initial', '2026-07-05', 1, 'Preprimer', 'initial'),
    point('Interim', '2026-08-04', 2, 'Primer'),
    point('Middle',  '2026-09-01', 4, 'Grade 2', 'middle'),
  ),
  'target' => array('rank' => 10, 'level' => 'Grade 8'),
);
$svg = BFTD_Charts::journey(1);
check($svg !== '', 'readings and a target draw a chart');
check(substr_count($svg, '<svg') === 1, 'one chart, drawn large, because it is the one number families ask about');
check(substr_count($svg, 'data-lab=') === 3, 'every assessment is plotted and carries its own label');

/* The target is the reading level of the child\'s own grade, and it has not
   happened: hollow, dashed, and never drawn as though it were a reading. */
check(strpos($svg, 'stroke-dasharray="7 6"') !== false, 'the run to the target is dashed, because nobody has promised a date');
check(1 === preg_match('/<circle[^>]*fill="var\(--card\)"[^>]*stroke="var\(--chart-goal\)"/', $svg),
  'and the target is hollow, because it has not happened');
check(strpos($svg, '>Grade 8<') !== false, 'the target names the level it is');
check(strpos($svg, '>Target<') !== false, 'and says that is what it is');

/* The two ends of the chart are written the same way round: what the level is,
   above the point; which reading it is, on the axis under it. So the LEVEL is
   on the marker and the WORD is an axis label, on the same line as Initial —
   not stacked above the marker, which read as a two-line caption floating in
   the plot while the other end of the line had none. */
check(1 === preg_match('/class="journey-g"[^>]*>Grade 8</', $svg), 'the level is on the marker');
check(false === strpos($svg, 'journey-t'), 'and the word is not stacked above it');

$ticks = array();
if (preg_match_all('/<text[^>]*y="' . (BFTD_Charts::JB + 22) . '"[^>]*>([^<]*)</', chart_only($svg), $mm)) $ticks = $mm[1];
check(in_array('Target', $ticks, true),
  'it is an axis label, on the line the readings are named on, got ' . json_encode($ticks));
check(in_array('Initial', $ticks, true), 'the same line as Initial');

/* And the two ends are named in the card colours: the first reading in the
   tone the cards use for a focus area, the target in the tone they use for a
   strength. Bold, because at 12px colour alone does not carry a difference —
   and the readings in between stay ordinary ink, or the axis becomes a
   traffic light rather than a journey with two ends. */
check(1 === preg_match('/class="jt-start"[^>]*>Initial</', chart_only($svg)),
  'the first reading is marked as the start');
check(1 === preg_match('/class="jt-goal"[^>]*>Target</', chart_only($svg)),
  'and the target as where it is going');
check(1 === substr_count(chart_only($svg), 'jt-start'), 'only the first one, got ' . substr_count(chart_only($svg), 'jt-start'));
check(0 === substr_count(chart_only($svg), 'fill="var(--chart-goal)" class="jt-goal"'),
  'and the goal tick takes its colour from the sheet rather than an inline fill that fights it');

$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(1 === preg_match('/\.jt-start\{fill:var\(--bf-clay\); font-weight:700\}/', $css),
  'the start wears the cards\' focus colour, bold');
check(1 === preg_match('/\.jt-goal\{fill:var\(--bf-sage-deep\); font-weight:700\}/', $css),
  'and the target the cards\' strength colour, bold');
/* Counted inside the chart alone. The table underneath names the target too,
   which is right: that is the same figure as text, not a second label. */
function chart_only($html) {
  $a = strpos($html, '<svg'); $b = strpos($html, '</svg>');
  return ($a === false || $b === false) ? '' : substr($html, $a, $b - $a);
}
check(1 === substr_count(chart_only($svg), '>Grade 8<'),
  'said once on the chart, not on the axis as well, got ' . substr_count(chart_only($svg), '>Grade 8<'));

/* Three labels: where they started, where they are, where they are going.
   Leaving the start unlabelled loses half the story, because "how far they
   have come" cannot be read off a line with only its far end named. */
/* Once, on the point they started at.

   It used to be twice: the axis named the bottom of the scale and the point
   sitting on that same line named it again, a few pixels apart. The axis label
   is dropped where a labelled point is already there, so counting is still the
   assertion, just of one rather than two. */
check(1 === substr_count($svg, '>Preprimer</text>'),
  'the starting level is named once, on the point, got ' . substr_count($svg, '>Preprimer</text>'));
check(strpos($svg, '>Grade 2</text>') !== false, 'and so is where they are now');
check(strpos($svg, '>Primer</text>') === false, 'while the readings in between are not, or the line becomes a table');

/* The scale runs to the target, not past it. Three quarters of a chart above a
   grade the child is not aiming at makes any progress look like none. */
$GLOBALS['JOURNEY']['target'] = array('rank' => 5, 'level' => 'Grade 3');
$near = BFTD_Charts::journey(1);
check(strpos($near, '>Grade 3<') !== false, 'a Grade 3 target is what the scale reaches');
check(strpos($near, '>Grade 8<') === false, 'and Grade 8 is not on a Grade 3 child\'s chart');

/* And it reaches it: the target sits at the top of the plot, not partway up a
   scale built for somebody else. Reading the label alone would pass on a chart
   that drew Grade 3 a third of the way up a Grade 8 axis. */
function target_y($svg) {
  return preg_match('/<circle cx="[\d.]+" cy="([\d.]+)" r="7"/', $svg, $m) ? (float) $m[1] : -1;
}
check(abs(target_y($near) - 34) < 1.5,
  'and the target is drawn at the top of the scale, got y ' . target_y($near));

/* Reaching it is said, because that is the moment the chart was drawn for. */
$GLOBALS['JOURNEY']['points'][] = point('Final', '2026-11-01', 5, 'Grade 3', 'final');
$done = BFTD_Charts::journey(1);
check(strpos($done, 'Target reached') !== false, 'reaching the target is said in as many words');
check(strpos($done, 'Where they got to') !== false, 'and the legend stops talking about where they are going');

/* One reading is still a chart here, unlike the old panels: the distance to
   the target is the subject, and that exists from the first assessment. */
$GLOBALS['JOURNEY'] = array(
  'points' => array(point('Initial', '2026-07-05', 1, 'Preprimer', 'initial')),
  'target' => array('rank' => 10, 'level' => 'Grade 8'),
);
$one = BFTD_Charts::journey(1);
check($one !== '', 'one assessment still draws, because the gap to the target is the point');
check(strpos($one, '<polyline') === false, 'with no line between readings, because there is only one');
check(strpos($one, 'stroke-dasharray="7 6"') !== false, 'but the run to the target is still there');

/* Nothing outside the plot. */
preg_match_all('/<circle cx="([\d.]+)" cy="([\d.]+)"/', $svg, $m);
$inside = true;
foreach ($m[1] as $i => $cx) {
  $cy = (float) $m[2][$i];
  if ($cx < 96 - 0.5 || $cx > 664 + 0.5 || $cy < 34 - 0.5 || $cy > 232 + 0.5) $inside = false;
}
check($inside, 'every point is inside the axes');

/* ---- the three milestones ---- */
$GLOBALS['MILES'] = array(
  'initial' => array('kind'=>'initial','label'=>'Initial','done'=>true,'id'=>901,'on'=>'2026-07-05','ts'=>BFTD_Time::stamp('2026-07-05')),
  'middle'  => array('kind'=>'middle', 'label'=>'Middle', 'done'=>false,'id'=>0,'on'=>'','ts'=>0),
  'final'   => array('kind'=>'final',  'label'=>'Final',  'done'=>false,'id'=>0,'on'=>'','ts'=>0),
);
$cards = BFTD_Charts::milestones(1);
check(substr_count($cards, '<li class="mile') === 3, 'three cards, because a programme has three milestones');
check(substr_count($cards, 'is-done') === 1, 'the one that has happened is marked');
check(strpos($cards, '5 Jul 2026') !== false, 'and dated');
check(substr_count($cards, 'To come') === 2, 'while the two still ahead say so rather than being left out');
check(strpos($cards, '>Middle<') !== false && strpos($cards, '>Final<') !== false,
  'and they are named, because a milestone still ahead is the half of the picture that says where the family is heading');

/* ---- the skills grid ---- */
$skill = function ($name) { return array('id'=>$name,'name'=>$name,'from'=>array(),'note'=>'','about'=>''); };
$GLOBALS['GRID'] = array(
  'lessons' => array(
    array('id'=>801,'label'=>'7 Jul 2026','skills'=>array('Mapping sounds'=>$skill('Mapping sounds'),'Forming letters'=>$skill('Forming letters'))),
    array('id'=>802,'label'=>'9 Jul 2026','skills'=>array('Mapping sounds'=>$skill('Mapping sounds'),'Reading a pattern'=>$skill('Reading a pattern'))),
    array('id'=>804,'label'=>'14 Jul 2026','skills'=>array('Mapping sounds'=>$skill('Mapping sounds'))),
  ),
  'rows' => array(
    'Mapping sounds'    => array(0=>'new',1=>'again',2=>'again'),
    'Forming letters'   => array(0=>'new'),
    'Reading a pattern' => array(1=>'new'),
  ),
  'names' => array('Mapping sounds'=>'Mapping sounds','Forming letters'=>'Forming letters','Reading a pattern'=>'Reading a pattern'),
);
$grid = BFTD_Charts::coverage(1);

/* The list is always there. It is the answer to the question a parent opened
   the report to ask, and it was the one thing the section did not say: the
   only answer on the page was a grid of coloured squares to be decoded. */
check($grid !== '', 'sessions that reached a skill draw something');
/* No count line above the list, and nothing on the right of a row but where
   the skill was first reached: Karl removed the count, the pips and the
   "N sessions" column. The section shows the skills. */
check(strpos($grid, 'reached so far') === false && strpos($grid, 'skl-lede') === false, 'no count line above the list');
check(strpos($grid, 'come back in a later session') === false, 'nor a count of skills that came back');
check(strpos($grid, 'skl-p') === false && strpos($grid, 'class="p') === false, 'no pips on a row');
check(strpos($grid, 'skl-t') === false && strpos($grid, '>3 sessions<') === false && strpos($grid, '>once<') === false, 'no count of sessions on a row');
foreach (array('Mapping sounds', 'Forming letters', 'Reading a pattern') as $nm) {
  check(substr_count($grid, '<span class="skl-n">' . $nm . '</span>') === 1, "$nm is named once, on its own row");
}
check(strpos($grid, 'class="skl-m"') === false && strpos($grid, 'Session 1 · 7 Jul 2026') === false, 'and nothing to the right of a skill\'s name: Karl removed that column');

/* ---- what is still ahead, which is now a track and not the library ----
 *
 * It used to be the whole library against what had been reached. That was right
 * when there was one library. There are two tracks now, each numbered from one,
 * and a student is on one of them at a time: counting a child against both would
 * say they are a hundred and forty behind on a programme they have not started.
 *
 * So what is left is their track, from the number they started it at, skills
 * rather than Wordwall. Wordwall is read on sight and optional, and putting it
 * in the outstanding count would be a number nobody intends to finish.
 */
$GLOBALS['LIB'] = array(
  'Mapping sounds' => 'Mapping sounds',
  'Forming letters' => 'Forming letters',
  'Reading a pattern' => 'Reading a pattern',
  'Hearing a syllable break' => 'Hearing a syllable break',
  'Spelling a plural' => 'Spelling a plural',
);

/* With no student, and so no track, nothing is claimed about what is
   outstanding. The report says what was reached and stops, which is true. */
$whole = BFTD_Charts::coverage(1);
check(substr_count($whole, 'class="skl-r is-on') === 3,
  'with nobody placed on a track, only what was reached is listed');
check(substr_count($whole, 'class="skl-r is-ahead') === 0,
  'and nothing is listed as outstanding, because there is no sequence to count against');
check(strpos($whole, 'skl-next') === false, 'nor is a next track promised');

/* Reached ones first, in the order they were reached. Opening on a run of
   things nobody has taught yet is a list that starts with what has not
   happened. */
preg_match_all('/<span class="skl-n">([^<]*)</', $whole, $mm);
check(array('Mapping sounds', 'Forming letters', 'Reading a pattern') === array_slice($mm[1], 0, 3),
  'the reached ones come first, got ' . json_encode(array_slice($mm[1], 0, 3)));

/* A skill reached but no longer in the library still has a row: a practice
   that renames or retires one must not blank a year of a child's record. */
unset($GLOBALS['LIB']['Forming letters']);
$gone = BFTD_Charts::coverage(1);
check(substr_count($gone, 'class="skl-r is-on') === 3, 'a retired skill a child reached is still theirs');

/* A bullet for anything done, and no tick with it: ticks appear in a report
   only where Karl asked for them. */
check(substr_count($whole, '<span class="skl-mark is-done" aria-hidden="true"></span>') === 3, 'each reached skill has a plain filled bullet');
check(strpos($whole, '<svg') === false, 'and no tick drawn on or beside it');
check(substr_count($whole, '<span class="skl-mark" aria-hidden="true">') === 0,
  'and nothing still ahead here, so no empty bullets');

$GLOBALS['LIB'] = array();

/* The grid is the spiral, and a spiral needs room. Three sessions is three
   columns of squares: all of the furniture of a chart and none of the picture,
   on the report a family reads most carefully because it is their first. */
check(strpos($grid, 'covtab') === false, 'three sessions is too few for the grid to say anything');

$GLOBALS['GRID']['lessons'][] = array('id'=>805,'label'=>'16 Jul 2026','skills'=>array('Mapping sounds'=>$skill('Mapping sounds')));
$GLOBALS['GRID']['rows']['Mapping sounds'][3] = 'again';
$grid4 = BFTD_Charts::coverage(1);
check(strpos($grid4, 'covtab') !== false, 'a fourth session brings the grid out');
check(substr_count($grid4, '<span class="skl-n">') === 3, 'with the list still above it');
check(substr_count($grid4, 'class="cell new"') === 4, 'three skills first reached, plus the key');
check(substr_count($grid4, '<th title=') === 4, 'one column per session');
check(strpos($grid4, 'First reached in session 1') !== false, 'the first appearance of a skill is where it was reached');
check(strpos($grid4, 'Practised again in session 2') !== false, 'a repeat is marked as a repeat');
check(strpos($grid4, '>Skill</th>') !== false, 'the rows are skills, not activities');
array_pop($GLOBALS['GRID']['lessons']);
unset($GLOBALS['GRID']['rows']['Mapping sounds'][3]);

/* One session. */
$GLOBALS['GRID'] = array(
  'lessons' => array(array('id'=>801,'label'=>'7 Jul 2026','skills'=>array('Mapping sounds'=>$skill('Mapping sounds')))),
  'rows'    => array('Mapping sounds' => array(0=>'new')),
  'names'   => array('Mapping sounds'=>'Mapping sounds'),
);
$one = BFTD_Charts::coverage(1);
check(substr_count($one, '<span class="skl-n">') === 1, 'one skill, one row');
check(strpos($one, 'covtab') === false, 'nor a grid');

/* ---- schedule and attendance ---- */
$GLOBALS['ATTEND'] = array();
check(BFTD_Charts::attendance(1) === '', 'a report with nothing attended and nothing missed draws no panel');

$GLOBALS['ATTEND'] = array('held'=>8,'rescheduled'=>1,'missed'=>2,'last_ts'=>0,'first_ts'=>0,
  'days_since'=>3,'weeks_missed'=>0);
$att = BFTD_Charts::attendance(1);
check(substr_count($att, 'class="attend-t') === 4, 'four figures');
check(strpos($att, '>8</span>') !== false && strpos($att, 'sessions attended') !== false, 'how many were attended');
check(strpos($att, '>3</span>') !== false && strpos($att, 'days since the last session') !== false, 'and how long it has been');

/* The standing line is always there. It is a fact about how reading is
   learned, not a telling-off, so it does not appear only when somebody is
   behind — which would make it one. */
check(strpos($att, 'Students must attend 1 session per week to make progress.') !== false,
  'the weekly line is on every report');
check(strpos($att, 'attend-gap') === false, 'but nothing is raised when nothing is wrong');

/* Moved more than once in a month, named by month. A running total cannot
   show when something happened, and the month is the thing a family can
   place and act on. */
$GLOBALS['ATTEND']['busy_months'] = array(
  array('month' => '2026-08', 'label' => 'August 2026', 'times' => 3),
  array('month' => '2026-05', 'label' => 'May 2026', 'times' => 2),
);
$busy = BFTD_Charts::attendance(1);
check(strpos($busy, 'moved more than once in August 2026 (3) and May 2026 (2)') !== false,
  'the crowded months are named, with how many');
check(substr_count($busy, 'is-warn') === 2, 'and the moved figure is marked as well as the missed one');
unset($GLOBALS['ATTEND']['busy_months']);
check(substr_count(BFTD_Charts::attendance(1), 'is-warn') === 1,
  'while a family who moved nothing twice is not marked at all');
check(substr_count($att, 'is-warn') === 1, 'and only the missed figure is marked, because there were two');

/* A number a reader has to turn back into a sentence is a number in the wrong
   form. Nought days since is "today". */
$GLOBALS['ATTEND']['days_since'] = 0;
check(strpos(BFTD_Charts::attendance(1), '>Today</span>') !== false, 'nought days since is today');
$GLOBALS['ATTEND']['days_since'] = 1;
check(strpos(BFTD_Charts::attendance(1), '>Yesterday</span>') !== false, 'and one is yesterday');

/* The gap, once there is one. */
$GLOBALS['ATTEND'] = array('held'=>3,'rescheduled'=>0,'missed'=>0,'last_ts'=>0,'first_ts'=>0,
  'days_since'=>23,'weeks_missed'=>3);
$gapped = BFTD_Charts::attendance(1);
check(strpos($gapped, 'no sessions for 3 weeks') !== false, 'a real gap is said in weeks');
check(substr_count($gapped, 'is-warn') === 1, 'and it is the days figure that is marked, not the nought missed');

/* Nothing attended at all, which is the family most worth telling. */
$GLOBALS['ATTEND'] = array('held'=>0,'rescheduled'=>2,'missed'=>1,'last_ts'=>0,'first_ts'=>0,
  'days_since'=>null,'weeks_missed'=>0);
$never = BFTD_Charts::attendance(1);
check($never !== '', 'a report where nothing was attended still draws the panel');
check(strpos($never, 'no dated session yet') !== false, 'and says there is nothing to count from rather than showing a nought');
$GLOBALS['ATTEND'] = array();

/* ---- words per minute ---- */

/* Its own chart, on its own scale. Reading level and reading speed are
   counted in units nobody can compare — a level and a rate — so one pair of
   axes would mean a second y scale, which is the single most misread thing in
   charting. And a child can gain thirty words a minute inside one reading
   level, which the level chart cannot show at all. */
$GLOBALS['WPM'] = array(
  'points' => array(
    array('report'=>900,'kind'=>'initial','when'=>'Initial','on'=>'2026-07-05',
          'ts'=>BFTD_Time::stamp('2026-07-05'),'rank'=>14,'level'=>'14 wpm'),
    array('report'=>901,'kind'=>'middle','when'=>'Middle','on'=>'2026-09-01',
          'ts'=>BFTD_Time::stamp('2026-09-01'),'rank'=>64,'level'=>'64 wpm'),
  ),
  'target' => array('rank'=>151,'level'=>'151 wpm'),
);
$w = BFTD_Charts::wpm(1);
check($w !== '', 'timed readings and a benchmark draw a chart');
check(strpos($w, '>151 wpm<') !== false, 'the target is the benchmark in words a minute');
check(strpos($w, '>14 wpm<') !== false && strpos($w, '>64 wpm<') !== false, 'and each reading is named in the same unit');
check(strpos($w, 'Words correct per minute') !== false, 'the table underneath is headed for this measure');
check(1 === preg_match('/<tr class="row-goal">/', $w),
  'and its last row is marked as the destination, not as one more reading');
check(strpos($w, 'Reading level') === false, 'and never for the other one');
check(strpos($w, '>0 wpm<') !== false, 'the scale is named from nought, because a rate has a real zero');

/* The alt text describes THIS chart. The two share one drawing, and a shared
   drawing that keeps one chart's words is a screen reader being told about a
   chart that is not there. */
check(1 === preg_match('/aria-label="Line chart of words read correctly per minute\./', $w),
  'a screen reader is told which measure it is');
check(strpos($w, 'end of year benchmark for their own school grade') !== false,
  'and where the target came from');

/* The legend is about this chart too. Two charts sharing one drawing, and one
   of them keeping the other's words, is a parent being told the dashed line
   on a speed chart is a reading level. */
check(strpos($w, 'The reading level of their own grade') === false,
  'and the legend is not the other chart\'s');
check(strpos($w, 'The end of year benchmark for their own school grade') !== false,
  'but this one\'s');

$GLOBALS['WPM'] = array();

/* ---- the sessions that did not go ahead ---- */

/* They used to be a six column table in a section of their own. A family
   reads one of these rows — the day they remember — rather than comparing a
   column, and what they want from it is the reason and who recorded it. So
   they are rows inside the attendance panel now, with the reason on its own
   line and the audit detail under it. */
$GLOBALS['ATTEND'] = array('held'=>6,'rescheduled'=>1,'missed'=>1,'last_ts'=>0,'first_ts'=>0,
  'days_since'=>3,'weeks_missed'=>0);
/* "Cancelled by" is a person now. Family or Brilliant Futures told a practice
   nothing it could act on, and it was also what the record said when somebody
   picked the wrong one of two options. The name is resolved where the row is
   derived, so every screen that prints one writes it the same way. */
$GLOBALS['MISSED'] = array(
  array('id'=>810,'status'=>'rescheduled','date'=>'2026-08-06','ts'=>BFTD_Time::stamp('2026-08-06'),
        'who'=>'31','who_name'=>'Laurel Sanders (Client)','reason'=>'Family away','counted'=>'no','made_up'=>'2026-08-08',
        'by'=>5,'at'=>'2026-08-05 16:40:00','number'=>4),
  array('id'=>811,'status'=>'missed','date'=>'2026-08-20','ts'=>BFTD_Time::stamp('2026-08-20'),
        'who'=>'tutor','who_name'=>'Brilliant Futures','reason'=>'Tutor unwell','counted'=>'yes','made_up'=>'','by'=>0,'at'=>'','number'=>7),
);
$t = BFTD_Charts::attendance(1);
check(substr_count($t, 'class="miss-r"') === 2, 'one row per session that did not go ahead');
check(strpos($t, 'Rescheduled') !== false && strpos($t, 'Missed') !== false,
  'a session that was moved and one that was lost are not called the same thing');
check(strpos($t, 'Cancelled by Laurel Sanders (Client)') !== false, 'a row names the person who cancelled it');
check(strpos($t, 'Brilliant Futures') !== false, 'and a record made before that still reads as something');

/* The reason gets a line of its own. In a cell it had to be squeezed into, it
   was the one thing on the row a family actually wanted. */
check(strpos($t, '<span class="miss-why">Family away</span>') !== false, 'the reason is its own line');
check(strpos($t, 'Tutor unwell') !== false, 'for each of them');

/* Who marked it, and when. Stamped on save, not typed: the one situation this
   exists for is a family and a practice remembering a cancellation
   differently, which is exactly where a typed answer is worth nothing. */
check(strpos($t, 'marked by Laurel Sanders') !== false, 'the row says who marked it');
check(strpos($t, 'on 5 Aug 2026, 4:40 pm') !== false, 'and when they did, to the minute');
check(strpos($t, 'made up on 8 Aug 2026') !== false, 'and when it was made up');
/* Whether it counts is the status, not a box somebody answered. There was a
   "counts against allowance" dropdown on every cancellation, and a practice
   cannot run a policy that is a different answer on each record. */
check(strpos($t, 'moved rather than used') !== false, 'a rescheduled session says it was moved');
check(strpos($t, 'counts against the sessions remaining') !== false, 'and a cancelled one that it was spent');
check(strpos($t, 'Session 4') !== false, 'and which session it was');

/* A row nobody has stamped yet says nothing rather than "marked by on". */
check(false === strpos($t, 'marked by  ·'), 'a row with no stamp does not print an empty one');
$GLOBALS['MISSED'] = array();
check(false === strpos(BFTD_Charts::attendance(1), 'class="miss'),
  'and a clean sheet lists nothing at all');
$GLOBALS['ATTEND'] = array();

/* ---- the reading log ---- */

/* A shelf, not a table. A parent scans for the book they recognise; they do
   not read down a column of titles comparing them. Title, level, date — the
   session count went, because it is a fact about the programme rather than
   about the reading. */
$GLOBALS['TEXTS'] = array(
  array('title'=>'Rook of the Pines','level'=>'Grade 4','date'=>'2026-08-11','from'=>'KAT','times'=>3),
  array('title'=>'The Yucky Feeling','level'=>'Grade 1','date'=>'2026-07-21','from'=>'Read Read Back','times'=>1),
);
$log = BFTD_Charts::texts(1);
check(substr_count($log, 'class="bk"') === 2, 'one card per text, however often it was read');
check(strpos($log, 'Rook of the Pines') !== false, 'named');
check(strpos($log, '>Grade 4<') !== false, 'with the level it is written at, in the words the tutor used');

/* The level field is free text, because a practice writes "Grade 4" for one
   book, "Preprimer" for another and "1" for a levelled reader. But a bare 1
   beside a title is not a level, it is a digit, and a parent reads it as a
   count of something. */
$GLOBALS['TEXTS'] = array(
  array('title'=>'Cat in the Hat','level'=>'1','date'=>'2026-09-14','from'=>'','times'=>1),
  array('title'=>'Half a step','level'=>'2.5','date'=>'2026-09-14','from'=>'','times'=>1),
  array('title'=>'No level at all','level'=>'','date'=>'2026-09-14','from'=>'','times'=>1),
);
$bare = BFTD_Charts::texts(1);
check(strpos($bare, '>Level 1<') !== false, 'a bare number is given the word that makes it a level');
check(strpos($bare, '>Level 2.5<') !== false, 'including a half step');
check(substr_count($bare, 'class="bk-lv"') === 2, 'and a text with no level shows no chip at all');

check('Preprimer' === BFTD_Charts::text_level_label('Preprimer'), 'words are left exactly as typed');
check('Grade 4' === BFTD_Charts::text_level_label(' Grade 4 '), 'trimmed, but not reworded');
check('Level 1' === BFTD_Charts::text_level_label('1'), 'and a number is not');
check('1a' === BFTD_Charts::text_level_label('1a'), 'while something that only starts with a number is left alone');
check('' === BFTD_Charts::text_level_label(''), 'and nothing is nothing');

$GLOBALS['TEXTS'] = array(
  array('title'=>'Rook of the Pines','level'=>'Grade 4','date'=>'2026-08-11','from'=>'KAT','times'=>3),
  array('title'=>'The Yucky Feeling','level'=>'Grade 1','date'=>'2026-07-21','from'=>'Read Read Back','times'=>1),
);
check(strpos($log, '11 Aug 2026') !== false, 'and the date it was read');
check(false === strpos($log, 'read in 3 sessions'), 'and no session count, which is not about the reading');
check(false === strpos($log, '<table'), 'nor a table, which is the shape for figures a reader compares');

/* Icons come from one set, so the same tick cannot be two weights in two
   places, and they take the colour of whatever they sit in. */
check(substr_count($log, 'class="bfic"') === 2, 'each card carries the book mark');
check(1 === preg_match('/<svg class="bfic"[^>]*aria-hidden="true"/', $log),
  'and it is hidden from a screen reader, because the words beside it already say it');
check('' === BFTD_Charts::icon('not a real icon'), 'an icon nobody drew is nothing, not a broken svg');

/* Not everywhere. An icon beside every figure is decoration, and decoration
   is what a reader learns to stop looking at — so the skills cards carry
   none: fourteen identical marks down a grid says nothing that the fourteen
   names beside them do not. */
$skl = BFTD_Charts::coverage(1);
check(false === strpos($skl, 'class="bfic"'), 'the skills cards carry no icon');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
