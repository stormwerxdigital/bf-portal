<?php
/*
 * What a progress report is made of, now that most of it is built.
 *
 * The screen used to hold a typed measures table, a typed reading log, a typed
 * list of missed lessons and a fluency line of its own. Every one of those was
 * a second copy of something the lessons and the diagnostics already held. The
 * checks here are about the seams: that the removed pieces really are gone
 * rather than orphaned, that the pieces that replaced them are actually drawn,
 * and that a card offering to take a reader somewhere has somewhere to take
 * them.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

class BFTD_Fields { public static function has_value($v){ return '' !== trim((string) $v); } }
class BFTD_Items { const PRIORITY='priority'; const REVIEW='review'; }
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$progress = BFTD_Schema::sections_for_screen(BFTD_Schema::SCREEN_PROGRESS);
$view     = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
$charts   = file_get_contents(BFTD_PATH . 'includes/class-bftd-charts.php');
$dash     = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
$cpt      = file_get_contents(BFTD_PATH . 'includes/class-bftd-cpt.php');

/* ---- what was taken off ---- */

/* Reading speed over time is gone. Timed reading is one of the six measures
   the diagnostic already produces, and a second fluency line kept beside it
   was two answers to the same question. */
check(!isset($progress['sec-reading-speed']), 'reading speed over time is off the progress report');
check(false === strpos($view, 'sec-reading-speed'), 'and the renderer does not still reach for it');
check(false === strpos($charts, 'function fluency('), 'the chart it drew is gone rather than orphaned');
check(false === strpos($dash, "'fluency' === \$kind"), 'and nothing can still ask for one');

/* Checkpoints and the typed measures table are gone with it. */
$ov = $progress['sec-progress-overview']['fields'];
check(!isset($ov['checkpoints']), 'the checkpoints rows are gone');
check(!isset($ov['measures']), 'and so is the measures table somebody kept by hand');
check(false === strpos($dash, 'function overview_table('), 'along with the renderer that drew it');
/* And nothing still points a reader back at them. A sentence saying "the
   measures above" outlived the measures by two releases, which is the quiet
   half of deleting a feature: the prose that referred to it. */
check(false === strpos($view, 'measures above'), 'and nothing still refers a reader to them');

/* ---- what draws instead ---- */
$wants = array(
  'sec-progress-overview' => 'journey',
  'sec-fluency'           => 'wpm',
  'sec-activity-map'      => 'coverage',
  'sec-texts-read'        => 'texts',
  'sec-attendance'        => 'attendance',
);
foreach ($wants as $id => $kind) {
  check(isset($progress[$id]['chart']) && $kind === $progress[$id]['chart'],
    "$id is drawn by the $kind builder");
  check(false !== strpos($dash, "'" . $kind . "' === \$kind") && false !== strpos($dash, 'BFTD_Charts::' . $kind . '('),
    "and the dispatcher can actually reach it: $kind");
  check(1 === preg_match("/function $kind\\( \\\$report_id/", $charts),
    "which exists: $kind");
}

/* Three of the four are drawn as cards at the top of the report; the missed
   lessons table sits with the written sections, which is where a family looks
   for it. Either way it has to be drawn, and the way a section with a chart
   gets drawn is body_html handing the chart builder its kind. */
foreach (array('sec-activity-map', 'sec-texts-read', 'sec-attendance') as $id) {
  check(false !== strpos($view, "self::chart_card( \$report, '" . $id . "'"), "$id is a card of its own");
}

/* The two measures share a card behind tabs. They are the same picture drawn
   from the same three assessments in two units nobody can compare, and nobody
   reads both at once — stacked they cost a screen and a half of scrolling to
   get from the first to the second. */
check(1 === preg_match("/chart_tabs\( \\\$report, \\\$name, \\\$sections, array\([\s\S]{0,300}sec-progress-overview[\s\S]{0,120}sec-fluency/", $view),
  'the reading level and the reading speed share one card');
check(1 === preg_match('/function chart_tabs\([\s\S]{0,2600}BFTD_Charts::milestones\(/', $view),
  'with one set of milestone cards between them, not two');

/* A report with scripts blocked, or printed, has to be both charts down the
   page rather than one chart and a row of dead buttons. So both panels ship
   visible and the script closes the rest. */
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-report.js');
check(0 === preg_match('/class="tabpanel"[^>]*\shidden/', $view), 'both panels are drawn open');
check(1 === preg_match('/is-tabbed/', $js), 'and the script is what makes it a tab strip');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(1 === preg_match('/\.chart-tabs:not\(\.is-tabbed\) \.tabstrip\{display:none\}/', $css),
  'so the buttons are not shown until they do something');
