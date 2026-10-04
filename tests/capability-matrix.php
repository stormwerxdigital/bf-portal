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
 * WordPress's own map_meta_cap, modelled closely enough to be worth trusting.
 *
 * The part that matters, and the part this harness got wrong for a long time:
 * core resolves a post type's declared capability name (edit_bftd_student) to
 * its OWN meta capability name (edit_post) and runs the map_meta_cap filter
 * with that. A filter watching for edit_bftd_student is therefore never
 * called in production. This harness used to call the filter with the
 * declared name, which is the name the filter was looking for, so the matrix
 * printed a restriction that did not exist on the live site.
 */
function core_meta_cap($declared){
  foreach(BFTD_Roles::post_types() as $pt){
    if($declared === 'edit_'.$pt)   return 'edit_post';
    if($declared === 'read_'.$pt)   return 'read_post';
    if($declared === 'delete_'.$pt) return 'delete_post';
  }
  return $declared;
}

function wp_map($core_cap,$user_id,$post_id){
  $type = get_post_type($post_id);
  $plural = BFTD_Roles::plural_for($type);
  $map = array(
    'create_posts' => array('create_'.$plural),
    'edit_post'    => array('edit_'.$plural, 'edit_others_'.$plural),
    'read_post'    => array('read'),
    'delete_post'  => array('delete_'.$plural, 'delete_others_'.$plural),
  );
  if(!isset($map[$core_cap])) return array($core_cap);
  $primitive = $map[$core_cap];
  // author owns it -> only the non-"others" cap is needed
  if((int)get_post_field('post_author',$post_id) === (int)$user_id) $primitive = array($primitive[0]);
  return $primitive;
}

function can_do($user_id,$declared_cap,$post_id){
  $GLOBALS['CURRENT']=$user_id;
  $cap   = core_meta_cap($declared_cap);
  $caps  = wp_map($cap,$user_id,$post_id);
  $caps  = BFTD_Access::restrict_tutor($caps,$cap,$user_id,array($post_id));
  $caps  = BFTD_Roles::protect_admin_accounts($caps,$cap,$user_id,array($post_id));
  foreach($caps as $c){ if($c==='do_not_allow') return false; if(!user_can($user_id,$c)) return false; }
  return true;
}

mkuser(1,'administrator','Karl');
mkuser(2,BFTD_Roles::SENIOR_ROLE,'Jo (senior)');
mkuser(3,BFTD_Roles::MANAGER_ROLE,'Laurel (manager)');
mkuser(4,BFTD_Roles::TUTOR_ROLE,'Denise (tutor, assigned)');
mkuser(5,BFTD_Roles::TUTOR_ROLE,'Sam (tutor, NOT assigned)');

/* one student, assigned to Denise only; its reports point back at it */
mkpost(100,'bftd_student',9);
$GLOBALS['META'][100][BFTD_CPT::STAFF_KEY]=array(4);
foreach(array(101=>'bftd_assessment',102=>'bftd_progress',103=>'bftd_session',104=>'bftd_resource') as $pid=>$t){
  mkpost($pid,$t,9); $GLOBALS['META'][$pid][BFTD_CPT::STUDENT_KEY]=100;
}

$actors=array(1=>'Admin',2=>'Senior Mgr',3=>'Tutor Mgr',4=>'Tutor (assigned)',5=>'Tutor (not)');
$checks=array(
  array('CREATE a diagnostic',     'create_posts',         101),
  array('CREATE a progress report','create_posts',         102),
  array('CREATE a lesson',         'create_posts',         103),
  array('open the student',        'edit_bftd_student',    100),
  array('open the diagnostic',     'edit_bftd_assessment', 101),
  array('open the progress report','edit_bftd_progress',   102),
  array('open a lesson',           'edit_bftd_session',    103),
  array('open a resource',         'edit_bftd_resource',   104),
  array('delete the progress report','delete_bftd_progress',102),
);

printf("%-28s", 'Can they...'); foreach($actors as $l) printf("%-18s",$l); echo "\n";
echo str_repeat('-',115),"\n";
foreach($checks as $c){
  printf("%-28s",$c[0]);
  foreach(array_keys($actors) as $a) printf("%-18s", can_do($a,$c[1],$c[2])?'yes':'NO');
  echo "\n";
}

echo "\nMenu-level capabilities WordPress checks to show each CPT list screen:\n";
printf("%-28s", ''); foreach($actors as $l) printf("%-18s",$l); echo "\n";
foreach(BFTD_Roles::post_type_plurals() as $pt=>$plural){
  printf("%-28s","edit_".$plural);
  foreach(array_keys($actors) as $a) printf("%-18s", user_can($a,'edit_'.$plural)?'yes':'NO');
  echo "\n";
}

