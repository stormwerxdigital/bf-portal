<?php
/* Render the sample progress report exactly as BFTD_Report_View draws it, with
 * only the collaborators it reaches out to stubbed. The point is to see the
 * page: charts that draw, a diagnostic pulled in, nothing empty, nothing
 * overlapping. */
define('ABSPATH','/'); define('BFTD_PATH','/home/claude/bf-tutoring-dashboard/'); define('BFTD_URL','');
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
function get_option($k,$d=''){ return $GLOBALS['OPT'][$k] ?? $d; }
/*
 * Nonces, shaped the way WordPress shapes them.
 *
 * Faked rather than left out: a screen that renders an action link without a
 * nonce looks identical in a fixture to one that renders it with a nonce, so a
 * missing check is invisible unless the stub can tell the two apart.
 */
if ( ! function_exists( 'wp_create_nonce' ) ) {
  function wp_create_nonce($a=-1){ return substr(md5('nonce'.$a),0,10); }
}
if ( ! function_exists( 'wp_nonce_url' ) ) {
  function wp_nonce_url($url,$a=-1,$name='_wpnonce'){
    return $url . (strpos($url,'?')===false?'?':'&') . $name . '=' . wp_create_nonce($a);
  }
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
  function wp_verify_nonce($n,$a=-1){ return $n === wp_create_nonce($a); }
}
if ( ! function_exists( 'update_option' ) ) {
  function update_option($k,$v,$a=null){ $GLOBALS['OPT'][$k]=$v; return true; }
}

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
class BFTD_Preview {
  public static $on = true;
  public static function active(){ return self::$on; }
  public static function url($id){ return '/?bftd_preview=PREVIEW-OF-' . $id; }
  public static function sample_url($w='diagnostic'){ return '/?bftd_preview=sample-'.$w; } }
class BFTD_Activities { public static function for_session($s){ return array(); } public static function texts_of($a){ return array(); } }
class BFTD_Skills { public static function for_session($s){ return array(); } }

require BFTD_PATH.'includes/class-bftd-fields.php';
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-derived.php';
require BFTD_PATH.'includes/class-bftd-charts.php';
require BFTD_PATH.'includes/class-bftd-sample.php';

/* The dashboard is where the sessions stream and the field renderer live. It
 * pulls in far too much to load here, so only the three entry points the
 * report reaches for are stood in. */
class BFTD_Dashboard {
  public static $family = 0;
  /* Mirrors the real builder: the family it is drawing comes along. */
  public static function url($sid=0,$sec=''){
    $args = array();
    if (self::$family) $args['family'] = self::$family;
    if ($sid) $args['student'] = $sid;
    if ($sec) $args['screen'] = 'progress';
    return '/portal/?' . http_build_query($args) . '#' . $sec;
  }
  public static function report_field($key,$field,$value){
    if (!empty($field['lede'])) { echo '<p class="lede">'.esc_html($value).'</p>'; return; }
    if (is_array($value)) {
      // Rows. The real renderer draws a table; this draws enough of one to
      // see whether the section has anything in it, because a stub that
      // silently drops arrays reported an empty Legend section that is not
      // empty at all.
      if (!$value) return;
      $cols = array_keys((array) reset($value));
      echo '<table class="tbl"><thead><tr>';
      foreach ($cols as $c) echo '<th>'.esc_html(ucfirst($c)).'</th>';
      echo '</tr></thead><tbody>';
      foreach ($value as $row) { echo '<tr>'; foreach ($cols as $c) echo '<td>'.wp_kses_post($row[$c] ?? '').'</td>'; echo '</tr>'; }
      echo '</tbody></table>';
      return;
    }
    echo '<div class="doc-body">'.wp_kses_post($value).'</div>';
  }
  public static function report_chart($kind,$rid,$sid){
    if ('journey'===$kind)  return BFTD_Charts::milestones($rid) . BFTD_Charts::journey($rid);
    if ('coverage'===$kind) return BFTD_Charts::coverage($rid);
    if ('missed'===$kind)   return BFTD_Charts::missed($rid);
    if ('texts'===$kind)    return BFTD_Charts::texts($rid);
    return '';
  }
  public static function render_sessions($rid,$sid){ echo '<p class="note">[the sixteen lesson records render here]</p>'; }
}
require BFTD_PATH.'includes/class-bftd-report-view.php';

