<?php
/*
 * Skills belong to a track, and an activity only takes skills from its own.
 *
 * A skill with no track stored is a Tracks 2 and 3 skill, and the stored data
 * is brought into line once, without touching a skill that already has one.
 *
 * A skill cannot be published from its edit screen without a track chosen:
 * it is held as a draft and nothing is written for the track.
 *
 * An activity's save refuses a skill from the other track, but keeps one that
 * was already attached, so a save never quietly drops what was there.
 */
$META = array(); $POSTS = array();
function get_post_meta($id,$k='',$single=false){ $v=$GLOBALS['META'][$id][$k] ?? null; return $single ? ($v ?? '') : ($v===null?array():array($v)); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function delete_post_meta($id,$k){ unset($GLOBALS['META'][$id][$k]); return true; }
function current_user_can($cap,$id=0){ return true; }
function wp_unslash($v){ return $v; }
function wp_is_post_revision($id){ return false; }
function get_posts($a){
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $type) {
    if ($type !== $a['post_type']) continue;
    $mq = $a['meta_query'][0] ?? null;
    if ($mq && 'NOT EXISTS' === $mq['compare'] && isset($GLOBALS['META'][$id][$mq['key']])) continue;
    $out[] = $id;
  }
  return $out;
}
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-skills.php';
require BFTD_PATH . 'includes/class-bftd-activities.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }
$T = BFTD_Skills::TRACK_KEY;

/* ---- the default ---- */
check('t23' === BFTD_Skills::TRACK_DEFAULT, 'the default track for a skill is Tracks 2 and 3');
check('t23' === BFTD_Skills::track_of(500), 'a skill with nothing stored reads as Tracks 2 and 3');
check('' === BFTD_Skills::stored_track(500), 'while what is stored says nobody has chosen one');

/* ---- the one-time update ---- */
$POSTS = array(1 => 'bftd_skill', 2 => 'bftd_skill', 3 => 'bftd_skill', 9 => 'bftd_activity');
$META[2][$T] = 't1';
$META[3][$T] = 't23';
BFTD_Skills::maybe_fill_tracks();
check('t23' === $META[1][$T], 'a skill with no track is given Tracks 2 and 3');
check('t1' === $META[2][$T], 'a skill already in Track 1 keeps it');
check(!isset($META[9][$T]), 'and nothing that is not a skill is touched');
check(1 === (int) get_option(BFTD_Skills::TRACK_FILL_OPTION), 'it records that it has run');
unset($META[1][$T]);
BFTD_Skills::maybe_fill_tracks();
check(!isset($META[1][$T]), 'and does not run a second time');

/* ---- no track, no publishing ---- */
$skill = array('post_type' => 'bftd_skill', 'post_status' => 'publish');
$_POST = array('bftd_skill_nonce' => 'x', 'bftd_skill_track' => '');
check('draft' === BFTD_Skills::hold_without_track($skill, array())['post_status'], 'publishing a skill with no track keeps it a draft');
$_POST['bftd_skill_track'] = 'invented';
check('draft' === BFTD_Skills::hold_without_track($skill, array())['post_status'], 'and so does a track that is not real');
$_POST['bftd_skill_track'] = 't1';
check('publish' === BFTD_Skills::hold_without_track($skill, array())['post_status'], 'a skill with a track publishes');
$_POST = array('bftd_skill_track' => '');
check('publish' === BFTD_Skills::hold_without_track($skill, array())['post_status'], 'only the edit screen is held, not every save');
$_POST = array('bftd_skill_nonce' => 'x', 'bftd_skill_track' => '');
$act = array('post_type' => 'bftd_activity', 'post_status' => 'publish');
check('publish' === BFTD_Skills::hold_without_track($act, array())['post_status'], 'and only skills');

$META[40] = array();
$_POST = array('bftd_skill_nonce' => wp_create_nonce('bftd_skill_40'), 'bftd_skill_group' => 'skill', 'bftd_skill_track' => '', 'bftd_skill_number' => '7');
BFTD_Skills::save(40, null);
check(!isset($META[40][$T]), 'saving with no track chosen writes no track');
check(7 === $META[40][BFTD_Skills::NUMBER_KEY], 'but still keeps the number that was typed');

/* ---- an activity takes skills from its own track ---- */
BFTD_Skills::use_fixture(array(
  61 => array('number' => 1, 'name' => 'Track 1 skill',   'track' => 't1',  'group' => 'skill'),
  62 => array('number' => 2, 'name' => 'Another Track 1', 'track' => 't1',  'group' => 'skill'),
  71 => array('number' => 1, 'name' => 'Track 2 3 skill', 'track' => 't23', 'group' => 'skill'),
  72 => array('number' => 2, 'name' => 'Old attachment',  'track' => 't23', 'group' => 'skill'),
));
$SK = BFTD_Activities::SKILLS_KEY;
$META[88] = array($SK => array(72));
$rows = function ($ids) { return array_map(function ($i) { return array('id' => (string) $i); }, $ids); };
$_POST = array(
  'bftd_activity_nonce' => wp_create_nonce('bftd_activity_88'),
  'bftd_activity_track' => 't1',
  'bftd_rows' => array('activity' => array('skills' => $rows(array(61, 71, 72)))),
);
BFTD_Activities::save(88, null);
$saved = $META[88][$SK];
check(in_array(61, $saved, true), 'a Track 1 activity takes a Track 1 skill');
check(!in_array(71, $saved, true), 'and refuses a newly added Tracks 2 and 3 skill');
check(in_array(72, $saved, true), 'but keeps the Tracks 2 and 3 skill it already had');

$_POST['bftd_activity_track'] = 't23';
$_POST['bftd_rows']['activity']['skills'] = $rows(array(62, 71, 72));
BFTD_Activities::save(88, null);
$saved = $META[88][$SK];
check(!in_array(62, $saved, true) && in_array(71, $saved, true), 'and a Tracks 2 and 3 activity the reverse');

exit($fail ? 1 : 0);
