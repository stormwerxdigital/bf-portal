<?php
/*
 * The CRM creating and then keeping up with a student.
 *
 * The agreed flow: basics are entered in the CRM, the CRM creates the record
 * here, and from then on this dashboard runs the customer journey. Two rules
 * fall out of that and both are easy to get subtly wrong.
 *
 * ONE, a tutor's typing wins. A field does not remember who last wrote it, so
 * the CRM keeps a shadow copy of what it wrote and compares against it. Equal
 * means untouched and may be corrected; different means a person changed it
 * and it is left alone.
 *
 * TWO, the journey belongs here. Status and the attendance dates are set once
 * when the record is created and never again. Without that rule a student
 * moved to Active here is dragged back to Prospect by the next sync, and
 * nobody can see why.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array(); $GLOBALS['POSTS'] = array(); $GLOBALS['NEXT_ID'] = 100;
function get_post_meta($id,$k,$single=false){ return $GLOBALS['META'][$id][$k] ?? ($single?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function delete_post_meta($id,$k){ unset($GLOBALS['META'][$id][$k]); return true; }
function get_the_title($id){ return $GLOBALS['POSTS'][$id]['title'] ?? ''; }
function wp_insert_post($a,$e=false){ $id=$GLOBALS['NEXT_ID']++; $GLOBALS['POSTS'][$id]=array('title'=>$a['post_title'],'status'=>$a['post_status']); return $id; }
function wp_update_post($a){ if(isset($a['post_title'])) $GLOBALS['POSTS'][$a['ID']]['title']=$a['post_title']; return $a['ID']; }
function get_edit_post_link($id,$c=''){ return '#'; }
function get_posts($args){
  $want = $args['meta_query'][0]['value'] ?? '';
  foreach ($GLOBALS['META'] as $id => $m) { if (($m['_bftd_crm_id'] ?? '') === $want) return array($id); }
  return array();
}

class BFTD_Audit { public static function log($e,$a=array()){ $GLOBALS['LOG'][]=$e; } }
class BFTD_CPT { const STUDENT='bftd_student'; }
class BFTD_Schema { public static function meta_key($sec,$key){ return '_bftd_'.$sec.'__'.$key; } }
class BFTD_Fields {
  public static function get($id,$sec,$key,$f=array()){ return $GLOBALS['META'][$id][BFTD_Schema::meta_key($sec,$key)] ?? ''; }
  public static function set($id,$sec,$key,$v){ $GLOBALS['META'][$id][BFTD_Schema::meta_key($sec,$key)] = $v; return true; }
}
class BFTD_MetaBoxes {
  public static function crm_fields() {
    return array(
      'birth_date'    => array('type'=>'date','label'=>'Birth date'),
      'school'        => array('type'=>'text','label'=>'School'),
      'phone'         => array('type'=>'text','label'=>'Phone'),
      'email'         => array('type'=>'text','label'=>'Email'),
      'status'        => array('type'=>'select','label'=>'Status','options'=>array('prospect'=>'Prospect','active'=>'Active','past'=>'Past student')),
      'diagnostic_on' => array('type'=>'date','label'=>'Diagnostic assessment'),
      'attend_from'   => array('type'=>'date','label'=>'Attending from'),
      'attend_to'     => array('type'=>'date','label'=>'Attending to'),
    );
  }
}
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-crm.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function val($id,$k){ return BFTD_Fields::get($id,'student',$k); }

/* ---------------------------------------------------------------- */
/* The CRM creates a student after a reading diagnostic              */
/* ---------------------------------------------------------------- */

$r = BFTD_CRM::upsert(array(
  'crm_id'                => 'ghl_melody',
  'Student Name'          => 'Melody Powell',
  'Birth Date'            => 'Mar 3, 2011',
  'School'                => 'Harwin Elementary',
  'Phone'                 => '',
  'Status'                => 'Prospect',
  'Diagnostic Assessment' => 'Sep 14, 2026',
  'From/To'               => '2022-Apr END 2023-Jun',
));
check(!is_wp_error($r), 'a CRM record with an id and a name creates a student');
$s = $r['student_id'];

check($r['created'] === true, 'and says it created one');
check(get_the_title($s) === 'Melody Powell', 'with the name from the CRM');
check($GLOBALS['POSTS'][$s]['status'] === 'draft', 'as a draft, so somebody here decides when they become a family');
check(val($s,'birth_date') === '2011-03-03', 'a birthday written the CRM way becomes a real date');
check(val($s,'school') === 'Harwin Elementary', 'plain text comes straight across');
check(val($s,'status') === 'prospect', 'a new student arrives as a prospect');
check(val($s,'diagnostic_on') === '2026-09-14', 'with the date of the diagnostic that created them');
check(val($s,'attend_from') === '2022-04-01', 'the from half of the span becomes a date');
check(val($s,'attend_to') === '2023-06-01', 'and so does the to half');
check(val($s,'phone') === '', 'a blank stays blank rather than becoming something');

