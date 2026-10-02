<?php
/* Render the sample progress report exactly as BFTD_Report_View draws it, with
 * only the collaborators it reaches out to stubbed. The point is to see the
 * page: charts that draw, a diagnostic pulled in, nothing empty, nothing
 * overlapping. */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
define('DAY_IN_SECONDS',86400);
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function esc_textarea($s){ return esc_html($s); }
function wp_kses_post($s){ return $s; }
function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); }
function wp_list_pluck($a,$f){ $o=array(); foreach((array)$a as $r){ $o[] = is_array($r) ? $r[$f] : $r->$f; } return $o; }
function wpautop($s){ return $s; }
$GLOBALS['F'] = array();
function add_filter($tag,$fn,$p=10,$n=1){ $GLOBALS['F'][$tag][] = $fn; }
function apply_filters($tag,$v){
  $args = array_slice(func_get_args(), 1);
  foreach ($GLOBALS['F'][$tag] ?? array() as $fn) { $args[0] = call_user_func_array($fn, $args); }
  return $args[0];
}
function add_action(){} function remove_all_filters($t=''){ unset($GLOBALS['F'][$t]); }
function wp_date($f,$ts,$z=null){ $d=new DateTime('@'.$ts); $d->setTimezone($z ?: new DateTimeZone('UTC')); return $d->format($f); }
function get_the_title($id){ return 'Lesson '.$id; }
function get_post($id=null){ return null; }
function get_posts($a){ return array(); }
function get_userdata($id){ return null; }
function get_option($k,$d=''){ return $d; }
function home_url($p=''){ return 'https://bftutoring.com'.$p; }
function get_permalink($id){ return 'https://bftutoring.com/portal/'; }
function add_query_arg($args,$url){ return $url.'?'.http_build_query($args); }
function absint($n){ return abs((int)$n); }
function number_format_i18n($n){ return number_format($n); }
function selected($a,$b,$e=true){ return $a==$b?' selected':''; }
function disabled($a,$b=true,$e=true){ return $a==$b?' disabled':''; }
function checked($a,$b=true,$e=true){ return $a==$b?' checked':''; }
function is_user_logged_in(){ return true; }
function get_current_user_id(){ return 1; }
function wp_get_attachment_image($id,$s='full',$i=false,$a=array()){ return ''; }
function wp_get_attachment_url($id){ return ''; }
function admin_url($p=''){ return '/wp-admin/'.$p; }
function sanitize_key($k){ return preg_replace('/[^a-z0-9_\-]/','',strtolower($k)); }

require BFTD_PATH.'includes/class-bftd-time.php';
require BFTD_PATH.'includes/class-bftd-schema.php';

class BFTD_Items {
  const PRIORITY='priority'; const REVIEW='review';
  private static $fx = array();
  public static function use_fixture($d){ self::$fx = (array)$d; }
  public static function clear_fixture(){ self::$fx = array(); }
  public static function open_items($sid,$scope,$x=''){ return self::$fx[$scope] ?? array(); }
  public static function config($l){
    $all = array(
      'priority' => array('title'=>'Priority items','eyebrow'=>'Before your next lesson','empty'=>'Nothing needs your attention right now.','done'=>'All done','verb'=>'Acknowledged by','tone'=>'clay'),
      'review'   => array('title'=>'For review','eyebrow'=>'Your to-do list','empty'=>'Nothing waiting on you.','done'=>'All done','verb'=>'Done by','tone'=>'sage'),
    );
    return $all[$l] ?? null;
  }
  public static function render_card(){}
}
class BFTD_Threads { public static function find_thread(){ return null; } public static function messages(){ return array(); } }
class BFTD_Settings {
  public static function help_bar_link(){ return 'https://bftutoring.com/book'; }
  public static function portal(){ return array('recording_days'=>14,'contact_line'=>''); }
  public static function standing_text($key,$fallback=''){ return $fallback; }
}
class BFTD_Roles { public static function is_staff($u=null){ return false; } }
class BFTD_Schedule {
  public static function taught_count($sid){ return 16; }
  public static function bank($sid){ return array('paid'=>20,'used'=>16,'left'=>4); }
  public static function pretty_time($t){ return $t; }
}
class BFTD_Preview { public static function active(){ return true; } public static function url($id){ return '#'; }
  public static function sample_url($w='diagnostic'){ return '/?bftd_preview=sample-'.$w; } }
