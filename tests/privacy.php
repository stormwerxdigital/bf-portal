<?php
/*
 * The portal privacy policy (Karl, 1.220.0, after a parent asked how student
 * data and recordings are protected): a draft page written once, a 30 day
 * recording window, and links that appear only once the policy is published.
 * Made-up records only.
 */
$OPT = array(); $META = array(); $POSTS = array(); $CAN = true; $NEXT = 100;
function update_option($k, $v, $a = null) { $GLOBALS['OPT'][$k] = $v; return true; }
function current_user_can($c) { return $GLOBALS['CAN']; }
function get_post($id) { return isset($GLOBALS['POSTS'][$id]) ? (object) $GLOBALS['POSTS'][$id] : null; }
function get_post_status($id) { return $GLOBALS['POSTS'][$id]['post_status'] ?? false; }
function get_permalink($id) { return 'https://example.test/' . $GLOBALS['POSTS'][$id]['post_name'] . '/'; }
function get_edit_post_link($id) { return '/wp-admin/post.php?post=' . $id; }
function get_post_meta($id, $k = '', $s = false) { return $GLOBALS['META'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function is_wp_error($x) { return false; }
function wp_update_post($a) { $GLOBALS['POSTS'][$a['ID']]['post_content'] = $a['post_content']; return $a['ID']; }
function date_i18n($f) { return 'November 25, 2026'; }
function wp_insert_post($a) {
  $id = $GLOBALS['NEXT']++;
  $GLOBALS['POSTS'][$id] = $a;
  foreach ($a['meta_input'] ?? array() as $k => $v) $GLOBALS['META'][$id][$k] = $v;
  return $id;
}
function get_posts($a) {
  $out = array();
  foreach ($GLOBALS['META'] as $id => $m) if (isset($m[$a['meta_key']]) && (string) $m[$a['meta_key']] === $a['meta_value']) $out[] = $id;
  return $out;
}
require __DIR__ . '/wp-stubs.php';
define('BFTD_VERSION', '1.220.0');
class BFTD_Settings { const OPTION_PORTAL = 'bftd_portal_settings'; }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_CPT { const STUDENT = 'bftd_student'; }
class BFTD_Dashboard { const SHORTCODE = 'bf_portal'; }
require dirname(__DIR__) . '/includes/class-bftd-privacy.php';

$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? '  ok ' : 'FAIL') . "  $msg\n"; if (!$ok) $fail++; }
$rec = '_bftd_student__recording_days';
$notice = function () { ob_start(); BFTD_Privacy::notice(); return ob_get_clean(); };

echo "Setting up once:\n";
$OPT['bftd_portal_settings'] = array('recording_days' => 14, 'welcome' => 'Hi');
$META[1][$rec] = '14'; $META[2][$rec] = '7'; $META[3]['other'] = 'x';
$CAN = false; BFTD_Privacy::maybe_setup();
check(!$POSTS && 14 === $OPT['bftd_portal_settings']['recording_days'], 'nothing happens for somebody who cannot manage the site');
$CAN = true; BFTD_Privacy::maybe_setup();
check(30 === $OPT['bftd_portal_settings']['recording_days'] && 'Hi' === $OPT['bftd_portal_settings']['welcome'], 'the portal recording window moves from 14 to 30 days, other settings kept');
check('30' === $META[1][$rec] && '7' === $META[2][$rec], 'a student on the old 14 days moves to 30; one set to 7 is left alone');
$id = (int) $OPT['wp_page_for_privacy_policy'];
check($id && 'draft' === $POSTS[$id]['post_status'] && 'Privacy policy' === $POSTS[$id]['post_title'], 'the policy is written as a draft page and set as the site privacy page');
BFTD_Privacy::maybe_setup();
check(1 === count($POSTS), 'it is written only once, so edits to it are never overwritten');

echo "\nWhile it is a draft:\n";
check('' === BFTD_Privacy::url(), 'there is no link to a draft');
check('<div>portal</div>' === BFTD_Privacy::portal_footer('<div>portal</div>', 'bf_portal'), 'the portal shows no privacy link yet');
check(false !== strpos($notice(), 'privacy policy is a draft') && false !== strpos($notice(), 'post=' . $id), 'administrators are reminded to review and publish it');
$CAN = false; check('' === $notice(), 'nobody else sees the reminder'); $CAN = true;