/* --- the same record arriving again must not make a second child --- */
$again = BFTD_CRM::upsert(array('crm_id' => 'ghl_melody', 'Student Name' => 'Melody Powell'));
check($again['student_id'] === $s, 'the same CRM id lands on the same student');
check($again['created'] === false, 'and does not create another');

/* A record with no id cannot be matched or safely created. */
check(is_wp_error(BFTD_CRM::upsert(array('Student Name' => 'No Id'))), 'a record with no CRM id is refused');
check(is_wp_error(BFTD_CRM::upsert(array('crm_id' => 'ghl_new'))), 'and a new one with no name is refused');

/* ---------------------------------------------------------------- */
/* The dashboard runs the journey from here                          */
/* ---------------------------------------------------------------- */

BFTD_Fields::set($s,'student','status','active');    // she starts a programme
BFTD_CRM::apply($s, array('Status' => 'Prospect'));
check(val($s,'status') === 'active', 'the CRM cannot drag an active student back to prospect');

/* Even if nobody here had touched it, the CRM still does not own it. */
$b = BFTD_CRM::upsert(array('crm_id'=>'ghl_b','Student Name'=>'Bea','Status'=>'Prospect'));
$bid = $b['student_id'];
$res = BFTD_CRM::apply($bid, array('Status' => 'Past Student'));
check(val($bid,'status') === 'prospect', 'the journey is not the CRM\'s to change after creation');
check(in_array('status', $res['journey'], true), 'and the sync reports that it left it to the dashboard');
check(!in_array('Status', BFTD_CRM::held_back($bid), true), 'which is not reported as a disagreement, because it is not one');

/* The attendance dates are journey too. */
BFTD_CRM::apply($bid, array('From/To' => '2019-Jan END 2020-Jan'));
check(val($bid,'attend_from') === '', 'attendance dates are not rewritten after creation either');

/* ---------------------------------------------------------------- */
/* Contact details do stay in step                                   */
/* ---------------------------------------------------------------- */

$r = BFTD_CRM::apply($s, array('School' => 'Harwin Middle School'));
check(val($s,'school') === 'Harwin Middle School', 'a value the CRM wrote and nobody edited can be corrected');
check(in_array('school', $r['written'], true), 'and it is reported as written');

BFTD_CRM::apply($s, array('Phone' => '250 555 0134'));
check(val($s,'phone') === '250 555 0134', 'a number the family gave the CRM reaches the dashboard');

/* --- until somebody corrects it by hand --- */
BFTD_Fields::set($s,'student','school','Duchess Park Secondary');
$r = BFTD_CRM::apply($s, array('School' => 'Harwin Middle School'));
check(val($s,'school') === 'Duchess Park Secondary', 'a typed correction is not overwritten');
check(in_array('school', $r['held'], true), 'and the CRM says it is holding that one back');
check(in_array('School', BFTD_CRM::held_back($s), true), 'which the student screen can show by name');

BFTD_CRM::apply($s, array('School' => 'Harwin Middle School'));
BFTD_CRM::apply($s, array('School' => 'Harwin Middle School'));
check(val($s,'school') === 'Duchess Park Secondary', 'and it stays held however many times the sync runs');

/* --- clearing a field hands it back --- */
BFTD_Fields::set($s,'student','school','');
BFTD_CRM::apply($s, array('School' => 'Harwin Middle School'));
check(val($s,'school') === 'Harwin Middle School', 'clearing a field lets the CRM fill it again');
check(BFTD_CRM::held_back($s) === array(), 'and nothing is held back any more');

/* --- the CRM going blank must not wipe anything --- */
BFTD_CRM::apply($s, array('School' => ''));
check(val($s,'school') === 'Harwin Middle School', 'the CRM having no answer does not erase ours');

/* --- a name corrected in the CRM follows through --- */
BFTD_CRM::upsert(array('crm_id' => 'ghl_melody', 'Student Name' => 'Melody Powell-Reid'));
check(get_the_title($s) === 'Melody Powell-Reid', 'a name corrected in the CRM is corrected here');

/* --- rubbish in --- */
$r = BFTD_CRM::apply($s, array('Status' => 'Something Else'));
check(val($s,'status') === 'active', 'an unrecognised status changes nothing');

/* --- linking --- */
check(BFTD_CRM::linked($s) === 'ghl_melody', 'a created student carries the CRM id');
check(BFTD_CRM::find('ghl_melody') === $s, 'and can be found by it');
check(BFTD_CRM::find('nobody') === 0, 'an id we have never seen finds nobody');
check(BFTD_CRM::last_synced($s) > 0, 'a sync records when it happened');

/* --- the span parser on its own --- */
$sp = BFTD_CRM::split_span('2022-Apr END 2023-Jun');
check($sp['from'] === '2022-04-01' && $sp['to'] === '2023-06-01', 'a full span parses both ends');
$sp = BFTD_CRM::split_span('2022-Apr');
check($sp['from'] === '2022-04-01' && $sp['to'] === '', 'a student still with us has no end');
check(BFTD_CRM::split_span('') === array('from'=>'','to'=>''), 'and nothing parses to nothing');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
