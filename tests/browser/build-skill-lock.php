<?php
/*
 * An activity's track and its skills box, for the track lock to be driven.
 *
 * A Track 1 activity, two skills in each track, and one Tracks 2 and 3 skill
 * already attached from before skills had tracks. The picker, fields and
 * libraries are the real code.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return $s; }
function esc_textarea($s){ return esc_html($s); }
// WordPress ECHOES unless told not to, and the picker relies on that: a stub
// that only returned left every option unmarked, so the page said nothing was
// chosen on a row that had been.
function selected($a,$b,$e=true){ $r = $a==$b ? ' selected' : ''; if ($e) echo $r; return $r; }
function wp_parse_args($a,$d){ return array_merge($d,(array)$a); }
function wp_nonce_field(...$a){ echo ''; }
function wp_kses_post($v){ return $v; }
function admin_url($p=''){ return '/wp-admin/'.$p; }
function get_post_meta($i,$k,$s=false){ return $GLOBALS['META'][$i][$k] ?? ($s?'':array()); }
function get_the_title($i){ return $GLOBALS['TITLES'][$i] ?? ''; }
function get_posts($a){ $o=array(); foreach($GLOBALS['TITLES'] as $id=>$t){ if(($GLOBALS['TYPE'][$id]??'')===$a['post_type']) $o[]=$id; } return $o; }
function get_post($i=null){ return null; }
function get_post_type($i){ return $GLOBALS['TYPE'][$i] ?? ''; }
function register_post_type(...$a){} function add_action(...$a){} function add_filter(...$a){}
function add_meta_box(...$a){} function add_submenu_page(...$a){} function get_post_type_object($t){ return null; }
function is_admin(){ return true; } function current_user_can(...$a){ return true; }
class BFTD_Admin { const MENU_SLUG='bftd'; }
class BFTD_Roles { public static function can_manage_team($u=null){ return true; } }
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Threads {}
require BFTD_PATH.'includes/class-bftd-library.php';
require BFTD_PATH.'includes/class-bftd-skills.php';
require BFTD_PATH.'includes/class-bftd-activities.php';
require BFTD_PATH.'includes/class-bftd-fields.php';

$GLOBALS['TITLES'] = array(
  100 => 'Hearing the middle sound',
  101 => 'Mapping sounds to letters',
  102 => 'Splitting a syllable',
  103 => 'Spelling a plural',
);
foreach (array_keys($GLOBALS['TITLES']) as $id) $GLOBALS['TYPE'][$id] = 'bftd_skill';
$GLOBALS['META'][100] = array('_bftd_skill_track'=>'t1');
$GLOBALS['META'][101] = array('_bftd_skill_track'=>'t1');
$GLOBALS['META'][102] = array('_bftd_skill_track'=>'t23');
// 103 has no track stored, so it is read as the default, Tracks 2 and 3.
$GLOBALS['TITLES'][88] = 'Activity 1'; $GLOBALS['TYPE'][88] = 'bftd_activity';
$GLOBALS['META'][88] = array('_bftd_activity_number'=>1,'_bftd_activity_track'=>'t1','_bftd_activity_skills'=>array(103));

$post = (object) array('ID'=>88,'post_type'=>'bftd_activity');
$css  = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js   = file_get_contents(BFTD_PATH.'assets/js/bftd-match.js') . "\n" . file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');

ob_start(); BFTD_Activities::box_number($post); $place = ob_get_clean();

file_put_contents(__DIR__.'/skill-lock.html',
  '<!doctype html><meta charset="utf-8">'
  . '<style>body{font:14px/1.5 system-ui;background:#f0f0f1;margin:0;padding:24px}.wrap{max-width:760px}' . $css . '</style>'
  . '<form id="post"><div class="wrap"><div id="place">' . $place . '</div>'
  . BFTD_Activities::box_skills_html($post)
  . '<textarea id="content" name="content"></textarea></div></form>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"/ajax",nonce:"n",post_id:88,autosave:1,tracks:'
  . json_encode(BFTD_Activities::tracks()) . '};</script>'
  . '<script>' . $js . '</script>');

echo __DIR__ . '/skill-lock.html' . "\n";
