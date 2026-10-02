<?php
/*
 * Nobody teaches more than ten lessons in a day, and none of them overlap.
 *
 * The check has to look across students, because the eleventh booking is never
 * the one that looks wrong on its own — it is a different child's schedule
 * that happens to land on the same afternoon.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array(); $GLOBALS['POSTS'] = array();
function get_post_meta($id,$k,$single=false){$v=$GLOBALS['META'][$id][$k]??($single?'':array());return $v;}
function update_post_meta($id,$k,$v){$GLOBALS['META'][$id][$k]=$v;return true;}
function get_post_type($id){return 'bftd_student';}
function get_the_title($id){return 'Student '.$id;}
function get_current_user_id(){return 1;}
function get_userdata($id){$u=new stdClass;$u->ID=$id;$u->display_name='Tutor '.$id;return $u;}
function set_transient($k,$v,$t=0){$GLOBALS['TRANSIENTS'][$k]=$v;return true;}
function get_transient($k){return $GLOBALS['TRANSIENTS'][$k]??false;}
/* Students when asked for students; nothing when asked for sessions, because
   nobody in this file has taught anything — it is about how many slots a
   tutor may be booked into in a day. */
function get_posts($args){
  if (($args['post_type'] ?? '') === 'bftd_session') return array();
  return $GLOBALS['STUDENT_IDS'];
}

