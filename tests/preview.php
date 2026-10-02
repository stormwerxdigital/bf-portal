<?php
/*
 * The report preview and the two sample reports.
 *
 * The rule that matters is that a preview renders through the portal's own
 * renderer. A preview built on a second template agrees with the real thing
 * right up until somebody changes one of them, and then it keeps insisting
 * everything is fine. So what is checked here is that the sample content
 * reaches that renderer intact, and that the fields which exist to drive the
 * summary cards never also print as rows underneath them.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

class BFTD_Schema_Stub {}
require BFTD_PATH . 'includes/class-bftd-schema.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* --- the machinery never reaches the family --- */
$sections = BFTD_Schema::sections();

$card_fields = array();
foreach ( $sections as $id => $sec ) {
  foreach ( $sec['fields'] as $k => $f ) {
    if ( ! empty( $f['card'] ) ) $card_fields[] = "$id.$k";
  }
}
check(in_array('dxsec-1.score', $card_fields, true), 'a card-only field is marked as one');
check(in_array('dxsec-6.score_of', $card_fields, true), 'and so is a figure that only feeds the bar');
check(!in_array('dxsec-1.body', $card_fields, true), 'while the section body is not, because that is the report');

/* The progress report no longer stores the things it draws. A typed measures
   table, a typed reading log, a typed list of missed lessons and a typed
   fluency line were four second copies of facts the lessons and diagnostics
   already held, and every one of them drifted. */
foreach (array('sec-progress-overview', 'sec-fluency', 'sec-texts-read', 'sec-attendance', 'sec-activity-map') as $built) {
  check(isset($sections[$built]), "$built is still on the progress report");
  check(array_keys($sections[$built]['fields']) === array('intro'),
    "and asks for nothing but its own explainer: $built");
  check(!empty($sections[$built]['derived']), "saying in the editor where it comes from: $built");
}
check(!isset($sections['sec-reading-speed']),
  'and reading speed over time is gone, because timed reading is one of the six measures');

/* Every diagnostic skill section offers a card. */
$cards = 0;
foreach ( $sections as $id => $sec ) if ( ! empty( $sec['card'] ) ) $cards++;
check($cards === 6, 'all six skill areas render a summary card, got ' . $cards);

/* --- the samples --- */
/* The real field resolver, not a stub that answers '' to everything. The
   sample is read through it below, and a stub would happily agree that every
   card is filled while the real thing showed none of them. */
require BFTD_PATH . 'includes/class-bftd-fields.php';
class BFTD_Items { const PRIORITY='priority'; const REVIEW='review'; }
require BFTD_PATH . 'includes/class-bftd-sample.php';

foreach ( array('diagnostic' => BFTD_Schema::SCREEN_REPORT, 'progress' => BFTD_Schema::SCREEN_PROGRESS) as $which => $screen ) {
  $s = BFTD_Sample::get($which);
  check(is_array($s), "there is a $which sample");
  check($s['screen'] === $screen, "and it renders on the right screen");
  check($s['student'] !== '', "named for the student it was written about");
  check(strpos($s['note'], 'sample') !== false, "and says plainly that it is a sample");

  // Every section it supplies must be a real section on that screen, or the
  // sample would quietly stop showing a part of itself after a schema change.
  $valid = BFTD_Schema::sections_for_screen($screen);
  $stray = array_diff(array_keys($s['fields']), array_keys($valid), array('student'));
  check($stray === array(), "every section in the $which sample still exists" . ($stray ? ': ' . implode(', ', $stray) : ''));

  // And every field within them.
  $bad = array();
  foreach ($s['fields'] as $sid => $vals) {
    if ('student' === $sid || !isset($valid[$sid])) continue;
    foreach (array_keys($vals) as $k) {
      if (!isset($valid[$sid]['fields'][$k])) $bad[] = "$sid.$k";
    }
  }
  check($bad === array(), "every field in the $which sample still exists" . ($bad ? ': ' . implode(', ', $bad) : ''));
}

/* The diagnostic sample has to actually fill the cards, or it demonstrates
   nothing about what a finished report looks like. */
$dx = BFTD_Sample::get('diagnostic');

/* Resolved the way the report resolves it, rather than by looking for a key.
   One section's score is worked out from two other figures and is not in the
   sample at all, and a check that counted keys called that an empty card. */
