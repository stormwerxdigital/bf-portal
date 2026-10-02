<?php
/*
 * The lesson bank and the occurrence generator. The point of this one is the
 * distinction that is easy to get wrong: booking is not using.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array(); $GLOBALS['POSTS'] = array();
function get_post_meta($id,$k,$single=false){$v=$GLOBALS['META'][$id][$k]??($single?'':array());return $v;}
function update_post_meta($id,$k,$v){$GLOBALS['META'][$id][$k]=$v;return true;}
function get_post_type($id){return $GLOBALS['POSTS'][$id]['type']??'';}
function get_the_title($id){return 'Student';}
function get_current_user_id(){return 1;}
function wp_get_current_user(){$u=new stdClass;$u->display_name='Test';$u->exists=function(){return true;};return $u;}
function get_edit_post_link($id,$c=''){return '#';}

class BFTD_Audit { public static function log($e,$a=array()){} }
class BFTD_CPT {
  public static function staff_ids($sid){ return $GLOBALS['STAFF'][$sid] ?? array(); }
  const PROGRESS='bftd_progress'; const SESSION='bftd_session';
  const STUDENT_KEY='_bftd_student_id';
  public static function report_for($sid,$t){ return 900; }
  public static function sessions_for($rid){ return $GLOBALS['SESSIONS'] ?? array(); }
  public static function sessions_of($ids, $drafts = false) {
    $out = array();
    foreach ((array) $ids as $i) $out[(int) $i] = array();
    foreach ((array) ($GLOBALS['SESSIONS'] ?? array()) as $id) {
      $owner = (int) ($GLOBALS['OWNER'][$id] ?? 0);
      if (isset($out[$owner])) $out[$owner][] = $id;
    }
    return $out;
  }
  public static function student_id($sessionId){ return (int) ($GLOBALS['OWNER'][$sessionId] ?? 0); }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
/* The sessions belonging to a set of students, which is what the bulk
   counter asks for. Every session here belongs to the one student this file
   schedules for. */
function get_posts($a){
  if (($a['post_type'] ?? '') === 'bftd_session') {
    $want = array_map('intval', (array) ($a['meta_query'][0]['value'] ?? array()));
    $out = array();
    foreach ((array) ($GLOBALS['SESSIONS'] ?? array()) as $id) {
      $owner = (int) ($GLOBALS['OWNER'][$id] ?? 0);
      if (in_array($owner, $want, true)) $out[] = $id;
    }
    return $out;
  }
  return array();
}
class BFTD_Fields {
  public static function get($id,$group,$key,$f=array()){
    if ($group==='session') return $GLOBALS['SESSION_META'][$id][$key] ?? '';
    return $GLOBALS['META'][$id]['_bftd_student__'.$key] ?? '';
  }
}
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-schedule.php';

/* Student 10: Mondays and Wednesdays at 16:00, from a fixed Monday. */
$student = 10;
$GLOBALS['META'][$student]['_bftd_student__lessons_bank'] = 20;
BFTD_Schedule::save_rules($student, array(
  array('day'=>'1','time'=>'16:00','minutes'=>50,'from'=>'2026-01-05'),
  array('day'=>'3','time'=>'16:00','minutes'=>50,'from'=>'2026-01-05'),
));

/* Two lessons actually taught. */
$GLOBALS['SESSIONS'] = array(801, 802);
$GLOBALS['OWNER']    = array(801 => $student, 802 => $student);
$GLOBALS['SESSION_META'] = array(
  801 => array('session_date'=>'2026-01-05','status'=>'held'),
  802 => array('session_date'=>'2026-01-07','status'=>'held'),
);

$occ = BFTD_Schedule::occurrences($student, '2026-01-05', '2026-01-19');
printf("Occurrences 5–19 Jan: %d  (2 per week over 2 weeks + 1)\n", count($occ));

$held = 0; foreach($occ as $o) if($o['status']==='held') $held++;
printf("Marked taught: %d\n", $held);

/* Cancel the 12th. */
BFTD_Schedule::set_exception($student, '2026-01-12 16:00', 'cancel', '', '', 'Family away');
$occ = BFTD_Schedule::occurrences($student, '2026-01-05', '2026-01-19');
$cancelled = 0; foreach($occ as $o) if($o['status']==='cancelled') $cancelled++;
printf("Cancelled: %d\n", $cancelled);

$bank = BFTD_Schedule::bank($student);
printf("\nPurchased %d | Used %d | Remaining %d\n", $bank['purchased'], $bank['used'], $bank['remaining']);

$fail = 0;
if ($held !== 2)            { echo "FAIL: taught lessons not detected\n"; $fail++; }
if ($cancelled !== 1)       { echo "FAIL: cancellation not applied\n"; $fail++; }
if ($bank['used'] !== 2)    { echo "FAIL: used should count only taught lessons, got {$bank['used']}\n"; $fail++; }
if ($bank['remaining']!==18){ echo "FAIL: remaining should be 18, got {$bank['remaining']}\n"; $fail++; }

/* The one that matters: a cancellation must not cost a lesson. */
$before = $bank['used'];
BFTD_Schedule::set_exception($student, '2026-01-14 16:00', 'cancel', '', '', 'Snow');
$after = BFTD_Schedule::bank($student)['used'];
if ($after !== $before) { echo "FAIL: cancelling a lesson changed the used count\n"; $fail++; }
else echo "Cancelling does not consume a lesson: correct\n";

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
