<?php
/*
 * The sessions screen.
 *
 * A flat list of sessions repeats the child, the client and the tutor on
 * twenty consecutive rows, and answers the question nobody asks. The shape
 * here follows the practice instead: a section per tutor, a row per student,
 * the sessions behind the row.
 *
 * What has to be true, and none of it is visible in the markup:
 *
 *   the attendance record counts what happened, drafts apart, because a
 *   draft is a tutor's notes in progress and is not a record of anything;
 *
 *   the sessions are not read until a row is opened, or the screen reads
 *   fifty thousand records in case somebody looks;
 *
 *   a page of twenty is twenty, and the last page is the remainder rather
 *   than twenty of whatever is left over.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['USERS'] = array();
$GLOBALS['QUERIES'] = 0;

function add_action(...$a) {} function add_filter(...$a) {}
$GLOBALS['CAN_PUBLISH'] = true;
$GLOBALS['TYPE_OBJ'] = (object) array('cap' => (object) array('publish_posts' => 'publish_bftd_sessions'), 'labels' => (object) array('name' => 'Sessions'));
function get_post_type_object($t){ return $GLOBALS['TYPE_OBJ']; }
function current_user_can($c,$id=0){ return 0 !== strpos($c,'publish_') || $GLOBALS['CAN_PUBLISH']; }
function get_post($id=null){ return $GLOBALS['POSTS'][(int)$id] ?? null; }
function get_post_type($id){ $p=get_post($id); return $p?$p->post_type:''; }
function get_the_title($id){ $p=get_post($id); return $p?$p->post_title:''; }
function get_post_status($id){ $p=get_post($id); return $p?$p->post_status:'publish'; }
function get_post_meta($id,$k,$s=false){ $v=$GLOBALS['META'][$id][$k]??null; return null===$v?($s?'':array()):($s?$v:(array)$v); }
function get_userdata($id){ return $GLOBALS['USERS'][(int)$id] ?? false; }
function get_current_user_id(){ return 1; }
function is_admin(){ return true; }
function get_edit_post_link($id){ return '/wp-admin/post.php?post='.(int)$id; }
function wp_list_pluck($l,$f){ $o=array(); foreach((array)$l as $r){ $o[]=is_array($r)?$r[$f]:$r->$f; } return $o; }
function update_meta_cache($t,$i){ $GLOBALS['QUERIES']++; return true; }
function cache_users($i){ $GLOBALS['QUERIES']++; return true; }
function add_query_arg($a,$b=null,$c=null){ if (is_array($a)) { $q=$a; $u=(string)$b; } else { $q=array($a=>$b); $u=(string)$c; } return $u.(strpos($u,'?')===false?'?':'&').http_build_query($q); }
function get_posts($a){
  $GLOBALS['QUERIES']++;
  $type=$a['post_type']??''; $out=array();
  if ('bftd_session'===$type) {
    $want=array_map('intval',(array)($a['meta_query'][0]['value']??array()));
    $states=(array)($a['post_status']??array('publish'));
    foreach($GLOBALS['POSTS'] as $id=>$p){
      if('bftd_session'!==$p->post_type) continue;
      if(!in_array($p->post_status,$states,true)) continue;
      if(!in_array((int)($GLOBALS['META'][$id]['_bftd_student_id']??0),$want,true)) continue;
      $out[]=$id;
    }
    return $out;
  }
  foreach($GLOBALS['POSTS'] as $id=>$p){
    if($p->post_type!==$type) continue;
    if(!empty($a['post__in'])&&!in_array($id,$a['post__in'],true)) continue;
    if(!empty($a['meta_query'])){
      $q=$a['meta_query'][0];
      $have=(array)($GLOBALS['META'][$id][$q['key']]??array());
      if(!in_array((string)$q['value'], array_map('strval',$have), true)) continue;
    }
    $out[]=$p;
  }
  usort($out,function($a,$b){ return strcasecmp($a->post_title,$b->post_title); });
  return empty($a['fields'])?$out:wp_list_pluck($out,'ID');
}

class BFTD_Admin { const MENU_SLUG='bftd'; const HUB_SLUG='bftd-student'; }
class BFTD_Roles {
  const STAFF_CAP='bftd_manage_students';
  public static function is_staff($u=null){ return true; }
  public static function can_manage($u=null){ return true; }
  public static function relationship($u){ return 'Parent or guardian'; }
  public static function role_name($u){ return 'Tutor'; }
  public static function post_types(){ return array('bftd_session'); }
  public static function plural_for($p){ return $p.'s'; }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Fields { public static function get($id,$g,$k,$f=array()){ return $GLOBALS['META'][$id]['_bftd_'.$g.'__'.$k] ?? ''; } }
class BFTD_Access {
  public static function visible_student_ids($u){ return $GLOBALS['VISIBLE']; }
  public static function can_staff_view($id,$u=0){ return in_array((int)$id, $GLOBALS['VISIBLE'], true); }
}
require BFTD_PATH.'includes/class-bftd-cpt.php';
require BFTD_PATH.'includes/class-bftd-schedule.php';
require BFTD_PATH.'includes/class-bftd-students.php';
require BFTD_PATH.'includes/class-bftd-preview.php';
require BFTD_PATH.'includes/class-bftd-sessions.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- a practice ---- */
$GLOBALS['USERS'][5]=(object)array('ID'=>5,'display_name'=>'Rae Tanaka');
$GLOBALS['USERS'][6]=(object)array('ID'=>6,'display_name'=>'Jo Patel');
$GLOBALS['USERS'][20]=(object)array('ID'=>20,'display_name'=>'Laurel Sanders','user_email'=>'l@example.test');

