<?php
/*
 * Two pieces of the family's session block: the folded activity description
 * with the tutor's notes under it, and the work image that opens full size.
 *
 * The activity blocks are drawn by the real BFTD_Dashboard::activity_block(),
 * lifted off the class, so the fixture cannot drift from the page. The fold is
 * a details element and needs no script; the lightbox is still an upgrade made
 * by bftd-portal.js, loaded here as on the portal.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function wp_strip_all_tags($s){ return strip_tags((string)$s); }
function esc_html($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function wp_kses_post($s){ return (string)$s; }
/* Enough of wpautop: blank-line separated runs become paragraphs unless they
   already open with a block tag. */
function wpautop($t){
  $out = '';
  foreach (preg_split('/\n\s*\n/', trim((string)$t)) as $chunk) {
    $chunk = trim($chunk); if ('' === $chunk) continue;
    $out .= preg_match('/^<(p|h[1-6]|ul|ol|div|blockquote)\b/i', $chunk) ? $chunk . "\n" : '<p>' . $chunk . "</p>\n";
  }
  return $out;
}

/* The real gist helper, read off the class so the fixture cannot drift from it. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
preg_match('/\/\*\* Words that end in a stop.*?\n\t\}\n/s', $src, $a);
preg_match('/\/\*\*\s*\n\s*\* The first sentence of some text\..*?\n\t\}\n/s', $src, $b);
preg_match('/\/\*\* A gist longer than.*?\n\t\}\n/s', $src, $c);
preg_match('/\/\*\*\s*\n\s*\* One activity on a session:.*?\n\t\}\n/s', $src, $d);
preg_match('/\/\*\*\s*\n\s*\* The sentence a folded activity description shows.*?\n\t\}\n/s', $src, $e);
if (!$d || !$e) { fwrite(STDERR, "could not lift activity_block or about_gist\n"); exit(1); }
eval('class G { ' . $a[0] . $b[0] . $c[0] . $d[0] . $e[0] . ' }');

$about = '<p>Sound Lines builds the link between a sound and its spelling. '
       . 'The child says the word, then writes each sound as they say it. '
       . 'It is the activity most of the early sequence is built on.</p>';
$kat   = "<h3>Completed</h3>\n\n<ul><li>Story introduction</li><li>Read a story</li></ul>\n\n"
       . "<h3>KAT routine in progress</h3>\n\n<ol><li>Identify the text structure</li><li>Generate the main idea</li></ol>";
$katnote = 'Main idea expanded: completed the problem and the solution.';

/* A 1x1 png, drawn at thumbnail size, so the link really occupies space. */
$thumb = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
$jq  = file_get_contents(__DIR__ . '/jquery.js');
$js  = file_get_contents(BFTD_PATH . 'assets/js/bftd-portal.js');
/* Both stylesheets, in the order the portal enqueues them: bftd-report.css
   declares a dependency on bftd-portal.css and loads after it. A fixture with
   only one of them is a fixture testing rules that are not on the page. */
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-portal.css')
     . "\n" . file_get_contents(BFTD_PATH . 'assets/css/bftd-report.css');

$html = '<!doctype html><meta charset="utf-8">'
  . '<meta name="viewport" content="width=device-width, initial-scale=1">'
  . '<title>portal bits</title>'
  . '<style>' . $css . '</style>'
  . '<div class="bf-report">'
  . G::activity_block(array('name' => 'Sound Lines'), true, $about, '', array())
  . G::activity_block(array('name' => 'KAT Routine'), true, $kat, $katnote, array())
  . '<div class="blk blk-work"><div class="blk-h">Work from the session</div>'
  . '<div class="samples">'
  . '<figure class="shot"><a class="shot-open" href="full-one.png" aria-label="Open Kaine&#039;s spelling page at full size">'
  . '<img src="' . $thumb . '" alt="Kaine&#039;s spelling page" width="300" height="200"></a></figure>'
  . '</div></div></div>'
  . '<script>' . $jq . '</script><script>' . $js . '</script>';

file_put_contents(__DIR__ . '/portal-bits.html', $html);
echo __DIR__ . "/portal-bits.html\n";
