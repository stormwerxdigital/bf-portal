<?php
/*
 * The two-chart card on its own, so a browser can be driven at it. Everything
 * that decides anything is the real renderer: the same BFTD_Report_View that
 * draws a family's report, with only its collaborators stubbed.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
define('DAY_IN_SECONDS', 86400);
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function esc_textarea($s){ return esc_html($s); }
function wp_kses_post($s){ return $s; }
function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); }
function wpautop($s){ return $s; }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[] = is_array($r) ? $r[$f] : $r->$f; } return $o; }
function add_filter(){} function add_action(){} function apply_filters($t,$v){ return $v; }
function wp_date($f,$ts,$z=null){ $d=new DateTime('@'.$ts); $d->setTimezone($z ?: new DateTimeZone('UTC')); return $d->format($f); }
function get_option($k,$d=''){ return $d; }
function get_the_title($i=0){ return 'Jot Singh'; }
function get_post($i=null){ return null; }
function get_posts($a){ return array(); }
function get_post_meta($i,$k,$s=false){ return $s ? '' : array(); }
function get_userdata($i){ return null; }

require BFTD_PATH.'includes/class-bftd-time.php';

class BFTD_Schema {
  const SCREEN_PROGRESS = 'progress';
  public static function reading_levels(){
    $l = array('preprimer'=>'Preprimer','primer'=>'Primer');
    for ($g=1; $g<=8; $g++) $l['grade-'.$g] = 'Grade '.$g;
    return $l;
  }
  public static function sections_for_screen($s){
    return array(
      'sec-progress-overview' => array('label'=>'Reading level','chart'=>'journey','fields'=>array('intro'=>array('type'=>'textarea','lede'=>true))),
      'sec-fluency'           => array('label'=>'Words correct per minute','chart'=>'wpm','fields'=>array('intro'=>array('type'=>'textarea','lede'=>true))),
    );
  }
}
class BFTD_Fields {
  public static function get($p,$s,$k,$f=array()){ return ('intro' === $k && 'sec-progress-overview' === $s) ? 'Where Jot is, and where he is going.' : ''; }
  public static function get_section($p,$s){ return array(); }
  public static function has_value($v){ return '' !== trim((string)$v); }
  public static function is_visible($p,$s){ return true; }
  public static function section_has_content($p,$s){ return true; }
}
class BFTD_Derived {
  public static function grade_journey($r){
    return array('points'=>array(
      array('report'=>1,'kind'=>'initial','when'=>'Initial','on'=>'2026-07-05','ts'=>BFTD_Time::stamp('2026-07-05'),'rank'=>1,'level'=>'Preprimer'),
      array('report'=>2,'kind'=>'middle','when'=>'Middle','on'=>'2026-09-01','ts'=>BFTD_Time::stamp('2026-09-01'),'rank'=>4,'level'=>'Grade 2'),
    ), 'target'=>array('rank'=>5,'level'=>'Grade 3'));
  }
  public static function fluency_journey($r){
    return array('points'=>array(
      array('report'=>1,'kind'=>'initial','when'=>'Initial','on'=>'2026-07-05','ts'=>BFTD_Time::stamp('2026-07-05'),'rank'=>40,'level'=>'40 wpm'),
      array('report'=>2,'kind'=>'middle','when'=>'Middle','on'=>'2026-09-01','ts'=>BFTD_Time::stamp('2026-09-01'),'rank'=>64,'level'=>'64 wpm'),
    ), 'target'=>array('rank'=>112,'level'=>'112 wpm'));
  }
  public static function milestones($r){
    return array(
      // The shape BFTD_Derived::milestones really returns. A fixture missing a
      // key the renderer reads is a fixture testing a different renderer.
      'initial' => array('kind'=>'initial','label'=>'Initial','done'=>true,'id'=>1,'on'=>'2026-07-05','ts'=>BFTD_Time::stamp('2026-07-05')),
      'middle'  => array('kind'=>'middle','label'=>'Middle','done'=>false,'id'=>0,'on'=>'','ts'=>0),
      'final'   => array('kind'=>'final','label'=>'Final','done'=>false,'id'=>0,'on'=>'','ts'=>0),
    );
  }
}
class BFTD_Dashboard {
  public static function report_field($k,$f,$v){}
  public static function report_chart($kind,$rid,$sid){
    if ('journey' === $kind) return BFTD_Charts::journey($rid);
    if ('wpm' === $kind)     return BFTD_Charts::wpm($rid);
    return '';
  }
}
require BFTD_PATH.'includes/class-bftd-charts.php';
require BFTD_PATH.'includes/class-bftd-report-view.php';

$m = new ReflectionMethod('BFTD_Report_View','chart_tabs'); $m->setAccessible(true);
ob_start();
$m->invoke(null, 1, 'Jot Singh', BFTD_Schema::sections_for_screen('progress'), array(
  'overview' => array('sec-progress-overview', 'Reading level'),
  'wpm'      => array('sec-fluency', 'Words correct per minute'),
));
$body = ob_get_clean();

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-report.css');
$js  = file_get_contents(BFTD_PATH.'assets/js/bftd-report.js');
file_put_contents(__DIR__.'/report-tabs.html',
  '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{margin:0;background:#FBF9F7;font-family:system-ui,sans-serif}'.$css.'</style>'
  . '<div class="bf-report" data-theme="light"><div style="max-width:900px;margin:0 auto;padding:20px">'
  . $body . '</div></div>'
  . '<script>'.$js.'</script>');
echo __DIR__ . '/report-tabs.html' . "\n";
