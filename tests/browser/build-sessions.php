<?php
/*
 * The sessions screen, drawn by the real renderer, with the drawers served
 * from the real one too — a stubbed fetch standing in for admin-ajax, so the
 * script under test is the real script and only the transport is fake.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
require BFTD_PATH . 'tests/wp-stubs.php';

$GLOBALS['POSTS']=array(); $GLOBALS['META']=array(); $GLOBALS['USERS']=array();
function add_action(...$a){} function add_filter(...$a){}
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
function add_query_arg($a,$u){ return $u.'?'.http_build_query($a); }
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
    if(!empty($a['meta_query'])){
      $q=$a['meta_query'][0];
      $have=(array)($GLOBALS['META'][$id][$q['key']]??array());
      if(!in_array((string)$q['value'],array_map('strval',$have),true)) continue;
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
  public static function can_staff_view($id,$u=0){ return true; }
}
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-schedule.php';
require BFTD_PATH.'includes/class-bftd-students.php';
require BFTD_PATH.'includes/class-bftd-sessions.php';

$GLOBALS['USERS'][5]=(object)array('ID'=>5,'display_name'=>'Rae Tanaka');
$GLOBALS['USERS'][6]=(object)array('ID'=>6,'display_name'=>'Jo Patel');
$GLOBALS['USERS'][20]=(object)array('ID'=>20,'display_name'=>'Laurel Sanders','user_email'=>'l@example.test');

$GLOBALS['VISIBLE']=array(); $sess=5000;
$names=array('Kaine M','Alice B','Milo Okafor','Freya Nguyen','Otis Hale','Nell Ward','Iris Petrov','Hugo Lindqvist');
for($i=0;$i<8;$i++){
  $sid=1001+$i;
  $GLOBALS['POSTS'][$sid]=(object)array('ID'=>$sid,'post_type'=>'bftd_student','post_status'=>'publish','post_title'=>$names[$i]);
  $GLOBALS['META'][$sid]=array('_bftd_assigned_staff'=>($i===2?array(5,6):array($i%2?6:5)),
    '_bftd_client_user_id'=>array(20),'_bftd_client_primary'=>20,
    '_bftd_student__status'=>'active','_bftd_student__lessons_bank'=>60);
  if($i===7) $GLOBALS['META'][$sid]['_bftd_assigned_staff']=array();
  $GLOBALS['VISIBLE'][]=$sid;

  $r=9000+$i;
  $GLOBALS['POSTS'][$r]=(object)array('ID'=>$r,'post_type'=>'bftd_progress','post_status'=>'publish','post_title'=>'PR');
  $GLOBALS['META'][$r]=array('_bftd_student_id'=>$sid);

  $n = ($i===0) ? 47 : (3 + $i * 2);
  for($k=0;$k<$n;$k++){
    $p=$sess++;
    $draft = ($i===0 && $k >= $n-2);
    $GLOBALS['POSTS'][$p]=(object)array('ID'=>$p,'post_type'=>'bftd_session',
      'post_status'=>$draft?'draft':'publish','post_title'=>'s');
    $GLOBALS['META'][$p]=array('_bftd_student_id'=>$sid,
      '_bftd_session__session_date'=>sprintf('2026-%02d-%02d',1+intdiv($k,4),1+($k%28)),
      '_bftd_session__session_time'=>'09:00',
      '_bftd_session__status'=>($k%9===8?'missed':($k%7===6?'rescheduled':'held')));
  }
}

ob_start(); BFTD_Sessions::render(); $html=ob_get_clean();

/* Each student's drawers, every page, rendered by the real renderer and
   handed to the page so the script can be driven without a server. */
$drawers=array();
foreach($GLOBALS['VISIBLE'] as $sid){
  for($pg=1;$pg<=3;$pg++){
    ob_start(); BFTD_Sessions::drawer($sid,$pg); $drawers[$sid][$pg]=ob_get_clean();
  }
}

$css=file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js =file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');
file_put_contents(__DIR__.'/sessions.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 -apple-system,system-ui,sans-serif;background:#F0F0F1;margin:0;padding:20px}'
  . '.wrap{max-width:1300px;margin:0 auto}' . $css . '</style>' . $html
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"/fake",nonce:"n",post_id:0,autosave:0,tracks:[]};'
  . 'window.__drawers=' . json_encode($drawers) . ';window.__posts=0;'
  /* admin-ajax, minus the network. Every drawer this can return was rendered
     by the real renderer above, so the only thing standing in is the wire. */
  . 'jQuery.post=function(url,data){ window.__posts++;'
  . ' var html=(window.__drawers[data.student]||{})[data.page]||"";'
  . ' var d=jQuery.Deferred(); setTimeout(function(){ d.resolve({success:true,data:{html:html}}); },10);'
  . ' return d.promise(); };</script>'
  . '<script>'.$js.'</script>');
echo __DIR__.'/sessions.html' . "\n";
