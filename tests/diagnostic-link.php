<?php
/*
 * Where the link on a progress report actually goes.
 *
 * A progress report pulls the reading diagnostic in: the overview, the six
 * cards, and a link to the assessment itself. That link has three different
 * right answers depending on who is reading, and it had one.
 *
 * The one it had was the portal. Followed from a PREVIEW, which is how a tutor
 * checks their own work, it sent them to the portal — where a signed-in staff
 * member with no family named is handed the family chooser. A tutor clicked
 * "Read Kaine's full reading diagnostic" and got a search box. Nothing errored,
 * and from a family's own session the same link worked perfectly, which is why
 * it survived.
 *
 * So the whole thing is rendered here, three times, and the link is read out
 * of the markup rather than out of the source.
 */
/* Only the collaborators the renderer reaches out to are stubbed. Everything
 * that decides anything — the report view, the schema, the derived tables — is
 * the real code. */
define('ABSPATH','/'); define('BFTD_PATH', dirname(__DIR__) . '/'); define('BFTD_URL','');
define('DAY_IN_SECONDS',86400);
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function esc_textarea($s){ return esc_html($s); }
function wp_kses_post($s){ return $s; }
function wp_strip_all_tags($s){ return trim(strip_tags((string)$s)); }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[] = is_array($r) ? $r[$f] : $r->$f; } return $o; }
function get_post_type($i=null){ $p = get_post($i); return $p ? $p->post_type : ''; }
function get_post_meta($i,$k,$single=false){ return $single ? '' : array(); }
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
  public static function open_items($sid,$scope){ return self::$fx[$scope] ?? array(); }
  public static function config($l){ return array('label'=>ucfirst($l),'lede'=>''); }
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
    if (is_array($value)) return;
    echo '<div class="doc-body">'.wp_kses_post($value).'</div>';
  }
  public static function report_chart($kind,$rid,$sid){
    if ('journey'===$kind)  return BFTD_Charts::milestones($rid) . BFTD_Charts::journey($rid);
    if ('coverage'===$kind) return BFTD_Charts::coverage($rid);
    if ('missed'===$kind)   return BFTD_Charts::missed($rid);
    if ('texts'===$kind)    return BFTD_Charts::texts($rid);
    return '';
  }
  public static function render_sessions($rid,$sid){ echo '<p class="note">[lesson records]</p>'; }
}
require BFTD_PATH.'includes/class-bftd-report-view.php';


$sample = BFTD_Sample::get('progress');
$fields = $sample['fields'];
$posts  = $sample['sessions'] + (array) $sample['posts'];
$fields['__posts'] = $posts;
BFTD_Fields::use_fixture($fields);
BFTD_Items::use_fixture($sample['items']);
BFTD_Derived::use_fixture($sample['derived']);
$ids = array_keys($sample['sessions']);
add_filter('bftd_pre_sessions_for', function () use ($ids) { return $ids; });

/* The sample names its own link. A real report does not, so that is taken away
   here: what is being checked is what diagnostic_pull decides on its own. */
$extra = $sample;
unset($extra['diagnostic_link']);

error_reporting(E_ALL & ~E_WARNING);

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

function render_with($extra, $student_id) {
  ob_start();
  BFTD_Report_View::render(0, $student_id, BFTD_Schema::SCREEN_PROGRESS, $extra['student'], $extra);
  return ob_get_clean();
}

/* ---- a tutor pressing Preview ---- */
BFTD_Preview::$on = true;
$html = render_with($extra, 39);
check(false !== strpos($html, 'PREVIEW-OF-995003'),
  'from a preview the link goes to that diagnostic\'s own preview');
check(false === strpos($html, '/portal/?'),
  'and never to the portal, where a signed-in tutor gets the family chooser');

/* ---- a family reading their own portal ---- */
BFTD_Preview::$on = false;
BFTD_Dashboard::$family = 0;
$html = render_with($extra, 39);
check(1 === preg_match('#href="[^"]*/portal/\?student=39&screen=progress\#sec-assessment-overview"#', $html)
   || false !== strpos($html, 'student=39'),
  'a family gets their own portal screen');
check(false === strpos($html, 'family='), 'with no family parameter, because they are the family');

/* ---- a staff member reading a family's portal ---- */
BFTD_Dashboard::$family = 77;
$html = render_with($extra, 39);
check(false !== strpos($html, 'family=77'),
  'a staff member reading over their shoulder keeps the family in the link');

/* ---- which reading these cards came from ---- */

/* "The assessment these cards are taken from" described the cards. A family
   wants to know which reading this is: a middle assessment and the baseline
   are not the same news, and the date is what lets them place it against the
   sessions further down the page. */
BFTD_Dashboard::$family = 0;
$html = render_with($extra, 39);
/* Which of the four, read off the diagnostic rather than written into the
   test: the sample's pulled assessment is an interim one, and a test that
   hard-codes "Initial" passes on a report describing the wrong reading. */
$kind = BFTD_Schema::assessment_kind_label(
  BFTD_Fields::get($extra['diagnostic'], 'sec-assessment-overview', 'kind')
);
check('Interim' === $kind, 'the sample pulls an interim assessment, got ' . $kind);
check(false !== strpos($html, 'Last reading assessment'), 'the block is headed as the last assessment');
check(false !== strpos($html, $kind . ' taken on 1 September 2026'),
  'and the line under it names which of the four, and the day it was taken');
check(false === strpos($html, 'these cards are taken from'), 'rather than describing the cards');

/* ---- the stat tiles ---- */

/* The skills tile is the number reached, with no "/N" out of the library:
   Karl removed the denominator. */
$rv = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false === method_exists('BFTD_Report_View', 'of') && false === strpos($rv, 'color:var(--muted)">/'), 'no count out of a total is drawn on a tile');
check(1 === preg_match("/array\\( \\(int\\) \\\$skills, 'skills we/", $rv), 'the skills tile is the plain number reached');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