$GLOBALS['VISIBLE']=array(); $sess=5000;
function student($id,$name,$tutors,$client=20){
  $GLOBALS['POSTS'][$id]=(object)array('ID'=>$id,'post_type'=>'bftd_student','post_status'=>'publish','post_title'=>$name);
  $GLOBALS['META'][$id]=array('_bftd_assigned_staff'=>$tutors,'_bftd_client_user_id'=>array($client),
    '_bftd_client_primary'=>$client,'_bftd_student__status'=>'active','_bftd_student__lessons_bank'=>60);
  $GLOBALS['VISIBLE'][]=$id;
}
function sess($owner,$date,$status,$published=true,$time='09:00'){
  global $sess;
  $p=$sess++;
  $GLOBALS['POSTS'][$p]=(object)array('ID'=>$p,'post_type'=>'bftd_session',
    'post_status'=>$published?'publish':'draft','post_title'=>'s');
  $GLOBALS['META'][$p]=array('_bftd_student_id'=>$owner,'_bftd_session__session_date'=>$date,
    '_bftd_session__session_time'=>$time,'_bftd_session__status'=>$status);
  return $p;
}

student(1001,'Kaine M',array(5));
/* A progress report, because the session numbers on this screen are the same
   numbers the family's report uses, and a screen that invented its own would
   have a tutor and a parent naming different sessions in the same sentence. */
$GLOBALS['POSTS'][9001]=(object)array('ID'=>9001,'post_type'=>'bftd_progress','post_status'=>'publish','post_title'=>'PR');
$GLOBALS['META'][9001]=array('_bftd_student_id'=>1001);
student(1002,'Alice B',array(6));
student(1003,'Milo Okafor',array(5,6));   // two tutors
student(1004,'Nell Ward',array());        // nobody assigned

/* Kaine: 45 sessions, a mixture, plus two drafts. */
for ($i=0;$i<45;$i++) {
  $st = ($i%9===8) ? 'missed' : (($i%7===6) ? 'rescheduled' : 'held');
  sess(1001, sprintf('2026-%02d-%02d', 1+intdiv($i,4), 1+($i%28)), $st);
}
sess(1001,'2026-12-01','held',false);
sess(1001,'','held',false);               // started, no date yet
sess(1002,'2026-03-03','held');
sess(1002,'2026-03-10','missed');

/* ---- the attendance record ---- */
$rec = BFTD_Sessions::records(array(1001,1002,1003));
check(45 === $rec[1001]['held'] + $rec[1001]['rescheduled'] + $rec[1001]['missed'],
  'every published session is in the record, got ' . ($rec[1001]['held'] + $rec[1001]['rescheduled'] + $rec[1001]['missed']));
