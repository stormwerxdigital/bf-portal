<?php
/*
 * The time card screen, rendered.
 *
 * Every rule about what may be paid is tested against the ledger. None of
 * that reaches a person: what a person meets is this screen, and the two
 * things it has to get right are the two that a source-level test cannot
 * see.
 *
 * An approver has to be able to find what still needs them, in a fortnight
 * that is mostly routine. A tutor has to see their own card and nothing
 * else, and "nothing else" is a claim about what is on the page, which is
 * the only place it can be checked.
 */
require dirname(__DIR__) . '/wp-stubs.php';
define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['UMETA'] = array();
$GLOBALS['OPTS'] = array(); $GLOBALS['NEXT'] = 2000;

function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id=null){ return $GLOBALS['POSTS'][(int)$id] ?? null; }
function get_post_type($id){ $p=get_post($id); return $p?$p->post_type:''; }
function get_the_title($id){ $p=get_post($id); return $p?$p->post_title:''; }
function get_post_meta($id,$k,$s=false){ return $GLOBALS['META'][$id][$k] ?? ($s?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function delete_post_meta($id,$k){ unset($GLOBALS['META'][$id][$k]); return true; }
function get_user_meta($id,$k,$s=false){ return $GLOBALS['UMETA'][$id][$k] ?? ($s?'':array()); }
function update_user_meta($id,$k,$v){ $GLOBALS['UMETA'][$id][$k]=$v; return true; }
function get_option($k,$d=''){ return $GLOBALS['OPTS'][$k] ?? $d; }
function update_option($k,$v){ $GLOBALS['OPTS'][$k]=$v; return true; }
function get_userdata($id){ return $GLOBALS['USERS'][(int)$id] ?? null; }
function get_users($a=array()){ $o=array(); foreach($GLOBALS['USERS'] as $u) $o[]=(object)array('ID'=>$u->ID); return $o; }
function user_can($id,$cap){ return in_array($cap, $GLOBALS['CAPS'][(int)$id] ?? array(), true); }
function current_user_can($cap,$id=0){ return true; }
function get_current_user_id(){ return (int) $GLOBALS['ME']; }
function get_edit_post_link($id){ return '/wp-admin/post.php?post=' . (int)$id . '&action=edit'; }
function admin_url($p=''){ return '/wp-admin/' . $p; }
function add_query_arg($args,$url=''){ $u = $url ?: '/wp-admin/admin.php'; return $u . '?' . http_build_query($args); }
function wp_nonce_field(...$a){ echo '<input type="hidden" name="_wpnonce" value="n">'; }
function selected($a,$b,$e=true){ $r = ((string)$a===(string)$b)?' selected':''; if($e) echo $r; return $r; }
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function update_meta_cache($t,$ids){ return true; }
function absint($n){ return abs((int)$n); }
function delete_user_meta($id,$k){ unset($GLOBALS['UMETA'][$id][$k]); return true; }
function wp_unslash($v){ return $v; }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function sanitize_key($v){ return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v)); }
function ucfirst_stub(){}
function register_post_type(...$a) {}
function wp_is_post_revision($id){ return false; }
function is_admin(){ return true; }
function add_submenu_page(...$a) {}
function wp_insert_post($a,$e=false){
  $id = $GLOBALS['NEXT']++;
  $GLOBALS['POSTS'][$id] = (object) array_merge(array('ID'=>$id,'post_type'=>'','post_status'=>'publish','post_title'=>'','post_date'=>'2026-09-01 09:00:00'), $a);
  $GLOBALS['POSTS'][$id]->ID = $id;
  return $id;
}
function wp_update_post($a){ $id=(int)$a['ID']; foreach($a as $k=>$v){ if('ID'!==$k) $GLOBALS['POSTS'][$id]->$k=$v; } return $id; }
function get_posts($a){
  $out=array();
  foreach($GLOBALS['POSTS'] as $id=>$p){
    if($p->post_type !== $a['post_type']) continue;
    if(isset($a['post_status']) && $p->post_status !== $a['post_status']) continue;
    if(isset($a['meta_key']) && isset($a['meta_value'])){
      if((string)($GLOBALS['META'][$id][$a['meta_key']] ?? '') !== (string)$a['meta_value']) continue;
    }
    if(!empty($a['meta_query'])){
      $ok=true;
      foreach($a['meta_query'] as $q){
        if(!is_array($q) || !isset($q['key'])) continue;
        $have=(string)($GLOBALS['META'][$id][$q['key']] ?? '');
        if(($q['compare'] ?? '=')==='BETWEEN'){ if($have<$q['value'][0]||$have>$q['value'][1]){$ok=false;break;} }
        elseif($have !== (string)$q['value']){$ok=false;break;}
      }
      if(!$ok) continue;
    }
    $out[]=$id;
  }
  if(isset($a['posts_per_page']) && $a['posts_per_page']>0) $out=array_slice($out,0,(int)$a['posts_per_page']);
  return $out;
}

