<?php
/*
 * The sessions screen.
 *
 * A flat list of sessions repeats the child, the client and the tutor on
 * twenty consecutive rows, and answers the question nobody asks. The shape
 * here follows the practice instead: a section per tutor, a row per student,
 * the sessions behind the row.
 *
 * What has to be true, and none of it is visible in the markup:
 *
 *   the attendance record counts what happened, drafts apart, because a
 *   draft is a tutor's notes in progress and is not a record of anything;
 *
 *   the sessions are not read until a row is opened, or the screen reads
 *   fifty thousand records in case somebody looks;
 *
 *   a page of twenty is twenty, and the last page is the remainder rather
 *   than twenty of whatever is left over.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['USERS'] = array();
$GLOBALS['QUERIES'] = 0;

function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id=null){ return $GLOBALS['POSTS'][(int)$id] ?? null; }
function get_post_type($id){ $p=get_post($id); return $p?$p->post_type:''; }
function get_the_title($id){ $p=get_post($id); return $p?$p->post_title:''; }
function get_post_status($id){ $p=get_post($id); return $p?$p->post_status:'publish'; }
function get_post_meta($id,$k,$s=false){ $v=$GLOBALS['META'][$id][$k]??null; return null===$v?($s?'':array()):($s?$v:(array)$v); }
function get_userdata($id){ return $GLOBALS['USERS'][(int)$id] ?? false; }
function get_current_user_id(){ return 1; }
function is_admin(){ return true; }
function get_edit_post_link($id){ return '/wp-admin/post.php?post='.(int)$id; }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[]=is_array($r)?$r[$f]:$r->$f; } return $o; }
function update_meta_cache($t,$i){ $GLOBALS['QUERIES']++; return true; }
function cache_users($i){ $GLOBALS['QUERIES']++; return true; }
function add_query_arg($a,$u){ return $u.'?'.http_build_query($a); }
function get_posts($a){
  $GLOBALS['QUERIES']++;
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
    if(!empty($a['meta_query'])){
      $q=$a['meta_query'][0];
      $have=(array)($GLOBALS['META'][$id][$q['key']]??array());
      if(!in_array((string)$q['value'], array_map('strval',$have), true)) continue;
    }
    $out[]=$p;
  }
  usort($out,function($a,$b){ return strcasecmp($a->post_title,$b->post_title); });
  return empty($a['fields'])?$out:wp_list_pluck($out,'ID');
}

class BFTD_Admin { const MENU_SLUG='bftd'; const HUB_SLUG='bftd-student'; }
class BFTD_Roles {
  const STAFF_CAP='bftd_manage_students';
  public static function is_staff($u=null){ return true; }
  public static function can_manage($u=null){ return true; }
  public static function relationship($u){ return 'Parent or guardian'; }
  public static function role_name($u){ return 'Tutor'; }
  public static function post_types(){ return array('bftd_session'); }
  public static function plural_for($p){ return $p.'s'; }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Fields { public static function get($id,$g,$k,$f=array()){ return $GLOBALS['META'][$id]['_bftd_'.$g.'__'.$k] ?? ''; } }
class BFTD_Access {
  public static function visible_student_ids($u){ return $GLOBALS['VISIBLE']; }
  public static function can_staff_view($id,$u=0){ return in_array((int)$id, $GLOBALS['VISIBLE'], true); }
}
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-schedule.php';
require BFTD_PATH.'includes/class-bftd-students.php';
require BFTD_PATH.'includes/class-bftd-sessions.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- a practice ---- */
$GLOBALS['USERS'][5]=(object)array('ID'=>5,'display_name'=>'Rae Tanaka');
$GLOBALS['USERS'][6]=(object)array('ID'=>6,'display_name'=>'Jo Patel');
$GLOBALS['USERS'][20]=(object)array('ID'=>20,'display_name'=>'Laurel Sanders','user_email'=>'l@example.test');

$GLOBALS['VISIBLE']=array(); $sess=5000;
function student($id,$name,$tutors,$client=20){
  $GLOBALS['POSTS'][$id]=(object)array('ID'=>$id,'post_type'=>'bftd_student','post_status'=>'publish','post_title'=>$name);
  $GLOBALS['META'][$id]=array('_bftd_assigned_staff'=>$tutors,'_bftd_client_user_id'=>array($client),
    '_bftd_client_primary'=>$client,'_bftd_student__status'=>'active','_bftd_student__lessons_bank'=>60);
  $GLOBALS['VISIBLE'][]=$id;
}
function sess($owner,$date,$status,$published=true,$time='09:00'){
  global $sess;
  $p=$sess++;
  $GLOBALS['POSTS'][$p]=(object)array('ID'=>$p,'post_type'=>'bftd_session',
    'post_status'=>$published?'publish':'draft','post_title'=>'s');
  $GLOBALS['META'][$p]=array('_bftd_student_id'=>$owner,'_bftd_session__session_date'=>$date,
    '_bftd_session__session_time'=>$time,'_bftd_session__status'=>$status);
  return $p;
}

student(1001,'Kaine M',array(5));
/* A progress report, because the session numbers on this screen are the same
   numbers the family's report uses, and a screen that invented its own would
   have a tutor and a parent naming different sessions in the same sentence. */
$GLOBALS['POSTS'][9001]=(object)array('ID'=>9001,'post_type'=>'bftd_progress','post_status'=>'publish','post_title'=>'PR');
$GLOBALS['META'][9001]=array('_bftd_student_id'=>1001);
student(1002,'Alice B',array(6));
student(1003,'Milo Okafor',array(5,6));   // two tutors
student(1004,'Nell Ward',array());        // nobody assigned