check(2 === $rec[1001]['draft'], 'drafts are counted apart, got ' . $rec[1001]['draft']);
check($rec[1001]['held'] > 0 && $rec[1001]['missed'] > 0 && $rec[1001]['rescheduled'] > 0,
  'and the three outcomes are told apart');
/* A draft is a tutor's notes in progress. Folded into "attended" it says a
   child came to a session nobody has finished writing up. */
check(47 === $rec[1001]['total'], 'the total counts them all, got ' . $rec[1001]['total']);
check(0 === $rec[1003]['total'], 'a student with no sessions has none, rather than nothing');
check('' === $rec[1003]['last'], 'and no last date to show');

/* ---- and the row it becomes ---- */
$rows = BFTD_Sessions::rows(array(1001,1002));
check(isset($rows[1001]['record']), 'the row carries the record');
check('Laurel Sanders' === $rows[1001]['client'], 'and the client, written once rather than on every session');

ob_start(); BFTD_Sessions::record_cell($rows[1001]['record']); $cell = ob_get_clean();
check(false !== strpos($cell,'attended') && false !== strpos($cell,'missed'),
  'the record is read in words, not by colour alone');
check(false !== strpos($cell,'draft'), 'and says how many are unfinished');
ob_start(); BFTD_Sessions::record_cell($rec[1003]); $none = ob_get_clean();
check(false !== strpos($none,'Nothing recorded yet'), 'a student with none says so');

/* ---- the drawer ---- */
ob_start(); BFTD_Sessions::drawer(1001, 1); $page1 = ob_get_clean();
check(20 === substr_count($page1,'<tr>') - 1, 'a page is twenty sessions, got ' . (substr_count($page1,'<tr>')-1));
check(false !== strpos($page1,'1–20 of 47'), 'saying which twenty of how many');
/* Each session opens, and previews the progress report it belongs to without
   opening it first. The same link as the session screen's own button. */
check(substr_count($page1, '>Open</a>') === substr_count($page1, '>View preview</a>') && substr_count($page1, '>View preview</a>') === 20,
  'every session has View preview beside Open, got ' . substr_count($page1, '>View preview</a>'));
check(1 === preg_match('#href="[^"]*' . BFTD_Preview::QUERY . '=\d+[^"]*_wpnonce=[^"]+"\s+target="_blank"[^>]*>View preview</a>#', $page1),
  'opening the preview of that session, signed, in a new tab');
check(3 === substr_count($page1,'data-page='), 'with a button per page, got ' . substr_count($page1,'data-page='));
check(false !== strpos($page1,'is-on'), 'and the page you are on marked');

ob_start(); BFTD_Sessions::drawer(1001, 3); $page3 = ob_get_clean();
check(7 === substr_count($page3,'<tr>') - 1, 'the last page is the remainder, got ' . (substr_count($page3,'<tr>')-1));
check(false !== strpos($page3,'41–47 of 47'), 'and says so');

/* A draft can be published from the row, by someone allowed to publish. A
   published session has nothing to publish. */
$drafts_on_3 = substr_count($page3, '>Draft</span>');
check($drafts_on_3 > 0 && $drafts_on_3 === substr_count($page3, '>Publish</a>'),
  'every draft row has Publish beside View preview, got ' . substr_count($page3, '>Publish</a>') . ' for ' . $drafts_on_3 . ' drafts');
check(0 === substr_count($page1, '>Publish</a>') || substr_count($page1, '>Draft</span>') === substr_count($page1, '>Publish</a>'),
  'and a published row has none');
check(1 === preg_match('#href="[^"]*admin-post\.php\?action=bftd_publish_session&(amp;)?session=\d+&(amp;)?_wpnonce=[^"]+"[^>]*>Publish</a>#', $page3),
  'publishing through a signed request for that session');
$GLOBALS['CAN_PUBLISH'] = false;
ob_start(); BFTD_Sessions::drawer(1001, 3); $nopub = ob_get_clean();
$GLOBALS['CAN_PUBLISH'] = true;
check(0 === substr_count($nopub, '>Publish</a>'), 'someone who may not publish is not offered it');

/* ---- between the regular view and the advanced view ---- */
check(1 === preg_match('#>View preview</a>\s*<a class="bftd-ses-adv" href="[^"]*edit\.php\?post_type=bftd_session&(amp;)?bftd_student=1001">View advanced</a>\s*<a class="bftd-ses-publish"#', $page3),
  'a draft row reads Open, View preview, View advanced, Publish, the advanced link narrowed to this student');