BFTD_Fields::use_fixture($dx['fields']);
$with_score = array();
foreach ($sections as $sid => $sec) {
  if (0 !== strpos($sid, 'dxsec-')) continue;
  $def = isset($sec['fields']['score']) ? $sec['fields']['score'] : array();
  if (BFTD_Fields::has_value(BFTD_Fields::get(0, $sid, 'score', $def))) $with_score[] = $sid;
}
BFTD_Fields::clear_fixture();
check(6 === count($with_score),
  'the sample diagnostic scores all six areas, got ' . count($with_score)
  . ' (' . implode(', ', $with_score) . ')');

/* The cards must not all be one colour, or the summary strip says nothing
   about where the work is. That used to come from a verdict a tutor chose. It
   now comes from each score against its own goal, so the check is on the tones
   the bars would actually show rather than on a field most sections no longer
   have. */
$view_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
$a = strpos($view_src, "\tprivate static function meter_tone(");
$b = strpos($view_src, "\t/**\n\t * The four figures across the top");
eval('class TonePeek {' . substr($view_src, $a, $b - $a) . '}');
$tm = new ReflectionMethod('TonePeek', 'meter_tone'); $tm->setAccessible(true);

BFTD_Fields::use_fixture($dx['fields']);
$tones = array();
foreach ($sections as $sid => $sec) {
  if (0 !== strpos($sid, 'dxsec-')) continue;
  $bench = isset($sec['benchmark']) ? (float) $sec['benchmark'] : 0;
  if (!empty($sec['benchmark_field'])) {
    $raw = BFTD_Fields::get(0, $sid, $sec['benchmark_field'], $sec['fields'][$sec['benchmark_field']]);
    $bench = ( !empty($sec['benchmark_lookup']) && 'fluency_norm' === $sec['benchmark_lookup'] )
      ? (float) BFTD_Schema::fluency_norm_wcpm($raw) : (float) $raw;
  }
  if ($bench <= 0) continue;
  $score = BFTD_Fields::get(0, $sid, 'score', $sec['fields']['score']);
  if (!empty($sec['score_rank'])) $score = BFTD_Schema::reading_level_rank($score);
  if (!BFTD_Fields::has_value($score)) continue;
  $tones[$tm->invoke(null, (float) $score / $bench)] = true;
}
BFTD_Fields::clear_fixture();
check(count($tones) > 1,
  'and the cards are not all one colour, got ' . implode(', ', array_keys($tones)));

/* The progress sample needs the charts to have something to draw. It has no
   lessons or diagnostics in a database behind it, so it answers the derived
   tables from a fixture — and if that fixture is missing, the sample shows
   its written sections and four empty spaces where most of a report is. */
$pr = BFTD_Sample::get('progress');
$d  = isset($pr['derived']) ? $pr['derived'] : array();
check(count($d['milestones']) === 3, 'the sample shows the three milestones of a programme');
check($d['milestones']['initial']['done'], 'with the initial assessment behind it');
check(!$d['milestones']['final']['done'], 'and the final still ahead, which is most of a real report');
check(count($d['journey']['points']) >= 3, 'the journey is drawn across three or more readings');
check(!empty($d['journey']['target']['level']), 'and has a target to head for, or the chart has no subject');
check($d['journey']['points'][count($d['journey']['points']) - 1]['rank'] < $d['journey']['target']['rank'],
  'with the target still ahead of where the sample child is, which is the case worth showing');
/* What the sample no longer stands in for.
 *
 * Everything a report BUILDS is now built for the sample too, off the
 * sample's own sessions, by the code that builds a family's own. A fixture
 * standing in for one of these is a second renderer that can drift — and did:
 * the sample once charted a mark out of forty for a section that marks
 * sixteen, a number no child could be shown on the card beside it.
 *
 * What is left is the two that no session record can produce: readings of the
 * child. */
foreach (array('texts', 'skills', 'missed', 'attendance') as $built) {
  check(!isset($d[$built]), "the sample builds its own $built rather than being handed one");
}
check(isset($d['milestones']) && isset($d['journey']),
  'while the readings, which nothing can derive, are still supplied');

/* So the records have to carry what those are built FROM. */
$read = 0; $resc = 0;
foreach ($pr['sessions'] as $l) {
  $read += count($l['session']['texts'] ?? array());
  if ('held' !== ($l['session']['status'] ?? 'held')) $resc++;
}
check($resc >= 1, 'a session that did not go ahead, or the missed table and the attendance figures show a clean sheet');

