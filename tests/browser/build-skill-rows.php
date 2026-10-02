<?php
/*
 * The skills section on its own, with more rows than it shows, so a browser
 * can be driven at the fold. The real BFTD_Charts draws it.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
define('DAY_IN_SECONDS', 86400);
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function get_option($k,$d=''){ return $d; }
function get_the_title($i=0){ return ''; }
require BFTD_PATH.'includes/class-bftd-time.php';

/* Three sessions, five skills reached, a library of eighteen. */
$reached = array('Forming lowercase letters automatically', 'One sound can be spelled by several letters',
                 'Holding the sounds of a word in order', 'Mapping sounds to their spellings',
                 'Reading a spelling pattern as a pattern');
$ahead = array('Flexing a sound until the word makes sense', 'Reading a long word by chunk',
               'Reading a passage smoothly', 'Finding a pattern in a real text',
               'Writing evenly and without effort', 'Working out what a sentence is saying',
               'Reading common words by their sounds', 'Writing every sound heard',
               'Letting meaning decide the spelling', 'Hearing a syllable break',
               'Spelling a plural', 'Reading a contraction', 'Reading dialogue as speech');

class BFTD_Schema { public static function reading_levels(){ return array(); } }
/*
 * A library with numbers and a track on every row, because that is the shape
 * the real one has now and what is still ahead comes from a track's sequence
 * rather than from the library as a whole. A flat id => name fixture would
 * leave nothing outstanding and the fold would have nothing to fold.
 */
class BFTD_Skills {
  public static function all(){ return $GLOBALS['LIB']; }
  public static function labels(){ $o=array(); foreach($GLOBALS['LIB'] as $i=>$r) $o[$i]=$r['name']; return $o; }
  public static function label($id){ return $GLOBALS['LIB'][$id]['name'] ?? ''; }
  public static function tracks(){ return array('t1'=>'Track 1','t23'=>'Track 2 & 3'); }
  public static function track_label($t){ return self::tracks()[$t] ?? ''; }
  public static function sequence($track){
    $o=array();
    foreach($GLOBALS['LIB'] as $id=>$r) if($r['track']===$track && $r['number']) $o[$r['number']]=$id;
    ksort($o); return $o;
  }
  public static function done_for_student($sid){ return $GLOBALS['DONE']; }
}
class BFTD_Derived {
  public static function skills($r){ return $GLOBALS['GRID']; }
  public static function track($sid){ return 't1'; }
  public static function track_start($sid,$t){ return 1; }
}
class BFTD_CPT { public static function student_id($r){ return 900; } }

$GLOBALS['LIB']  = array();
$GLOBALS['DONE'] = array();
$id = 500;
$no = 0;
foreach ($reached as $name) {
  $GLOBALS['LIB'][++$id] = array('number'=>++$no, 'name'=>$name, 'track'=>'t1', 'group'=>'skill');
  $GLOBALS['DONE'][$id] = true;
}
foreach ($ahead as $name) {
  $GLOBALS['LIB'][++$id] = array('number'=>++$no, 'name'=>$name, 'track'=>'t1', 'group'=>'skill');
}

$lessons = array();
foreach (array('7 Jul 2026', '9 Jul 2026', '14 Jul 2026') as $i => $label) {
  $lessons[] = array('id' => 800 + $i, 'label' => $label, 'skills' => array());
}
$rows = array();
$rid  = 500;
foreach ($reached as $i => $name) {
  $rid++;
  $rows[$rid] = ($i % 2) ? array(0 => 'new', 2 => 'again') : array($i % 3 => 'new');
}
$names = array();
foreach ($reached as $i => $name) $names[501 + $i] = $name;
$GLOBALS['GRID'] = array('lessons' => $lessons, 'rows' => $rows, 'names' => $names);

require BFTD_PATH.'includes/class-bftd-charts.php';
$body = BFTD_Charts::coverage(1);

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-report.css');
$js  = file_get_contents(BFTD_PATH.'assets/js/bftd-report.js');
file_put_contents(__DIR__.'/skill-rows.html',
  '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{margin:0;background:#FBF9F7;font-family:system-ui,sans-serif}'.$css.'</style>'
  . '<div class="bf-report" data-theme="light"><div style="max-width:860px;margin:0 auto;padding:20px">'
  . '<div class="card"><div class="chart-body">' . $body . '</div></div>'
  . '</div></div><script>'.$js.'</script>');
echo __DIR__ . '/skill-rows.html' . "\n";
