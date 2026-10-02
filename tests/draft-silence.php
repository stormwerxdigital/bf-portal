<?php
/*
 * Nothing reaches a family about a draft.
 *
 * A tutor drafts a report over several sittings. While it is a draft it does
 * not exist as far as a caregiver is concerned: no email, no portal notice,
 * and no review bar offering to send one. The work is still flagged, so
 * publishing is what opens the gate rather than what loses the notes.
 *
 * The trap this guards against is the one that is invisible in testing and
 * obvious to a parent: an email about a report they cannot open.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');

$GLOBALS['STATUS'] = array();
$GLOBALS['PARENT'] = array();
$GLOBALS['UMETA']  = array();
$GLOBALS['SENT']   = array();
$GLOBALS['NOTED']  = array();

function get_post_status($id){ return $GLOBALS['STATUS'][$id] ?? false; }
function get_post_meta($id,$k,$single=false){ return $single ? '' : array(); }
function update_post_meta($id,$k,$v){ return true; }
function delete_post_meta($id,$k){ return true; }
function get_user_meta($uid,$k,$single=false){ return $GLOBALS['UMETA'][$uid][$k] ?? ($single?'':array()); }
function update_user_meta($uid,$k,$v){ $GLOBALS['UMETA'][$uid][$k]=$v; return true; }
function get_the_title($id){ return 'Student '.$id; }
function wp_strip_all_tags($t,$b=false){ return strip_tags($t); }

class BFTD_CPT {
  const STUDENT='bftd_student'; const ASSESSMENT='bftd_assessment';
  const PROGRESS='bftd_progress'; const SESSION='bftd_session';
  public static function student_id($id){ return $GLOBALS['PARENT'][$id] ?? 0; }
  public static function client_ids($id){ return array(7); }
}
class BFTD_Audit { public static function log(...$a){} }
class BFTD_Emails {
  public static function send($tpl,$to,$tags=array(),$ctx=array()){ $GLOBALS['SENT'][]=$tpl; return true; }
  public static function get_template($k){ return array('subject'=>'','preview'=>'','message'=>''); }
  public static function apply_tags($t,$g){ return $t; }
}
class BFTD_Notices { public static function add(...$a){ $GLOBALS['NOTED'][]=$a; } }
class BFTD_MetaBoxes {
  public static function send_welcome($uid,$sid){
    $k = BFTD_Change_Notify::welcomed_key($sid);
    if (get_user_meta($uid,$k,true)) return;
    update_user_meta($uid,$k,'now');
    BFTD_Notices::add($uid,'system',$sid,$sid,'','Your portal is ready','');
    BFTD_Emails::send('client_welcome','a@b.c');
  }
}
class BFTD_Dashboard { public static function url(...$a){ return '#'; } }

require BFTD_PATH . 'includes/class-bftd-change-notify.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- the gate ---- */
$GLOBALS['STATUS'] = array(10 => 'publish', 11 => 'draft', 12 => 'publish', 13 => 'publish');
$GLOBALS['PARENT'] = array(12 => 10, 13 => 11);   // 12 hangs off a live student, 13 off a draft one

check(BFTD_Change_Notify::is_published(10) === true,  'a published student is live');
check(BFTD_Change_Notify::is_published(11) === false, 'a draft student is not');
check(BFTD_Change_Notify::is_published(12) === true,  'a published report on a live student is live');
check(BFTD_Change_Notify::is_published(13) === false, 'but not one hanging off a draft student');
check(BFTD_Change_Notify::is_published(99) === false, 'and a record that does not exist is never live');
check(BFTD_Change_Notify::is_published(0) === false,  'nor is nothing at all');

/* A pending status is not published either. Reports move through pending
   review on their way out, and that is precisely when nothing should go. */
$GLOBALS['STATUS'][14] = 'pending';
check(BFTD_Change_Notify::is_published(14) === false, 'a report awaiting review is not published');
$GLOBALS['STATUS'][15] = 'private';
check(BFTD_Change_Notify::is_published(15) === false, 'and neither is a private one');

/* ---- publishing is what welcomes a caregiver ---- */
/* A caregiver linked while the student was a draft was deliberately not
   told. If publishing does not tell them, they are never told at all. */
$GLOBALS['SENT'] = array(); $GLOBALS['NOTED'] = array();
$post = new stdClass; $post->ID = 11; $post->post_type = BFTD_CPT::STUDENT;
$GLOBALS['STATUS'][11] = 'publish';
BFTD_Change_Notify::on_publish('publish','draft',$post);
check(in_array('client_welcome',$GLOBALS['SENT'],true), 'publishing a drafted student welcomes the caregiver');
check(count($GLOBALS['NOTED']) === 1, 'and puts one notice in their portal');

/* Saving it again must not welcome them a second time. */
$GLOBALS['SENT'] = array();
BFTD_Change_Notify::on_publish('publish','draft',$post);
check($GLOBALS['SENT'] === array(), 'and a later republish welcomes nobody twice');

/* An update to an already published record is not a publish event. */
$GLOBALS['UMETA'] = array(); $GLOBALS['SENT'] = array();
BFTD_Change_Notify::on_publish('publish','publish',$post);
check($GLOBALS['SENT'] === array(), 'editing a live record is not a welcome');

/* Only students. A published lesson does not welcome anybody. */
$GLOBALS['SENT'] = array();
$lesson = new stdClass; $lesson->ID = 12; $lesson->post_type = BFTD_CPT::SESSION;
BFTD_Change_Notify::on_publish('publish','draft',$lesson);
check($GLOBALS['SENT'] === array(), 'publishing a lesson does not welcome anybody');

/* ---- the tutor is told, rather than left guessing ---- */
/* Hiding the bar on a draft is right, but hiding it silently means a tutor
   who made changes cannot tell whether the plugin noticed. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-change-notify.php');
check(false !== strpos($src, 'is-held'), 'a draft says so instead of showing nothing');
check(false !== strpos($src, 'Nothing has gone to the family'), 'and says plainly that nothing went out');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(false !== strpos($css, '.bftd-change-bar.is-held'), 'and the held bar is styled as its own thing');

/* ---- every path that can reach a family checks the gate ---- */
/* Grep-level, because these are the calls that would otherwise be found by a
   parent rather than by a test. */
$paths = array(
  'class-bftd-change-notify.php' => array('render_bar','guard'),
  'class-bftd-threads.php'       => array('notify'),
  'class-bftd-metaboxes.php'     => array('welcome'),
);
foreach ($paths as $file => $fns) {
  $src = file_get_contents(BFTD_PATH . 'includes/' . $file);
  check(false !== strpos($src, 'is_published'), "$file checks whether the record is published");
}

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
