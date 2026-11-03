<?php
/*
 * One demo student for both sample reports (Karl, 1.167).
 *
 * The samples are shown to staff, to families and in ads, so they carry a
 * made-up child rather than a real one, and the two reports are the same
 * child: the diagnostic is the baseline the progress report starts from. A
 * reader who opens one from the other must not find a different grade or a
 * different starting score. And the samples are found where reports are, at
 * the top of the two report lists.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

class BFTD_Items { const PRIORITY = 'priority'; const REVIEW = 'review'; }
class BFTD_Fields {
  public static function has_value($v) { return '' !== trim((string) $v); }
  public static function get($p, $s, $k, $f = array()) { return ''; }
}
function add_query_arg($k, $v, $u) { return $u . '?' . $k . '=' . $v; }
class BFTD_CPT { const ASSESSMENT = 'bftd_assessment'; const PROGRESS = 'bftd_progress'; }
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-sample.php';
require BFTD_PATH . 'includes/class-bftd-preview.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$dx = BFTD_Sample::get('diagnostic');
$pr = BFTD_Sample::get('progress');

/* ---- one made-up child ---- */
check('Rowan Ellery' === $dx['student'] && $dx['student'] === $pr['student'], 'both samples are the demo student, Rowan Ellery');
check(0 === strpos($pr['eyebrow'], 'Rowan Ellery · Grade 3 · started 5 July 2026'), 'and the progress report says so in its heading line');

$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-sample.php')
     . file_get_contents(BFTD_PATH . 'includes/class-bftd-pay.php');
foreach (array('Kaine', 'Jot', 'Miller', 'Singh', 'Chewelah') as $old) {
  check(0 === preg_match('/\b' . $old . '\b/', $src), "no trace of \"$old\" in the samples or the time card's worked example");
}

/* ---- the diagnostic is the progress report's starting point ---- */
$f       = $dx['fields'];
$journey = $pr['derived']['journey']['points'][0];
$fluency = $pr['derived']['fluency']['points'][0];

check('3' === $f['student']['grade'] && '3' === $pr['fields']['student']['grade'], 'Grade 3 on both');
check('2026-07-05' === $f['sec-assessment-overview']['assessed_on'] && $journey['on'] === $f['sec-assessment-overview']['assessed_on'],
  'assessed on the day the progress report says he started');
check('initial' === $f['sec-assessment-overview']['kind'] && 'initial' === $journey['kind'], 'as the initial assessment');
check('grade-1' === $f['dxsec-3']['score'] && 'Grade 1' === $journey['level'], 'reading at Grade 1, where the reading level chart begins');
check(40 === (int) $f['dxsec-4']['words'] - (int) $f['dxsec-4']['errors'] && 40 === $fluency['rank'], '40 correct words a minute, where the fluency chart begins');
check('14' === $f['dxsec-1']['score'] && false !== strpos($pr['posts'][BFTD_Sample::DX_ID]['sec-assessment-overview']['overview'], 'from 14 letters a minute'),
  '14 letters a minute, which the reassessment measures his growth from');

$age = (int) date_diff(date_create($f['student']['birth_date']), date_create($f['sec-assessment-overview']['assessed_on']))->y;
check($age >= 7 && $age <= 9, 'and old enough for Grade 3, got age ' . $age);

/* ---- found where reports are ---- */
$row = BFTD_Preview::sample_row_html('diagnostic');
check(false !== strpos($row, '<strong>Rowan Ellery, reading diagnostic</strong>'), 'the diagnostics list gets a row named the way a report is named');
check(false !== strpos($row, 'Demo student') && false !== strpos($row, 'Not a real student'), 'marked as the demo, not a real student');
check(false !== strpos($row, 'bftd_preview=sample-diagnostic'), 'opening the sample');
check(false !== strpos($row, 'getElementById( "the-list" )') && false !== strpos($row, 'insertBefore'), 'at the top of the list');
/* 1.167 spanned 99 columns. List tables have fixed widths, so that made 99
   columns and squeezed every real report to a sliver: they looked gone. */
check(false === strpos($row, 'colspan="99"') && 0 === preg_match('/colspan="\d+"/', $row), 'the row does not guess how many columns the table has');
check(false !== strpos($row, 'r.firstElementChild.colSpan = Math.max( 1, n );') && false !== strpos($row, 'classList.contains( "hidden" )'),
  'it spans exactly the columns on show, counted from the table head');
check(false !== strpos(BFTD_Preview::sample_row_html('progress'), '<strong>Rowan Ellery, progress report</strong>'), 'and the progress list gets his progress report');
check('' === BFTD_Preview::sample_row_html('nothing'), 'and nothing for a sample that does not exist');

$pv = file_get_contents(BFTD_PATH . 'includes/class-bftd-preview.php');
check(false !== strpos($pv, "add_action( 'admin_footer-edit.php', array( __CLASS__, 'sample_row' ) );"), 'the row is added on the list screens');
check(1 === preg_match("#function sample_row\(\).*?BFTD_CPT::ASSESSMENT => 'diagnostic', BFTD_CPT::PROGRESS => 'progress'.*?'trash' === \\\$_GET\['post_status'\]#s", $pv),
  'only on the two report lists, and not on their trash');

/* ---- anyone can open them (1.169) ---- */
$a = strpos($pv, "\tprivate static function render_sample(");
$b = strpos($pv, "\tprivate static function hidden_sections(");
$sample_src = substr($pv, $a, $b - $a);
check(false === strpos($sample_src, 'is_user_logged_in'), 'a sample opens without signing in, for families and ads');
check(false !== strpos($pv, "if ( ! current_user_can( 'edit_post', \$report_id ) && ! BFTD_Access::can_staff_view( \$report_id ) ) {"),
  'while a real report still needs someone allowed to open it');
check(false !== strpos($pv, "wp_verify_nonce( sanitize_key( \$_GET['_wpnonce'] ), 'bftd_preview_' . \$report_id )"), 'and a signed link');
check(false !== strpos($pv, '<meta name="robots" content="noindex, nofollow">'), 'and the page is kept out of search results');
check(1 === preg_match('/\.bftd-sample-row a\{text-decoration:underline/', file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css')), 'View report is underlined, so it reads as a link');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
