<?php
/*
 * The activity screen, standing on its own, so a browser can be driven at it.
 *
 * Everything that decides anything is the real code: the picker, the fields,
 * the skills library. Only what WordPress would have printed around it is
 * stood in for.
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
  100 => 'sounds can be represented by 1-4 letters',
  101 => 'Hearing the middle sound in a word',
  102 => 'Reading a passage smoothly',
);
foreach (array_keys($GLOBALS['TITLES']) as $id) $GLOBALS['TYPE'][$id] = 'bftd_skill';
$GLOBALS['TITLES'][88] = 'Activity 1'; $GLOBALS['TYPE'][88] = 'bftd_activity';
$GLOBALS['META'][88] = array('_bftd_activity_number'=>1,'_bftd_activity_track'=>'t1');

$post = (object) array('ID'=>88,'post_type'=>'bftd_activity');
$css  = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js   = file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');

file_put_contents(__DIR__.'/skill-page.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#f0f0f1;margin:0;padding:24px}'
  . '.wrap{max-width:760px}.postbox{background:#fff;border:1px solid #c3c4c7;border-radius:4px}'
  . '.postbox-header{padding:8px 12px;border-bottom:1px solid #c3c4c7}.hndle{margin:0;font-size:14px}'
  . '.inside{padding:12px}textarea{width:100%;height:150px;font:13px/1.6 monospace}'
  . $css . '</style><div class="wrap">'
  . '<h1 contenteditable style="background:#fff;border:1px solid #c3c4c7;padding:6px">Activity 1</h1>'
  . BFTD_Activities::box_skills_html($post)
  . '<p><b>The description (id=content)</b></p><textarea id="content">'
  . '<p>This is the description for activity 1</p></textarea>'
  . '</div>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"/ajax",nonce:"n",post_id:88,autosave:0,tracks:'
  . json_encode(BFTD_Activities::tracks()) . '};</script>'
  . '<script>' . $js . '</script>');

/*
 * The same screen with the visual editor in front.
 *
 * The page above has a bare textarea, which is the text tab. Every tutor
 * works in the visual tab, and that is a different branch of the script
 * entirely — the one that was replacing the whole document. A page with no
 * TinyMCE on it can never drive it, so here is a stand-in: a contenteditable
 * box behind the small part of the TinyMCE surface the script actually uses.
 *
 * It is not TinyMCE and does not pretend to be. What it can answer is the
 * question that matters: was the description added to, or was it rebuilt.
 */
$fake = '<div id="fake-mce" contenteditable'
  . ' style="background:#fff;border:1px solid #c3c4c7;padding:12px;min-height:120px">'
  . '<h2>A Quick Review</h2>'
  . '<p>This is the description for activity 1</p>'
  . '<ul><li>first</li><li>second</li></ul>'
  . '</div>';

$shim = '<script>(function(){'
  . 'var body=document.getElementById("fake-mce");'
  . 'window.__setContent=0;'
  . 'var ed={'
  . 'isHidden:function(){return false;},'
  . 'getBody:function(){return body;},'
  . 'getContent:function(){return body.innerHTML;},'
  . 'setContent:function(h){window.__setContent++;body.innerHTML=h;},'
  . 'setDirty:function(){},'
  . 'fire:function(){},'
  . 'undoManager:{add:function(){}},'
  . 'on:function(){},off:function(){},id:"content",'
  . 'getElement:function(){return body;},save:function(){}'
  . '};'
  . 'window.tinymce={get:function(id){return "content"===id?ed:null;},'
  . 'editors:[ed],on:function(){},off:function(){},triggerSave:function(){}};'
  . '}());</script>';

file_put_contents(__DIR__.'/skill-page-mce.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#f0f0f1;margin:0;padding:24px}'
  . '.wrap{max-width:760px}.postbox{background:#fff;border:1px solid #c3c4c7;border-radius:4px}'
  . '.postbox-header{padding:8px 12px;border-bottom:1px solid #c3c4c7}.hndle{margin:0;font-size:14px}'
  . '.inside{padding:12px}textarea{width:100%;height:150px;font:13px/1.6 monospace}'
  . $css . '</style><div class="wrap">'
  . BFTD_Activities::box_skills_html($post)
  . '<p><b>The description (visual)</b></p>' . $fake
  . '</div>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"/ajax",nonce:"n",post_id:88,autosave:0,tracks:'
  . json_encode(BFTD_Activities::tracks()) . '};</script>'
  . $shim
  . '<script>' . $js . '</script>');

echo __DIR__ . '/skill-page.html' . "\n";