$library = BFTD_Sample::sample_skills_library();
check(count($library) > 8, 'enough distinct skills for a spiral, got ' . count($library));

/* The library has to hold skills this child has NOT reached yet, or the stat
   tile reads "14 of 14" and shows a family eight weeks in a finished
   programme. */
$with = 0;
$acts_lib = (new ReflectionMethod('BFTD_Sample', 'sample_activities'));
$acts_lib->setAccessible(true);

$reached = array();
foreach ($acts_lib->invoke(null) as $a) foreach ((array) $a['skills'] as $sid) $reached[$sid] = true;
check(count($library) > count($reached),
  'and some still ahead of this child, got ' . count($reached) . ' of ' . count($library));

foreach ($acts_lib->invoke(null) as $a) { if (!empty($a['skills'])) $with++; }
check($with > 10, 'and the activities point at them, which is where the spiral comes from, got ' . $with);
foreach ($acts_lib->invoke(null) as $id => $a) {
  foreach ((array) $a['skills'] as $sid) {
    if (!isset($library[$sid])) { check(false, "activity $id points at skill $sid, which is not in the library"); break 2; }
  }
}
check($read >= 5, 'and the sessions carry what they read, got ' . $read);
check(!empty($pr['diagnostic']) && !empty($pr['posts'][$pr['diagnostic']]['sec-assessment-overview']['overview']),
  'the progress sample pulls from a diagnostic that has an overview to pull');

/* --- the sixteen mocked lessons behind the progress sample --- */
/* The coverage map, the session stream and the lesson counts are all built
   from lesson records. A sample with none of them shows a progress report
   with its middle missing, which is exactly the part a family reads. */
$lessons = isset($pr['sessions']) ? $pr['sessions'] : array();
check(count($lessons) === 17, 'the progress sample carries sixteen taught sessions and one that was moved, got ' . count($lessons));

/* The lessons point at the activity library, the way real ones do.
   A sample carrying typed names is a sample of a model the plugin no longer
   uses: the id is what lets an activity be renamed once and read correctly on
   every report that ever used it. (The family's report shows the name alone;
   the track and the number are how a tutor finds one in a library of two
   hundred and eighty.) */
$lib = new ReflectionMethod('BFTD_Sample', 'sample_activities'); $lib->setAccessible(true);
$library = $lib->invoke(null);

$dates = array(); $used = array(); $acts = 0; $stray = array();
foreach ($lessons as $sid => $l) {
  $dates[] = $l['session']['session_date'];
  // A session that did not go ahead taught nothing, so it has no activities.
  foreach (($l['session']['activities'] ?? array()) as $a) {
    $acts++;
    if (!isset($a['id']) || !isset($library[(int) $a['id']])) { $stray[] = json_encode($a); continue; }
    $used[(int) $a['id']] = true;
  }
}
check(count(array_unique($dates)) === 17, 'each session has its own date');
$sorted = $dates; sort($sorted);
check($sorted === $dates, 'and they are stored oldest first');
check($stray === array(), 'every activity on a lesson points at the library' . ($stray ? ': ' . implode(', ', $stray) : ''));
check(count($used) > 8, 'enough distinct activities to build a spiral, got ' . count($used));
check($acts > count($used), 'and they come back in later lessons, or the grid has no repeats');

/* The library reads the way the real one does: two tracks, each numbered from
   one, and every activity explained once. */
$tracks = array();
foreach ($library as $id => $one) {
  $tracks[$one['track']][] = $one['number'];
  check('' !== trim($one['about']), "every activity explains itself: {$one['name']}");
}
check(count($tracks) === 2, 'the sample library covers both tracks, got ' . count($tracks));
foreach ($tracks as $t => $numbers) {
  sort($numbers);
  check($numbers === range(1, count($numbers)), "$t is numbered from one with no gaps");
}

/* --- the report follows the prototype's shape --- */
/* The heading fields name the report in its hero; printing them again as
   rows underneath is the mistake this guards against. */
$ov = $sections['sec-assessment-overview']['fields'];
foreach (array('assessed_on','assessed_by','grade') as $k) {
  check(!empty($ov[$k]['card']), "$k is used in the heading, not printed as a row");
}
check(empty($ov['overview']['card']), 'while the written overview is the section itself');
check(empty($ov['headline']['card']), 'and so is the headline finding');