check(1 === preg_match('/chart-tabs:not\(\.is-tabbed\)[\s\S]{0,400}\.chart-tabs\.is-tabbed \.tabpanel-h/', $css),
  'and each panel carries its own heading until the strip can say which is which');

/* The order on the page. Attendance first, because it is the shortest card
   and the one that can change what a family does this week. */
$order = array();
foreach (array('sec-attendance' => 'attend', 'sec-progress-overview' => 'overview',
               'sec-activity-map' => 'cover') as $id => $anchor) {
  $order[$id] = strpos($view, "'" . $id . "'");
}
check($order['sec-attendance'] < $order['sec-progress-overview'],
  'attendance comes before the reading level');
check($order['sec-progress-overview'] < $order['sec-activity-map'],
  'and the charts before the skills');

/* And the reading log moved to the foot, as the preface to the sessions it
   was read in. */
check(1 === preg_match('/id="texts-block"[\s\S]{0,300}sec-texts-read/', $view),
  'the reading log is drawn in its own block near the foot');
check(strpos($view, 'texts-block') < strpos($view, 'id="stream">Sessions'),
  'just before the session list');

/* Reading level and reading speed are two charts, not one with two y scales.
   A level and a rate cannot share an axis, and a child can gain thirty words
   a minute inside one reading level, which the level chart cannot show. */
check(isset($progress['sec-progress-overview']) && isset($progress['sec-fluency']),
  'the two measures have a section each');

/* The milestone cards are drawn exactly once.
 *
 * They belong to the assessments rather than to either measure, and both
 * charts are drawn from the same three. Prepending them in the chart
 * dispatcher put them above each chart — which was right while the charts
 * were two cards and became the same three cards twice inside one card the
 * moment they shared it. The card that knows how many charts it is drawing is
 * the only place that can get this right. */
check(false === strpos($dash, 'BFTD_Charts::milestones('),
  'the chart dispatcher does not prepend them');
check(1 === substr_count($view, 'BFTD_Charts::milestones('),
  'and the card draws them once, got ' . substr_count($view, 'BFTD_Charts::milestones('));
check($progress['sec-progress-overview']['chart'] !== $progress['sec-fluency']['chart'],
  'and a builder each, so neither is drawn on the other\'s scale');

/* The missed sessions moved into the attendance card. A family reading about
   the day they remember wants the reason and who recorded it, not a six
   column table to compare down, and two places listing the same cancellations
   in two different shapes is one too many. */
check(!isset($progress['sec-missed-lessons']), 'the standalone missed table is gone');
check(false === strpos($charts, 'function missed( $report_id'), 'and the builder that drew it, rather than being orphaned');
check(false === strpos($dash, "'missed' === \$kind"), 'and nothing can still ask for one');
check(1 === preg_match('/function attendance\([\s\S]{0,4000}BFTD_Derived::missed\(/', $charts),
  'while the attendance panel is where they are listed now');
check(1 === preg_match('/if \( ! empty\( \$section\[.chart.\] \) \) echo BFTD_Dashboard::report_chart\(/', $view),
  'and any section with a chart has it drawn under its own fields');
$skipped = array();
if (preg_match('/if \( in_array\( \$id, array\( ([^)]*) \), true \) \) continue;/', $view, $mm)) {
  $skipped = array_map(function ($x) { return trim($x, " '"); }, explode(',', $mm[1]));
}
check(in_array('sec-attendance', $skipped, true),
  'and a section already drawn as a card is not drawn again among the written ones');

/* ---- the diagnostic, pulled in ---- */

/* Two things come across from the reading diagnostic: the six skill
   assessments, and a link to the diagnostic itself.
 *
 * The written overview does not. It is the summary of the diagnostic in full
 * and it is already the first thing on the diagnostic that link opens; carried
 * here it was a closed accordion between the heading and the cards, which a
 * reader either ignored or opened to read three paragraphs about an assessment
 * before reaching the report they came for. */
check(1 === preg_match('/function diagnostic_pull\([\s\S]{0,2600}skill_cards\(/', $view),
  'the progress report pulls the six reading skill assessments');
check(0 === preg_match('/function diagnostic_pull\([\s\S]{0,3000}docsec\( .sec-assessment-overview/', $view),
  'and not the written overview with them');
check(1 === preg_match('/function diagnostic_pull\([\s\S]{0,4000}full reading diagnostic/', $view),
  'and a link to the diagnostic itself');