class BFTD_Audit { public static function log(...$a) {} }
class BFTD_Admin { const MENU_SLUG='bftd'; }
class BFTD_Roles {
  const ADMIN_CAP='bftd_administer'; const STAFF_CAP='bftd_staff';
  public static function is_staff($id=null){ $id=$id?:get_current_user_id(); return user_can($id,self::STAFF_CAP)||user_can($id,'manage_options'); }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.str_replace('-','_',$g).'__'.$k; } }
class BFTD_CPT {
  const SESSION='bftd_session'; const ASSESSMENT='bftd_assessment'; const STUDENT_KEY='_bftd_student_id';
  public static function student_id($id){ return (int)($GLOBALS['META'][$id][self::STUDENT_KEY] ?? 0); }
}
class BFTD_Fields {
  public static function get($id,$g,$k,$f=array()){ return $GLOBALS['META'][$id][BFTD_Schema::meta_key($g,$k)] ?? ''; }
  public static function set($id,$g,$k,$v){ $GLOBALS['META'][$id][BFTD_Schema::meta_key($g,$k)]=$v; }
}
class BFTD_Schedule { public static function pretty_time($t){
  if('' === (string)$t) return '';
  list($h,$m)=array_pad(explode(':',$t),2,'00'); $h=(int)$h;
  $ap = $h<12 ? 'am':'pm'; $x = $h%12; if(0===$x) $x=12;
  return $x . ':' . $m . ' ' . $ap;
} }
require BFTD_PATH.'includes/class-bftd-pay-period.php';
require BFTD_PATH.'includes/class-bftd-stat-holidays.php';
require BFTD_PATH.'includes/class-bftd-pay.php';
require BFTD_PATH.'includes/class-bftd-stat-pay.php';
/* The real one, so who gets a section on this screen is decided by the code
   that decides it and not by a second copy of the rule living in a test. */
require BFTD_PATH.'includes/class-bftd-pay-profile.php';
require BFTD_PATH.'includes/class-bftd-timecards.php';

/* ---- a fortnight of work ---- */
$GLOBALS['USERS'] = array(
  5 => (object) array('ID'=>5,'display_name'=>'Laurel Sanders'),
  6 => (object) array('ID'=>6,'display_name'=>'Nina Okafor'),
  7 => (object) array('ID'=>7,'display_name'=>'Sam Idowu'),
  9 => (object) array('ID'=>9,'display_name'=>'Karl Sanders'),
);
$GLOBALS['CAPS'] = array(
  5 => array(BFTD_Roles::STAFF_CAP),
  6 => array(BFTD_Roles::STAFF_CAP),
  7 => array(BFTD_Roles::STAFF_CAP),
  9 => array(BFTD_Roles::STAFF_CAP, BFTD_Roles::ADMIN_CAP),
);
update_option(BFTD_Pay::OPT_SESSION, 4000);
update_option(BFTD_Pay::OPT_DIAGNOSIS, 15000);
update_user_meta(5, BFTD_Pay::META_SESSION, '4500');
/* Nina is on the payroll on the practice's default rates, and is a
   contractor, so both employment pills appear somewhere on the screen.
   Sam has never been given terms at all, and has a session anyway: the
   case where money would otherwise be on the ledger and on no screen. */
update_user_meta(6, BFTD_Pay::META_KIND, BFTD_Pay::CONTRACTOR);
update_user_meta(5, BFTD_Pay::META_KIND, BFTD_Pay::EMPLOYEE);
update_user_meta(5, BFTD_Pay::META_STARTED, '2020-01-06');
update_user_meta(6, BFTD_Pay::META_STARTED, '2020-01-06');

$kids = array(700=>'Jot Singh', 701=>'Kaine M', 702=>'Bea Fournier');
foreach ($kids as $id=>$name) $GLOBALS['POSTS'][$id] = (object) array('ID'=>$id,'post_type'=>'bftd_student','post_status'=>'publish','post_title'=>$name);

