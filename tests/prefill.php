<?php
/*
 * Nothing gets typed twice.
 *
 * The rule is narrow and the edges are where it goes wrong: fill an empty
 * field, never touch a filled one, translate a stored code into the words the
 * report wants, and never hand a diagnostic a field that belongs to a
 * progress report.
 *
 * The other thing under test is that these are copies, not live reads. A
 * diagnostic records what was true on the day; if it read the grade from the
 * student every time it were opened, last year's report would quietly start
 * claiming the child is in this year's grade.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array();
function get_post_meta($id,$k,$single=false){ return $GLOBALS['META'][$id][$k] ?? ($single?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }

class BFTD_CPT {
  const STUDENT='bftd_student'; const ASSESSMENT='bftd_assessment'; const PROGRESS='bftd_progress';
  public static function staff_ids($sid){ return $GLOBALS['STAFF'][$sid] ?? array(); }
}
class BFTD_Fields {
  public static function get($id,$sec,$key,$f=array()){ return $GLOBALS['META'][$id]['_bftd_'.$sec.'__'.$key] ?? ''; }
}
class BFTD_MetaBoxes {
  public static function grade_options(){ return array('0'=>'Kindergarten','3'=>'Grade 3','adult'=>'Adult'); }
  public static function level_options(){ return array('preprimer'=>'Preprimer','g1'=>'Grade 1','g4'=>'Grade 4'); }
}
class BFTD_Schema {
  public static function sections_for_post_type($pt){
    $dx = array(
      'sec-assessment-overview' => array('label'=>'Assessment overview','fields'=>array(
        'grade'=>array('label'=>'Grade at assessment'),'assessed_on'=>array('label'=>'Assessment date'),
        'assessed_by'=>array('label'=>'Assessed by'))),
      'dxsec-3' => array('label'=>'Reading words on their own','fields'=>array('score'=>array('label'=>'Score'))),
      'dxsec-4' => array('label'=>'Reading a passage smoothly','fields'=>array('score'=>array('label'=>'Score'))),
    );
    /* A progress report has no prefillable field left: every table on it is
       derived from lessons, activities and diagnostics. */
    $pr = array('sec-progress-overview' => array('label'=>'Progress overview','fields'=>array('intro'=>array('label'=>'One-line explainer'))));
    if ($pt === 'bftd_assessment') return $dx;
    if ($pt === 'bftd_progress') return $pr;
    return array_merge($dx,$pr);
  }
  public static function section($id){ $all = self::sections_for_post_type(''); return $all[$id] ?? null; }
}
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-prefill.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* A student with the record filled in. */
$s = 10;
$GLOBALS['META'][$s] = array(
  '_bftd_student__grade'         => '3',
  '_bftd_student__diagnostic_on' => '2026-09-14',
);
$GLOBALS['STAFF'][$s] = array(7, 9);

$v = BFTD_Prefill::values($s, 'bftd_assessment');

/* --- codes become the words a report uses --- */
check($v['sec-assessment-overview']['grade'] === 'Grade 3', 'a grade stored as 3 reaches the report as "Grade 3"');

/* The reading level, the speed and the date of the assessment are read off
   the diagnostics now, not typed on the student record. Prefilling a new
   diagnostic from them would fill this assessment in with the previous one's
   answers: a reassessment that opens already agreeing with the result it
   exists to test, and a tutor who tabs past three fields that look done. */
foreach (array('dxsec-3.score', 'dxsec-4.score', 'sec-assessment-overview.assessed_on') as $gone) {
  check(!isset(BFTD_Prefill::map()[$gone]), "a diagnostic is not prefilled with $gone");
}
check(!isset($v['dxsec-3']) && !isset($v['dxsec-4']), 'so the scores arrive empty, to be measured');
check(!isset($v['sec-assessment-overview']['assessed_on']), 'and so does the date it was done');

/* --- the tutor --- */
check($v['sec-assessment-overview']['assessed_by'] === '7', 'assessed by starts as the assigned tutor, as an id');

