<?php
require __DIR__ . '/wp-stubs.php';

$GLOBALS['ROLES']=array(); $GLOBALS['USERS']=array(); $GLOBALS['POSTS']=array(); $GLOBALS['META']=array();
function add_role($s,$n,$c){$GLOBALS['ROLES'][$s]=array('name'=>$n,'caps'=>$c);}
function remove_role($s){unset($GLOBALS['ROLES'][$s]);}
/* A stand-in for WP_Role. It exposes ->capabilities and ->remove_cap because
   the real one does, and code that reconciles a role reads both. A fake with
   only add_cap() reports every capability as missing and every removal as a
   fatal, so it cannot test reconciliation at all. */
function get_role($s){ if(!isset($GLOBALS['ROLES'][$s]))return null;
  return new class($s){
    public $s; public $capabilities;
    function __construct($s){$this->s=$s;$this->capabilities=&$GLOBALS['ROLES'][$s]['caps'];}
    function add_cap($c){$GLOBALS['ROLES'][$this->s]['caps'][$c]=true;}
    function remove_cap($c){unset($GLOBALS['ROLES'][$this->s]['caps'][$c]);}
  };}
function update_option($k,$v,$a=null){$GLOBALS['OPT'][$k]=$v;return true;}
function mkuser($id,$role,$name){$u=new stdClass;$u->ID=$id;$u->roles=array($role);$u->display_name=$name;$u->first_name='';$u->user_email="u$id@x.test";$GLOBALS['USERS'][$id]=$u;return $u;}
function get_userdata($id){return $GLOBALS['USERS'][$id]??false;}
function user_can($id,$cap){ if(is_object($id))$id=$id->ID; $u=get_userdata($id); if(!$u)return false;
  foreach($u->roles as $r) if(!empty($GLOBALS['ROLES'][$r]['caps'][$cap]))return true; return false;}
function get_current_user_id(){return $GLOBALS['CURRENT']??0;}
function wp_get_current_user(){return get_userdata(get_current_user_id());}
function current_user_can($c){return user_can(get_current_user_id(),$c);}
function mkpost($id,$type,$author){$GLOBALS['POSTS'][$id]=array('type'=>$type,'author'=>$author);}
function get_post_type($id){return $GLOBALS['POSTS'][$id]['type']??'';}
function get_post_field($f,$id){return $GLOBALS['POSTS'][$id]['author']??0;}
function get_post_meta($id,$k,$single=false){$v=$GLOBALS['META'][$id][$k]??($single?'':array());return $v;}
function get_posts($a){return array();}
function get_the_title($id){return 'Student '.$id;}
function absint($v){return abs((int)$v);}

$GLOBALS['ROLES']['administrator']=array('name'=>'Administrator','caps'=>array('manage_options'=>true,'read'=>true));

define("BFTD_PATH", dirname(__DIR__) . "/");
foreach(['schema','roles','cpt','access'] as $f) require BFTD_PATH . "includes/class-bftd-$f.php";
BFTD_Roles::activate();

/*
 * Who manages accounts (Karl, 1.147).
 *
 *   Senior manager: clients, tutors, tutor managers.
 *   Tutor manager:  clients, tutors. Not other tutor managers.
 *   Tutor:          no accounts at all.
 *   WordPress's own roles (Editor and the rest) are never handed out by staff.
 */
$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$senior  = mkuser(2, 'bftd_senior_manager', 'S');
$manager = mkuser(3, 'bftd_tutor_manager', 'M');
$tutor   = mkuser(4, 'bftd_tutor', 'T');
mkuser(5, 'bftd_client', 'C');

foreach (BFTD_Roles::user_caps() as $cap) {
  check(!user_can(4, $cap), "a tutor does not hold $cap");
  check(user_can(3, $cap) && user_can(2, $cap), "a manager and a senior manager hold $cap");
}

/* A site that granted tutors the account capabilities before 1.147 has them
   taken back when the roles are brought into line. */
foreach (BFTD_Roles::user_caps() as $cap) $GLOBALS['ROLES']['bftd_tutor']['caps'][$cap] = true;
$GLOBALS['ROLES']['bftd_tutor']['caps']['some_other_plugins_cap'] = true;
BFTD_Roles::activate();
check(!user_can(4, 'create_users') && !user_can(4, 'edit_users') && !user_can(4, 'list_users'), 'an existing tutor role loses them on the next re-grant');
check(user_can(4, 'some_other_plugins_cap'), 'while a capability another plugin added is left alone');
check(BFTD_Roles::CAPS_VERSION >= 11, 'and the caps version is bumped so live roles re-grant');

/* The role list on Add User and Edit User. */
$all = array_fill_keys(array('administrator','bftd_senior_manager','bftd_tutor_manager','bftd_tutor','bftd_client','editor','author','contributor','subscriber'), array());
$GLOBALS['CURRENT'] = 2;
check(array('bftd_tutor_manager','bftd_tutor','bftd_client') === array_keys(BFTD_Roles::hide_administrator_role($all)), 'a senior manager is offered tutor manager, tutor and client');
$GLOBALS['CURRENT'] = 3;
check(array('bftd_tutor','bftd_client') === array_keys(BFTD_Roles::hide_administrator_role($all)), 'a tutor manager is offered tutor and client');

/* Handing a role out, which is also how a new account is made with it. */
$promote = function ($actor, $role) { return !in_array('do_not_allow', BFTD_Roles::protect_admin_accounts(array('promote_users'), 'promote_user', $actor, array(0, $role)), true); };
check($promote(2, 'bftd_tutor_manager') && $promote(2, 'bftd_tutor') && $promote(2, 'bftd_client'), 'a senior manager may hand out tutor manager, tutor and client');
check(!$promote(2, 'bftd_senior_manager') && !$promote(2, 'administrator'), 'but not senior manager or administrator');
check($promote(3, 'bftd_tutor') && $promote(3, 'bftd_client') && !$promote(3, 'bftd_tutor_manager'), 'a tutor manager may hand out tutor and client, not tutor manager');
foreach (array('editor','author','contributor','subscriber') as $r) {
  check(!$promote(2, $r) && !$promote(3, $r), "nobody on staff hands out WordPress's $r role");
}

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
