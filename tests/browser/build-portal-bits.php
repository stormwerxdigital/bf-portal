<?php
/*
 * The two pieces of the family's session block that depend on a script: the
 * folded activity description and the work image that opens full size.
 *
 * The markup here is what BFTD_Dashboard emits, taken from it rather than
 * invented, and the real bftd-portal.js is loaded over it. Both features ship
 * open and working without script, so what is being tested is the upgrade: a
 * fold that actually folds and a lightbox that actually opens. Both are the kind
 * of thing that can be written, shipped and do nothing.
 */
define('ABSPATH','/'); define('BFTD_PATH', dirname(dirname(__DIR__)) . '/');
function esc_attr($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_url($s){ return (string)$s; }
function wp_strip_all_tags($s){ return strip_tags((string)$s); }

/* The real gist helper, read off the class so the fixture cannot drift from it. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-dashboard.php');
preg_match('/\/\*\* Words that end in a stop.*?\n\t\}\n/s', $src, $a);
preg_match('/\/\*\*\s*\n\s*\* The first sentence of some text\..*?\n\t\}\n/s', $src, $b);
preg_match('/\/\*\* A gist longer than.*?\n\t\}\n/s', $src, $c);
eval('class G { ' . $a[0] . $b[0] . $c[0] . ' }');

$about = '<p>Sound Lines builds the link between a sound and its spelling. '
       . 'The child says the word, then writes each sound as they say it. '
       . 'It is the activity most of the early sequence is built on.</p>';
$gist  = G::first_sentence($about);

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
  . '<div class="bf-report"><div class="blk">'
  . '<div class="blk-h"><span class="blk-k">Activity:</span> Sound Lines</div>'
  . '<div class="doc-body about has-more" data-gist="' . esc_attr($gist) . '">' . $about . '</div>'
  . '</div>'
  . '<div class="blk blk-work"><div class="blk-h">Work from the session</div>'
  . '<div class="samples">'
  . '<figure class="shot"><a class="shot-open" href="full-one.png" aria-label="Open Kaine&#039;s spelling page at full size">'
  . '<img src="' . $thumb . '" alt="Kaine&#039;s spelling page" width="300" height="200"></a></figure>'
  . '</div></div></div>'
  . '<script>' . $jq . '</script><script>' . $js . '</script>';

file_put_contents(__DIR__ . '/portal-bits.html', $html);
echo __DIR__ . "/portal-bits.html\n";
