<?php
/*
 * Skills we are building, once there are two tracks.
 *
 * What is LEFT is the track the student is on, from the number they started it
 * at. A child who joined Tracks 2 and 3 at skill ten is not behind on the nine
 * before it, because they were never on them.
 *
 * What is DONE is every track, because a skill learned in Track 1 is still
 * learned after they move. Dropping it on the move would make a report say a
 * child had gone backwards.
 *
 * WORDWALL is not in what is left. Those are words read on sight and they are
 * optional, so counting them as outstanding puts a number on a family's report
 * nobody intends to finish. A done one still shows, because the child did it.
 *
 * And the other track is mentioned in a line rather than listed, because a
 * parent on Track 1 does not want a hundred and forty things not yet begun.
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
  public static function sequence($track){
    $o=array();
    foreach($GLOBALS['LIB'] as $id=>$r) if($r['track']===$track && $r['number']) $o[$r['number']]=$id;
    ksort($o); return $o;
  }
  public static function done_for_student($sid){ return $GLOBALS['DONE']; }
}
class BFTD_Derived {
  public static function skills($r){ return $GLOBALS['GRID']; }
  public static function track($sid){ return $GLOBALS['TRACK']; }
  public static function track_start($sid,$t){ return $GLOBALS['START'][$t] ?? 1; }
}
class BFTD_CPT { public static function student_id($r){ return 900; } }
class BFTD_Schema {
  public static function reading_levels(){ return array('grade-1'=>'Grade 1'); }
}
function get_the_title($id){ return 'Session '.$id; }
function get_userdata($id){ return null; }

require BFTD_PATH . 'includes/class-bftd-charts.php';

/* One session that reached two Track 1 skills. */
$GLOBALS['GRID'] = array(
  'lessons' => array(array('id'=>801,'label'=>'7 Jul 2026','skills'=>array(11=>'Hearing the middle sound',12=>'Mapping sounds'))),
  'rows'    => array(11=>array(0=>'new'), 12=>array(0=>'new')),
  'names'   => array(11=>'Hearing the middle sound', 12=>'Mapping sounds'),
);
$GLOBALS['LIB']   = $LIB;
$GLOBALS['DONE']  = array(11=>true, 12=>true);
$GLOBALS['TRACK'] = 't1';
$GLOBALS['START'] = array('t1'=>1,'t23'=>1);

/* ---- on Track 1, from the start ---- */
echo "On Track 1 from skill one:\n";
$out = BFTD_Charts::coverage(1);

check(false !== strpos($out, '3. Forming letters'), 'skill 3 is listed as still ahead, with its number');
check(false !== strpos($out, '4. Reading a pattern'), 'and skill 4');
check(false === strpos($out, 'said'), 'Wordwall is NOT in what is left, because it is optional');
check(2 === substr_count($out, 'class="skl-r is-ahead'), 'two skills outstanding, not three');
check(false === strpos($out, 'Splitting a syllable'), 'and nothing from the other track is listed');
check(false !== strpos($out, 'Track 2 &amp; 3 comes next, after the 2 remaining skills in Track 1'),
  'the other track is one line, naming how many are left first');
check(2 === substr_count($out, 'skl-mark is-done'), 'the two reached skills are ticked');

/* ---- joining a track part way through ---- */
echo "\nJoining Tracks 2 and 3 at skill two:\n";
$GLOBALS['TRACK'] = 't23';
$GLOBALS['START'] = array('t1'=>1,'t23'=>2);
$out = BFTD_Charts::coverage(1);
check(false !== strpos($out, '2. Spelling a plural'), 'skill 2 of that track is outstanding');
check(false === strpos($out, '1. Splitting a syllable'), 'and skill 1 is not, because they never started on it');
check(1 === substr_count($out, 'class="skl-r is-ahead'), 'exactly one outstanding');

/* ---- what they did in the track they left is still theirs ---- */
echo "\nHistory survives the move:\n";
/* Nothing is secure yet at this point: the only two done skills are both on
   this report's own session, so they are listed with the session that reached
   them rather than as history. */
check(false === strpos($out, 'Already secure'),
  'a skill reached on THIS report is credited to its session, not to history');

/* Now one that was finished before the move, with no session here to show. */
$GLOBALS['DONE'] = array(11=>true, 12=>true, 13=>true);
$out = BFTD_Charts::coverage(1);
check(false !== strpos($out, '3. Forming letters'),
  'a Track 1 skill completed before the move is still listed as done');
check(false !== strpos($out, 'Already secure'),
  'and shown as history, because no session on this report reached it');
check(false === strpos($out, 'class="skl-r is-ahead">' . "\n" . '3. Forming letters'),
  'and not as outstanding');

/* ---- a skill in the current track, finished somewhere this report cannot see
 *
 * Ticked on the student, or reached on a session belonging to an earlier report.
 * Either way it is done, so it must not be listed as outstanding. This needs a
 * skill that is NOT on this report's grid, otherwise the grid check catches it
 * first and the done check is never reached: the first version of this test
 * passed with the done check deleted for exactly that reason.
 */
echo "\nA current-track skill finished out of this report's sight:\n";
$GLOBALS['TRACK'] = 't23';
$GLOBALS['START'] = array('t1'=>1,'t23'=>1);
$GLOBALS['DONE']  = array(21=>true);           // Track 2 & 3, skill 1, not on the grid
$out = BFTD_Charts::coverage(1);
check(1 === substr_count($out, 'class="skl-r is-ahead'),
  'only the one genuinely outstanding skill is listed');
check(false !== strpos($out, '2. Spelling a plural'), 'and it is the right one');
check(false !== strpos($out, 'Splitting a syllable'),
  'the finished one still appears, as something already done');
check(false !== strpos($out, 'Already secure'), 'labelled as history rather than as a session on this report');

/* ---- nobody placed on a track ---- */
echo "\nNobody placed on a track yet:\n";
$GLOBALS['TRACK'] = '';
$out = BFTD_Charts::coverage(1);
check(0 === substr_count($out, 'class="skl-r is-ahead'),
  'nothing is claimed outstanding, because there is no sequence to count against');
check(false === strpos($out, 'skl-next'), 'and no next track is promised');

/* ---- a track with no numbered skills ---- */
echo "\nA track nobody has numbered:\n";
$GLOBALS['TRACK'] = 't1';
$GLOBALS['LIB']   = array(11 => array('number'=>0,'name'=>'Unnumbered','track'=>'t1','group'=>'skill'));
$GLOBALS['DONE']  = array();
$out = BFTD_Charts::coverage(1);
check(0 === substr_count($out, 'class="skl-r is-ahead'),
  'an unnumbered skill is in the library but not a position in a programme');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
