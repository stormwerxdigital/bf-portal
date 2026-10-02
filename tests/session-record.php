<?php
/*
 * What one session shows a family.
 *
 * The record used to show the activity and the tutor's written notes and
 * nothing else. The skills behind that activity and the book in front of the
 * child were both being counted into the summaries at the top of the report
 * and shown nowhere on the session that produced them, so a parent reading
 * "Sound Lines · went well" could not find out what their child had actually
 * learned or what they had read.
 *
 * Everything that decides anything here is the real renderer.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['SESS'] = array();

class BFTD_Fields {
  public static function get($id, $sec, $key, $f = array()) { return $GLOBALS['SESS'][$id][$key] ?? ''; }
  public static function has_value($v) { return is_array($v) ? (bool) $v : '' !== trim((string) $v); }
}
class BFTD_Activities {
  public static function for_session($sid) { return $GLOBALS['ACTS'][$sid] ?? array(); }
}
class BFTD_Skills {
  public static function for_session($sid) { return $GLOBALS['SKILLS'][$sid] ?? array(); }
}
class BFTD_CPT {
  const SESSION = 'bftd_session';
  public static function lesson_number($sid) { return 1; }
  public static function sessions_in_order($r, $d = false) { return array_keys($GLOBALS['SESS']); }
  public static function lesson_numbers($ids) { $o = array(); $n = 0; foreach ($ids as $i) $o[$i] = ++$n; return $o; }
}
class BFTD_Schedule { public static function pretty_time($t) { return $t; } }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Settings {
  public static function portal() { return array('recording_days' => 14, 'contact_line' => ''); }
  public static function standing_text($k, $f = '') { return $f; }
}
class BFTD_Threads { public static function find_thread() { return null; } public static function messages() { return array(); } }
class BFTD_Roles { public static function is_staff($u = null) { return false; } }
class BFTD_Items {}
class BFTD_Preview { public static function active() { return false; } public static function url($id) { return ''; } }
class BFTD_Access { public static function report_types() { return array(); } }
function wp_get_attachment_image($id, $s = '', $i = false, $a = array()) { return '<img alt="">'; }
if (!function_exists('wpautop')) { function wpautop($s, $br = true) { return '<p>' . $s . '</p>'; } }
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s, $b = false) { return trim(strip_tags((string) $s)); } }
if (!function_exists('esc_url')) { function esc_url($s) { return (string) $s; } }
if (!function_exists('get_permalink')) { function get_permalink($id = 0) { return ''; } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'https://bftutoring.com' . $p; } }
if (!function_exists('get_option')) { function get_option($k, $d = '') { return $d; } }
if (!function_exists('is_user_logged_in')) { function is_user_logged_in() { return true; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
/* The generated name a session actually carries in the admin. It is a
   reference, not a title, so nothing a family reads may contain it. */
function get_the_title($id) { return 'Kaine M · 14 Sep 2026, 9:00 am [#' . $id . ']'; }

// The real one, because how a text's level reads is its rule and a second
// copy of that rule in a stub is the bug this test is here to catch.
class BFTD_Derived { public static function attendance($r){ return array(); } public static function missed($r){ return array(); } }
require BFTD_PATH . 'includes/class-bftd-charts.php';
require BFTD_PATH . 'includes/class-bftd-dashboard.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$draw = function ($id) {
  $m = new ReflectionMethod('BFTD_Dashboard', 'render_session');
  $m->setAccessible(true);
  ob_start(); $m->invoke(null, $id, 7, null, array($id => 1)); return ob_get_clean();
};

/* A session with an activity, two skills behind it and a book. */
$GLOBALS['SESS'][901] = array(
  'session_date' => '2026-09-14', 'status' => 'held',
  'notes' => '<p>He read it twice.</p>',
  'texts' => array(
    array('title' => 'Cat in the Hat', 'level' => 'Grade 1'),
    array('title' => '', 'level' => 'Grade 2'),
  ),
);
$GLOBALS['ACTS'][901] = array(
  // Both, the way BFTD_Activities::resolve really answers: the label is how a
  // tutor finds it in the library, the name is what it is called.
  array('key' => 'a1', 'id' => 1, 'name' => 'Sound Lines',
        'label' => 'Track 1 · 2 · Sound Lines', 'about' => 'What it is.', 'note' => '', 'samples' => array()),
);
$GLOBALS['SKILLS'][901] = array(
  11 => array('id' => 11, 'name' => 'Mapping sounds to their spellings', 'from' => array(), 'note' => 'Unprompted today.', 'about' => ''),
  12 => array('id' => 12, 'name' => 'Writing every sound heard', 'from' => array(), 'note' => '', 'about' => ''),
);

