<?php
/*
 * The students screen, drawn by the real renderer, so it can be looked at and
 * measured. A practice of three tutors and sixty children, which is the shape
 * that shows whether the sections work.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
require BFTD_PATH . 'tests/wp-stubs.php';

$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['USERS'] = array();
function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id=null){ return $GLOBALS['POSTS'][(int)$id] ?? null; }
function get_post_type($id){ $p=get_post($id); return $p?$p->post_type:''; }
function get_the_title($id){ $p=get_post($id); return $p?$p->post_title:''; }
function get_post_status($id){ $p=get_post($id); return $p?$p->post_status:'publish'; }
function get_post_meta($id,$k,$s=false){ $v=$GLOBALS['META'][$id][$k]??null; return null===$v?($s?'':array()):($s?$v:(array)$v); }
function get_userdata($id){ return $GLOBALS['USERS'][(int)$id] ?? false; }
function get_current_user_id(){ return 1; }
function is_admin(){ return true; }
function get_edit_post_link($id){ return '/wp-admin/post.php?post='.(int)$id.'&action=edit'; }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[]=is_array($r)?$r[$f]:$r->$f; } return $o; }
function update_meta_cache($t,$i){ return true; }
function cache_users($i){ return true; }
function add_query_arg($args,$url){ return $url . '?' . http_build_query($args); }
function get_posts($a){
  $type=$a['post_type']??''; $out=array();
  if ('bftd_session'===$type) {
    $want=array_map('intval',(array)($a['meta_query'][0]['value']??array()));
    foreach($GLOBALS['POSTS'] as $id=>$p){
      if('bftd_session'!==$p->post_type||'publish'!==$p->post_status) continue;
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
  public static function can_staff_view(...$a){ return true; }
}
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-schedule.php';
require BFTD_PATH.'includes/class-bftd-students.php';

$TUTORS = array(5=>'Rae Tanaka', 6=>'Jo Patel', 7=>'Avery Lin');
foreach($TUTORS as $u=>$n) $GLOBALS['USERS'][$u]=(object)array('ID'=>$u,'display_name'=>$n);

$first = array('Kaine','Alice','Milo','Freya','Otis','Nell','Rufus','Iris','Jonah','Elsie','Hugo','Mabel');
$last  = array('M','B','Okafor','Nguyen','Sandoval','Petrov','Hale','Ward','Achebe','Lindqvist');
$GLOBALS['VISIBLE']=array(); $sid=1000; $sess=5000;
for($i=0;$i<60;$i++){
  $sid++;
  $GLOBALS['POSTS'][$sid]=(object)array('ID'=>$sid,'post_type'=>'bftd_student',
    'post_status'=>($i===7?'draft':'publish'),
    'post_title'=>$first[$i%12].' '.$last[($i*3)%10]);
  $tutor=array_keys($TUTORS)[$i%3];
  $cid=200+($i%18);
  $GLOBALS['USERS'][$cid]=(object)array('ID'=>$cid,'display_name'=>array('Laurel Sanders','Dana Cole','Ravi Patel','Mia Okafor','Tom Ward','Ana Petrov')[$cid%6].'');
  $care=400+($i%9);
  $GLOBALS['USERS'][$care]=(object)array('ID'=>$care,'display_name'=>array('Sam Reid','Jo Vance','Kit Ellis')[$care%3]);
  $GLOBALS['META'][$sid]=array(
    '_bftd_assigned_staff'=>($i===4?array(5,6):array($tutor)),
    '_bftd_client_user_id'=>($i%4===0?array($cid,$care):array($cid)),
    '_bftd_client_primary'=>$cid,
    '_bftd_student__status'=>($i%7===0?'prospect':($i%11===0?'past':'active')),
    '_bftd_student__lessons_bank'=>($i%6===0?0:20),
  );
  if($i===11) $GLOBALS['META'][$sid]['_bftd_assigned_staff']=array();
  // A child nobody is linked to yet, so the empty branch of the caregivers
  // cell is actually drawn rather than reasoned about.
  if($i===13){ $GLOBALS['META'][$sid]['_bftd_client_user_id']=array(); $GLOBALS['META'][$sid]['_bftd_client_primary']=0; }
  $GLOBALS['VISIBLE'][]=$sid;
  $n = 3 + ($i % 16);
  for($k=0;$k<$n;$k++){
    $p=$sess++;
    $GLOBALS['POSTS'][$p]=(object)array('ID'=>$p,'post_type'=>'bftd_session','post_status'=>'publish','post_title'=>'s');
    $GLOBALS['META'][$p]=array('_bftd_student_id'=>$sid,
      '_bftd_session__session_date'=>'2026-0'.(1+($k%9)).'-05',
      '_bftd_session__status'=>($k%5===4?'missed':'held'));
  }
}

ob_start(); BFTD_Students::render(); $html = ob_get_clean();
$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
file_put_contents(__DIR__.'/students.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 -apple-system,system-ui,sans-serif;background:#F0F0F1;margin:0;padding:20px}'
  . '.wrap{max-width:1300px;margin:0 auto}' . $css . '</style>' . $html);
echo __DIR__.'/students.html' . "\n";
