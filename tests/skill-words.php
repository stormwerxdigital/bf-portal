<?php
/*
 * What a skill hands the activity editor.
 *
 * Choosing a skill on an activity drops what that skill says about itself
 * into the description below it. The endpoint that fetches those words was
 * sending WordPress's stored form of them: the author's blank lines, and no
 * tags at all, because nothing here reads a skill through the_content and so
 * nothing ever put the paragraph tags in.
 *
 * Handed to an HTML editor, that is one run-on paragraph. Everything the
 * author had arranged, arrived flattened, and the tutor then saved it that
 * way. So the endpoint sends the same markup a reader of the skill would
 * see.
 *
 * The wpautop below is not WordPress's. It is the part of its behaviour this
 * depends on: blank lines become paragraphs, and content that is already
 * blocked out is left alone. A stub that wrapped everything in one <p> would
 * pass whether the fix was there or not.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

function wpautop($text, $br = true) {
  $text = trim((string) $text);
  if ('' === $text) return '';
  $out = array();
  foreach (preg_split('/\n\s*\n/', $text) as $chunk) {
    $chunk = trim($chunk);
    if ('' === $chunk) continue;
    // Already a block of its own: left as it is, which is what the real one
    // does and is why running this twice does not double wrap.
    $out[] = preg_match('#^<(p|h[1-6]|ul|ol|div|blockquote|table|figure)[\s>]#i', $chunk)
      ? $chunk
      : '<p>' . $chunk . '</p>';
  }
  return implode("\n", $out);
}

$GLOBALS['POSTS'] = array();
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_the_title($id) { return $GLOBALS['POSTS'][(int) $id]->post_title ?? ''; }
function get_posts($a) {
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $post) {
    if ($post->post_type !== $a['post_type']) continue;
    $out[] = $id;
  }
  return $out;
}
function get_post_meta($id, $k, $s = false) { return $s ? '' : array(); }
function register_post_type(...$a) {}
function add_action(...$a) {}
function add_meta_box(...$a) {}
function is_admin() { return false; }
function current_user_can(...$a) { return true; }
function is_user_logged_in() { return true; }
function check_ajax_referer(...$a) { return true; }
function admin_url($p = '') { return '/wp-admin/' . $p; }

/* The two ways out of a handler, caught rather than exiting. */
class BFTD_Sent extends Exception { public $payload; public $code;
  public function __construct($p, $c) { parent::__construct('sent'); $this->payload = $p; $this->code = $c; } }
function wp_send_json_success($data = null, $code = 200) { throw new BFTD_Sent($data, $code); }
function wp_send_json_error($data = null, $code = 400) { throw new BFTD_Sent($data, $code); }

class BFTD_Roles {
  public static $manage = true;
  public static function can_manage_team($u = null) { return self::$manage; }
  public static function post_types() { return array('bftd_skill'); }
}
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Threads {}

require BFTD_PATH . 'includes/class-bftd-library.php';
require BFTD_PATH . 'includes/class-bftd-skills.php';
require BFTD_PATH . 'includes/class-bftd-ajax.php';

$GLOBALS['POSTS'][100] = (object) array(
  'ID' => 100, 'post_type' => 'bftd_skill', 'post_title' => 'Hearing the middle sound in a word',
  // As WordPress stores it from the editor: blank lines, no tags.
  'post_content' => "The child can say the sound in the middle of a short word.\n\nIt is the last of the three positions to come, because the middle of a word is the part a mouth moves through rather than lands on.",
);
$GLOBALS['POSTS'][101] = (object) array(
  'ID' => 101, 'post_type' => 'bftd_skill', 'post_title' => 'Already blocked out',
  'post_content' => "<p>One paragraph.</p>\n<p>And another.</p>",
);
foreach ($GLOBALS['POSTS'] as $p) { $p->post_status = 'publish'; }

$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? "  ok   " : "FAIL  ") . $msg . "\n"; if (!$ok) $fail++; }

function words_for($id) {
  $_POST = array('skill_id' => $id, 'nonce' => 'n');
  try { BFTD_Ajax::skill_words(); } catch (BFTD_Sent $e) { return $e->payload; }
  return null;
}

$got = words_for(100);
check(is_array($got) && isset($got['words']), 'the endpoint answers with the skill\'s words');
$w = $got['words'] ?? '';

check(2 === substr_count($w, '<p>'),
  'a skill written as two paragraphs arrives as two paragraphs');
check(false !== strpos($w, 'the middle of a word is the part a mouth moves through'),
  'with the second one\'s words intact');
check(false === strpos($w, "word.\n\nIt is"),
  'and no bare blank line left for an editor to swallow');
check('Hearing the middle sound in a word' === ($got['name'] ?? ''),
  'under the skill\'s own name');

$blocked = words_for(101)['words'] ?? '';
check(2 === substr_count($blocked, '<p>'),
  'a description already written as paragraphs is not wrapped a second time');

/* The capability is the point of the handler, so it is checked here too. */
BFTD_Roles::$manage = false;
$denied = words_for(100);
check(isset($denied['message']) && false !== stripos($denied['message'], 'senior manager'),
  'and somebody who does not maintain the library is turned away');
BFTD_Roles::$manage = true;

exit($fail ? 1 : 0);