$html = $draw(901);

/* The record is headed by where the child is in the programme, because that
   is the question a parent has. The admin's who-and-when reference answers a
   different one, and answers it twice: the name is at the top of the page and
   the date is on the row. */
check(false === strpos($html, '[#901]'), 'a family never reads the session\'s own title');
check(1 === preg_match('/class="session-title">Session \d/', $html), 'they read which session it is');

check(false !== strpos($html, 'Skills practiced'), 'a session says what it practiced');
check(false !== strpos($html, 'Mapping sounds to their spellings'), 'and names them');
check(false !== strpos($html, 'Writing every sound heard'), 'all of them');
check(false !== strpos($html, 'Unprompted today.'), 'with what the tutor wrote against one');

check(false !== strpos($html, 'What we read'), 'and says what was read');

/* The activity is named, and only named.
 *
 * "Track 2 & 3 · 1 · Up tea earn weigh" is how a tutor finds one in a library
 * of two hundred and eighty. To a family the track and the number are filing
 * references for a cabinet they have never seen, and they were the first two
 * things on every chip and every block heading. */
check(false !== strpos($html, 'Sound Lines'), 'the activity is named on the report');

/* And said to be an activity.
 *
 * The block heading sits in a run of headings that all say what they are:
 * "Skills practiced", "What we read", "Homework", "Work from the session".
 * The activity's block said only its own name, so a title like "Up tea earn
 * weigh" read as one more section of the record rather than as the thing the
 * child worked on. */
check(1 === preg_match('#<div class="blk-h">.*?Activity:.*?Sound Lines#s', $html),
  'and labelled as an activity, before its name');
check(false === strpos($html, 'Activity: </div>'), 'never the label on its own');
check(false === strpos($html, 'Track 1 · 2 · Sound Lines'),
  'without the track and the number a tutor uses to find it');
check(false !== strpos($html, 'Cat in the Hat'), 'naming the book');
check(false !== strpos($html, '>Grade 1<'), 'and the level it is written at');

/* A bare number is a digit, not a level. The session record and the reading
   log ask one rule, so the same book cannot be "1" on one screen and
   "Level 1" on the other. */
$GLOBALS['SESS'][901]['texts'][] = array('title' => 'Cat in the Hat', 'level' => '1');
check(false !== strpos($draw(901), '>Level 1<'), 'a bare number reads as a level here too');
array_pop($GLOBALS['SESS'][901]['texts']);

/* A row with a level and no title is somebody halfway through typing. A blank
   line on a family's report reads as a fault. */
preg_match('#<ul class="text-list">([\s\S]*?)</ul>#', $html, $tl);
$lines = isset($tl[1]) ? substr_count($tl[1], '<li>') : 0;
check(1 === $lines, 'a half-typed text row is not a line on the report, got ' . $lines);

/* The collapsed header promises a number of parts. It has to be the number
   that is actually behind the chevron, or the chevron is a lie. */
if (preg_match('/(\d+) parts/', $html, $m)) {
  $parts = (int) $m[1];
  // The block divs themselves, not the headings inside them.
  $drawn = preg_match_all('/<div class="blk[ "]/', $html);
  check($parts === $drawn, "the header counts the parts that are there, says $parts and draws $drawn");
} else {
  check(false, 'the header says how many parts there are');
}

/* Nothing reached and nothing read draws neither heading, rather than two
   empty boxes. */
$GLOBALS['SESS'][902] = array('session_date' => '2026-09-16', 'status' => 'held', 'notes' => '<p>Quiet one.</p>');
$GLOBALS['ACTS'][902] = array();
$GLOBALS['SKILLS'][902] = array();
$bare = $draw(902);
check(false === strpos($bare, 'Skills practiced'), 'a session that reached nothing says nothing about skills');
check(false === strpos($bare, 'What we read'), 'and one that read nothing says nothing about reading');
check(false !== strpos($bare, 'Quiet one.'), 'while what was written is still there');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
