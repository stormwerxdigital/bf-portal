<?php
/*
 * The activity and skill pickers, with a library the size of the real one.
 *
 * Two of them on one page, because they are the same widget serving two
 * libraries that behave differently: an activity has a number and a skill
 * does not, and a search that works on one can be useless on the other
 * without anything looking wrong.
 *
 * A hundred and forty activities per track is the real figure. Search
 * quality is not visible on a list of three.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL','');
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return $s; }
function esc_textarea($s){ return esc_html($s); }
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

$GLOBALS['TITLES'] = array(); $GLOBALS['TYPE'] = array(); $GLOBALS['META'] = array();

/* A library shaped like the real one: a few names a tutor actually types,
   padded out to a realistic length with names that share words with them. */
/* Two traps in here on purpose, both about ORDER.
 *
 * Number 2 merely contains the word "blending" and number 3 begins with it,
 * and 2 comes first in the library. A list that takes the first eight it
 * finds puts the wrong one at the top.
 *
 * And 170 shares its opening digits with 17 while sitting between the two
 * activities actually numbered 17. Same trap, for numbers. */
$t1 = array(
  1 => 'Sound Lines', 2 => 'Warm up before Blending', 3 => 'Blending two sounds',
  7 => 'Say the Sounds', 170 => 'Sound Search Extended',
  4 => 'Read, Read Back, Read Again', 5 => 'Key word, Action, Thing (KAT)',
  12 => 'Blending three sounds', 17 => 'Phoneme Deletion',
  20 => 'Sound Search', 45 => 'Reading multisyllable words',
  112 => 'Sound Lines with digraphs', 120 => 'Blending with blends',
  121 => 'Multisyllable Split Word Reading', 140 => 'Listen, Tally, Say, Write (LTSW)',
);
$t2 = array(
  1 => 'Same Sound / Different Spelling', 12 => 'Homophones',
  17 => 'Multisyllable Spelling', 30 => 'High Frequency Words',
  112 => 'Handwriting Fluency with a Metronome',
);

$id = 500;
foreach (array('t1' => $t1, 't23' => $t2) as $track => $rows) {
  foreach ($rows as $n => $name) {
    $GLOBALS['TITLES'][$id] = $name;
    $GLOBALS['TYPE'][$id] = 'bftd_activity';
    $GLOBALS['META'][$id] = array('_bftd_activity_number' => $n, '_bftd_activity_track' => $track);
    $id++;
  }
  // Padding, so the eight-row cap is reached the way it is in real use.
  for ($n = 60; $n <= 100; $n++) {
    $GLOBALS['TITLES'][$id] = 'Practice routine ' . $n;
    $GLOBALS['TYPE'][$id] = 'bftd_activity';
    $GLOBALS['META'][$id] = array('_bftd_activity_number' => $n, '_bftd_activity_track' => $track);
    $id++;
  }
}

$skills = array(
  'Holding the sounds of a word in order', 'Mapping sounds to their spellings',
  'Reading a long word by chunk', 'Reading a passage smoothly',
  'Reading a spelling pattern as a pattern', 'Writing every sound heard',
  'Writing evenly and without effort', 'Letting meaning decide the spelling',
  'Flexing a sound until the word makes sense', 'One sound can be spelled by several letters',
  'Forming lowercase letters automatically', 'Working out what a sentence is saying',
  'Reading common words by their sounds', 'Finding a pattern in a real text',
  'Hearing the middle sound in a word', 'sounds can be represented by 1-4 letters',
);
foreach ($skills as $name) {
  $GLOBALS['TITLES'][$id] = $name;
  $GLOBALS['TYPE'][$id] = 'bftd_skill';
  $id++;
}

$css = file_get_contents(BFTD_PATH.'assets/css/bftd-admin.css');
$js  = file_get_contents(BFTD_PATH.'assets/js/bftd-admin.js');

file_put_contents(__DIR__.'/picker.html',
  '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width,initial-scale=1">'
  . '<style>body{font:14px/1.5 system-ui;background:#f0f0f1;margin:0;padding:24px}'
  . '.wrap{max-width:760px}.screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)}'
  . $css . '</style><div class="wrap">'
  . '<h2>Activity</h2><div id="acts">' . BFTD_Activities::picker('act', 0) . '</div>'
  . '<h2>Skill</h2><div id="skills">' . BFTD_Skills::picker('skill', 0) . '</div>'
  . '</div>'
  . '<script src="' . BFTD_PATH . 'tests/browser/jquery.js"></script>'
  . '<script>window.BFTD={ajax_url:"/ajax",nonce:"n",post_id:1,autosave:0,'
  . 'tracks:' . json_encode(BFTD_Activities::tracks()) . ','
  . 'groups:' . json_encode(BFTD_Skills::groups()) . '};</script>'
  . '<script>' . $js . '</script>');

echo __DIR__.'/picker.html' . "\n";
