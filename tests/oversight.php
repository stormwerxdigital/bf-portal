<?php
/*
 * The oversight report.
 *
 * It measures the paperwork against the schedule, and every rule in it is one
 * that quietly accuses somebody. A report that flags the wrong tutor is worse
 * than no report: it is read once, found wrong, and never read again.
 *
 * Four things have to be exactly right.
 *
 *   ON TIME is the day the session happened. Written up the next morning is
 *   late, and the report says by how many days.
 *
 *   LATE is measured from when the record was MADE, not when it was last
 *   touched. A tutor who fixes a typo six weeks later has not written the
 *   session up six weeks late.
 *
 *   NOTHING RECORDED is a scheduled hour with no session against its day at
 *   all, which is the only item here that means nobody can say whether a
 *   child was taught.
 *
 *   A MOVED slot is not an hour that was due. Counting it would have every
 *   rescheduled lesson appear as one nobody wrote up.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['USERS'] = array();
$GLOBALS['SLOTS'] = array();

function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id=null){ return $GLOBALS['POSTS'][(int)$id] ?? null; }
function get_post_type($id){ $p=get_post($id); return $p?$p->post_type:''; }
function get_the_title($id){ $p=get_post($id); return $p?$p->post_title:''; }
function get_post_status($id){ $p=get_post($id); return $p?$p->post_status:'publish'; }
function get_post_field($f,$id){ $p=get_post($id); return $p && isset($p->$f) ? $p->$f : ''; }
function get_post_meta($id,$k,$s=false){ $v=$GLOBALS['META'][$id][$k]??null; return null===$v?($s?'':array()):($s?$v:(array)$v); }
function get_userdata($id){ return $GLOBALS['USERS'][(int)$id] ?? false; }
function get_current_user_id(){ return 1; }
function is_admin(){ return true; }
function get_edit_post_link($id){ return '/wp-admin/post.php?post='.(int)$id; }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[]=is_array($r)?$r[$f]:$r->$f; } return $o; }
function update_meta_cache($t,$i){ return true; }
function cache_users($i){ return true; }
function get_posts($a){
  $type=$a['post_type']??''; $out=array();
  if ('bftd_session'===$type) {
    $want=array_map('intval',(array)($a['meta_query'][0]['value']??array()));
    $states=(array)($a['post_status']??array('publish'));
    foreach($GLOBALS['POSTS'] as $id=>$p){
      if('bftd_session'!==$p->post_type) continue;
      if(!in_array($p->post_status,$states,true)) continue;
      if(!in_array((int)($GLOBALS['META'][$id]['_bftd_student_id']??0),$want,true)) continue;
      $out[]=$id;
    }
    return $out;
  }
  foreach($GLOBALS['POSTS'] as $id=>$p){
    if($p->post_type!==$type) continue;
    if(!empty($a['post__in'])&&!in_array($id,$a['post__in'],true)) continue;
    $out[]=$p;
  }
  usort($out,function($a,$b){ return strcasecmp($a->post_title,$b->post_title); });
  return empty($a['fields'])?$out:wp_list_pluck($out,'ID');
}

class BFTD_Admin { const MENU_SLUG='bftd'; const HUB_SLUG='bftd-student'; }
class BFTD_Roles {
  const STAFF_CAP='bftd_manage_students'; const MANAGE_CAP='bftd_manage_all';
  public static function is_staff($u=null){ return true; }
  public static function can_manage($u=null){ return empty($GLOBALS['NO_MANAGE']); }
  public static function relationship($u){ return 'Parent or guardian'; }
  public static function role_name($u){ return 'Tutor'; }
  public static function post_types(){ return array('bftd_session'); }
  public static function plural_for($p){ return $p.'s'; }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Fields { public static function get($id,$g,$k,$f=array()){ return $GLOBALS['META'][$id]['_bftd_'.$g.'__'.$k] ?? ''; } }
class BFTD_Access {
  public static function visible_student_ids($u){ return $GLOBALS['VISIBLE']; }
  public static function can_staff_view($id,$u=0){ return true; }
}
/* The schedule's side of it, stated rather than generated: this file is about
   what the report does with a set of slots, and generating them here would be
   testing BFTD_Schedule twice. */
class BFTD_Schedule {
  const OFFSET_KEY='_bftd_lessons_used_before';
  public static function occurrences($sid,$from='',$to='',$limit=200){ return $GLOBALS['SLOTS'][$sid] ?? array(); }
  public static function used_counts($ids){ $o=array(); foreach((array)$ids as $i) $o[(int)$i]=0; return $o; }
  public static function pretty_time($t){ return $t; }
}
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-students.php';
require BFTD_PATH.'includes/class-bftd-oversight.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$GLOBALS['USERS'][5]=(object)array('ID'=>5,'display_name'=>'Rae Tanaka');
$GLOBALS['USERS'][6]=(object)array('ID'=>6,'display_name'=>'Jo Patel');
$GLOBALS['VISIBLE']=array();