check(substr_count($page1, '>View advanced</a>') === substr_count($page1, '>Open</a>'), 'and every row has View advanced, published or not');

/* The advanced view's two new columns are the drawer's own cells, so the
   two views cannot disagree about a session. */
$a_sid = BFTD_CPT::sessions_of(array(1001), true)[1001][1];
$nums  = BFTD_Sessions::numbers_for(1001);
ob_start(); BFTD_Sessions::number_cell($a_sid, $nums); $num_cell = ob_get_clean();
ob_start(); BFTD_Sessions::happened_cell($a_sid); $what_cell = ob_get_clean();
check(false !== strpos($page1, '<td class="bftd-ses-num">' . $num_cell . '</td>'), 'the drawer\'s session number is the shared cell: ' . $num_cell);
check(false !== strpos($page1, '<td data-l="What happened">' . $what_cell . '</td>'), 'and so is what happened: ' . strip_tags($what_cell));
$cols = BFTD_CPT::session_columns(array('cb' => 'x'));
check(array('cb', 'bftd_student', 'bftd_number', 'bftd_client', 'bftd_tutor', 'bftd_when', 'bftd_what') === array_keys($cols)
  && 'Session number' === $cols['bftd_number'] && 'What happened' === $cols['bftd_what'],
  'the advanced view lists the session number after the student, and what happened last');
ob_start(); BFTD_CPT::session_column('bftd_number', $a_sid); $c1 = ob_get_clean();
ob_start(); BFTD_CPT::session_column('bftd_what', $a_sid); $c2 = ob_get_clean();
check($c1 === $num_cell && $c2 === $what_cell, 'drawn by the same cells as the drawer');

/* Arriving on the regular view for one student: that student alone, their
   sessions already open, drawn by the server rather than fetched. */
$_GET = array('student' => '1001');
ob_start(); BFTD_Sessions::render(); $one = ob_get_clean();
$_GET = array();
check(1 === substr_count($one, 'class="bftd-ses-r"'), 'the regular view for one student shows that student only, got ' . substr_count($one, 'class="bftd-ses-r"'));
check(false !== strpos($one, 'aria-expanded="true"') && 1 === preg_match('#<tr class="bftd-ses-drawer" id="bftd-ses-1001">#', $one)
  && false !== strpos($one, 'data-loaded="1"') && false !== strpos($one, '>View advanced</a>'),
  'with their sessions open and already there');
$GLOBALS['USERS'][6] = (object) array('ID' => 6, 'display_name' => 'Second Tutor');
$keep = $GLOBALS['META'][1001][BFTD_CPT::STAFF_KEY] ?? null;
$GLOBALS['META'][1001][BFTD_CPT::STAFF_KEY] = array_merge((array) $keep, array(6));
$_GET = array('student' => '1001');
ob_start(); BFTD_Sessions::render(); $twice = ob_get_clean();
$_GET = array();
$GLOBALS['META'][1001][BFTD_CPT::STAFF_KEY] = $keep;
preg_match_all('#<tr class="bftd-ses-drawer" id="([^"]+)"(\s*hidden)?>#', $twice, $dm);
check(count($dm[1]) > 1 && count(array_unique($dm[1])) === count($dm[1]) && 1 === count(array_filter($dm[2], function ($h) { return '' === $h; })),
  'a student under two tutors opens once, and each copy has its own drawer: ' . implode(', ', $dm[1]));
check(false !== strpos($one, 'class="bftd-ses-one"') && false !== strpos($one, 'page=bftd-sessions">Every student</a>'), 'saying so, with the way back to every student');
check(1 === preg_match('#href="[^"]*edit\.php\?post_type=bftd_session&(amp;)?bftd_student=1001">Advanced view</a>#', $one), 'and the advanced view for the same student');
$_GET = array('student' => '999999');
ob_start(); BFTD_Sessions::render(); $other = ob_get_clean();
$_GET = array();
check(0 === substr_count($other, 'class="bftd-ses-r"'), 'a student who is not yours shows nobody, not everybody');
ob_start(); BFTD_Sessions::render(); $everyone = ob_get_clean();
check(substr_count($everyone, 'class="bftd-ses-r"') > 1 && false === strpos($everyone, 'aria-expanded="true"') && false === strpos($everyone, 'bftd-ses-one'),
  'and with nobody named, every student, every row closed');
