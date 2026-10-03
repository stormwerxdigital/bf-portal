<?php
/*
 * Quick edit on the skills list: the track and the number, and nothing else.
 *
 * Quick edit posts only what its own box holds. The group is not in that box,
 * so a save that wrote the group whenever it ran would quietly turn every
 * Wordwall entry quick-edited into an ordinary skill. The group is left alone.
 *
 * And one box serves every row, so the values it opens with come from the row:
 * the number column prints them for the script to copy in.
 */
$META = array();
$CAN  = true;
function get_post_meta($id,$k='',$single=false){ $v=$GLOBALS['META'][$id][$k] ?? null; return $single ? ($v ?? '') : ($v===null?array():array($v)); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function delete_post_meta($id,$k){ unset($GLOBALS['META'][$id][$k]); return true; }
function current_user_can($cap,$id=0){ return $GLOBALS['CAN']; }
function wp_unslash($v){ return $v; }
function wp_nonce_field($a,$name){ echo '<input type="hidden" name="'.$name.'" value="'.wp_create_nonce($a).'">'; }
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-skills.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

$G = BFTD_Skills::GROUP_KEY; $T = BFTD_Skills::TRACK_KEY; $N = BFTD_Skills::NUMBER_KEY;
function reset_skill(){ $GLOBALS['META'][7] = array(BFTD_Skills::GROUP_KEY=>'wordwall', BFTD_Skills::TRACK_KEY=>'t1', BFTD_Skills::NUMBER_KEY=>12); }
function quick($post){ $_POST = $post; BFTD_Skills::save_quick(7, null); }
$ok = wp_create_nonce('bftd_skill_quick');

/* ---- what quick edit writes ---- */
reset_skill();
quick(array('bftd_skill_qe_nonce'=>$ok, 'bftd_skill_track'=>'t23', 'bftd_skill_number'=>'5'));
check('t23' === $META[7][$T], 'quick edit moves a skill to Tracks 2 and 3, got ' . $META[7][$T]);
check(5 === $META[7][$N], 'and gives it its new number, got ' . var_export($META[7][$N], true));
check('wordwall' === $META[7][$G], 'and leaves the group exactly as it was, got ' . $META[7][$G]);

reset_skill();
quick(array('bftd_skill_qe_nonce'=>$ok, 'bftd_skill_track'=>'t1', 'bftd_skill_number'=>''));
check(!isset($META[7][$N]), 'a cleared number is stored as no number, not as zero');

reset_skill();
quick(array('bftd_skill_qe_nonce'=>$ok, 'bftd_skill_track'=>'t9', 'bftd_skill_number'=>'4'));
check('t23' === $META[7][$T], 'a track that is not real falls back to Tracks 2 and 3, got ' . $META[7][$T]);

/* ---- what it refuses ---- */
reset_skill();
quick(array('bftd_skill_qe_nonce'=>'nope', 'bftd_skill_track'=>'t23', 'bftd_skill_number'=>'5'));
check('t1' === $META[7][$T] && 12 === $META[7][$N], 'a bad nonce changes nothing');

reset_skill();
quick(array('bftd_skill_track'=>'t23', 'bftd_skill_number'=>'5'));
check('t1' === $META[7][$T] && 12 === $META[7][$N], 'a save from anywhere but quick edit is not taken for one');

reset_skill();
quick(array('bftd_skill_qe_nonce'=>$ok, 'bftd_skill_track'=>'t23'));
check('t1' === $META[7][$T] && 12 === $META[7][$N], 'a post missing the number is not read as clearing it');

reset_skill(); $CAN = false;
quick(array('bftd_skill_qe_nonce'=>$ok, 'bftd_skill_track'=>'t23', 'bftd_skill_number'=>'5'));
check('t1' === $META[7][$T] && 12 === $META[7][$N], 'somebody who cannot edit the skill changes nothing');
$CAN = true;

/* ---- the box, and what fills it ---- */
ob_start(); BFTD_Skills::quick_box('bftd_number', BFTD_Skills::POST_TYPE); $box = ob_get_clean();
check(false !== strpos($box,'name="bftd_skill_track"') && false !== strpos($box,'name="bftd_skill_number"'),
  'the quick edit box has a track and a number');
check(false !== strpos($box,'bftd_skill_qe_nonce'), 'and carries its own nonce');
check(2 === substr_count($box,'<option'), 'offering the two tracks, got ' . substr_count($box,'<option'));
ob_start(); BFTD_Skills::quick_box('title', BFTD_Skills::POST_TYPE); BFTD_Skills::quick_box('bftd_number', 'bftd_activity'); $none = ob_get_clean();
check('' === $none, 'and appears once, on the skills list only');

reset_skill();
ob_start(); BFTD_Skills::column('bftd_number', 7); $cell = ob_get_clean();
check(false !== strpos($cell,'data-track="t1"') && false !== strpos($cell,'data-number="12"'),
  'each row carries its own track and number for quick edit to open with');

/* ---- the edit screen still saves all three, through the same writer ---- */
function wp_is_post_revision($id){ return false; }
reset_skill();
$_POST = array('bftd_skill_nonce'=>wp_create_nonce('bftd_skill_7'), 'bftd_skill_group'=>'skill', 'bftd_skill_track'=>'t23', 'bftd_skill_number'=>'9');
BFTD_Skills::save(7, null);
check('skill' === $META[7][$G] && 't23' === $META[7][$T] && 9 === $META[7][$N],
  'the edit screen still writes group, track and number together');

exit($fail ? 1 : 0);