function session_on($tutor, $student, $date, $time, $status='held') {
  $id = wp_insert_post(array('post_type'=>BFTD_CPT::SESSION,'post_status'=>'publish','post_title'=>'A session'));
  $GLOBALS['META'][$id][BFTD_CPT::STUDENT_KEY] = $student;
  BFTD_Fields::set($id,'session','session_date',$date);
  BFTD_Fields::set($id,'session','session_time',$time);
  BFTD_Fields::set($id,'session','status',$status);
  BFTD_Fields::set($id,'session','delivered_by',(string)$tutor);
  return BFTD_Pay::record(get_post($id));
}
function diagnostic_on($tutor, $student, $date) {
  $id = wp_insert_post(array('post_type'=>BFTD_CPT::ASSESSMENT,'post_status'=>'publish','post_title'=>'Initial assessment'));
  $GLOBALS['META'][$id][BFTD_CPT::STUDENT_KEY] = $student;
  BFTD_Fields::set($id,BFTD_Pay::DX_SECTION,'assessed_by',(string)$tutor);
  BFTD_Fields::set($id,BFTD_Pay::DX_SECTION,'assessed_on',$date);
  return BFTD_Pay::record(get_post($id));
}

$GLOBALS['ME'] = 9;

$a = session_on(5,700,'2026-09-01','16:00');
$b = session_on(5,701,'2026-09-03','09:00');
$c = session_on(5,700,'2026-09-08','16:00','rescheduled');
$d = session_on(5,702,'2026-09-10','17:30','missed');
$e = diagnostic_on(5,702,'2026-09-04');
$f = session_on(6,701,'2026-09-02','15:00');
$g = session_on(6,702,'2026-09-09','11:00');
$h = session_on(7,700,'2026-09-11','13:00');

/* Two already decided, so the screen shows every state at once. */
BFTD_Pay::decide($a, BFTD_Pay::APPROVED, 9);
BFTD_Pay::decide($e, BFTD_Pay::APPROVED, 9);
BFTD_Pay::decide($g, BFTD_Pay::DECLINED, 9);

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$head = '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 -apple-system,system-ui,sans-serif;background:#F0F0F1;margin:0;padding:20px}'
  . '.wrap{max-width:1300px;margin:0 auto}'
  . '.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);clip-path:inset(50%)}'
  . $css . '</style>';

/* The approver's view. */
$_GET = array('period' => '2026-09-A');
ob_start(); BFTD_Timecards::render(); $boss = ob_get_clean();
file_put_contents(__DIR__.'/timecards.html', $head . $boss);

/* And the tutor's own, which is the same screen asked by somebody else. */
$GLOBALS['ME'] = 5;
$_GET = array('period' => '2026-09-A');
ob_start(); BFTD_Timecards::render(); $mine = ob_get_clean();
file_put_contents(__DIR__.'/timecard-mine.html', $head . $mine);

/* ---- a period with a statutory holiday in it ----
 *
 * Christmas 2026. Laurel has taught enough of the thirty days before it to
 * qualify; Nina is a contractor and never will; Sam has never been given
 * terms. Three different answers on one screen, which is the point. */
for ($d = 25; $d <= 30; $d++) session_on(5, 700, sprintf('2026-11-%02d', $d), '16:00');
for ($d = 1;  $d <= 18; $d++) session_on(5, 701, sprintf('2026-12-%02d', $d), '09:00');
foreach (BFTD_Pay::entries_between('2026-11-25','2026-12-18',5) as $eid) BFTD_Pay::decide($eid, BFTD_Pay::APPROVED, 9);
session_on(6, 702, '2026-12-03', '15:00');
session_on(7, 700, '2026-12-04', '13:00');
/* And Laurel teaches on the day itself, so the premium shows too. */
session_on(5, 700, '2026-12-25', '10:00');

$GLOBALS['ME'] = 9;
$_GET = array('period' => '2026-12-B');
ob_start(); BFTD_Timecards::render(); $stat = ob_get_clean();
file_put_contents(__DIR__.'/timecard-stat.html', $head . $stat);

/* And the worked example, which is the same screen with a fortnight that
   did not happen on it. */
$GLOBALS['ME'] = 9;
$_GET = array('period' => '2026-09-A', 'sample' => '1');
ob_start(); BFTD_Timecards::render(); $sample = ob_get_clean();
file_put_contents(__DIR__.'/timecard-sample.html', $head . $sample);

echo __DIR__.'/timecards.html' . "\n";
