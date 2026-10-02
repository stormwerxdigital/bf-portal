<?php
/*
 * The journey chart at every number of readings it will ever be asked to draw,
 * side by side, so a browser can measure it.
 *
 * The labels on this chart share a fixed width between them, so the gaps close
 * as readings are added. Nothing in the source says when two of them touch;
 * only the rendering does.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL','');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function wp_date($f,$ts,$z=null){ $d=new DateTime('@'.$ts); $d->setTimezone($z ?: new DateTimeZone('UTC')); return $d->format($f); }
function get_option($k,$d=''){ return $d; }
function apply_filters($t,$v){ return $v; }

$GLOBALS['JOURNEY'] = array();
class BFTD_Derived {
  public static function grade_journey($rid){ return $GLOBALS['JOURNEY']; }
  public static function milestones($rid){ return array(); }
}
class BFTD_Schema {
  public static function reading_levels(){
    $l = array('preprimer'=>'Preprimer','primer'=>'Primer');
    for ($g=1; $g<=8; $g++) $l['grade-'.$g] = 'Grade '.$g;
    return $l;
  }
}
require BFTD_PATH . 'includes/class-bftd-time.php';
require BFTD_PATH . 'includes/class-bftd-charts.php';

/* The names a reading can carry, longest last: Interim is the one that repeats
   and it is the widest of the three that are not the bookends. */
$kinds = array('Initial', 'Interim', 'Middle', 'Interim', 'Interim', 'Interim', 'Final');

function journey_case($n, $reached, $kinds) {
  $points = array();
  for ($i = 0; $i < $n; $i++) {
    $rank = $reached ? min(10, 1 + $i) : min(4, 1 + $i);
    $points[] = array(
      'report' => 900 + $i, 'kind' => 'interim', 'when' => $kinds[$i % count($kinds)],
      'on' => '2026-0' . (1 + ($i % 9)) . '-05', 'ts' => BFTD_Time::stamp('2026-0' . (1 + ($i % 9)) . '-05'),
      'rank' => $rank, 'level' => 'Grade ' . $rank,
    );
  }
  // A reading that reached the target IS the target level, in both the number
  // and the words. Letting them disagree hid the collision behind two strings
  // that happened to differ.
  if ($reached) { $points[$n - 1]['rank'] = 10; $points[$n - 1]['level'] = 'Grade 8'; }
  return array(
    'points' => $points,
    'target' => array('rank' => 10, 'level' => 'Grade 8'),
  );
}

$out = '';
foreach (array(false, true) as $reached) {
  for ($n = 1; $n <= 7; $n++) {
    $GLOBALS['JOURNEY'] = journey_case($n, $reached, $kinds);
    $out .= '<h3>' . $n . ' reading' . (1 === $n ? '' : 's') . ($reached ? ', target reached' : '') . '</h3>'
      . '<div class="case" data-n="' . $n . '" data-reached="' . ($reached ? '1' : '0') . '">'
      . BFTD_Charts::journey(1) . '</div>';
  }
}

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-report.css');
file_put_contents(__DIR__.'/journey.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . ''
  . '<style>body{margin:0;padding:18px;background:#FBF9F7;font-family:system-ui,sans-serif}'
  . '.case{max-width:760px;background:#fff;padding:10px;margin-bottom:8px}' . $css . '</style>'
  . '<div class="bf-report" data-theme="light">' . $out . '</div>');
echo __DIR__ . '/journey.html' . "\n";
