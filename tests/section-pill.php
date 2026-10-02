<?php
/*
 * What the header pill promises a tutor.
 *
 * The pill is the one place in the editor that says whether the family can
 * see a thing, and a tutor decides whether to keep working on the strength
 * of it. So it has to answer the question the family's side actually asks,
 * and the portal only ever serves a published report: BFTD_CPT::report_for
 * queries post_status 'publish' and nothing else.
 *
 * A draft with a full section is therefore not live. It said "Live for the
 * family" on an unpublished report, which reads as "they have already seen
 * this" over work nobody outside the practice can reach.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
function get_post_meta($id, $k, $single = false) { return $GLOBALS['META'][$id][$k] ?? ($single ? '' : array()); }
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$sid = 'dxsec-2';
$note = BFTD_Schema::meta_key($sid, 'body_after');
$vis  = BFTD_Fields::visibility_key($sid);

/* A draft and a published report, each with the same section filled in the
 * same way. Only the status differs. */
$GLOBALS['META'] = array(
  10 => array($note => 'Kaine heard 30 of the 36 sounds.'),
  11 => array($note => 'Kaine heard 30 of the 36 sounds.'),
  12 => array($note => 'Kaine heard 30 of the 36 sounds.', $vis => '0'),
  13 => array(),
);
$GLOBALS['STATUS'] = array(10 => 'draft', 11 => 'publish', 12 => 'publish', 13 => 'publish');

$draft = BFTD_Fields::pill_state(10, $sid);
check('Live for the family' !== $draft['text'], 'a draft does not claim the family can see it');
check('Not live' === $draft['text'], 'it says plainly that it is not live');
check('purple' === $draft['tone'], 'and it is not wearing the live colour');

$live = BFTD_Fields::pill_state(11, $sid);
check('Live for the family' === $live['text'], 'the same section on a published report is live');
check('sage' === $live['tone'], 'and wears the live colour');

/* Order matters: a hidden section is hidden whatever the status, and an
 * empty one is empty. Neither should be overtaken by the status answer. */
$hidden = BFTD_Fields::pill_state(12, $sid);
check('Hidden from the family' === $hidden['text'], 'switching a section off still reads as hidden');
$empty = BFTD_Fields::pill_state(13, $sid);
check('Empty, so not shown to the family' === $empty['text'], 'and an untouched section still reads as empty');

/* Every status the portal will not serve reads the same way from the
 * family's side of the glass. */
foreach (array('draft', 'pending', 'private', 'auto-draft') as $status) {
  $GLOBALS['STATUS'][10] = $status;
  $p = BFTD_Fields::pill_state(10, $sid);
  check('Not live' === $p['text'], "$status is not live");
  check(!BFTD_Fields::reaches_family(10), "$status does not reach a family");
}
foreach (array('publish', 'future') as $status) {
  $GLOBALS['STATUS'][10] = $status;
  check(BFTD_Fields::reaches_family(10), "$status does reach a family, now or on the clock");
}

/* The rule is asked, not assumed: the pill has to consult the status. */
$src = '';
foreach (token_get_all(file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php')) as $t) {
  if (is_array($t)) { if (T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0]) continue; $src .= $t[1]; }
  else { $src .= $t; }
}
check(1 === preg_match('/function pill_state\(.*?reaches_family\(/s', $src),
  'pill_state consults the record status rather than assuming it');

/* And the portal really is publish-only, which is the whole premise. */
$cpt = file_get_contents(BFTD_PATH . 'includes/class-bftd-cpt.php');
check(1 === preg_match("/function report_for\(.*?'post_status'\s*=>\s*'publish'/s", $cpt),
  'the family is served published reports only, which is why the pill must care');

/* A value the schema supplies is not somebody's work.
 *
 * Section two's denominator is a fixed 36. An untouched section therefore
 * had content, was not empty, and its pill said the family would see it: a
 * report with "/36" and nothing else in it. */
$GLOBALS['META'][14] = array();
$GLOBALS['STATUS'][14] = 'publish';
check(!BFTD_Fields::section_has_content(14, 'dxsec-2'),
  'a fixed denominator is not a started section');
$p14 = BFTD_Fields::pill_state(14, 'dxsec-2');
check('Empty, so not shown to the family' === $p14['text'],
  'so an untouched hearing-sounds section reads as empty');

foreach (array_keys(BFTD_Schema::sections()) as $any) {
  if (0 !== strpos($any, 'dxsec-')) continue;
  check(!BFTD_Fields::section_has_content(14, $any), "$any starts empty on a blank report");
}

/* One typed figure is enough to start it. */
$GLOBALS['META'][14][BFTD_Schema::meta_key('dxsec-2', 'score')] = '30';
check(BFTD_Fields::section_has_content(14, 'dxsec-2'),
  'and a score somebody entered does start it');

/* Four states, four colours.
 *
 * The pills all sat on violet-tinted surfaces, so the empty chip read as a
 * paler version of the purple one next to it rather than as its own state.
 * A tutor scanning six panels is reading colour before wording. */
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
preg_match_all('/\.bftd-pill-([a-z]+)\{([^}]*)\}/', $css, $m, PREG_SET_ORDER);
$tones = array();
foreach ($m as $hit) { $tones[$hit[1]] = $hit[2]; }
foreach (array('sage', 'clay', 'quiet', 'purple') as $tone) {
  check(isset($tones[$tone]), "the $tone pill has a colour of its own");
}
check(4 === count($tones), 'and there are exactly the four states');

$backs = array();
foreach ($tones as $tone => $rule) {
  preg_match('/background:\s*([^;]+)/', $rule, $b);
  $backs[$tone] = trim($b[1]);
}
check(count(array_unique($backs)) === count($backs), 'no two states share a background');
check(false === strpos($backs['quiet'], 'line-soft') && false === strpos($backs['quiet'], 'purple'),
  'the empty chip is off the violet surfaces it used to share with the others');
check(false !== strpos($tones['quiet'], 'stone'), 'it wears the stone the palette keeps for "nothing yet"');
foreach (array('--bftd-stone-pale', '--bftd-stone-deep', '--bftd-stone-line') as $token) {
  check(1 === preg_match('/' . preg_quote($token, '/') . ':\s*#[0-9A-Fa-f]{6}/', $css),
    "$token is a named token, not a loose hex");
}

exit($fail ? 1 : 0);