/* Which diagnostic, and where the link goes.

   It is the last one BY THE DATE OF THE ASSESSMENT, the same one the chart
   above ends on. Picking "the newest record" instead let the cards describe
   one assessment while the line beside them finished on another.

   And a preview is not the portal: a tutor pressing Preview is signed in as
   staff, and staff arriving at the portal without naming a family are handed
   the family chooser, so the portal link followed from a preview showed a
   search box instead of the assessment that was clicked. */
check(1 === preg_match('/function diagnostic_pull\([\s\S]{0,900}BFTD_CPT::current_diagnostic\( \$student_id \)/', $view),
  'the cards come from the diagnostic the chart ends on, not the newest record');
check(1 === preg_match('/function diagnostic_pull\([\s\S]{0,3000}BFTD_Preview::active\(\)[\s\S]{0,200}BFTD_Preview::url\( \$dx \)/', $view),
  'and from a preview the link goes to that diagnostic, not to the portal');
check(1 === preg_match('/function current_diagnostic\([\s\S]{0,600}diagnostics_for\(/', $cpt),
  'which is the last of the diagnostics the chart was drawn from');

/* Every link the portal builds while a staff member is reading a family's
   portal has to carry the family, or it sends them back to the chooser. The
   nav knew to do that; nothing else did. */
check(1 === preg_match('/private static \$viewing_family = 0;/', $dash),
  'the portal records which family it is drawing');
check(1 === preg_match('/function url\([\s\S]{0,400}self::\$viewing_family[\s\S]{0,120}\$args\[.family.\]/', $dash),
  'and every link it builds carries that family through');
check(1 === preg_match('/self::\$viewing_family = \$family_id;/', $dash),
  'set where the portal works out whose portal this is');

/* The family clicking through lands on the same assessment too. */
check(1 === preg_match('/function screen_report\([\s\S]{0,400}current_diagnostic\( \$student_id \)/', $dash),
  'and the diagnostic screen shows that same one');
check(1 === preg_match('/self::diagnostic_pull\( \$report, \$student_id, \$name \);/', $view),
  'and the progress renderer calls it, rather than merely defining it');

/* It is the most recent published diagnostic, because that is the one that
   says where the child is now. The earlier ones are the chart. */
check(1 === preg_match('/function diagnostics_for\([\s\S]{0,900}\x27post_status\x27\s*=>\s*\x27publish\x27/',
  file_get_contents(BFTD_PATH . 'includes/class-bftd-cpt.php')),
  'a diagnostic still being written is not charted or pulled');

/* A card on the progress report is on a different screen from the section it
   offers to open, so a bare fragment scrolls to nothing. Where there is
   nowhere to send a reader, the card must not offer. */
check(1 === preg_match('/\$linked\s*=\s*\(\s*null !== \$base\s*\);/', $view),
  'a card knows whether it has anywhere to send a reader');
check(1 === preg_match('/if \( \$linked \) :[\s\S]{0,260}Read the full section/', $view),
  'and only offers when it has');
check(1 === preg_match('/skill_cards\( \$dx, \$sections, \$link \? \$link : null \)/', $view),
  'so the pulled cards link out, or say nothing');

/* ---- the sample is what a family would see ---- */
class BFTD_CPT { const ASSESSMENT = 'bftd_assessment'; }
require BFTD_PATH . 'includes/class-bftd-sample.php';
$pr = BFTD_Sample::get('progress');
$dx = $pr['posts'][$pr['diagnostic']];

/* Two of the six sections work their score out rather than being told it, so a
   fixture that hands them a score fills in a field nobody can fill in and the
   card comes out blank. */
foreach (array('dxsec-1','dxsec-2','dxsec-3','dxsec-4','dxsec-5','dxsec-6') as $id) {
  $f = BFTD_Schema::section($id)['fields'];
  $derived = !empty($f['score']['derived']);
  check($derived ? !isset($dx[$id]['score']) : isset($dx[$id]['score']),
    "the sample fills in what $id actually asks for");
  if ($derived) {
    $from = isset($f['score']['derived']['minus'])
      ? $f['score']['derived']['minus']
      : $f['score']['derived']['count']['fields'];
    foreach ($from as $key) check(isset($dx[$id][$key]), "including $id.$key, which the score is worked out from");
  }
}

/* The sample's chart and the sample's diagnostic describe the same child.

   The chart is a fixture, and a fixture can drift: the sample once charted
   writing out of forty when the section marks sixteen points, which is a number
   no child could ever be shown on the card beside it. */