echo "\nPlugin-level gates:\n";
printf("%-28s", ''); foreach($actors as $l) printf("%-18s",$l); echo "\n";
foreach(array('is_staff','can_manage','can_manage_team') as $fn){
  printf("%-28s",$fn."()");
  foreach(array_keys($actors) as $a) printf("%-18s", BFTD_Roles::$fn($a)?'yes':'NO');
  echo "\n";
}
printf("%-28s","can_staff_view(student)");
foreach(array_keys($actors) as $a) printf("%-18s", BFTD_Access::can_staff_view(100,$a)?'yes':'NO');
echo "\n";
printf("%-28s","can_staff_view(report)");
foreach(array_keys($actors) as $a) printf("%-18s", BFTD_Access::can_staff_view(102,$a)?'yes':'NO');
echo "\n";


/* ------------------------------------------------------------------------ */
/* The table above is for a person to read. These are the assertions.        */
/*                                                                          */
/* It printed a matrix and asserted nothing for a long time, which means it  */
/* could not fail: the suite counted it as a pass whenever PHP exited 0, and */
/* an isolation rule that had stopped working looked exactly the same as one */
/* that worked. Every row anybody relies on is now checked.                  */
/* ------------------------------------------------------------------------ */

$fail = 0;
function want($got,$expect,$what){
  global $fail;
  if($got === $expect){ echo "  ok  $what\n"; return; }
  printf("FAIL  %s (expected %s, got %s)\n", $what, $expect?'yes':'no', $got?'yes':'no');
  $fail++;
}

echo "\nAssertions:\n";

/* Student 100 is assigned to tutor 4 and authored by 9, who is nobody here. */
foreach(array(1=>'administrator',2=>'senior manager',3=>'tutor manager') as $uid=>$label){
  want(can_do($uid,'edit_bftd_student',100), true, "$label opens any student");
  want(can_do($uid,'edit_bftd_session',103), true, "$label opens any session");
}

want(can_do(4,'edit_bftd_student',100), true,  'the assigned tutor opens their student');
want(can_do(5,'edit_bftd_student',100), false, 'an unassigned tutor CANNOT open the student');

/* A report follows the student. Its own list, empty here, only adds people;
   the student's tutor opens it without being on it. */
want(can_do(4,'edit_bftd_assessment',101), true, 'the student\'s tutor opens the student\'s diagnostic without being on it');
want(can_do(4,'edit_bftd_progress',102), true, 'and the student\'s progress report');
want(can_do(5,'edit_bftd_assessment',101), false, 'an unassigned tutor cannot open a diagnostic');

/* Settings, the email wording, the practice-wide log, pay and erasure are the
   senior tier, not the manager tier. */
want(BFTD_Roles::can_administer(1), true,  'administrator can administer');
want(BFTD_Roles::can_administer(2), true,  'senior manager can administer');
want(BFTD_Roles::can_administer(3), false, 'tutor manager CANNOT administer');
want(BFTD_Roles::can_administer(4), false, 'tutor cannot administer');
want(BFTD_Roles::can_erase(3),      false, 'tutor manager cannot erase');
want(BFTD_Roles::can_erase(2),      true,  'senior manager can erase');

/* The filter sees edit_post for EVERY post type on the site, so it has to let
   go of anything that is not ours. Without the guard a tutor stops being able
   to edit their own media and pages, which is a WordPress question and none of
   this plugin's business. Asserted on the filter directly, because whether a
   tutor can edit a page is decided by their role long before we are asked, and
   a can_do() check would read the same either way. */
mkpost(200,'page',9);
$untouched = BFTD_Access::restrict_tutor(array('edit_pages'),'edit_post',5,array(200));
want( ! in_array('do_not_allow',$untouched,true), true, 'the filter leaves a page alone' );

/* The page that proves the guard is doing the work rather than being
   decorative. A foreign post carrying one of our meta keys — another plugin
   reusing the name, an import, a stray row — would otherwise be read as a
   record belonging to a student, and an unassigned tutor would be refused a
   page on the strength of it. The guard decides this one; without it the
   student's assignment list does, and the answer flips. */
mkpost(201,'page',9);
$GLOBALS['META'][201][BFTD_CPT::STUDENT_KEY]=100;
$untouched = BFTD_Access::restrict_tutor(array('edit_pages'),'edit_post',5,array(201));
want( ! in_array('do_not_allow',$untouched,true), true, 'a page carrying our meta key is still none of our business' );

/* And it still restricts one of ours, so the guard is not simply letting
   everything through. */
$ours = BFTD_Access::restrict_tutor(array('edit_bftd_students'),'edit_post',5,array(100));
want( in_array('do_not_allow',$ours,true), true, 'the filter still restricts one of ours' );

/* Every tier that is meant to reach a list screen holds the plural capability
   WordPress checks to render it. */
foreach(BFTD_Roles::post_type_plurals() as $pt=>$plural){
  if(in_array($pt, array('bftd_resource'), true)) continue; // library tier, checked elsewhere
  foreach(array(1=>'administrator',2=>'senior manager',3=>'tutor manager',4=>'tutor') as $uid=>$label){
    want(user_can($uid,'edit_'.$plural), true, "$label reaches the $plural list");
  }
}

echo $fail ? "\n$fail failure(s)\n" : "\nAll assertions passed.\n";
exit($fail ? 1 : 0);
