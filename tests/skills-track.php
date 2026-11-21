<?php
/*
 * Skills on a progress report come from sessions and nothing else (Karl,
 * 1.208: "pull skills reached from what is entered into sessions. No longer
 * concerned about total list or remaining items as its not the same and is
 * customized for each student").
 *
 * So the list is the skills reached on this report's sessions, then any
 * reached on the student's earlier sessions. Nothing still to do is listed,
 * no line says what track comes next, and a student has no track. Skills once
 * ticked on the student (the old Skills completed box) do not count.
 */
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

define('BFTD_PATH', dirname(__DIR__).'/');

/* ---- the library, as rows the way the real one returns them ---- */
$LIB = array(
  11 => array('number'=>1, 'name'=>'Hearing the middle sound', 'track'=>'t1',  'group'=>'skill'),
  12 => array('number'=>2, 'name'=>'Mapping sounds',           'track'=>'t1',  'group'=>'skill'),
  13 => array('number'=>3, 'name'=>'Forming letters',          'track'=>'t1',  'group'=>'skill'),
  14 => array('number'=>4, 'name'=>'Reading a pattern',        'track'=>'t1',  'group'=>'skill'),
  15 => array('number'=>5, 'name'=>'said',                     'track'=>'t1',  'group'=>'wordwall'),
  21 => array('number'=>1, 'name'=>'Splitting a syllable',     'track'=>'t23', 'group'=>'skill'),
  22 => array('number'=>2, 'name'=>'Spelling a plural',        'track'=>'t23', 'group'=>'skill'),
);

class BFTD_Skills {
  public static function all(){ return $GLOBALS['LIB']; }
  public static function labels(){ $o=array(); foreach($GLOBALS['LIB'] as $i=>$r) $o[$i]=$r['name']; return $o; }
  public static function label($id){ return $GLOBALS['LIB'][$id]['name'] ?? ''; }
  public static function tracks(){ return array('t1'=>'Track 1','t23'=>'Track 2 & 3'); }
  public static function track_label($t){ return self::tracks()[$t] ?? ''; }
  public static function reached_in_sessions($sid){ return $GLOBALS['SESSIONS']; }
}
class BFTD_Derived {
  public static function skills($r){ return $GLOBALS['GRID']; }
}
class BFTD_CPT { public static function student_id($r){ return 900; } }
class BFTD_Schema {
  public static function reading_levels(){ return array('grade-1'=>'Grade 1'); }
}
function get_the_title($id){ return 'Session '.$id; }
function get_userdata($id){ return null; }

require BFTD_PATH . 'includes/class-bftd-charts.php';

/* One session on this report that reached two skills. */
$GLOBALS['GRID'] = array(
  'lessons' => array(array('id'=>801,'label'=>'7 Jul 2026','skills'=>array(11=>'Hearing the middle sound',12=>'Mapping sounds'))),
  'rows'    => array(11=>array(0=>'new'), 12=>array(0=>'new')),
  'names'   => array(11=>'Hearing the middle sound', 12=>'Mapping sounds'),
);
$GLOBALS['LIB']      = $LIB;
$GLOBALS['SESSIONS'] = array(11=>true, 12=>true);

echo "Only this report's sessions:\n";
$out = BFTD_Charts::coverage(1);
check(2 === substr_count($out, 'class="skl-r is-on'), 'the two skills reached are listed');
check(2 === substr_count($out, 'skl-mark is-done'), 'each with a checkmark');
check(0 === substr_count($out, 'is-ahead'), 'nothing is listed as still to do');
check(false === strpos($out, 'Forming letters') && false === strpos($out, 'Reading a pattern'),
  'so the rest of the library is not on the report');
check(false === strpos($out, 'skl-next') && false === strpos($out, 'comes next'), 'and no line says what track comes next');

echo "\nEarlier sessions count too:\n";
$GLOBALS['SESSIONS'] = array(11=>true, 12=>true, 22=>true, 15=>true);
$out = BFTD_Charts::coverage(1);
check(4 === substr_count($out, 'class="skl-r is-on'), 'a skill reached on an earlier session is listed as well, in any track, Wordwall included');
check(false !== strpos($out, '2. Spelling a plural'), 'with its library number');
check(strpos($out, 'Hearing the middle sound') < strpos($out, 'Spelling a plural'), 'after the ones from this report');
check(0 === substr_count($out, 'is-ahead'), 'and still nothing to do is listed');

echo "\nThe student page has no track and no ticked skills:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false === strpos($mb, "'Skills completed'") && false === strpos($mb, 'box_skills_done'), 'no Skills completed box');
check(false === strpos($mb, 'track_fields') && false === strpos($mb, 'bftd-track-box'), 'no Track or starting skill number in History');
$sk = file_get_contents(BFTD_PATH . 'includes/class-bftd-skills.php');
check(false === strpos($sk, 'done_for_student') && false === strpos($sk, 'save_ticks'), 'skills once ticked on the student are not read or saved');
$st = file_get_contents(BFTD_PATH . 'includes/class-bftd-students.php');
check(false === strpos($st, 'students_on_track') && false === strpos($st, 'Any track'), 'and the Students list has no track filter');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
