<?php
/*
 * Capability drift, and whether the plugin notices.
 *
 * This is the bug a live site had: an administrator getting 403 on a student
 * they owned, while a plugin that advertises self-healing roles reported
 * itself healthy. The healing was gated on a version stamp. A stamp answers
 * "has this build healed yet"; the question that matters is "do the roles hold
 * what they should", and that drifts afterwards — another plugin edits a role,
 * an update stops half way, somebody tidies capabilities by hand. Once the
 * stamp was current, the drift was permanent.
 *
 * The symptom is worth remembering because it does not look like a permission
 * problem. get_edit_post_link() returns null when edit_post is denied, so the
 * row action renders as <a href="">Open</a> and clicking it does nothing at
 * all. An administrator sees it first, because they hold no second role of
 * ours for WordPress to union a missing capability in from.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['ROLES']=array(); $GLOBALS['USERS']=array(); $GLOBALS['OPT']=array();

function add_role($s,$n,$c){$GLOBALS['ROLES'][$s]=array('name'=>$n,'caps'=>$c);}
function remove_role($s){unset($GLOBALS['ROLES'][$s]);}
/* Behaves like WP_Role: a real capabilities array, add and remove. */
function get_role($s){ if(!isset($GLOBALS['ROLES'][$s]))return null;
  return new class($s){
    public $s; public $capabilities;
    function __construct($s){$this->s=$s;$this->capabilities=&$GLOBALS['ROLES'][$s]['caps'];}
    function add_cap($c){$GLOBALS['ROLES'][$this->s]['caps'][$c]=true;}
    function remove_cap($c){unset($GLOBALS['ROLES'][$this->s]['caps'][$c]);}
  };}
function get_option($k,$d=false){return $GLOBALS['OPT'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['OPT'][$k]=$v;return true;}
function user_can($id,$cap){ if(is_object($id))$id=$id->ID; $u=get_userdata($id); if(!$u)return false;
  foreach($u->roles as $r) if(!empty($GLOBALS['ROLES'][$r]['caps'][$cap]))return true; return false;}
function mkuser($id,$roles,$name){$u=new stdClass;$u->ID=$id;$u->roles=(array)$roles;$u->display_name=$name;$GLOBALS['USERS'][$id]=$u;return $u;}
function get_userdata($id){return $GLOBALS['USERS'][$id]??false;}
function get_current_user_id(){return 0;}
function wp_get_current_user(){$u=new stdClass;$u->ID=0;$u->roles=array();$u->display_name='';return $u;}
function get_posts($a){return array();}
function absint($v){return abs((int)$v);}

$GLOBALS['ROLES']['administrator']=array('name'=>'Administrator','caps'=>array('manage_options'=>true,'read'=>true));

define('BFTD_PATH', dirname(__DIR__).'/');
foreach(array('schema','roles','cpt','access') as $f) require BFTD_PATH."includes/class-bftd-$f.php";

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

/* ---------------------------------------------------------------- */
BFTD_Roles::activate();
mkuser(1,'administrator','Karl');
mkuser(2,array(BFTD_Roles::SENIOR_ROLE,BFTD_Roles::TUTOR_ROLE),'Jo');
mkuser(3,BFTD_Roles::TUTOR_ROLE,'Denise');

check( BFTD_Roles::missing_caps() === array(), 'a freshly activated site is missing nothing' );
check( (int) get_option('bftd_caps_version') === BFTD_Roles::CAPS_VERSION, 'activation stamps the caps version' );

/* One definition: every role the plugin grants to is a role it also checks. */
$expected = BFTD_Roles::expected_caps();
check( isset($expected['administrator']), 'the administrator is in the expected set, not granted on the side' );
foreach(array(BFTD_Roles::TUTOR_ROLE,BFTD_Roles::MANAGER_ROLE,BFTD_Roles::SENIOR_ROLE) as $r){
  check( isset($expected[$r]) && $expected[$r], "$r has an expected capability list" );
}

/* The capability WordPress actually needs to open somebody else's published
   student. This is the one that went missing on live. */
$needed = 'edit_others_' . BFTD_Roles::plural_for('bftd_student');
foreach(array('administrator',BFTD_Roles::SENIOR_ROLE,BFTD_Roles::MANAGER_ROLE,BFTD_Roles::TUTOR_ROLE) as $r){
  check( in_array($needed,$expected[$r],true), "$r is expected to hold $needed" );
}

/* ---------------------------------------------------------------- */
echo "\nDrift, with the version stamp already current:\n";

$admin = get_role('administrator');
$admin->remove_cap($needed);
$admin->remove_cap('edit_published_' . BFTD_Roles::plural_for('bftd_student'));

check( ! user_can(1,$needed), 'the capability really is gone' );
check( (int) get_option('bftd_caps_version') === BFTD_Roles::CAPS_VERSION, 'the stamp still says healed' );

$missing = BFTD_Roles::missing_caps();
check( isset($missing['administrator']) && in_array($needed,$missing['administrator'],true),
  'missing_caps() reports the gap despite the stamp' );

BFTD_Roles::maybe_heal_capabilities();
check( user_can(1,$needed), 'healing puts it back without a version bump' );
check( BFTD_Roles::missing_caps() === array(), 'nothing is missing afterwards' );

/* A role another plugin deleted outright comes back. */
echo "\nA role that was deleted entirely:\n";
remove_role(BFTD_Roles::TUTOR_ROLE);
check( get_role(BFTD_Roles::TUTOR_ROLE) === null, 'the tutor role is gone' );
check( isset(BFTD_Roles::missing_caps()[BFTD_Roles::TUTOR_ROLE]), 'an absent role counts as missing everything' );
BFTD_Roles::maybe_heal_capabilities();
check( get_role(BFTD_Roles::TUTOR_ROLE) !== null, 'healing recreates it' );
check( user_can(3,$needed), 'and the tutor can work again' );

/* Healing must not write on every request once things are well. */
echo "\nCost when nothing is wrong:\n";
$GLOBALS['WRITES'] = 0;
$before = $GLOBALS['OPT'];
BFTD_Roles::maybe_heal_capabilities();
check( $GLOBALS['OPT'] === $before, 'a healthy site is left alone' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