/* An explainer is a sentence, not a property. */
check(!empty($sections['sec-progress-overview']['fields']['intro']['lede']), 'a section explainer renders as a lede');

/* --- a summary card goes somewhere --- */
/* The card says "tap to see what it measures, how your child did, and what we
   will do about it". Its own panel is not drawn at all, so what that tap has
   to do is open the full section further down. Nothing errors when a control
   looks pressable and does nothing; it just quietly stops being worth
   pressing. */
$rjs = file_get_contents(BFTD_PATH . 'assets/js/bftd-report.js');
check(1 === preg_match('/closest\(\s*\x27\.skill-head\x27\s*\)/', $rjs), 'a tap on a card is caught on its own');
check(1 === preg_match('/skill-head[\s\S]{0,700}scrollIntoView/', $rjs), 'and takes the reader to the section');
check(1 === preg_match('/skill-head[\s\S]{0,700}classList\.add\(\s*\x27is-open\x27/', $rjs),
  'which is opened first, or the page lands on a closed box');

/* The card must not also be handled by the generic toggle, or it would open a
   panel the stylesheet never shows and swallow its own click. */
check(1 !== preg_match('/closest\(\s*\x27\.docsec-h,[^\x27]*\.skill-head/', $rjs),
  'and is not also treated as a plain open-and-close head');
$rcss = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');
check(false !== strpos($rcss, '.bf-report .skill > .acc{display:none}'),
  'which matters because that panel is deliberately not drawn');

/* --- one renderer draws a report section --- */
/* A second one is how a design drifts: both work, neither errors, and the
   two slowly stop agreeing. The dashboard had one left over after the report
   moved to its own view, complete with its own thread markup. */
$dash_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
check(false === strpos($dash_src, 'function render_section('),
  'the dashboard does not keep a second way to draw a report section');
