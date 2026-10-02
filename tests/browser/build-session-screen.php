<?php
/*
 * The whole session screen, drawn by the real renderer.
 *
 * The cancellation questions were guarded by a fixture with the right ids
 * written out by hand, which proves the script and says nothing about the
 * screen. What decides whether those questions appear is the class on one
 * div, written by the real box, and the only way to know it is still there
 * is to draw the real box.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function esc_textarea($s){ return esc_html($s); }
function wp_kses_post($v){ return $v; }
function wp_strip_all_tags($v,$b=false){ return trim(strip_tags((string)$v)); }
function absint($n){ return abs((int)$n); }
function selected($a,$b,$e=true){ $r = $a==$b ? ' selected' : ''; if ($e) echo $r; return $r; }
function disabled($a,$b=true,$e=true){ $r = $a==$b ? ' disabled' : ''; if ($e) echo $r; return $r; }
function wp_parse_args($a,$d){ return array_merge($d,(array)$a); }
function wp_nonce_field(...$a){}
function wp_rand($a=0,$b=0){ return 4242; }
function wp_editor($c,$id,$s=array()){ echo '<textarea id="'.$id.'" name="'.($s['textarea_name'] ?? $id).'">'.esc_html($c).'</textarea>'; }
function wp_unslash($v){ return $v; }
function admin_url($p=''){ return '/wp-admin/'.$p; }
function is_admin(){ return true; }
function current_user_can(...$a){ return true; }
function get_current_user_id(){ return 1; }
function get_post_meta($i,$k,$s=false){ $v = $GLOBALS['META'][$i][$k] ?? null; return null===$v ? ($s?'':array()) : ($s?$v:(array)$v); }
function get_the_title($i){ return $GLOBALS['TITLES'][$i] ?? ''; }
function get_post($i=null){ return $GLOBALS['POSTOBJ'][(int)$i] ?? null; }
function get_post_type($i){ return $GLOBALS['TYPE'][$i] ?? ''; }
function get_post_status($i){ return 'publish'; }
function get_posts($a){ $o=array(); foreach(($GLOBALS['TYPE']??array()) as $id=>$t){ if($t===$a['post_type']) $o[]=$id; } return $o; }
function get_userdata($i){ return $GLOBALS['USERS'][(int)$i] ?? false; }
function get_users($a=array()){ return array_values($GLOBALS['USERS']); }
function register_post_type(...$a){} function add_action(...$a){} function add_filter(...$a){}
function add_meta_box(...$a){} function add_submenu_page(...$a){} function get_post_type_object($t){ return null; }
function wp_get_attachment_image_url($i,$s=''){ return ''; }
function wp_get_attachment_image($i,$s='',$c=false,$a=array()){ return ''; }
function get_edit_post_link($i){ return ''; }
function apply_filters($t,$v){ return $v; }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[] = is_array($r)?$r[$f]:$r->$f; } return $o; }
function get_transient($k){ return false; }
function set_transient($k,$v,$t=0){ return true; }

class BFTD_Admin { const MENU_SLUG='bftd'; }
class BFTD_Roles {
  const CLIENT_ROLE='bftd_client';
  public static function can_manage_team($u=null){ return true; }
  public static function can_manage($u=null){ return true; }
  public static function is_staff($u=null){ return true; }
  public static function relationship($u){ return 'Parent or guardian'; }
  public static function role_name($u){ return 'Tutor'; }
  public static function staff_users(){ return array(); }
  public static function post_types(){ return array('bftd_session'); }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Threads {}
class BFTD_Items {}
class BFTD_Audit { public static function log($e,$a=array()){} }
class BFTD_Change_Notify { public static function flag(...$a){} }
class BFTD_Schedule { public static function pretty_time($t){ return $t; } }
class BFTD_Preview { public static function active(){ return false; } public static function url($i){ return ''; } }
class BFTD_Settings { public static function portal(){ return array(); } }
class BFTD_CRM { public static function linked($i){ return ''; } public static function held_back($i){ return array(); } }
class BFTD_Access { public static function report_types(){ return array(); } }
class BFTD_CPT {
  const STUDENT='bftd_student'; const SESSION='bftd_session'; const PROGRESS='bftd_progress';
  const RESOURCE='bftd_resource'; const ASSESSMENT='bftd_assessment';
  const CLIENT_KEY='_bftd_client_user_id'; const STAFF_KEY='_bftd_assigned_staff';
  const PRIMARY_KEY='_bftd_client_primary'; const STUDENT_KEY='_bftd_student_id';
  public static function student_id($i){ return 11; }
  public static function client_ids($s){ return array(20); }
  public static function staff_ids($s){ return array(5); }
  public static function primary_client_id($s){ return 20; }
  public static function people_on($s){ return array(20=>'Laurel Sanders (Client)', 5=>'Rae T (Tutor)'); }
  public static function cancelled_by_label($v,$s=0){ return $GLOBALS['PEOPLE'][$v] ?? ''; }
  public static function lesson_number($i){ return 3; }
  public static function report_for($s,$t){ return 0; }
}
$GLOBALS['PEOPLE'] = array('20'=>'Laurel Sanders (Client)', '5'=>'Rae T (Tutor)');
$GLOBALS['USERS'] = array();

require BFTD_PATH.'includes/class-bftd-library.php';
require BFTD_PATH.'includes/class-bftd-skills.php';
require BFTD_PATH.'includes/class-bftd-activities.php';
require BFTD_PATH.'includes/class-bftd-fields.php';
require BFTD_PATH.'includes/class-bftd-metaboxes.php';

$GLOBALS['TITLES'] = array(); $GLOBALS['TYPE'] = array(); $GLOBALS['META'] = array();

function screen($status) {
  $GLOBALS['META'][40] = array('_bftd_session__status' => $status);
  $GLOBALS['POSTOBJ'][40] = (object) array('ID'=>40,'post_type'=>'bftd_session','post_status'=>'publish','post_title'=>'x');
  ob_start();
  BFTD_MetaBoxes::box_session($GLOBALS['POSTOBJ'][40]);
  return ob_get_clean();
}

$held = screen('held');
$moved = screen('rescheduled');

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js  = file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');
/*
 * The rescheduled screen on its own page, with no script on it at all. What
 * a tutor sees before anything has run is a question about PHP, and it
 * cannot be asked on a page that has the script sitting in it.
 */
