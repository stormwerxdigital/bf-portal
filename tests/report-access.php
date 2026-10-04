<?php
/*
 * Who can open a report. Runs the real BFTD_Access::can_staff_view() against
 * the real role grants — no WordPress needed.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['ROLES']=array(); $GLOBALS['USERS']=array(); $GLOBALS['POSTS']=array(); $GLOBALS['META']=array();
function add_role($s,$n,$c){$GLOBALS['ROLES'][$s]=array('name'=>$n,'caps'=>$c);}
function remove_role($s){unset($GLOBALS['ROLES'][$s]);}
function get_role($s){ if(!isset($GLOBALS['ROLES'][$s]))return null;
  return new class($s){public $s;public $capabilities;
    function __construct($s){$this->s=$s;$this->capabilities=&$GLOBALS['ROLES'][$s]['caps'];}
    function add_cap($c){$GLOBALS['ROLES'][$this->s]['caps'][$c]=true;}
    function remove_cap($c){unset($GLOBALS['ROLES'][$this->s]['caps'][$c]);}};}
function update_option($k,$v,$a=null){return true;}
function mkuser($id,$role,$name){$u=new stdClass;$u->ID=$id;$u->roles=array($role);$u->display_name=$name;$GLOBALS['USERS'][$id]=$u;}
function get_userdata($id){return $GLOBALS['USERS'][$id]??false;}
function user_can($id,$cap){if(is_object($id))$id=$id->ID;$u=get_userdata($id);if(!$u)return false;
  foreach($u->roles as $r) if(!empty($GLOBALS['ROLES'][$r]['caps'][$cap]))return true;return false;}
function get_current_user_id(){return $GLOBALS['CURRENT']??0;}
function wp_get_current_user(){return get_userdata(get_current_user_id());}
function mkpost($id,$type,$author,$staff=array(),$student=0){
  $GLOBALS['POSTS'][$id]=array('type'=>$type,'author'=>$author);
  $GLOBALS['META'][$id][BFTD_CPT::STAFF_KEY]=$staff;
  if($student)$GLOBALS['META'][$id][BFTD_CPT::STUDENT_KEY]=$student;}
function get_post_type($id){return $GLOBALS['POSTS'][$id]['type']??'';}
function get_post_field($f,$id){return $GLOBALS['POSTS'][$id]['author']??0;}
function get_post_meta($id,$k,$single=false){$v=$GLOBALS['META'][$id][$k]??($single?'':array());return $v;}
function get_posts($a){return array();}
function absint($v){return abs((int)$v);}

$GLOBALS['ROLES']['administrator']=array('name'=>'Administrator','caps'=>array('manage_options'=>true));
define('BFTD_PATH', dirname(__DIR__).'/');
foreach(['schema','roles','cpt','access'] as $f) require BFTD_PATH."includes/class-bftd-$f.php";
BFTD_Roles::activate();

mkuser(1,'administrator','Admin');
mkuser(2,BFTD_Roles::SENIOR_ROLE,'Senior Mgr');
mkuser(3,BFTD_Roles::MANAGER_ROLE,'Tutor Mgr');
mkuser(4,BFTD_Roles::TUTOR_ROLE,'Denise (wrote it)');
mkuser(5,BFTD_Roles::TUTOR_ROLE,'Sam (assigned later)');
mkuser(6,BFTD_Roles::TUTOR_ROLE,'Alex (student tutor, not on report)');

mkpost(100,'bftd_student',9,array(6));                 // Alex teaches the student
mkpost(101,'bftd_assessment',4,array(4,5),100);        // Denise wrote it, Sam added

$actors=array(1=>'Admin',2=>'Senior Mgr',3=>'Tutor Mgr',4=>'Author',5=>'Assigned',6=>'Student tutor');
$fail=0;
$expect=array(1=>true,2=>true,3=>true,4=>true,5=>true,6=>true);

printf("%-30s", 'Can open the diagnostic'); foreach($actors as $l) printf("%-15s",$l); echo "\n";
echo str_repeat('-',120),"\n";
printf("%-30s",'');
foreach(array_keys($actors) as $a){
  $GLOBALS['CURRENT']=$a;
  $got=BFTD_Access::can_staff_view(101,$a);
  if($got!==$expect[$a])$fail++;
  printf("%-15s",$got?'yes':'NO');
}
echo "\n\nExpected: everyone. The student's own tutor opens it without being on the report, because a report follows the student.\n";

/* A session is different: it belongs to the student's tutors as well, whoever
   started it. A session a manager drafted for a tutor's own student used to
   refuse that tutor outright, so they could not open it, let alone publish. */
mkpost(102,'bftd_session',3,array(3),100);              // the manager started it
$sexpect=array(1=>true,2=>true,3=>true,4=>false,5=>false,6=>true);
printf("%-30s",'Can open the session');
foreach(array_keys($actors) as $a){
  $got=BFTD_Access::can_staff_view(102,$a);
  if($got!==$sexpect[$a])$fail++;
  printf("%-15s",$got?'yes':'NO');
}
echo "\nExpected: managers, and the student's own tutor; not tutors of other students.\n";
/* And a tutor of some other student still cannot. */
mkuser(7,BFTD_Roles::TUTOR_ROLE,'Pat (other students)');
$got=BFTD_Access::can_staff_view(101,7);
if($got)$fail++;
printf("%-30s%s\n",'A tutor of other students',$got?'yes (WRONG)':'NO, as expected');
echo $fail?"FAIL: $fail mismatch(es)\n":"PASS\n";
exit($fail?1:0);