echo "\nOnce it is published:\n";
$POSTS[$id]['post_status'] = 'publish';
check(false !== strpos(BFTD_Privacy::portal_footer('<div>portal</div>', 'bf_portal'), 'href="https://example.test/privacy-policy/">Privacy policy</a>'), 'every portal screen links to it');
check('<b>x</b>' === BFTD_Privacy::portal_footer('<b>x</b>', 'other_shortcode'), 'other shortcodes are untouched');
check('' === $notice(), 'the reminder goes away');
$brand = file_get_contents(dirname(__DIR__) . '/includes/class-bftd-brand.php');
check(false === strpos($brand, 'privacy-policy-page-link{display:none}'), 'the sign-in screen no longer hides WordPress\'s privacy policy link');

echo "\nThe 1.220.0 draft loses its Google Fonts line (1.220.1):\n";
$gf = "<!-- wp:list-item -->\n<li><strong>Google Fonts:</strong> the lettering. Shares your address with Google.</li>\n<!-- /wp:list-item -->";
$keep = "<!-- wp:list-item -->\n<li><strong>Dropbox:</strong> session recordings.</li>\n<!-- /wp:list-item -->";
$old = '<ul>' . $keep . $gf . '</ul>';
$OPT = array('bftd_privacy_setup' => '1.220.0', 'wp_page_for_privacy_policy' => 60);
$POSTS = array(60 => array('post_status' => 'draft', 'post_name' => 'privacy-policy', 'post_content' => $old)); $META = array(60 => array('_bftd_written_by' => 'bftd_privacy'));
$CAN = false; BFTD_Privacy::maybe_setup();
check($old === $POSTS[60]['post_content'], 'not for somebody who cannot manage the site');
$CAN = true; BFTD_Privacy::maybe_setup();
check('<ul>' . $keep . '</ul>' === $POSTS[60]['post_content'], 'an administrator\'s next screen takes the line out and keeps the rest');
$POSTS[60]['post_content'] = $old; BFTD_Privacy::maybe_setup();
check($old === $POSTS[60]['post_content'], 'only once, so a line somebody puts back stays');
foreach (array('published' => array('post_status' => 'publish'), 'written by somebody else' => array('meta' => '')) as $what => $case) {
  $OPT = array('bftd_privacy_setup' => '1.220.0', 'wp_page_for_privacy_policy' => 60);
  $POSTS[60] = array('post_status' => $case['post_status'] ?? 'draft', 'post_name' => 'privacy-policy', 'post_content' => $old);
  $META[60]['_bftd_written_by'] = $case['meta'] ?? 'bftd_privacy';
  BFTD_Privacy::maybe_setup();
  check($old === $POSTS[60]['post_content'], "a policy that is $what is left as it is");
}
$OPT = array(); $POSTS = array(); $META = array(); BFTD_Privacy::maybe_setup();
check(!empty($OPT['bftd_privacy_fonts_dropped']) && false === strpos(end($POSTS)['post_content'], 'Google'), 'a new site\'s draft never names Google Fonts');

echo "\nA site that already has a published policy:\n";
$OPT = array('wp_page_for_privacy_policy' => 50); $POSTS = array(50 => array('post_status' => 'publish', 'post_name' => 'privacy'));
BFTD_Privacy::create_draft();
check(50 === $OPT['wp_page_for_privacy_policy'] && 2 === count($POSTS), 'the published one stays the privacy page; the draft sits beside it');

echo "\nThe wording:\n";
$c = BFTD_Privacy::content();
foreach (array('PIPEDA', '30 days after the session', 'Dropbox', 'GoHighLevel', 'AI writing tools', 'outside Canada', 'priv.gc.ca', 'within 30 days', 'do not sell') as $must) {
  check(false !== strpos($c, $must), "it covers: $must");
}
check(false === strpos($c, "\u{2014}") && false === strpos($c, '&mdash;'), 'no em dashes');
check(substr_count($c, '<!-- wp:heading -->') === substr_count($c, '<!-- /wp:heading -->') && substr_count($c, '<!-- wp:list -->') === substr_count($c, '<!-- /wp:list -->'), 'every editor block is closed');
preg_match_all('/<h2[^>]*>([^<]+)</', $c, $h);
check(count($h[1]) >= 10 && !array_filter($h[1], function ($t) { return preg_match('/ [A-Z]/', $t); }), 'headings are in sentence case');

echo "\nDefaults:\n";
$set = file_get_contents(dirname(__DIR__) . '/includes/class-bftd-settings.php');
$mb  = file_get_contents(dirname(__DIR__) . '/includes/class-bftd-metaboxes.php');
check(false !== strpos($set, "'recording_days' => 30,") && false !== strpos($mb, "in days', 'default' => '30'"), 'a new site or student starts on 30 days');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
