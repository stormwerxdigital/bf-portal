<?php
/*
 * The report is styled wherever it is rendered.
 *
 * This test exists because of a bug that survived three versions. The report
 * markup is the prototype's, and every selector in bftd-report.css is prefixed
 * .bf-report, so the stylesheet only ever matches inside that wrapper. The
 * wrapper was emitted by the preview template rather than by the renderer, and
 * the portal enqueued neither the stylesheet nor the script. The result was a
 * preview that looked perfect and a family portal that showed the same report
 * as unstyled browser defaults, with every accordion dead.
 *
 * Nothing about that failure was visible from the back end, which is the whole
 * problem with it: the person who could see it was the parent, and the people
 * checking were looking at the preview.
 *
 * So the rules held here are the ones that would have caught it:
 *   - the renderer carries its own scope, so it cannot be called somewhere
 *     that forgot the wrapper;
 *   - exactly one place emits that wrapper, so the two cannot nest or drift;
 *   - every surface that renders a report loads both of the report's assets;
 *   - the stylesheet stays scoped, which is what makes it safe to load on a
 *     page the theme also owns.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
define('BFTD_URL', 'https://example.test/wp-content/plugins/bf-tutoring-dashboard/');
define('BFTD_VERSION', 'test');
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-report-view.php';
require BFTD_PATH . 'includes/class-bftd-dashboard.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$view      = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
$dashboard = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
$preview   = file_get_contents(BFTD_PATH . 'includes/class-bftd-preview.php');
$main      = file_get_contents(BFTD_PATH . 'bf-tutoring-dashboard.php');
$css       = file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');

/* ------------------------------------------------------------------ */
/* 1. The renderer brings its own wrapper                              */
/* ------------------------------------------------------------------ */

/* Inside render(), before it hands off to either report type. */
$render = '';
if (preg_match('/function render\(.*?\n\t\}/s', $view, $m)) $render = $m[0];
check('' !== $render, 'the renderer has a render() entry point');
check(1 === preg_match('/class="bf-report"/', $render),
  'render() opens the .bf-report wrapper itself');
check(false !== strpos($render, "echo '</div>'"),
  'and closes it again');

/* It must wrap both report types, not just one. */
$before_dx = strpos($render, 'bf-report');
check(false !== $before_dx
      && $before_dx < strpos($render, 'self::diagnostic')
      && $before_dx < strpos($render, 'self::progress'),
  'the wrapper opens before either kind of report is drawn');

/* ------------------------------------------------------------------ */
/* 2. Exactly one place emits it                                       */
/* ------------------------------------------------------------------ */

$emitters = array();
foreach (glob(BFTD_PATH . 'includes/*.php') as $f) {
  $body = file_get_contents($f);
  if (preg_match('/class="bf-report"/', $body)) $emitters[] = basename($f);
}
check(array('class-bftd-report-view.php') === $emitters,
  'the wrapper is emitted by the renderer and nowhere else: ' . implode(', ', $emitters));

/* ------------------------------------------------------------------ */
/* 3. Every surface that renders a report loads the report's assets    */
/* ------------------------------------------------------------------ */

/* The portal's assets are not read out of the file as text. A commented-out
   enqueue, an early return, or the wrong hook all leave the text in place and
   the stylesheet off the page, which is close to how this shipped broken in
   the first place. So the method is run, against stubs that record what it
   asked WordPress for, and the record is what is checked. */
/* A stylesheet and a script may share a handle, so their dependencies are
   recorded apart. Keeping one map let the script's empty list overwrite the
   stylesheet's and quietly turn the ordering check green. */
$GLOBALS['ENQ'] = array( 'style' => array(), 'script' => array(), 'sdeps' => array(), 'jdeps' => array() );
function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false, $m = 'all' ) {
  $GLOBALS['ENQ']['style'][ $h ] = $src;
  $GLOBALS['ENQ']['sdeps'][ $h ] = (array) $deps;
}
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $foot = false ) {
  $GLOBALS['ENQ']['script'][ $h ] = $src;
  $GLOBALS['ENQ']['jdeps'][ $h ]  = (array) $deps;
}
function wp_localize_script() {}
function wp_create_nonce( $a = '' ) { return 'nonce'; }
function is_singular() { return true; }
function get_the_ID() { return 7; }

$GLOBALS['OPT'][ BFTD_Dashboard::OPTION_PAGE ] = 7;   // we are on the portal page
BFTD_Dashboard::assets();
$enq = $GLOBALS['ENQ'];

check( isset( $enq['style']['bftd-report'] )
       && false !== strpos( $enq['style']['bftd-report'], 'assets/css/bftd-report.css' ),
  'the portal really enqueues bftd-report.css' );
check( isset( $enq['script']['bftd-report'] )
       && false !== strpos( $enq['script']['bftd-report'], 'assets/js/bftd-report.js' ),
  'the portal really enqueues bftd-report.js' );
check( isset( $enq['style']['bftd-fonts'] ), 'and the fonts the design is set in' );
check( isset( $enq['style']['bftd-portal'] ), 'alongside the portal\'s own stylesheet' );

