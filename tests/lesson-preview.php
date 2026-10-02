<?php
/*
 * Previewing a lesson.
 *
 * A lesson is not a document a family opens. It is one entry in the progress
 * report, so previewing it on its own would show a page that does not exist.
 * What a tutor wants to check is how the lesson they have just written reads
 * where the family will actually meet it.
 *
 * And it has to include the lesson being previewed even while it is a draft.
 * A draft is kept out of the report everywhere else, deliberately, so without
 * that the preview would show the report exactly as it is now: without the
 * thing the tutor pressed the button to look at. That is the whole feature.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

function code_of($file) {
  $out = '';
  foreach (token_get_all(file_get_contents($file)) as $t) {
    if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $out .= $t[1]; }
    else { $out .= $t; }
  }
  return $out;
}
$pv = code_of(BFTD_PATH . 'includes/class-bftd-preview.php');
preg_match('/function render_report\(.*?\n\t\}/s', $pv, $m);
$fn = isset($m[0]) ? $m[0] : '';
check('' !== $fn, 'the preview has one place where a record becomes a page');

/* ---- a lesson previews as the report it belongs to ---- */
check(1 === preg_match('/BFTD_CPT::SESSION\s*===\s*\$post->post_type/', $fn),
  'a lesson is recognised as not being a report of its own');
check(1 === preg_match('/report_for\(\s*\$student_id,\s*BFTD_CPT::PROGRESS\s*\)/', $fn),
  'and the progress report is what gets rendered');
check(1 === preg_match('/\$screen\s*=\s*BFTD_Schema::SCREEN_PROGRESS/', $fn),
  'on the progress screen, not whatever screen a lesson would map to');

/* ---- with the lesson in it, draft or not ---- */
check(1 === preg_match("/add_filter\(\s*'bftd_pre_sessions_for'/", $fn),
  'the lessons are handed in through the filter that exists for exactly this');
check(1 === preg_match('/in_array\(\s*\$lesson,\s*\$ids,\s*true\s*\)\s*\)\s*\$ids\[\]\s*=\s*\$lesson;/', $fn),
  'and the one being previewed is added when the report does not already carry it');

/* The order matters and is easy to get wrong: sessions_in_order asks
 * sessions_for, so a filter installed first would call itself forever. */
$at_ids    = strpos($fn, 'sessions_in_order');
$at_filter = strpos($fn, "add_filter( 'bftd_pre_sessions_for'");
check($at_ids !== false && $at_filter !== false && $at_ids < $at_filter,
  'the lessons are worked out before the filter goes on, or the filter would call itself');
check(1 === preg_match("/remove_all_filters\(\s*'bftd_pre_sessions_for'\s*\)/", $pv),
  'and the filter comes off afterwards, so nothing else in the request sees it');

/* ---- the page says what it is ---- */
check(1 === preg_match('/\$meta\[.note.\]/', $pv), 'the preview page can carry a note');
check(false !== strpos($pv, 'A session still in draft is shown here and nowhere else'),
  'and a session preview says that is what it is doing');
check(1 === preg_match("/get_edit_post_link\(\s*\\\$lesson\s*\?\s*\\\$lesson\s*:/", $pv),
  '"Back to editing" goes back to the lesson, not to the report it was shown inside');

/* ---- and the button on the lesson screen says what it opens ---- */
$mb = code_of(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, 'Preview the progress report'),
  'the button on a lesson does not promise a report the lesson does not have');
check(false !== strpos($mb, 'Preview this report'),
  'while a diagnostic or a progress report keeps its own wording');

/* ---- the thing that makes all of this safe ---- */
check(1 === preg_match('/wp_verify_nonce.*bftd_preview_.*\$report_id/s', $pv),
  'the link is still checked against the record that was asked for');
$at_nonce = strpos($fn, 'wp_verify_nonce');
check($at_nonce !== false && $at_nonce < $at_filter,
  'before any of the swapping happens, so a lesson id cannot be traded for a report somebody may not open');