function student($id,$name,$tutors,$status='active'){
  $GLOBALS['POSTS'][$id]=(object)array('ID'=>$id,'post_type'=>'bftd_student','post_status'=>'publish','post_title'=>$name);
  $GLOBALS['META'][$id]=array('_bftd_assigned_staff'=>$tutors,'_bftd_student__status'=>$status,
    '_bftd_client_user_id'=>array(),'_bftd_client_primary'=>0,'_bftd_student__lessons_bank'=>40);
  $GLOBALS['VISIBLE'][]=$id;
}
$next=7000;
function sess($owner,$date,$status,$made,$published=true){
  global $next;
  $p=$next++;
  $GLOBALS['POSTS'][$p]=(object)array('ID'=>$p,'post_type'=>'bftd_session',
    'post_status'=>$published?'publish':'draft','post_title'=>'s',
    'post_date'=>$made.' 19:00:00','post_modified'=>'2027-01-01 09:00:00');
  $GLOBALS['META'][$p]=array('_bftd_student_id'=>$owner,'_bftd_session__session_date'=>$date,
    '_bftd_session__session_time'=>'16:00','_bftd_session__status'=>$status);
  return $p;
}
function slot($owner,$date,$status='booked'){
  $GLOBALS['SLOTS'][$owner][]=array('date'=>$date,'time'=>'16:00','status'=>$status,'session'=>0,'tutor'=>5);
}

$WIN = array('key'=>'30','from'=>'2026-09-01','to'=>'2026-09-30','label'=>'September');

student(1001,'Kaine M',array(5));

/* ---- on time is the day it happened ---- */
$same = sess(1001,'2026-09-02','held','2026-09-02');
slot(1001,'2026-09-02');
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(1 === $r['on_time'] && array() === $r['late'], 'written up on the day is on time');

/* One day later is late, and the report says by how much. */
$GLOBALS['POSTS'][$same]->post_date = '2026-09-03 08:00:00';
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(0 === $r['on_time'] && 1 === count($r['late']), 'the next morning is late');
check(1 === $r['late'][0]['days'], 'by one day, got ' . $r['late'][0]['days']);

$GLOBALS['POSTS'][$same]->post_date = '2026-09-16 08:00:00';
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(14 === $r['late'][0]['days'], 'a fortnight later is fourteen days, got ' . $r['late'][0]['days']);

/* Measured from when the record was MADE. A tutor who fixes a typo six weeks
   later has not written the session up six weeks late, and a report that said
   so would be read once and never again. */
$GLOBALS['POSTS'][$same]->post_date = '2026-09-02 19:00:00';
$GLOBALS['POSTS'][$same]->post_modified = '2026-12-25 09:00:00';
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(1 === $r['on_time'], 'editing it months later does not make the write-up late');

/* Written up ahead of the session is not late either: a tutor preparing a
   record before the hour is doing the opposite of falling behind. */
check(0 === BFTD_Oversight::days_late($same, '2026-09-30'), 'and neither is writing it up in advance');

/* ---- an hour with nothing against it ---- */
slot(1001,'2026-09-09');
slot(1001,'2026-09-16');
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(3 === $r['due'], 'three scheduled hours were due, got ' . $r['due']);
check(2 === count($r['nothing']), 'two of them have nothing against them, got ' . count($r['nothing']));
check('2026-09-09' === $r['nothing'][0]['date'], 'named by date, so somebody can go and look');

/* A slot that was cancelled or moved in the schedule was never an hour that
   was due. Counting it would put every rescheduled lesson on the report as
   one nobody wrote up. */
slot(1001,'2026-09-23','cancelled');
slot(1001,'2026-09-24','moved');
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(3 === $r['due'], 'a slot cancelled or moved in the schedule was never due, got ' . $r['due']);
check(2 === count($r['nothing']), 'and is not reported as unwritten, got ' . count($r['nothing']));

/* ---- outside the window ---- */
sess(1001,'2026-08-05','held','2026-08-30');
$r = BFTD_Oversight::student_row(1001, BFTD_CPT::sessions_of(array(1001), true)[1001], $WIN);
check(array() === $r['late'] || 0 === count(array_filter($r['late'], function($l){ return '2026-08-05' === $l['date']; })),
  'a session before the window is not on this report');

/* ---- drafts, cancellations, reschedules ---- */
student(1002,'Alice B',array(5));
sess(1002,'2026-09-03','held','2026-09-03',false);       // never published
sess(1002,'2026-09-10','missed','2026-09-10');
sess(1002,'2026-09-17','rescheduled','2026-09-17');
sess(1002,'2026-09-24','rescheduled','2026-09-24');
slot(1002,'2026-09-03'); slot(1002,'2026-09-10'); slot(1002,'2026-09-17'); slot(1002,'2026-09-24');

$r2 = BFTD_Oversight::student_row(1002, BFTD_CPT::sessions_of(array(1002), true)[1002], $WIN);
check(1 === count($r2['drafts']), 'a session left as a draft is reported, got ' . count($r2['drafts']));
/* A draft is counted once, as a draft. It must not also appear as an hour
   written up — the three published sessions here were all written on the day,
   and the draft is the fourth hour with nothing a family can see. */