check(1 === substr_count(file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php'), 'class="sec-thread"'),
  'and there is one place that draws a section conversation');

/* --- a section is a document, not a summary of one --- */
/* The sample exists to show a family what a finished report reads like. An
   abridged section teaches nobody anything, and it is the part of this that
   rots quietly: the renderer keeps working while the content thins out. */
$dx = BFTD_Sample::get('diagnostic');
foreach (array('dxsec-1','dxsec-2','dxsec-3','dxsec-4','dxsec-5','dxsec-6') as $sid) {
  $v = $dx['fields'][$sid];
  $schema = BFTD_Schema::section($sid);

  // A section's writing is what the sample supplies plus the standing
  // wording the schema owns. Where a section has been split so the practice
  // keeps the explanation and the tutor keeps the notes, the sample carries
  // only half of it, and counting only the sample would read as a section
  // that had been gutted.
  $standing = '';
  foreach ($schema['fields'] as $fk => $fd) {
    if (isset($v[$fk])) continue;
    if (isset($fd['shipped'])) $standing .= $fd['shipped'];
    elseif (isset($fd['default'])) $standing .= $fd['default'];
  }
  $body = ( isset($v['body']) ? $v['body'] : '' ) . $standing;
  $after = isset($v['body_after']) ? $v['body_after'] : '';
  // A checklist carries as much of a section as its prose does, so it counts
  // towards whether the section is written out or merely started.
  $list = '';
  foreach (array('conventions', 'challenges') as $k) {
    if (!isset($v[$k])) continue;
    foreach ($v[$k] as $row) $list .= ($row['item'] ?? $row['value'] ?? '') . ($row['note'] ?? '');
  }
  // So does a drawn table. A section that used to write its results out as
  // hand-typed HTML and now has them drawn from the schema carries exactly as
  // much for the parent to read; counting only the prose would report it as
  // having been gutted, when what changed is who types it.
  $drawn = '';
  foreach ($schema['fields'] as $fd) {
    if (!isset($fd['type']) || 'score_table' !== $fd['type']) continue;
    foreach ($fd['rows'] as $row) foreach ((array) $row as $cell) $drawn .= $cell;
    foreach ((array) $fd['head'] as $cell) $drawn .= $cell;
  }
  $whole = $body . $list . $after . $drawn;
  check(strlen($whole) > 1500, "$sid is written out in full, got " . strlen($whole) . ' characters');
  $body = $body . $after;
  $has_notes = false !== strpos($body, '<h3>Notes</h3>')
    || ( isset($schema['fields']['body_after']['heading']) && 'Notes' === $schema['fields']['body_after']['heading'] );
  check($has_notes, "$sid carries the tutor's notes");
  // Either written into the section's prose, or held as its own standing
  // field with the question as a heading.
  $explains = false !== stripos($body, 'Evidence-Based Literacy Instruction');
  foreach ($schema['fields'] as $fd) {
    if (isset($fd['heading']) && false !== stripos($fd['heading'], 'Evidence-Based Literacy Instruction')) $explains = true;
  }
  check($explains, "$sid explains how the method addresses it");
}

/* Five of the six sections show the work itself. Reading words aloud from a
   list is the one that leaves nothing behind to photograph. */
$shots = 0;
foreach ($dx['fields'] as $sid => $v) if (!empty($v['images'])) $shots++;
check(5 === $shots, 'five sections show a work sample, got ' . $shots);
check(!isset($dx['fields']['dxsec-3']['images']), 'and reading a word list aloud is not one of them');

/* The results tables are what a parent is actually being shown. A section
   either writes them into its body or declares them in the schema and has
   them drawn; what must never happen is a section with neither. */
$bare = array();
foreach ($sections_dx = BFTD_Schema::sections_for_screen(BFTD_Schema::SCREEN_REPORT) as $sid => $sec) {
  if (empty($sec['card'])) continue;                       // the six assessment areas only
  $written = 0;
  foreach (array('body','body_after') as $bk) {
    if (isset($dx['fields'][$sid][$bk])) $written += substr_count($dx['fields'][$sid][$bk], '<table');
  }
  $drawn = 0;
  foreach ($sec['fields'] as $f) {
    if (isset($f['type']) && in_array($f['type'], array('score_table','criteria','rows','figures','checklist'), true)) $drawn++;
  }
  if (!$written && !$drawn) $bare[] = $sid;
}
check($bare === array(), 'every assessment area shows its results'
  . ($bare ? ': ' . implode(', ', $bare) . ' show none' : ''));

/* Both sections' lists are fixed now, so the sample answers them rather than
   writing them out. The heading row that used to divide the writing list is a
   second list of its own, which is what it always was. */
check(count($dx['fields']['dxsec-6']['conventions']) >= 8, 'the writing conventions are all answered');
check(count($dx['fields']['dxsec-6']['grammar']) >= 8, 'and so is the grammar list');
check(count($dx['fields']['dxsec-5']['challenges']) >= 6, 'and the spelling challenges');
foreach (array('dxsec-6' => array('conventions', 'grammar'), 'dxsec-5' => array('challenges')) as $sid => $keys) {
  foreach ($keys as $key) {
    foreach ($dx['fields'][$sid][$key] as $rid => $row) {
      check(isset($sections[$sid]['fields'][$key]['rows'][$rid]),
        "the sample answers a row the schema declares: $sid.$key.$rid");
    }
  }
}

/* The checklists are drawn as the design draws them, not as a table. */
$sections = BFTD_Schema::sections();
/* Section 5's challenges are a fixed list now, so they are checked in
   tests/spelling.php. Section 6 still has rows a tutor writes. */
/* Both sections' lists are fixed and are checked in their own files. What is
   held here is that neither went back to rows a tutor types out. */
foreach (array('dxsec-5' => 'challenges', 'dxsec-6' => 'conventions', 'dxsec-6' => 'grammar') as $sid => $key) {
  check('checklist' === $sections[$sid]['fields'][$key]['type'],
    "$sid.$key is a fixed list, not rows somebody retypes");
}

/* --- every section a family can ask about carries somewhere to ask --- */
/* A question belongs where it was asked. A thread at the bottom of the report
   means a parent reading about spelling has to remember which paragraph they
   were on by the time they reach the box. */
$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(false !== strpos($view, 'class="sec-thread"'), 'a section draws the conversation the design gives it');
check(3 === substr_count($view, 'self::thread_for('), 'and every place that draws a section asks for one');
// Asking for one and drawing it are two different things, and only the
// second one a family can see.
check(1 === preg_match('/function docsec\(.*?self::thread\(/s', $view), 'and a section draws the one it was handed');
check(false !== strpos($view, "empty( \$section['thread'] )"), 'which sections take one is a schema question');

/* It has to be there in a preview too, or the preview is not of this report. */
check(false !== strpos($view, "BFTD_Preview::active()"), 'the thread knows it is being previewed');
check(false !== strpos($view, 'data-preview="1"'), 'and says so, so nothing can post from it');

/* Mocked conversations in the sample, because an empty box shows the feature
   without showing the reason for it. */
foreach (array('diagnostic', 'progress') as $which) {
  $s = BFTD_Sample::get($which);
  $threads = isset($s['threads']) ? $s['threads'] : array();
  check(count($threads) >= 2, "the $which sample mocks conversations, got " . count($threads));
  $msgs = 0; $replies = 0;
  foreach ($threads as $t) foreach ($t as $m) {
    $msgs++;
    if (empty($m['mine'])) $replies++;
    check(trim($m['body']) !== '' && trim($m['name']) !== '', 'every mocked message has a name and something to say');
  }
  check($msgs >= 4, "and enough of them to read as a conversation, got $msgs");
  check($replies > 0, 'with the tutor answering, not just the family asking');
}

/* --- priority before review, on every report --- */
/* One list is what has to happen before the next lesson and the other is what
   to look over when there is time. A family reading top to bottom meets the
   urgent one first, and that order is not something two call sites get to
   disagree about. */
$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
$dash = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');

foreach (array('class-bftd-report-view.php' => $view, 'class-bftd-dashboard.php' => $dash) as $file => $src) {
  // Only the calls that actually draw a card; a mention of the constant
  // elsewhere in the file says nothing about order.
  preg_match_all('/(?:items_card|render_card)\([^;]*BFTD_Items::(PRIORITY|REVIEW)/', $src, $m);
  $drawn = $m[1];
  check(count($drawn) >= 2 && 0 === count($drawn) % 2,
    "$file draws the two lists in pairs, got " . count($drawn));
  $wrong = array();
  for ($i = 0; $i + 1 < count($drawn); $i += 2) {
    if ('PRIORITY' !== $drawn[$i] || 'REVIEW' !== $drawn[$i + 1]) $wrong[] = $i;
  }
  check($wrong === array(), "$file draws priority items before for review");
}

/* --- the report markup is the design's own --- */
/* The renderer and the stylesheet are the prototype's; if the class names
   drift apart, the design silently stops applying and nothing errors. */
$css  = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');

foreach (array('hero-report','pi-card','banner','docsec','docsec-h','skills','skill-head',
               'skill-fig','track','pill','chart-card','chart-head','kpis','kpit','jump',
               'page-head','acc','acc-in') as $c) {
  // The class can sit anywhere in a class attribute, alone or beside others.
  $in_view = 1 === preg_match('/class="[^"]*\b' . preg_quote($c, '/') . '\b/', $view);
  $in_css  = false !== strpos($css, '.bf-report .' . $c);
  check($in_view && $in_css, "$c is in both the renderer and the design's stylesheet");
}

/* Everything the stylesheet collapses, and that the renderer actually puts on
   the page, must be something the script can open. A chevron that does nothing
   is worse than no chevron, and nothing errors when the two drift apart. */
$js  = file_get_contents(BFTD_PATH . 'assets/js/bftd-report.js');
$dash = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
$markup = $view . $dash;

// Every class this renderer actually puts on the page, as whole tokens. A
// prefix match would let ai-empty stand in for .ai, which is a different
// component entirely.
// A class attribute is often part literal, part PHP. Dropping the PHP
// leaves the literal tokens, which is what the browser ends up seeing.
$literal = preg_replace('/<\?php.*?\?>/s', ' ', $markup);
preg_match_all('/class="([^"]*)"/', $literal, $mc);
$drawn = array();
foreach ($mc[1] as $attr) {
  foreach (preg_split('/\s+/', $attr) as $one) if ('' !== $one) $drawn[$one] = true;
}

// What the stylesheet collapses, and the renderer draws, has to be something
// the script can open. A chevron that does nothing is worse than no chevron,
// and nothing errors when the markup and the script drift apart.
preg_match_all('/\.bf-report \.([a-z-]+)\.is-open/', $css, $m);
$dead = array(); $live = 0;
foreach (array_unique($m[1]) as $c) {
  if (!isset($drawn[$c])) continue;   // prototype chrome we never draw
  $live++;
  // Either the box itself, or the head that toggles it, has to be a selector
  // the script listens for.
  $ok = false;
  foreach (array($c, $c . '-head', $c . '-h') as $sel) {
    if (preg_match('/(^|[\s,\x27"])\.' . preg_quote($sel, '/') . '([\s,\x27"]|$)/', $js)) { $ok = true; break; }
  }
  if (!$ok) $dead[] = $c;
}
check($live >= 4, 'the report collapses several things, found ' . $live);
check($dead === array(), 'and each of those can be opened' . ($dead ? ': ' . implode(', ', $dead) : ''));

/* Accordions nest: a point's detail sits inside a card that is itself an
   accordion. A descendant selector on the outer one opens the inner ones too,
   which turns a tidy list of points into a wall of text and looks like the
   page simply ignored the design. */
$loose = array();
foreach (explode("\n", $css) as $line) {
  if (false === strpos($line, '.acc{grid-template-rows:1fr}')) continue;
  if (false !== strpos($line, ':not(.has-js)')) continue;   // no-JS ships open, on purpose
  $head = trim(substr($line, 0, strpos($line, '{')));
  foreach (explode(',', $head) as $sel) {
    $sel = trim($sel);
    // An opener naming a container that holds nested accordions has to say
    // which .acc it means.
    if (preg_match('/\.(pi-card|pi-item)\b/', $sel)
        && false === strpos($sel, '> .acc') && false === strpos($sel, '+ .acc')) {
      $loose[] = $sel;
    }
  }
}
check($loose === array(), 'a card opens its own accordion, not every one inside it'
  . ($loose ? ': ' . implode(' / ', $loose) : ''));

/* All caps is not a convention this business uses anywhere. It is easy for it
   to creep back in with a lifted stylesheet, and nothing errors when it does. */
$shouty = array();
foreach (glob(BFTD_PATH . 'assets/css/*.css') as $f) {
  if (false !== strpos(file_get_contents($f), 'uppercase')) $shouty[] = basename($f);
}
check($shouty === array(), 'no stylesheet shouts at anybody' . ($shouty ? ': ' . implode(', ', $shouty) : ''));

/* ---- a preview says what it cannot show ---------------------------------
   A preview shows the record. On a draft that is the tutor's current work,
   because a draft autosaves for real. On a published report it is what the
   family can open, and anything typed since is held to one side rather than
   written, so the preview cannot show it. Saying nothing leaves somebody
   deciding their edits were lost. */
$prev = file_get_contents(BFTD_PATH . 'includes/class-bftd-preview.php');
check(1 === preg_match('/BFTD_Autosave::unsaved\( \$post \)/', $prev),
  'a preview knows when there is work it is not showing');
check(1 === preg_match('/not saved yet[\s\S]{0,140}the family can open/', $prev),
  'and says so rather than looking as though the edits vanished');
check(1 === preg_match("/\\\$meta\['unsaved'\][\s\S]{0,120}bfp-warn/", $prev),
  'where the person can see it, beside the other things a preview warns about');

/* ---- jQuery helpers that no longer exist ----------------------------------
   $.trim was removed in jQuery 4. WordPress still ships 3.x, where it exists
   but is deprecated, so a call to it works right up until it does not, and the
   failure is silent: the handler throws, the feature does nothing, and nothing
   on the screen says so. Sixteen of them were sitting in the admin script,
   found by running it against jQuery 4 rather than by reading it.

   The others here were removed in 3.0 and are the same kind of trap. */
$gone = array('$.trim(', '$.isArray(', '$.isFunction(', '$.isNumeric(', '$.now(',
              '$.parseJSON(', '.andSelf(', '.size()', '$.type(');
foreach (glob(BFTD_PATH . 'assets/js/*.js') as $f) {
  $src = file_get_contents($f);
  foreach ($gone as $call) {
    check(false === strpos($src, $call),
      basename($f) . ' does not call ' . rtrim($call, '(') . ', which jQuery has removed');
  }
}

/* The stylesheet must not leak past its wrapper. That rule is checked in
   tests/report-styling.php, which owns it along with the rest of how the report
   is styled. It used to be checked here as well, by a second parser that read
   the file a line at a time; when a selector was first written across several
   lines, that parser called each continuation an unscoped rule and this test
   went red on correct code. Two checks of one rule is one too many, and the
   weaker one is not a safety net, it is a false alarm waiting to be silenced. */

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