// The real skills library, reading through the sample's own fixture, so the
// skills named on each session are the ones the plugin would name.
if (!function_exists('register_post_type')) { function register_post_type(...$a){} }
if (!function_exists('add_submenu_page')) { function add_submenu_page(...$a){} }
if (!function_exists('get_post_type_object')) { function get_post_type_object($t){ return null; } }
if (!function_exists('is_admin')) { function is_admin(){ return false; } }
if (!function_exists('current_user_can')) { function current_user_can(...$a){ return false; } }
if (!function_exists('admin_url')) { function admin_url($p=''){ return '/wp-admin/'.$p; } }
if (!function_exists('get_post_meta')) { function get_post_meta($i,$k,$s=false){ return $s ? '' : array(); } }
if (!function_exists('get_post_type')) { function get_post_type($i){ return ''; } }
if (!function_exists('wp_parse_args')) { function wp_parse_args($a,$d){ return array_merge($d,(array)$a); } }
if (!function_exists('selected')) { function selected($a,$b,$e=true){ $r=$a==$b?' selected':''; if($e) echo $r; return $r; } }
if (!function_exists('absint')) { function absint($n){ return abs((int)$n); } }
class BFTD_Admin { const MENU_SLUG='bftd'; }
require_once BFTD_PATH.'includes/class-bftd-library.php';
require BFTD_PATH.'includes/class-bftd-skills.php';

require_once BFTD_PATH.'includes/class-bftd-library.php';
require BFTD_PATH.'includes/class-bftd-activities.php';
require BFTD_PATH.'includes/class-bftd-fields.php';
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-derived.php';
require BFTD_PATH.'includes/class-bftd-charts.php';
require BFTD_PATH.'includes/class-bftd-sample.php';

/* The real dashboard, this time. Every other fixture stubs the sessions
 * stream out, which means the lesson records a family actually reads have
 * never been rendered by a test. This builds them from the sample report,
 * through BFTD_Dashboard itself. */
if (!function_exists('_n')) { function _n($s,$p,$n,$d=''){ return 1 == $n ? $s : $p; } }
if (!function_exists('get_avatar')) { function get_avatar($u,$s=48){ return ''; } }
if (!function_exists('esc_html__')) { function esc_html__($s,$d=''){ return esc_html($s); } }
if (!function_exists('__')) { function __($s,$d=''){ return $s; } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field(...$a){ return ''; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a=''){ return 'nonce'; } }
if (!function_exists('human_time_diff')) { function human_time_diff($a,$b=null){ return '2 days'; } }
if (!function_exists('current_time')) { function current_time($t='timestamp'){ return time(); } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script(...$a){} }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style(...$a){} }
if (!function_exists('wp_localize_script')) { function wp_localize_script(...$a){} }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v){ return json_encode($v); } }
class BFTD_Access { public static function can_view($a=0,$b=0){ return true; }
  public static function students_for($u=0){ return array(); } }
class BFTD_Attachments { public static function url($id){ return ''; } }
class BFTD_Audit { public static function log(...$a){} }
class BFTD_Brand { public static function logo(){ return ''; } }

require BFTD_PATH.'includes/class-bftd-report-view.php';
require BFTD_PATH.'includes/class-bftd-dashboard.php';

$sample = BFTD_Sample::get('progress');
$fields = $sample['fields'];
$posts  = $sample['sessions'];
$posts += (array) $sample['posts'];
$fields['__posts'] = $posts;

BFTD_Fields::use_fixture($fields);
BFTD_Items::use_fixture($sample['items']);
BFTD_Derived::use_fixture($sample['derived'] ?? array());
BFTD_Activities::use_fixture($sample['activities'] ?? array());
BFTD_Skills::use_fixture($sample['skills_library'] ?? array());
$ids = array_keys($sample['sessions']);
add_filter('bftd_pre_sessions_for', function() use ($ids){ return $ids; });

ob_start();
BFTD_Dashboard::render_sessions(0, $sample['student']);
$body = ob_get_clean();

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-report.css');
file_put_contents(__DIR__.'/lessons.html',
  '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{margin:0;background:#FBF9F7;font-family:system-ui,sans-serif}'.$css.'</style>'
  . '<div style="max-width:1060px;margin:0 auto;padding:22px"><div class="bf-report has-js"><div id="stream-list">'
  . $body . '</div></div></div>'
  . '<script>'.file_get_contents(BFTD_PATH.'assets/js/bftd-report.js').'</script>');
echo "written ".strlen($body)." bytes\n";