/* Order matters: the report sheet has to come after the portal's own, so it
   wins where both touch an element. A dependency is how that is stated. */
check( in_array( 'bftd-portal', $enq['sdeps']['bftd-report'], true ),
  'bftd-report.css depends on bftd-portal.css, so it is loaded after it' );

/* Off the portal page it must stay off: the report stylesheet has no business
   on the rest of bftutoring.com. */
$GLOBALS['ENQ'] = array( 'style' => array(), 'script' => array(), 'sdeps' => array(), 'jdeps' => array() );
$GLOBALS['OPT'][ BFTD_Dashboard::OPTION_PAGE ] = 99;  // some other page
BFTD_Dashboard::assets();
check( array() === $GLOBALS['ENQ']['style'],
  'and loads none of it on the rest of the site' );

/* The preview, as plain tags on a standalone page. */
check(false !== strpos($preview, 'bftd-report.css'), 'the preview links bftd-report.css');
check(false !== strpos($preview, 'bftd-report.js'),  'the preview links bftd-report.js');

/* Both must be the same two files. A preview styled by something the portal
   does not load is the exact lie this test exists to stop. */
$preview_assets = preg_match_all("#assets/(css|js)/bftd-report\\.(css|js)#", $preview);
check(2 === $preview_assets,
  'the preview and the portal load the same two report assets');

/* ------------------------------------------------------------------ */
/* 4. One font list, not three                                         */
/* ------------------------------------------------------------------ */

check(1 === preg_match('/function fonts_url\(\)/', $view),
  'the font list has one definition');
$hardcoded = 0;
foreach (array($dashboard, $preview, $main) as $f) {
  /* A preconnect hint is fine. A second copy of the family list is not. */
  $hardcoded += preg_match_all('#googleapis\.com/css2\?family#', $f);
}
check(0 === $hardcoded,
  'and no screen keeps a second copy of it');
foreach (array('Didact\+Gothic' => 'the alphabet face the writing section is set in',
               'Raleway'       => 'the display face',
               'Source\+Sans'  => 'the body face',
               'IBM\+Plex\+Mono' => 'the figures face') as $fam => $what) {
  check(1 === preg_match('/' . $fam . '/', $view), "the shared list asks for $what");
}

/* ------------------------------------------------------------------ */
/* 5. The stylesheet stays scoped                                      */
/* ------------------------------------------------------------------ */

/* This is what makes it safe to load on a page the theme also owns. A single
   unscoped rule here would restyle bftutoring.com, not the report. */
$stripped = preg_replace('#/\*.*?\*/#s', '', $css);

/* Selectors may run over several lines, and a parser that reads line by line
   would call each continuation an unscoped rule of its own. Collapse first,
   then split on the braces. */
$flat = preg_replace('/\s+/', ' ', $stripped);
preg_match_all('/([^{}]+)\{[^{}]*\}/', $flat, $m);
$loose = array();
foreach ($m[1] as $sel) {
  $sel = trim($sel);
  /* Skip the at-rule preambles the collapse leaves attached. */
  $sel = preg_replace('/^.*\}\s*/', '', $sel);
  $sel = preg_replace('/^@[^{]*\{\s*/', '', $sel);
  if ('' === $sel || '@' === substr($sel, 0, 1)) continue;
  foreach (array_map('trim', preg_split('/,(?![^(]*\))/', $sel)) as $one) {
    if ('' === $one) continue;
    if (0 === strpos($one, '.bf-report')) continue;
    if ('from' === $one || 'to' === $one || preg_match('/^\d+%$/', $one)) continue;
    $loose[] = $one;
  }
}
check(array() === $loose,
  'every selector is scoped under .bf-report: ' . implode(' | ', array_slice($loose, 0, 5)));

/* Nothing in it may reach outward from the wrapper either. */
check(0 === preg_match('/\.bf-report\s*:has|body\s*:has/', $stripped),
  'and nothing reaches back out at the page around it');

/* ------------------------------------------------------------------ */
/* 6. The site theme is held at the wrapper                            */
/* ------------------------------------------------------------------ */

/* bftutoring.com runs a block theme, and a block theme styles bare elements:
   p, h1..h6, a, li, td and the rest carry its font, line height and letter
   spacing. Those are element selectors, so they beat plain inheritance from
   the wrapper and land inside anything this plugin draws. Measured against the
   live site, one diagnostic came out 296px taller, set in Helvetica, with
   1.6px of letter spacing on every paragraph.

   Both stylesheets carry the same block, each scoped to its own wrapper. What
   is held here is the part that is easy to get subtly wrong and invisible when
   it is: the zero specificity, and the two copies staying the same shape. */

$pcss = file_get_contents(BFTD_PATH . 'assets/css/bftd-portal.css');