$r = new ReflectionMethod('BFTD_Sample', 'sample_journey'); $r->setAccessible(true);
$j = $r->invoke(null);
$lvl = BFTD_Schema::reading_levels();
$card_level = $lvl[ $dx['dxsec-3']['score'] ];
$last = $j['points'][ count($j['points']) - 1 ];
check($last['level'] === $card_level,
  "the chart ends on the level the card shows, got {$last['level']} not $card_level");
check($last['rank'] === BFTD_Schema::reading_level_rank($dx['dxsec-3']['score']),
  'at the same place on the scale');
check($j['target']['rank'] === BFTD_Schema::reading_level_rank('grade-3'),
  'and targets the grade the sample child is in');

/* The milestones and the journey are the same set of assessments. A card
   saying Middle with no matching point would be describing a reading that is
   not on the chart under it. */
$ms = new ReflectionMethod('BFTD_Sample', 'sample_milestones'); $ms->setAccessible(true);
$m  = $ms->invoke(null);
$ids = array();
foreach ($j['points'] as $p) $ids[] = $p['report'];
foreach ($m as $one) {
  if (!$one['done']) continue;
  check(in_array($one['id'], $ids, true), "the {$one['label']} milestone is a reading on the chart");
}

/* ---- what the session list is called ---- */
/* One name, in one place. The heading a family reads and the name the tutor's
   editor puts on the section are the same section, and having them written out
   twice is how one gets renamed and the other does not. */
check('Sessions' === $progress['sec-sessions']['label'], 'the section is called Sessions');
check(false === strpos($view, 'Every lesson'), 'and nothing still says Every lesson');
check(false === strpos($view, 'session by session'), 'and the skills card does not say session by session');

/* The card and the jump link that points at it say the same words. A strip
   that names a card something the card does not call itself is a map of
   somewhere else. */
check(2 === substr_count($view, "Skills we\\'re building"),
  'the skills card and its jump link agree on the heading, got ' . substr_count($view, "Skills we\\'re building"));
check(1 === preg_match('/id="stream">Sessions<\/h2>/', $view), 'including the heading on the family\'s report');

/* Same rule, the reading log. These two disagreed: the card said "What we
   have read together" and the button pointing at it said "What we have read",
   which is two names for one place written in two files. */
check(2 === substr_count($view, 'What we have read so far'),
  'the reading log card and its jump link agree, got ' . substr_count($view, 'What we have read so far'));

/* A lesson is a session now, everywhere a person reads. The internals still
   say session because they always did: the post type, the meta keys and the
   field group were bftd_session from the start, so there is nothing to
   migrate and nothing for a rename to break. What is checked here is that no
   screen still says the old word. */
$screens = array();
foreach (glob(BFTD_PATH . 'includes/*.php') as $f) $screens[$f] = file_get_contents($f);
$screens[BFTD_PATH . 'assets/js/bftd-admin.js'] = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');

/* Internal names that keep the old word on purpose. Renaming a meta key
   migrates data; renaming an option key orphans what a tutor has already
   written into their email templates. */
$internal = array(
  'lessons_bank', 'lesson_rescheduled', 'client_lesson_', 'client_missed_lesson',
  'lesson_when', '_bftd_lessons_used_before', 'next-lesson', 'cancel-lesson',
  'lesson_number', 'lesson_numbers', 'next_lesson', '$lesson', 'sec-missed-lessons',
  "'lesson'", '[\'lessons\']', "=> 'lesson'",
  // BFTD_Sample's own private list of them, and its callers.
  'self::lessons(', 'function lessons(',
);
$stray = array();
foreach ($screens as $path => $src) {
  /* Email TEMPLATES are left alone deliberately: changing them changes what
     lands in a family's inbox, and the wording of ones already sent. The
     admin labels around them were renamed. */
  if (false !== strpos($path, 'class-bftd-emails.php')) continue;
  $src = preg_replace('#/\*[\s\S]*?\*/#', '', $src);
  // Line comments, but not the // in a URL.
  $src = preg_replace('#(?m)(?<![:\'"])//.*$#', '', $src);
  foreach (explode("\n", $src) as $i => $line) {
    if (!preg_match('/[Ll]esson/', $line)) continue;
    foreach ($internal as $ok) { if (false !== strpos($line, $ok)) { $line = ''; break; } }
    if ('' === trim($line)) continue;
    $stray[] = basename($path) . ':' . ($i + 1) . ' ' . trim($line);
  }
}
check(array() === $stray, 'no screen still says lesson: ' . json_encode(array_slice($stray, 0, 6)));

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