class BFTD_Audit { public static function log($e,$a=array()){} }
class BFTD_CPT {
  const STUDENT='bftd_student';
  const PROGRESS='bftd_progress';
  const SESSION='bftd_session';
  const STUDENT_KEY='_bftd_student_id';
  public static function report_for($sid,$t){ return 0; }
  public static function sessions_for($rid){ return array(); }
  public static function sessions_of($ids, $drafts = false) {
    $out = array(); foreach ((array) $ids as $i) $out[(int) $i] = array(); return $out;
  }
  public static function student_id($sid){ return 0; }
  public static function staff_ids($sid){ return $GLOBALS['STAFF'][$sid] ?? array(); }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Fields {
  public static function get($id,$group,$key,$f=array()){ return $GLOBALS['META'][$id]['_bftd_student__'.$key] ?? ''; }
}
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-schedule.php';

$TUTOR = 7;
$fail  = 0;
$CAP   = BFTD_Schedule::daily_cap();
printf("Daily ceiling: %d\n\n", $CAP);

function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* A schedule only books as far as the lessons paid for reach, so every
   student here has a generous block bought. */
function stock($sid, $n = 200) {
  $GLOBALS['META'][$sid]['_bftd_student__lessons_bank'] = $n;
  $GLOBALS['STUDENT_IDS'][] = $sid;
  return $sid;
}

/* Ten other children fill Tutor 7's Mondays, an hour apart from nine. */
$GLOBALS['STUDENT_IDS'] = array();
$hours = array('09:00','10:00','11:00','12:00','13:00','14:00','15:00','16:00','17:00','18:00');
foreach ($hours as $i => $h) {
  $sid = stock(100 + $i);
  BFTD_Schedule::save_rules($sid, array(
    array('day'=>'1','time'=>$h,'minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
  ));
}

$monday = date('Y-m-d', strtotime('next monday'));
$load   = BFTD_Schedule::tutor_load($TUTOR, $monday, $monday);
printf("Tutor %d on %s: %d lessons\n", $TUTOR, $monday, $load[$monday] ?? 0);

check(($load[$monday] ?? 0) === 10, 'ten lessons in a day is allowed');
check(BFTD_Schedule::day_is_full($TUTOR, $monday), 'a day at the ceiling reads as full');

/* --- the ceiling --- */
$eleventh = 200;
stock($eleventh);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($eleventh, array(
  array('day'=>'1','time'=>'19:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($eleventh)) === 0, 'an eleventh Monday lesson is turned away');
$w = get_transient('bftd_sched_warn_1');
check(!empty($w) && strpos($w[0], 'most anyone teaches in a day') !== false, 'and the reason given is the ceiling');
if (!empty($w)) echo "      \"{$w[0]}\"\n";

/* The cap is per day, not per week. */
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($eleventh, array(
  array('day'=>'2','time'=>'19:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($eleventh)) === 1, 'the same slot on a Tuesday is fine');

/* --- overlaps --- */
$clasher = 300;
stock($clasher);

/* Straight on top of the eleven o'clock. */
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($clasher, array(
  array('day'=>'1','time'=>'11:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($clasher)) === 0, 'the same time as an existing lesson is turned away');
$w = get_transient('bftd_sched_warn_1');
check(!empty($w) && strpos($w[0], 'two sessions at once') !== false, 'and the reason given is the clash, not the ceiling');
if (!empty($w)) echo "      \"{$w[0]}\"\n";

/* Overlapping by ten minutes counts. */
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($clasher, array(
  array('day'=>'1','time'=>'11:40','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($clasher)) === 0, 'overlapping by ten minutes is still an overlap');

/* Starting exactly as another ends is not an overlap. Saturday, because
   Monday is already at the ceiling and would refuse on that ground instead. */
$sat_a = stock(310);
BFTD_Schedule::save_rules($sat_a, array(
  array('day'=>'6','time'=>'14:00','minutes'=>60,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
$sat_b = 311; stock($sat_b);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($sat_b, array(
  array('day'=>'6','time'=>'15:00','minutes'=>60,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($sat_b)) === 1, 'back to back is not an overlap');

/* One minute of shared time is an overlap. */
$sat_c = 312; stock($sat_c);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($sat_c, array(
  array('day'=>'6','time'=>'14:59','minutes'=>60,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($sat_c)) === 0, 'sharing a single minute is an overlap');

/* A clash is caught even when the day is nearly empty. */
$quiet = 400; stock($quiet);
$sunday = date('Y-m-d', strtotime('next sunday'));
BFTD_Schedule::save_rules($quiet, array(
  array('day'=>'7','time'=>'14:00','minutes'=>60,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
$rival = 401; stock($rival);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($rival, array(
  array('day'=>'7','time'=>'14:30','minutes'=>60,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($rival)) === 0, 'a clash on an otherwise empty day is still refused');

/* Two new slots in the same save cannot clash with each other either. */
$twin = 500; stock($twin);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($twin, array(
  array('day'=>'4','time'=>'10:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
  array('day'=>'4','time'=>'10:20','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));
check(count(BFTD_Schedule::rules($twin)) === 1, 'of two slots that overlap each other, only the first is kept');

/* A different tutor at the same time is not a clash. */
$other = 600; stock($other);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($other, array(
  array('day'=>'1','time'=>'11:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>9),
));
check(count(BFTD_Schedule::rules($other)) === 1, 'a different tutor at the same time is fine');

/* An unassigned slot has nobody to clash with. */
$free = 700; stock($free);
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($free, array(
  array('day'=>'1','time'=>'11:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>0),
));
check(count(BFTD_Schedule::rules($free)) === 1, 'a slot with no tutor picked always fits');

/* --- moving a lesson --- */
$mover = 800; stock($mover);
$friday = date('Y-m-d', strtotime('next friday'));
BFTD_Schedule::save_rules($mover, array(
  array('day'=>'5','time'=>'09:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>$TUTOR),
));

$res = BFTD_Schedule::set_exception($mover, $friday.' 09:00', 'move', $monday, '11:00', 'Swap');
check(is_wp_error($res) && $res->get_error_code() === 'bftd_clash', 'moving onto an occupied time is refused as a clash');
if (is_wp_error($res)) echo "      \"{$res->get_error_message()}\"\n";

$res = BFTD_Schedule::set_exception($mover, $friday.' 09:00', 'move', $monday, '20:00', 'Late');
check(is_wp_error($res) && $res->get_error_code() === 'bftd_day_full', 'a free time on a full day is refused as full');
if (is_wp_error($res)) echo "      \"{$res->get_error_message()}\"\n";

$wednesday = date('Y-m-d', strtotime('next wednesday'));
$res = BFTD_Schedule::set_exception($mover, $friday.' 09:00', 'move', $wednesday, '18:00', 'Fine');
check(!is_wp_error($res), 'a free time on a free day is allowed');

/* --- what the calendar is handed --- */
$m = BFTD_Schedule::month($mover, date('Y-m', strtotime($monday)));
$cell = null;
foreach ($m['cells'] as $c) { if ($c && $c['date'] === $monday) $cell = $c; }
check($m['cap'] === $CAP, 'the grid is told the ceiling');
check(!empty($cell) && $cell['full'] === true, 'the full Monday is marked full on the grid');
check(!empty($cell) && count($cell['taken']) === 10, 'and carries the ten times that are taken');
check(!empty($cell['taken'][0]['time']), 'each taken slot is labelled for the warning text');

/* ---------------------------------------------------------------- */
/* "Whoever is assigned" is a real tutor, not an exemption            */
/* ---------------------------------------------------------------- */

/* This is the default every slot starts on, so if it escaped the rules,
   the rules would apply to almost nothing. */
$dflt = stock(900);
$GLOBALS['STAFF'][$dflt] = array($TUTOR);          // Tutor 7 is their tutor
$GLOBALS['TRANSIENTS'] = array();
BFTD_Schedule::save_rules($dflt, array(
  array('day'=>'1','time'=>'11:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>0),
));
check(count(BFTD_Schedule::rules($dflt)) === 0, 'a default slot clashing with its own tutor is refused');
$w = get_transient('bftd_sched_warn_1');
check(!empty($w) && strpos($w[0], 'two sessions at once') !== false, 'and it is refused as a clash, like any other');

/* The resolved tutor is what the rest of the system reads. */
$free = stock(901);
$GLOBALS['STAFF'][$free] = array($TUTOR);
BFTD_Schedule::save_rules($free, array(
  array('day'=>'6','time'=>'08:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>0),
));
$occ = BFTD_Schedule::occurrences($free, date('Y-m-d'), date('Y-m-d', strtotime('+14 days')));
$first = $occ ? $occ[0] : array();
check(!empty($first) && (int) $first['tutor'] === $TUTOR, 'an occurrence names the tutor the default resolves to');
check(isset($first['tutor_named']) && (int) $first['tutor_named'] === 0, 'while remembering the slot itself named nobody');

/* Naming somebody overrides it, which is the sick day and holiday case. */
$temp = stock(902);
$GLOBALS['STAFF'][$temp] = array($TUTOR);
BFTD_Schedule::save_rules($temp, array(
  array('day'=>'6','time'=>'09:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>9),
));
$occ = BFTD_Schedule::occurrences($temp, date('Y-m-d'), date('Y-m-d', strtotime('+14 days')));
check(!empty($occ) && (int) $occ[0]['tutor'] === 9, 'a named tutor overrides the default');

/* A student with no tutor at all has nobody to overload. */
$orphan = stock(903);
BFTD_Schedule::save_rules($orphan, array(
  array('day'=>'1','time'=>'11:00','minutes'=>50,'from'=>date('Y-m-d'),'tutor'=>0),
));
check(count(BFTD_Schedule::rules($orphan)) === 1, 'a student with no tutor assigned is not blocked by anyone else\'s day');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
