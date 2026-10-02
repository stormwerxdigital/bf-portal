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
require dirname(__DIR__) . '/wp-stubs.php';
define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
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


function check($ok,$msg){}


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


/* Everything the report is drawn from, stated here rather than left to the
   window: a fixture that moved with today's date would draw a different
   screen every day and a test could assert nothing about it. */
$WIN = array('key'=>'30','from'=>'2026-09-01','to'=>'2026-09-30','label'=>'September');
student(1001,'Kaine M',array(5));
$same = sess(1001,'2026-09-02','held','2026-09-05');      // three days late
slot(1001,'2026-09-02'); slot(1001,'2026-09-09'); slot(1001,'2026-09-16');
sess(1001,'2026-09-09','held','2026-09-09');

student(1002,'Alice B',array(5));
sess(1002,'2026-09-03','held','2026-09-03',false);
sess(1002,'2026-09-10','missed','2026-09-10');
sess(1002,'2026-09-17','rescheduled','2026-09-17');
sess(1002,'2026-09-24','rescheduled','2026-09-24');
slot(1002,'2026-09-03'); slot(1002,'2026-09-10'); slot(1002,'2026-09-17'); slot(1002,'2026-09-24');

student(1003,'Milo Okafor',array(6));
slot(1003,'2026-09-07');
sess(1003,'2026-09-14','held','2026-09-14');
slot(1003,'2026-09-14');

$_GET = array();
$groups = BFTD_Oversight::report($WIN);
ob_start();
echo '<div class="wrap bftd-wrap bftd-stu bftd-ov"><h1>Oversight</h1>';
$sec = new ReflectionMethod('BFTD_Oversight','section'); $sec->setAccessible(true);
foreach ($groups as $uid => $g) $sec->invoke(null, $uid, $g, $WIN);
echo '</div>';
$html = ob_get_clean();

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
file_put_contents(__DIR__.'/oversight.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 -apple-system,system-ui,sans-serif;background:#F0F0F1;margin:0;padding:20px}'
  . '.wrap{max-width:1300px;margin:0 auto}' . $css . '</style>' . $html);
echo __DIR__.'/oversight.html' . "\n";