check(1 === preg_match('#href="[^"]*edit\.php\?post_type=bftd_session">Advanced view</a>#', $everyone), 'pointing at the advanced view for everyone');

/* The advanced view names itself and links back. */
$GLOBALS['typenow'] = 'bftd_session'; $GLOBALS['pagenow'] = 'edit.php';
BFTD_Sessions::advanced_heading();
check('Sessions: advanced view' === $GLOBALS['TYPE_OBJ']->labels->name, 'the WordPress list is headed Sessions: advanced view');
$_GET = array('bftd_student' => '1001');
ob_start(); BFTD_Sessions::regular_link('top'); $back = ob_get_clean();
ob_start(); BFTD_Sessions::regular_link('bottom'); $back2 = ob_get_clean();
$_GET = array();
check(1 === preg_match('#<a class="bftd-ses-regular" href="[^"]*admin\.php\?page=bftd-sessions&(amp;)?student=1001">Regular view</a>#', $back), 'with Regular view back to the same student');
check('' === $back2, 'once, above the list, not again below it');
$GLOBALS['typenow'] = 'bftd_student'; $GLOBALS['TYPE_OBJ']->labels->name = 'Sessions';
BFTD_Sessions::advanced_heading();
ob_start(); BFTD_Sessions::regular_link('top'); $elsewhere = ob_get_clean();
check('Sessions' === $GLOBALS['TYPE_OBJ']->labels->name && '' === $elsewhere, 'and no other list is touched');

/* Asking for a page that is not there lands on the last one rather than on
   an empty drawer. */
ob_start(); BFTD_Sessions::drawer(1001, 99); $far = ob_get_clean();
check($far === $page3, 'a page past the end is the last page, not an empty one');

/* Newest first, because the session somebody is looking for is almost always
   the one that just happened. An undated one is unfinished, so it sorts to
   the end rather than to the top. */
$all = BFTD_CPT::sessions_of(array(1001), true);
$dates = array();
foreach ($all[1001] as $sid) $dates[] = BFTD_Fields::get($sid,'session','session_date');
$sorted = $dates; $undated = array_filter($dates, function($d){ return '' === $d; });
check('' === end($dates), 'an undated session is last, not first');
array_pop($sorted);
$copy = $sorted; rsort($copy);
check($copy === $sorted, 'and the rest are newest first');

/* ---- what one session row says ---- */
check(false !== strpos($page1,'Published') && false !== strpos($page3,'Draft'),
  'a row says whether the family can see it');
check(1 === preg_match('/Session \d+/',$page1), 'and which session it is in the programme');
/* The same numbering the family's report uses. Forty five published sessions
   means the newest is forty five, and a screen that numbered its own page
   would call it one. */
check(false !== strpos($page1,'Session 45'), 'counted from the start of the programme, not from the top of the page');
check(false === strpos($page1,'Session 1<'), 'so session one is not on the first page at all');
/* However this site writes a time. The format is a site setting, so
   asserting "9:00 am" would fail on a practice that prefers a 24 hour
   clock — and pass while the time was missing on one that does not. */
check(false !== strpos($page1,'9:00'), 'with the time beside the date');
check(false !== strpos($page1,'bftd-ses-t'), 'as its own line under it');
check(false !== strpos($page3,'No date yet'), 'and an unfinished one says it has no date');

/* ---- the cost ---- */
/* The screen must not read a student's sessions to draw their row: fifty
   thousand records fetched in case somebody opens one. */
$GLOBALS['QUERIES'] = 0;
BFTD_Sessions::rows(array(1001,1002,1003,1004));
$screen = $GLOBALS['QUERIES'];
check($screen <= 6, 'four students cost ' . $screen . ' queries');

$GLOBALS['QUERIES'] = 0;
BFTD_Sessions::rows(array(1001));
$one = $GLOBALS['QUERIES'];
check($one === $screen, 'and one student costs the same as four, got ' . $one);

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
