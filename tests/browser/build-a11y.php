<?php
/*
 * Two real screens with accessibility mode on: the students list and the
 * family progress report, each built by its own fixture, then given the
 * mode's body class, its stylesheet and the type sizes worked out from the
 * plugin's stylesheets, exactly as BFTD_Accessibility gives them to a page.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/'); define('BFTD_URL',''); define('BFTD_VERSION','test');
define('WEEK_IN_SECONDS', 604800);
function get_transient($k){ return false; } function set_transient($k,$v,$t){ return true; }
require BFTD_PATH . 'includes/class-bftd-accessibility.php';

$css   = file_get_contents(BFTD_PATH . 'assets/css/bftd-a11y.css');
$sizes = BFTD_Accessibility::sizes_css();
$add   = '<style id="bftd-a11y">' . $css . '</style><style id="bftd-a11y-sizes">' . $sizes . '</style>'
       . '<script>document.body.classList.add("bftd-a11y")</script>';

foreach (array('students' => 'build-students.php', 'report' => 'build-report.php') as $name => $builder) {
  exec('php ' . escapeshellarg(__DIR__ . '/' . $builder) . ' 2>&1', $o, $rc);
  if ($rc) { fwrite(STDERR, "$builder failed\n" . implode("\n", $o)); exit(1); }
  $html = file_get_contents(__DIR__ . "/$name.html");
  // An admin screen sits inside WordPress's #wpbody-content, which the
  // mode's rules for WordPress's own text are scoped to.
  if ('students' === $name) $html = preg_replace('/<div class="wrap/', '<div id="wpbody-content"><div class="wrap', $html, 1);
  file_put_contents(__DIR__ . "/a11y-$name.html", $html . $add);
}
echo "built\n";
