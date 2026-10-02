<?php
/*
 * A session is named, not titled.
 *
 * Left to a person it was "Wednesday", or the child's name on its own three
 * times over, and none of it was anything a list could be sorted or searched
 * by. So the field comes off the screen and the name is generated from the
 * two things that identify one session: who, and when.
 *
 * Three things have to be true for that to hold, and all three are the kind
 * that look fine until somebody edits something:
 *
 *   the name is right when the record is saved;
 *   it is right again when the date or the time moves;
 *   it is right on every session a child has when the child is renamed,
 *   which is the one nobody thinks of.
 *
 * And it must not write itself forever: the write is a post save, and a post
 * save is what triggers the write.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS']   = array();
$GLOBALS['META']    = array();
$GLOBALS['WRITES']  = 0;
$GLOBALS['WALKS']   = 0;

function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p ? $p->post_title : ''; }
function get_post_meta($id, $k, $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return null === $v ? ($s ? '' : array()) : ($s ? $v : array($v)); }
function is_admin() { return true; }
function get_current_user_id() { return 1; }
function wp_is_post_revision($id) { return false; }
function get_posts($a) {
  $GLOBALS['WALKS']++;
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (!empty($a['meta_query'])) {
      $q = $a['meta_query'][0];
      if ((string) ($GLOBALS['META'][$id][$q['key']] ?? '') !== (string) $q['value']) continue;
    }
    $out[] = $id;
  }
  return $out;
}
/* The real thing: writing a post fires the save hooks again. A stub that only
   set a string would let an endless loop pass. */
function wp_update_post($args) {
  $GLOBALS['WRITES']++;
  if ($GLOBALS['WRITES'] > 20) { echo "FAIL  runaway: the retitle wrote itself 20 times\n"; exit(1); }
  $id = (int) $args['ID'];
  // WordPress runs a title through wp_insert_post_data and the sanitize_post
  // filters on the way in, and a site with any of them hooked stores
  // something other than what was handed over. That is the case the guard
  // exists for: the generated name and the stored name never agree, so
  // "write it if it differs" differs forever.
  $GLOBALS['POSTS'][$id]->post_title = empty($GLOBALS['MANGLE'])
    ? $args['post_title']
    : str_replace(' · ', ' - ', $args['post_title']);
  BFTD_CPT::retitle_on_save($id, $GLOBALS['POSTS'][$id]);
  return $id;
}

class BFTD_Roles { public static function post_types() { return array('bftd_session'); } public static function plural_for($p) { return $p . 's'; } }
class BFTD_Access { public static function visible_student_ids($u) { return array(); } public static function can_staff_view(...$a) { return true; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Fields { public static function get($id, $g, $k, $f = array()) { return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? ''; } }
class BFTD_Schedule { public static function pretty_time($t) { list($h, $m) = array_pad(explode(':', $t), 2, '00'); $ap = $h < 12 ? 'am' : 'pm'; $h = (int) $h % 12; if (!$h) $h = 12; return $h . ':' . $m . ' ' . $ap; } }
require BFTD_PATH . 'includes/class-bftd-cpt.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$mk = function ($id, $type, $title, $meta = array()) {
  $GLOBALS['POSTS'][$id] = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => $title);
  $GLOBALS['META'][$id]  = $meta;
};

$mk(11, 'bftd_student', 'Kaine M');
$mk(40, 'bftd_session', 'Wednesday', array(
  '_bftd_student_id'             => 11,
  '_bftd_session__session_date'  => '2026-09-16',
  '_bftd_session__session_time'  => '09:00',
));

/* ---- what it says ---- */
$t = BFTD_CPT::session_title(40);
check(false !== strpos($t, 'Kaine M'), 'the name is the child, got ' . $t);
check(false !== strpos($t, '16 Sep 2026'), 'and the day of the session, got ' . $t);
check(false !== strpos($t, '9:00 am'), 'and the time, because two sessions in one day are otherwise one string');

/* ---- and when it says it ---- */
BFTD_CPT::retitle_on_save(40, $GLOBALS['POSTS'][40]);
check('Wednesday' !== $GLOBALS['POSTS'][40]->post_title, 'saving replaces whatever somebody typed');
check($t === $GLOBALS['POSTS'][40]->post_title, 'with exactly the generated name');

$before = $GLOBALS['WRITES'];
BFTD_CPT::retitle_on_save(40, $GLOBALS['POSTS'][40]);
check($before === $GLOBALS['WRITES'], 'a save that changes nothing writes nothing');

/* One write even when the stored title never agrees with the generated one.
   A site with anything hooked to the title filters stores something other
   than what was handed over, so "write it if it differs" differs forever and
   the write is itself a save. */
$GLOBALS['MANGLE'] = true;
$GLOBALS['WRITES'] = 0;
$GLOBALS['META'][40]['_bftd_session__session_date'] = '2026-09-17';
BFTD_CPT::retitle_on_save(40, $GLOBALS['POSTS'][40]);
check(1 === $GLOBALS['WRITES'], 'and writes once even where the site rewrites titles on save, got ' . $GLOBALS['WRITES']);
$GLOBALS['MANGLE'] = false;
$GLOBALS['META'][40]['_bftd_session__session_date'] = '2026-09-16';
BFTD_CPT::retitle_on_save(40, $GLOBALS['POSTS'][40]);