/* --- the right fields for the right report --- */
check(!isset($v['sec-progress-overview']), 'a diagnostic is not handed progress report fields');

/* The progress report is now built rather than filled in — the measures come
   from the diagnostics, the log from the activities, the missed lessons from
   the lessons — so there is nothing left on it to prefill, and the map must
   not go on naming a section that no longer asks for anything. */
$p = BFTD_Prefill::values($s, 'bftd_progress');
check(array() === $p, 'a progress report offers nothing, because everything on it is derived');
foreach (array_keys(BFTD_Prefill::map()) as $target) {
  check(0 === strpos($target, 'dxsec-') || 0 === strpos($target, 'sec-assessment-overview'),
    "prefill only fills the diagnostic, not $target");
}

/* --- a student who has not been filled in yet --- */
$empty = 11;
$v = BFTD_Prefill::values($empty, 'bftd_assessment');
check($v === array(), 'a student with nothing recorded offers nothing, rather than blanks');

$partial = 12;
$GLOBALS['META'][$partial] = array('_bftd_student__grade' => 'adult');
$v = BFTD_Prefill::values($partial, 'bftd_assessment');
check($v['sec-assessment-overview']['grade'] === 'Adult', 'an adult learner is described as one');
check(!isset($v['sec-assessment-overview']['assessed_on']), 'and a field with no answer is left out entirely, not sent empty');
check(!isset($v['dxsec-4']), 'along with its whole section when nothing in it is known');

/* An unknown code is passed through rather than dropped, because a value
   somebody typed is better than silence. */
$odd = 13;
$GLOBALS['META'][$odd] = array('_bftd_student__grade' => 'Grade 11 French immersion');
$v = BFTD_Prefill::values($odd, 'bftd_assessment');
check($v['sec-assessment-overview']['grade'] === 'Grade 11 French immersion', 'a value that is not one of the options is passed through');

/* --- no student, nothing to say --- */
check(BFTD_Prefill::values(0, 'bftd_assessment') === array(), 'no student means nothing to fill in');

/* --- the list shown on screen matches what actually happens --- */
$explained = BFTD_Prefill::explain();
check(count($explained) === count(BFTD_Prefill::map()), 'every mapped field is named on screen, so nothing fills in secretly');
check(in_array('Grade at assessment (Assessment overview)', $explained, true), 'named by its label and its section');

/* --- the subheading is shown, not typed --- */
/* Who teaches a student is already answered by the assignment. A second place
   to say it is a second place to be wrong: cover a lesson, reassign a student,
   and a hand-typed line keeps insisting on last year's tutor. So there is no
   input to contradict it, and nothing renders until there is a name. */
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
$a  = strpos($mb, 'public static function subtitle_field(');
$b  = strpos($mb, 'public static function ajax_student_header(');
$fn = substr($mb, $a, $b - $a);

check(false === strpos($fn, 'type="text"'), 'the subheading offers no text box');
check(false !== strpos($fn, 'type="hidden"'), 'the value still posts, so saving is unchanged');
check(false !== strpos($fn, 'bftd-subtitle-line'), 'and it is drawn as a line of text');
check(false !== strpos($fn, 'is-empty'), 'with nothing rendered until there is a name');

$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(false !== strpos($css, '.bftd-subtitle.is-empty{display:none}'), 'an empty subheading takes up no room');

/* The server can answer for itself, so a save with no JavaScript still names
   the tutor rather than leaving the line blank forever. */
check(false !== strpos($mb, 'public static function assigned_line('), 'the assigned tutors resolve to one line on the server');
check(1 === preg_match('/save_subtitle.*?tutor_line\(/s', $mb), 'and saving falls back to it when nothing was posted');

/* One place answers the question. Two would drift, and the screen and the
   save would start disagreeing about who taught a report. */
check(1 === substr_count($mb, 'self::and_list('), 'only one place turns an assignment into that line');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