check(3 === $r2['on_time'], 'the published ones are counted as written up, got ' . $r2['on_time']);
check(array() === $r2['late'], 'none of them late');
check(4 === $r2['on_time'] + count($r2['late']) + count($r2['drafts']),
  'and every session in the window is counted exactly once');
$draft_ids = wp_list_pluck($r2['drafts'], 'session');
$late_ids  = wp_list_pluck($r2['late'], 'session');
check(array() === array_intersect($draft_ids, $late_ids), 'the draft is never also the late one');

/* Marking a session missed or moved is still the tutor doing the paperwork.
   A report that only credited attended sessions would tell a tutor their
   record was poor for a fortnight a family cancelled. */
check(0 === count(array_filter($r2['late'], function ($l) { return in_array($l['date'], array('2026-09-10','2026-09-17'), true); })),
  'recording a cancellation on the day is written up on time, like any other');
check(1 === count($r2['cancelled']), 'a cancellation is reported, got ' . count($r2['cancelled']));
check(2 === count($r2['moved']), 'and every reschedule, got ' . count($r2['moved']));

/* More than one move inside one calendar month is the flag. */
check(1 === count($r2['crowded']), 'two moves in one month is flagged, got ' . count($r2['crowded']));
check(2 === $r2['crowded'][0]['times'], 'saying how many, got ' . $r2['crowded'][0]['times']);

/* One a month is not. Six across a year is a family with a busy year; two
   inside one month is a fortnight with no reading in it. */
$spread = BFTD_Oversight::crowded(array(
  array('date'=>'2026-01-05'), array('date'=>'2026-02-05'), array('date'=>'2026-03-05'),
));
check(array() === $spread, 'one a month, however many months, is not flagged');

/* ---- the whole report ---- */
student(1003,'Milo Okafor',array(6));
slot(1003,'2026-09-07');
student(1004,'Past Student',array(5),'past');
slot(1004,'2026-09-07');

$groups = BFTD_Oversight::report($WIN);
check(2 === count($groups), 'a section per tutor, got ' . count($groups));

$names = array(); foreach ($groups as $g) $names[] = $g['name'];
check(array('Jo Patel','Rae Tanaka') === $names, 'by name, got ' . json_encode($names));

/* A past student has no schedule to be measured against and is not being
   taught. Reporting them puts a permanent row of unwritten hours on a
   tutor's record for a child who left. */
$all = array();
foreach ($groups as $g) foreach ($g['rows'] as $row) $all[] = $row['name'];
check(!in_array('Past Student', $all, true), 'a past student is not on it, got ' . json_encode($all));

/* The tutor's headline is their own students' hours, added up. */
$rae = null; foreach ($groups as $uid => $g) { if ('Rae Tanaka' === $g['name']) $rae = $g; }
check(null !== $rae, 'the tutor is named');
check($rae['due'] === $r['due'] + $r2['due'], 'their total is their students\' totals, got ' . $rae['due']);
check($rae['nothing'] === count($r['nothing']) + count($r2['nothing']), 'and so is what is unwritten');
check(1 === $rae['flagged'], 'and the flags, got ' . $rae['flagged']);

/* ---- the window ---- */
$w = BFTD_Oversight::window('30');
check($w['from'] < $w['to'], 'the default window runs backwards from today');
check($w['to'] === BFTD_Time::today(), 'and ends today, never in the future');
$m = BFTD_Oversight::window('month');
check('-01' === substr($m['from'], -3), 'this month starts on the first');
$l = BFTD_Oversight::window('last');
check($l['to'] < $m['from'], 'and last month ends before it, got ' . $l['to'] . ' and ' . $m['from']);
check('-01' === substr($l['from'], -3), 'starting on its own first');
$junk = BFTD_Oversight::window('nonsense');
check('30' === $junk['key'], 'anything unrecognised falls back rather than showing nothing');

/* ---- who may read it ---- */
/*
 * Managers and above. A tutor must not reach a screen that grades them
 * against their colleagues, and a hidden menu row is not what stops them: the
 * row is hidden, the URL is not. So the capability is on the page itself, and
 * the render refuses independently of the menu.
 */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-oversight.php');
check(false !== strpos($src, 'BFTD_Roles::MANAGE_CAP'),
  'the page is registered against the manager capability, not the staff one');
check(false === strpos($src, 'BFTD_Roles::STAFF_CAP'),
  'and never against the one every tutor holds');
check(false !== strpos($src, 'if ( ! BFTD_Roles::can_manage() )'),
  'and the render refuses on its own, because a hidden row is still a URL');

/* And does, when a tutor asks for it. */
$GLOBALS['NO_MANAGE'] = true;
ob_start(); BFTD_Oversight::render(); $refused = ob_get_clean();
check(false !== strpos($refused, 'for managers'), 'a tutor opening the URL is told it is not theirs');
check(false === strpos($refused, 'Rae Tanaka'), 'and is shown nobody\'s record, got ' . strlen($refused) . ' characters');
$GLOBALS['NO_MANAGE'] = false;

ob_start(); BFTD_Oversight::render(); $ok = ob_get_clean();
check(false !== strpos($ok, 'Rae Tanaka'), 'while a manager sees the report');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