/* The write is a post save and a post save is what triggers the write. One
   write, not a stack overflow. */
$GLOBALS['META'][40]['_bftd_session__session_time'] = '16:00';
$GLOBALS['WRITES'] = 0;
BFTD_CPT::retitle_on_save(40, $GLOBALS['POSTS'][40]);
check(1 === $GLOBALS['WRITES'], 'moving the time rewrites the name once, got ' . $GLOBALS['WRITES']);
check(false !== strpos($GLOBALS['POSTS'][40]->post_title, '4:00 pm'), 'to the new time');

/* ---- the one nobody thinks of ---- */
$mk(41, 'bftd_session', '', array('_bftd_student_id' => 11, '_bftd_session__session_date' => '2026-09-23'));
BFTD_CPT::retitle_on_save(41, $GLOBALS['POSTS'][41]);

$was = (object) array('ID' => 11, 'post_type' => 'bftd_student', 'post_title' => 'Kaine M');
$GLOBALS['POSTS'][11]->post_title = 'Kaine Mackenzie';
BFTD_CPT::student_renamed(11, $GLOBALS['POSTS'][11], $was);

check(false !== strpos($GLOBALS['POSTS'][40]->post_title, 'Kaine Mackenzie')
  && false !== strpos($GLOBALS['POSTS'][41]->post_title, 'Kaine Mackenzie'),
  'renaming a child renames every session they have');

/* A student is saved far more often than they are renamed, and the walk is a
   query plus a read per session. It has to not happen. */
$GLOBALS['WALKS'] = 0;
BFTD_CPT::student_renamed(11, $GLOBALS['POSTS'][11], $GLOBALS['POSTS'][11]);
check(0 === $GLOBALS['WALKS'], 'and a save that did not touch the name looks nothing up, got ' . $GLOBALS['WALKS']);

/* ---- the halves that are missing ---- */
$mk(42, 'bftd_session', '', array('_bftd_student_id' => 11));
check('Kaine Mackenzie' === BFTD_CPT::session_title(42), 'a session with no date yet is just the child');
$mk(43, 'bftd_session', '', array('_bftd_session__session_date' => '2026-09-23'));
check('23 Sep 2026' === BFTD_CPT::session_title(43), 'and one with no child yet is just the day');
$mk(44, 'bftd_session', '');
check('New session' === BFTD_CPT::session_title(44), 'and a blank one is named rather than "(no title)"');

/* An auto-draft is WordPress holding a row open for a screen nobody has
   saved. Naming it makes it look like a record. */
$GLOBALS['POSTS'][44]->post_status = 'auto-draft';
$GLOBALS['WRITES'] = 0;
BFTD_CPT::retitle_on_save(44, $GLOBALS['POSTS'][44]);
check(0 === $GLOBALS['WRITES'], 'an auto-draft is left alone');

/* ---- the ones already on the site ---- */
/* Everything above fires on a save, so without a backfill the records that
   exist keep "Wednesday" until somebody happens to open each one. */
function delete_option($k) { unset($GLOBALS['OPT'][$k]); return true; }
$GLOBALS['OPT'] = array();

for ($i = 60; $i < 63; $i++) {
  $mk($i, 'bftd_session', 'Wednesday', array('_bftd_student_id' => 11, '_bftd_session__session_date' => '2026-10-0' . ($i - 59)));
}
BFTD_CPT::backfill_titles();
check('Wednesday' !== $GLOBALS['POSTS'][62]->post_title, 'the sessions already recorded get named, got ' . $GLOBALS['POSTS'][62]->post_title);
check(BFTD_CPT::TITLES_VERSION === (int) ($GLOBALS['OPT']['bftd_titles_version'] ?? 0), 'and it records that it has run');

$GLOBALS['WALKS'] = 0;
BFTD_CPT::backfill_titles();
check(0 === $GLOBALS['WALKS'], 'so every admin page load after it is one option read, got ' . $GLOBALS['WALKS'] . ' queries');

/* ---- and there is no box to type one in ---- */
/* Generating the title while still showing the field means a tutor types one,
   saves, and watches it vanish. */
function register_post_type($type, $args) {
  $GLOBALS['TYPES'][$type] = $args;
  add_post_type_support($type, $args['supports']);
}
BFTD_CPT::register();
check(!post_type_supports('bftd_session', 'title'), 'the session editor has no title field');
check(post_type_supports('bftd_student', 'title'), 'while a student is still something somebody names');
check(post_type_supports('bftd_progress', 'title'), 'and so is a progress report');

/* ---- and nothing else is renamed ---- */
$mk(50, 'bftd_progress', 'Kaine M — progress report', array('_bftd_student_id' => 11));
$GLOBALS['WRITES'] = 0;
BFTD_CPT::retitle_on_save(50, $GLOBALS['POSTS'][50]);
check(0 === $GLOBALS['WRITES'] && 'Kaine M — progress report' === $GLOBALS['POSTS'][50]->post_title,
  'a progress report keeps the title somebody gave it');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
