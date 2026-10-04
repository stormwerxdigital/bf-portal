<?php
/*
 * Quick edit on the skills list: the track and the number, and nothing else.
 *
 * The track dropdown offers Wordwall beside the two tracks, so choosing a
 * track makes an entry an ordinary skill and choosing Wordwall makes it a
 * Wordwall entry, with its stored track left alone.
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
check('skill' === $META[7][$G], 'choosing a track makes a Wordwall entry an ordinary skill, got ' . $META[7][$G]);

reset_skill(); $META[7][$G] = 'skill';
quick(array('bftd_skill_qe_nonce'=>$ok, 'bftd_skill_track'=>'wordwall', 'bftd_skill_number'=>'3'));
check('wordwall' === $META[7][$G], 'choosing Wordwall makes it a Wordwall entry, got ' . $META[7][$G]);
check('t1' === $META[7][$T], 'and leaves its stored track alone, got ' . $META[7][$T]);
check(3 === $META[7][$N], 'and still saves the number');

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
check(3 === substr_count($box,'<option'), 'offering the two tracks and Wordwall, got ' . substr_count($box,'<option'));
check(false !== strpos($box,'value="wordwall"'), 'Wordwall among them');
ob_start(); BFTD_Skills::quick_box('title', BFTD_Skills::POST_TYPE); BFTD_Skills::quick_box('bftd_number', 'bftd_activity'); $none = ob_get_clean();
check('' === $none, 'and appears once, on the skills list only');

reset_skill();
ob_start(); BFTD_Skills::column('bftd_number', 7); $cell = ob_get_clean();
check(false !== strpos($cell,'data-track="wordwall"') && false !== strpos($cell,'data-number="12"'),
  'each row carries where it is and its number for quick edit to open with: Wordwall here');
$META[7][$G] = 'skill';
ob_start(); BFTD_Skills::column('bftd_number', 7); $cell = ob_get_clean();
check(false !== strpos($cell,'data-track="t1"'), 'and its track for an ordinary skill');

/* ---- the edit screen still saves all three, through the same writer ---- */
function wp_is_post_revision($id){ return false; }
reset_skill();
$_POST = array('bftd_skill_nonce'=>wp_create_nonce('bftd_skill_7'), 'bftd_skill_track'=>'t23', 'bftd_skill_number'=>'9');
BFTD_Skills::save(7, null);
check('skill' === $META[7][$G] && 't23' === $META[7][$T] && 9 === $META[7][$N],
  'the edit screen writes group, track and number together from the one dropdown');

reset_skill(); $META[7][$G] = 'skill';
$_POST = array('bftd_skill_nonce'=>wp_create_nonce('bftd_skill_7'), 'bftd_skill_track'=>'wordwall', 'bftd_skill_number'=>'');
BFTD_Skills::save(7, null);
check('wordwall' === $META[7][$G] && 't1' === $META[7][$T] && !isset($META[7][$N]),
  'and Wordwall chosen there makes a Wordwall entry, track left alone');

/* ---- the edit screen's box: one dropdown, no separate group box ---- */
reset_skill();
ob_start(); BFTD_Skills::box_place((object) array('ID' => 7)); $place = ob_get_clean();
check(1 === substr_count($place,'<select'), 'the edit screen has one dropdown for where a skill is');
check(1 === preg_match('/value="wordwall"\s+selected/', $place), 'and it opens on Wordwall for a Wordwall entry');
check(false !== strpos($place,'value="t1"') && false !== strpos($place,'value="t23"'), 'beside both tracks');
check(!method_exists('BFTD_Skills','box_group'), 'and the separate group box is gone');

exit($fail ? 1 : 0);