/* Kaine: 45 sessions, a mixture, plus two drafts. */
for ($i=0;$i<45;$i++) {
  $st = ($i%9===8) ? 'missed' : (($i%7===6) ? 'rescheduled' : 'held');
  sess(1001, sprintf('2026-%02d-%02d', 1+intdiv($i,4), 1+($i%28)), $st);
}
sess(1001,'2026-12-01','held',false);
sess(1001,'','held',false);               // started, no date yet
sess(1002,'2026-03-03','held');
sess(1002,'2026-03-10','missed');

/* ---- the attendance record ---- */
$rec = BFTD_Sessions::records(array(1001,1002,1003));
check(45 === $rec[1001]['held'] + $rec[1001]['rescheduled'] + $rec[1001]['missed'],
  'every published session is in the record, got ' . ($rec[1001]['held'] + $rec[1001]['rescheduled'] + $rec[1001]['missed']));
check(2 === $rec[1001]['draft'], 'drafts are counted apart, got ' . $rec[1001]['draft']);
check($rec[1001]['held'] > 0 && $rec[1001]['missed'] > 0 && $rec[1001]['rescheduled'] > 0,
  'and the three outcomes are told apart');
/* A draft is a tutor's notes in progress. Folded into "attended" it says a
   child came to a session nobody has finished writing up. */
check(47 === $rec[1001]['total'], 'the total counts them all, got ' . $rec[1001]['total']);
check(0 === $rec[1003]['total'], 'a student with no sessions has none, rather than nothing');
check('' === $rec[1003]['last'], 'and no last date to show');

/* ---- and the row it becomes ---- */
$rows = BFTD_Sessions::rows(array(1001,1002));
check(isset($rows[1001]['record']), 'the row carries the record');
check('Laurel Sanders' === $rows[1001]['client'], 'and the client, written once rather than on every session');

ob_start(); BFTD_Sessions::record_cell($rows[1001]['record']); $cell = ob_get_clean();
check(false !== strpos($cell,'attended') && false !== strpos($cell,'missed'),
  'the record is read in words, not by colour alone');
check(false !== strpos($cell,'draft'), 'and says how many are unfinished');
ob_start(); BFTD_Sessions::record_cell($rec[1003]); $none = ob_get_clean();
check(false !== strpos($none,'Nothing recorded yet'), 'a student with none says so');

/* ---- the drawer ---- */
ob_start(); BFTD_Sessions::drawer(1001, 1); $page1 = ob_get_clean();
check(20 === substr_count($page1,'<tr>') - 1, 'a page is twenty sessions, got ' . (substr_count($page1,'<tr>')-1));
check(false !== strpos($page1,'1–20 of 47'), 'saying which twenty of how many');
check(3 === substr_count($page1,'data-page='), 'with a button per page, got ' . substr_count($page1,'data-page='));
check(false !== strpos($page1,'is-on'), 'and the page you are on marked');

ob_start(); BFTD_Sessions::drawer(1001, 3); $page3 = ob_get_clean();
check(7 === substr_count($page3,'<tr>') - 1, 'the last page is the remainder, got ' . (substr_count($page3,'<tr>')-1));
check(false !== strpos($page3,'41–47 of 47'), 'and says so');

/* Asking for a page that is not there lands on the last one rather than on
   an empty drawer. */
ob_start(); BFTD_Sessions::drawer(1001, 99); $far = ob_get_clean();
check($far === $page3, 'a page past the end is the last page, not an empty one');

/* Newest first, because the session somebody is looking for is almost always
   the one that just happened. An undated one is unfinished, so it sorts to
   the end rather than to the top. */
$all = BFTD_CPT::sessions_of(array(1001), true);
$dates = array();
foreach ($all[1001] as $sid) $dates[] = BFTD_Fields::get($sid,'session','session_date');
$sorted = $dates; $undated = array_filter($dates, function($d){ return '' === $d; });
check('' === end($dates), 'an undated session is last, not first');
array_pop($sorted);
$copy = $sorted; rsort($copy);
check($copy === $sorted, 'and the rest are newest first');

/* ---- what one session row says ---- */
check(false !== strpos($page1,'Published') && false !== strpos($page3,'Draft'),
  'a row says whether the family can see it');
check(1 === preg_match('/Session \d+/',$page1), 'and which session it is in the programme');
/* The same numbering the family's report uses. Forty five published sessions
   means the newest is forty five, and a screen that numbered its own page
   would call it one. */
check(false !== strpos($page1,'Session 45'), 'counted from the start of the programme, not from the top of the page');
check(false === strpos($page1,'Session 1<'), 'so session one is not on the first page at all');
/* However this site writes a time. The format is a site setting, so
   asserting "9:00 am" would fail on a practice that prefers a 24 hour
   clock — and pass while the time was missing on one that does not. */
check(false !== strpos($page1,'9:00'), 'with the time beside the date');
check(false !== strpos($page1,'bftd-ses-t'), 'as its own line under it');
check(false !== strpos($page3,'No date yet'), 'and an unfinished one says it has no date');

/* ---- the cost ---- */
/* The screen must not read a student's sessions to draw their row: fifty
   thousand records fetched in case somebody opens one. */
$GLOBALS['QUERIES'] = 0;
BFTD_Sessions::rows(array(1001,1002,1003,1004));
$screen = $GLOBALS['QUERIES'];
check($screen <= 6, 'four students cost ' . $screen . ' queries');

$GLOBALS['QUERIES'] = 0;
BFTD_Sessions::rows(array(1001));
$one = $GLOBALS['QUERIES'];
check($one === $screen, 'and one student costs the same as four, got ' . $one);

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