file_put_contents(__DIR__.'/session-screen-moved.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#fff;margin:0;padding:20px}'
  . $css . '</style><div class="postbox" id="screen">' . $moved . '</div>');

file_put_contents(__DIR__.'/session-screen.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#fff;margin:0;padding:20px}'
  . $css . '</style>'
  . '<form id="post" method="post" action="#"><div class="postbox" id="screen">' . $held . '</div>'
  . '<div id="publishing-action"><span class="spinner"></span>'
  . '<input type="submit" id="publish" value="Publish"><input type="submit" id="save-post" value="Save Draft"></div>'
  . '</form>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"",nonce:"",post_id:40,autosave:0,tracks:'.json_encode(BFTD_Activities::tracks()).'};'
  . 'window.__submits=0;'
  /* The same screen as PHP draws it for a session already marked
     rescheduled, so a test can check what a tutor opening one sees before
     any script has run. */
  . 'window.__movedHtml=' . json_encode($moved) . ';</script>'
  . '<script>'.$js.'</script>'
  /*
   * Counts the submissions that were ALLOWED. The guard calls preventDefault
   * rather than stopping propagation, because other things on a WordPress
   * edit screen legitimately listen for this event. Delegated on document and
   * bound after the plugin, so it sees the event as the browser finally has
   * it: a counter bound directly to the form would run BEFORE the delegated
   * guard and record every press as a submission. An allowed submit also puts
   * the button back, standing in for the page navigating away.
   */
  . '<script>jQuery(document).on("submit", "#post", function(e){'
  . ' if (!e.isDefaultPrevented()) { window.__submits++;'
  . '   jQuery("#publish").removeClass("disabled").prop("disabled", false);'
  . '   jQuery("#publishing-action .spinner").removeClass("is-active"); }'
  . ' e.preventDefault(); });</script>'
  /*
   * What WordPress itself does on submit, in post.js: the button is disabled
   * and the spinner starts, so a second press cannot double-post. Bound
   * directly to the form, which is why it runs BEFORE a delegated guard, and
   * why a guard that stops the submit without undoing this leaves a greyed
   * out Publish button that never comes back.
   */
  . '<script>jQuery("#post").on("submit", function(){'
  . ' jQuery("#publish").addClass("disabled").prop("disabled", true);'
  . ' jQuery("#publishing-action .spinner").addClass("is-active"); });</script>');
echo __DIR__ . '/session-screen.html' . "\n";