/* ================================================================== */
/* One click from the list                                             */
/* ================================================================== */
/*
 * Opening a report to press Preview means loading an edit screen, with its
 * editors and its media library, to answer a question that has nothing to do
 * with editing. The row already knows which record it is.
 */
$GLOBALS['CAN'] = array();
$GLOBALS['VIEW'] = true;
function current_user_can($cap, $id = 0) { return !empty($GLOBALS['CAN'][(int) $id]); }
function wp_nonce_url($url, $action) { return $url . '&_wpnonce=abc'; }
function add_query_arg($k, $v, $url) { return $url . '?' . $k . '=' . rawurlencode($v); }
function home_url($p = '/') { return 'https://example.test' . $p; }
function nocache_headers() {}

class BFTD_CPT {
  const STUDENT = 'bftd_student'; const ASSESSMENT = 'bftd_assessment';
  const PROGRESS = 'bftd_progress'; const SESSION = 'bftd_session';
  public static function student_id($id) { return 0; }
  public static function report_for($s, $t) { return 0; }
  public static function sessions_in_order($r, $d = false) { return array(); }
}
class BFTD_Access {
  public static function report_types() { return array('bftd_assessment', 'bftd_progress', 'bftd_session'); }
  public static function can_staff_view($id, $u = 0) { return !empty($GLOBALS['VIEW']); }
}
class BFTD_Schema { const SCREEN_PROGRESS = 'progress'; public static function screen_for_post_type($p) { return ''; } }
class BFTD_Dashboard { public static function report_body(...$a) {} }
class BFTD_Report_View { public static function fonts_url() { return ''; } }
class BFTD_Autosave { public static function unsaved($p) { return false; } }
class BFTD_Roles { public static function post_types() { return array(); } }

require BFTD_PATH . 'includes/class-bftd-preview.php';

function post($type, $status = 'publish', $id = 5) {
  return (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status);
}
$base = array('edit' => 'Edit', 'trash' => 'Trash');

$a = BFTD_Preview::row_action($base, post('bftd_progress'));
check(isset($a['bftd_view']), 'a progress report has a way to see it as the family does');
check(false !== strpos($a['bftd_view'], '>View report<'), 'and it says so plainly');
check(false !== strpos($a['bftd_view'], 'target="_blank"'), 'opening beside the list rather than over it');
check(false !== strpos($a['bftd_view'], '_wpnonce'), 'through the same signed link the Preview button uses');
check(isset($a['edit']) && isset($a['trash']), 'and the actions that were there are still there');

check(isset(BFTD_Preview::row_action($base, post('bftd_assessment'))['bftd_view']),
  'a reading diagnostic has one too');

$l = BFTD_Preview::row_action($base, post('bftd_session'));
check(false !== strpos($l['bftd_view'], '>View in the report<'),
  'and a lesson says where it will be seen, because a lesson is an entry rather than a document');

/* Not everywhere, and not to everybody. */
check(!isset(BFTD_Preview::row_action($base, post('page'))['bftd_view']),
  'a page of the site is not ours to preview');
check(!isset(BFTD_Preview::row_action($base, post('bftd_progress', 'auto-draft'))['bftd_view']),
  'a screen somebody opened and never saved has nothing to look at');
check(!isset(BFTD_Preview::row_action($base, post('bftd_progress', 'trash'))['bftd_view']),
  'and neither has one in the trash');

check(isset(BFTD_Preview::row_action($base, post('bftd_progress', 'draft'))['bftd_view']),
  'a draft does, which is when looking at it matters most');

$GLOBALS['VIEW'] = false;
check(!isset(BFTD_Preview::row_action($base, post('bftd_progress'))['bftd_view']),
  'somebody who cannot open the record is not given a link that would refuse them');
$GLOBALS['CAN'][5] = true;
check(isset(BFTD_Preview::row_action($base, post('bftd_progress'))['bftd_view']),
  'while its author keeps theirs');

exit($fail ? 1 : 0);