$resets = array();
foreach (array('bf-report' => $css, 'bf-portal' => $pcss) as $scope => $sheet) {
  preg_match_all('/\.' . $scope . '\s+:where\(([^)]*)\)\s*\{([^}]*)\}/s', $sheet, $m, PREG_SET_ORDER);
  check(count($m) >= 3, "the $scope wrapper holds the theme off its contents");
  $props = array(); $tags = array();
  foreach ($m as $one) {
    foreach (explode(',', $one[1]) as $t) { $t = trim($t); if ('' !== $t) $tags[$t] = true; }
    foreach (explode(';', $one[2]) as $d) {
      $d = trim($d); if ('' === $d) continue;
      list($k, $v) = array_pad(explode(':', $d, 2), 2, '');
      $props[trim($k)] = true;
      /* The value has to restore inheritance, not impose a value of its own.
         A reset that picks a font is a second theme. */
      check(in_array(trim($v), array('inherit', 'none'), true),
        "$scope reset gives back " . trim($k) . ", it does not set it");
    }
  }
  $resets[$scope] = array('props' => array_keys($props), 'tags' => array_keys($tags));
}

/* The four properties are the entire measured footprint of the live theme.
   Anything more is guesswork, and guesswork in a reset is its own bug. */
sort($resets['bf-report']['props']);
check(array('font-family','font-size','letter-spacing','line-height','max-width')
      === $resets['bf-report']['props'],
  'and resets exactly the properties the theme was measured to take over');

/* Two copies, one shape. They are two files because of load order, not because
   they are allowed to differ. */
sort($resets['bf-portal']['props']);
check($resets['bf-report']['props'] === $resets['bf-portal']['props'],
  'the portal and the report reset the same properties');
sort($resets['bf-report']['tags']); sort($resets['bf-portal']['tags']);
check($resets['bf-report']['tags'] === $resets['bf-portal']['tags'],
  'and the same elements');
check(count($resets['bf-report']['tags']) > 30,
  'covering every element the theme was measured to style');

/* :where() is what makes this a reset rather than a takeover. Without it the
   block outranks the design's own single-class rules, and the first thing that
   goes is the brand mark's typeface. */
foreach (array('bf-report' => $css, 'bf-portal' => $pcss) as $scope => $sheet) {
  /* Find the reset by what it does, then look at how it is addressed. Every
     rule that gives an inherited property back must do it from inside
     :where(), or it is outranking the design rather than the theme. */
  preg_match_all('/([^{}]+)\{([^}]*letter-spacing\s*:\s*inherit[^}]*)\}/s', $sheet, $r, PREG_SET_ORDER);
  check(count($r) >= 3, "the $scope reset was found by what it does");
  $loose = 0;
  foreach ($r as $one) if (false === strpos($one[1], ':where(')) $loose++;
  check(0 === $loose, "the $scope reset carries no specificity of its own");
}

/* Load order. The portal's copy has to come before the rules it must not beat;
   the report's has to come before its own. */
/* Measured on the rules, not on the prose. The comment above each block names
   :where() too, and a check that found the comment would go on passing after
   the rule itself moved to the bottom of the file. */
foreach (array('bf-report' => $css, 'bf-portal' => $pcss) as $scope => $sheet) {
  $bare = preg_replace('#/\*.*?\*/#s', '', $sheet);
  $bare = preg_replace('/\s+/', ' ', $bare);
  preg_match_all('/([^{}]+)\{/', $bare, $sel);
  $order = array();
  foreach ($sel[1] as $one) { $one = trim($one); if ('' !== $one) $order[] = $one; }
  check(false !== strpos($bare, '.' . $scope . ' :where('),
    "the $scope reset is a rule, not only a comment");
  /* The design's own rules must be able to outrank it, and the reset weighs
     exactly one class, so any rule that also weighs exactly one class and can
     match the same element is a tie the reset has to lose. A tie is decided by
     order, so every such rule must come after it.

     The wrapper's own rules are not ties: .bf-report styles the wrapper, and
     the reset only ever matches things inside it. */
  $reset_at = null; $early = array();
  foreach ($order as $n => $one) {
    if (false !== strpos($one, '.' . $scope . ' :where(')) { if (null === $reset_at) $reset_at = $n; continue; }
    if (null !== $reset_at) continue;
    foreach (array_map('trim', preg_split('/,(?![^(]*\))/', $one)) as $part) {
      /* A rule of exactly one class, which is not the wrapper itself. */
      if (preg_match('/^\.[A-Za-z0-9_-]+$/', $part) && '.' . $scope !== $part) $early[] = $part;
    }
  }
  check(null !== $reset_at, "the $scope reset appears among that stylesheet's rules");
  check(array() === $early,
    "nothing the $scope reset would tie with is written above it: " . implode(', ', array_slice($early, 0, 4)));
}

/* ------------------------------------------------------------------ */
/* 7. The theme is not asked to guess the colour scheme                */
/* ------------------------------------------------------------------ */

/* The design has a dark mode; the portal around it does not. A report that
   went dark inside a light page would read as a fault. */
check(1 === preg_match('/data-theme="light"/', $render),
  'the report is pinned to the light scheme the portal is built in');

exit($fail ? 1 : 0);
