<?php
/*
 * One activity row off a lesson, standing on its own so a browser can be
 * driven at it. Everything that decides anything is the real code.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return $s; } function esc_textarea($s){ return esc_html($s); }
// WordPress ECHOES unless told not to, and the picker relies on that: a stub
// that only returned left every option unmarked, so the page said nothing was
// chosen on a row that had been.
function selected($a,$b,$e=true){ $r = $a==$b ? ' selected' : ''; if ($e) echo $r; return $r; }
function wp_parse_args($a,$d){ return array_merge($d,(array)$a); }
function wp_nonce_field(...$a){} function wp_kses_post($v){ return $v; }
function wp_strip_all_tags($v,$b=false){ return trim(strip_tags((string)$v)); }
function absint($n){ return abs((int)$n); }
function wp_editor($c,$id,$s=array()){ echo '<textarea id="'.$id.'"></textarea>'; }
function wp_unslash($v){ return $v; }
function admin_url($p=''){ return '/wp-admin/'.$p; }
function get_post_meta($i,$k,$s=false){ return $GLOBALS['META'][$i][$k] ?? ($s?'':array()); }
function get_the_title($i){ return $GLOBALS['TITLES'][$i] ?? ''; }
function get_posts($a){ $o=array(); foreach(($GLOBALS['TYPE']??array()) as $id=>$t){ if($t===$a['post_type']) $o[]=$id; } return $o; }
function get_post($i=null){ return $GLOBALS['POSTOBJ'][(int)$i] ?? null; } function get_post_type($i){ return $GLOBALS['TYPE'][$i] ?? ''; }
function register_post_type(...$a){} function add_action(...$a){} function add_filter(...$a){}
function add_meta_box(...$a){} function add_submenu_page(...$a){} function get_post_type_object($t){ return null; }
function is_admin(){ return true; } function current_user_can(...$a){ return true; }
function wp_get_attachment_image_url($i,$s=''){ return ''; }
class BFTD_Admin { const MENU_SLUG='bftd'; }
class BFTD_Roles { public static function can_manage_team($u=null){ return true; } }
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Threads {}
require BFTD_PATH.'includes/class-bftd-library.php';
require BFTD_PATH.'includes/class-bftd-skills.php';
require BFTD_PATH.'includes/class-bftd-activities.php';
require BFTD_PATH.'includes/class-bftd-fields.php';

$GLOBALS['TITLES'] = array(201=>'Sound Lines', 202=>'Blending two sounds');
$GLOBALS['TYPE']   = array(201=>'bftd_activity', 202=>'bftd_activity');
$GLOBALS['META']   = array(
  201=>array('_bftd_activity_number'=>1,'_bftd_activity_track'=>'t1'),
  202=>array('_bftd_activity_number'=>12,'_bftd_activity_track'=>'t1'),
);

$cols = array(
  'id'      => array('type'=>'activity','label'=>'Activity'),
  'note'    => array('type'=>'rich','label'=>'Notes'),
  'samples' => array('type'=>'gallery','label'=>'Work samples'),
);
$rr = new ReflectionMethod('BFTD_Fields','render_rows'); $rr->setAccessible(true);
ob_start();
$rr->invoke(null, 'session', 'activities', array(
  'type'=>'rows','label'=>'Activities','layout'=>'stack','add_label'=>'Add activity','columns'=>$cols,
), array());
$empty = ob_get_clean();

ob_start();
$rr->invoke(null, 'session', 'activities', array(
  'type'=>'rows','label'=>'Activities','layout'=>'stack','add_label'=>'Add activity','columns'=>$cols,
), array(array('id'=>'202','note'=>'<p>went well</p>','samples'=>array())));
$filled = ob_get_clean();

/* A lesson holding one live activity and one whose activity went in the
   trash. The trashed one is a real post, so it is kept; it is not in the
   library, so it is not drawn. */
$GLOBALS['POSTOBJ'] = array(
  299 => (object) array('ID'=>299,'post_type'=>'bftd_activity','post_status'=>'trash','post_title'=>'Retired'),
);
BFTD_Activities::flush();
ob_start();
$rr->invoke(null, 'session', 'activities', array(
  'type'=>'rows','label'=>'Activities','layout'=>'stack','add_label'=>'Add activity','columns'=>$cols,
), array(
  array('id'=>'299','note'=>'<p>he read it twice</p>','samples'=>array(7,8)),
  array('id'=>'201','note'=>'','samples'=>array()),
));
$trashed = ob_get_clean();

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js  = file_get_contents(BFTD_PATH.'assets/js/bftd-match.js') . "\n" . file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');
file_put_contents(__DIR__.'/lesson-row.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#fff;margin:0;padding:20px}'
  . $css . '</style>'
  . '<h3>Nothing chosen yet</h3><div id="empty">' . $empty . '</div>'
  . '<h3 style="margin-top:30px">One chosen</h3><div id="filled">' . $filled . '</div>'
  . '<h3 style="margin-top:30px" id="th">One trashed, one live</h3><div id="trashed">' . $trashed . '</div>'
  . '<script>window.wp={editor:{initialize:function(id,s){(window.__ed=window.__ed||[]).push({id:id,s:s});},remove:function(){}}};</script>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"",nonce:"",post_id:1,autosave:0,tracks:'.json_encode(BFTD_Activities::tracks()).'};</script>'
  . '<script>'.$js.'</script>');
echo __DIR__ . '/lesson-row.html' . "\n";
