<?php
/*
 * Autosave, the way Karl set it out (1.148).
 *
 * NOT PUBLISHED: the record is saved, silently. No message, no revision, no
 * "Saved" notice waiting on the next screen, nothing offered back.
 *
 * PUBLISHED: the live record is never written by a machine. One draft is kept
 * beside it, reopening shows the draft in the form, Update takes it live, and
 * the only message is "Draft saved, update to take live".
 *
 * The end-to-end proof (every field of every record type through draft
 * autosave, publish, published autosave and reopen, and update) is
 * tests/real-wp/save-everything.mjs against a real WordPress.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');

$GLOBALS['META'] = array(); $GLOBALS['POSTS'] = array(); $GLOBALS['LOG'] = array();
$GLOBALS['SAVED'] = 0; $GLOBALS['SENT'] = null;

function get_post_meta($id,$k,$s=false){ return $GLOBALS['META'][$id][$k] ?? ($s?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function delete_post_meta($id,$k){ unset($GLOBALS['META'][$id][$k]); return true; }
function get_post($id=null){ if (is_object($id)) return $id; return $GLOBALS['POSTS'][$id] ?? null; }
function get_userdata($id){ $u=new stdClass; $u->ID=$id; $u->display_name='Laurel Sanders'; return $u; }
function get_current_user_id(){ return $GLOBALS['CURRENT'] ?? 7; }
function current_user_can($c,$o=null){ return !empty($GLOBALS['CAN']); }
function check_ajax_referer($a,$b=false,$die=true){ return true; }
function wp_send_json_error($d=null,$s=null){ $GLOBALS['SENT']=array('ok'=>false,'data'=>$d,'status'=>$s); throw new Exception('json'); }
function wp_send_json_success($d=null){ $GLOBALS['SENT']=array('ok'=>true,'data'=>$d); throw new Exception('json'); }
function wp_update_post($a){ foreach (array('post_title','post_status') as $k) if (array_key_exists($k,$a)) $GLOBALS['POSTS'][$a['ID']]->$k = $a[$k]; return $a['ID']; }
function wp_is_post_revision($id){ return false; }
function wp_slash($v){ return $v; }
function wp_unslash($v){ return $v; }
$GLOBALS['FILTERS'] = array(); $GLOBALS['TRANSIENTS'] = array();
if (!function_exists('remove_filter')) { function remove_filter($t,$f,$p=10){ $GLOBALS['FILTERS'][] = array('-', $t); return true; } }
if (!function_exists('get_transient')) { function get_transient($k){ return $GLOBALS['TRANSIENTS'][$k] ?? false; } }
if (!function_exists('delete_transient')) { function delete_transient($k){ unset($GLOBALS['TRANSIENTS'][$k]); return true; } }
if (!function_exists('__return_zero')) { function __return_zero(){ return 0; } }

class BFTD_Roles { public static function post_types(){ return array('bftd_assessment','bftd_progress','bftd_session','bftd_student'); } }
/* The stub records whether the real quiet flag was set when it was called,
   rather than deciding for itself whether to be quiet. Otherwise the check
   below tests the stub's own behaviour and would pass whatever our code did. */
class BFTD_Audit {
  public static $quiet = false;
  public static function log($e,$a=array()){ $GLOBALS['LOG'][]=$e; $GLOBALS['LOG_QUIET'][]=self::$quiet; }
}
/* Enough of the schema and the fields for the pill refresh to run. The pill
   states themselves are the real ones: the stub answers what the section holds,
   and BFTD_Fields::pill_state turns that into the wording, so the check below
   is on our rule rather than on the stub's opinion. */
class BFTD_Schema {
  public static function sections_for_post_type($t){ return array('dxsec-1'=>array(), 'dxsec-4'=>array()); }
}
class BFTD_Fields {
  public static $has = array('dxsec-4'=>true, 'dxsec-1'=>false);
  public static function section_has_content($p,$s){ return !empty(self::$has[$s]); }
  public static function is_visible($p,$s){ return true; }
  public static function pill_state($post_id,$section_id){
    if (!self::section_has_content($post_id,$section_id)) return array('tone'=>'quiet','text'=>'Empty, so not shown to the family');
    if (!self::is_visible($post_id,$section_id)) return array('tone'=>'clay','text'=>'Hidden from the family');
    return array('tone'=>'sage','text'=>'Live for the family');
  }
}
class BFTD_MetaBoxes {
  public static function save_fields($post_id,$post){
    $GLOBALS['SAVED']++; $GLOBALS['SAVED_WITH']=$_POST;
    $GLOBALS['QUIET_DURING_SAVE'] = BFTD_Audit::$quiet;
    BFTD_Audit::log('section_updated');
  }
  /* The real rule, not a stub that always says yes: a stub that cannot answer
     "no" cannot test what happens when a request arrives cut short. */
  public static function posted_is_complete(){
    if (!isset($_POST['bftd_sent'])) return true;
    $sent = (int) $_POST['bftd_sent'];
    if ($sent < 1) return true;
    return self::count_posted_fields() >= $sent;
  }
  public static function count_posted_fields($where = null){
    if (null === $where) $where = $_POST;
    $n = 0;
    foreach (array('bftd','bftd_rows','bftd_sched','bftd_visible','bftd_items') as $k) {
      if (!isset($where[$k])) continue;
      $stack = array($where[$k]);
      while ($stack) { $v = array_pop($stack);
        if (is_array($v)) { foreach ($v as $x) $stack[] = $x; } else { $n++; } }
    }
    return $n;
  }
  public static function log_refused_save($post_id){ $GLOBALS['REFUSED'] = ($GLOBALS['REFUSED'] ?? 0) + 1; }
}

require BFTD_PATH . 'includes/class-bftd-autosave.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function attempt($fn){ try { $fn(); } catch (Exception $e) {} return $GLOBALS['SENT']; }

function mkpost($id,$status,$modified='2026-09-17 10:00:00',$type='bftd_assessment'){
  $p = new stdClass; $p->ID=$id; $p->post_status=$status; $p->post_type=$type;
  $p->post_modified_gmt=$modified; $p->post_title='Kaine M';
  $GLOBALS['POSTS'][$id]=$p; return $p;
}

/* ---- what counts as a draft ---- */
check(BFTD_Autosave::is_draft(mkpost(1,'draft')) === true, 'a draft is a draft');
check(BFTD_Autosave::is_draft(mkpost(2,'auto-draft')) === true, 'so is a screen just opened');
check(BFTD_Autosave::is_draft(mkpost(3,'pending')) === true, 'and one waiting for review');
check(BFTD_Autosave::is_draft(mkpost(4,'private')) === true, 'and a private one, which no family is reading');
check(BFTD_Autosave::is_draft(mkpost(5,'publish')) === false, 'a published report is not');
check(BFTD_Autosave::is_draft(mkpost(6,'future')) === false, 'nor one scheduled to publish itself');
check(BFTD_Autosave::is_draft(mkpost(7,'draft','2026-09-17 10:00:00','post')) === false,
  'and a WordPress post is none of our business');

/* ---- a draft is saved properly ---- */
$GLOBALS['CAN'] = true;
$GLOBALS['SAVED'] = 0; $GLOBALS['LOG'] = array(); $GLOBALS['LOG_QUIET'] = array();
$_POST = array(
  'post_id'    => 1,
  'bftd'       => array('dxsec-4'=>array('kinder_score'=>'10')),
  'post_title' => 'Kaine Miller',
);
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'], 'a draft autosaves');
check('draft' === $r['data']['mode'], 'and says it was really saved, not merely kept');
check(!isset($r['data']['message']), 'with no message for the screen to show: a draft saves silently');

/* The header pills describe the record, which has just changed. They come back
   with the answer so the screen is not left saying "Empty, so not shown to the
   family" beside a figure that has just been saved, which is what sent
   somebody looking for a bug in the report. */
check(isset($r['data']['pills']['dxsec-4']), 'and hands back what the header pills should now say');
check('Live for the family' === $r['data']['pills']['dxsec-4']['text'],
  'for a section that now has something in it');
check('Empty, so not shown to the family' === $r['data']['pills']['dxsec-1']['text'],
  'and for one that still does not');
check(1 === $GLOBALS['SAVED'], 'through the same save the form uses, not around it');
check('10' === $GLOBALS['SAVED_WITH']['bftd']['dxsec-4']['kinder_score'],
  'carrying what was typed, so a figure entered is a figure stored');
check('Kaine Miller' === $GLOBALS['POSTS'][1]->post_title, 'and the title with it');
check(null === BFTD_Autosave::snapshot(1),
  'with no snapshot beside it, because there is nothing left to put back');

/* The log is held back, or twenty-five seconds of typing buries the entries
   people actually made. */
check(true === $GLOBALS['QUIET_DURING_SAVE'],
  'the activity log is quiet while a draft autosaves');
$GLOBALS['TRANSIENTS']['bftd_saved_7'] = 3;
$GLOBALS['SENT']=null;
attempt(function(){ BFTD_Autosave::ajax_save(); });
check(!isset($GLOBALS['TRANSIENTS']['bftd_saved_7']),
  'and leaves no "Saved, N sections changed" notice for the next screen');
check(array() === $GLOBALS['SENT']['data']['warnings'], 'and has nothing to warn about when everything was kept');

/* The one exception: something typed that could not be kept, such as a
   session slot clashing with another of the tutor's. Said at once, on the
   screen being typed on, and not left for the next page load. */
$GLOBALS['TRANSIENTS']['bftd_sched_warn_7'] = array('Sundays at 10:00 am was not added: it runs over a session.');
$GLOBALS['SENT']=null;
attempt(function(){ BFTD_Autosave::ajax_save(); });
check(array('Sundays at 10:00 am was not added: it runs over a session.') === $GLOBALS['SENT']['data']['warnings'],
  'a slot the save could not keep comes back with the autosave');
check(!isset($GLOBALS['TRANSIENTS']['bftd_sched_warn_7']), 'and is not shown a second time on the next page load');
check(false !== strpos(file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js'), "say( 'Not saved: ' + d.warnings.join( ' ' ), 'bad' )"),
  'where the screen says so as a failure, the only time a draft autosave says anything');
check(1 === preg_match("/add_filter\\( 'wp_revisions_to_keep', '__return_zero'/", file_get_contents(BFTD_PATH . 'includes/class-bftd-autosave.php')),
  'and makes no revision');
check(false === BFTD_Audit::$quiet, 'and is listening again afterwards');

/* ---- a brand new record becomes a real draft ----
   WordPress leaves a just-opened record as an "auto-draft": on no list, and
   deleted after a week. Autosaving its fields and saying "Draft saved" left a
   tutor with a diagnostic nobody could find, so the first autosave makes it a
   draft, as Save Draft would. */
$GLOBALS['SAVED'] = 0;
$np = mkpost(20,'auto-draft','0000-00-00 00:00:00'); $np->post_title = 'Auto Draft';
$_POST = array('post_id'=>20, 'bftd'=>array('dxsec-4'=>array('kinder_score'=>'12')), 'post_title'=>'');
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'] && 1 === $GLOBALS['SAVED'], 'a just-opened diagnostic autosaves');
check('draft' === $GLOBALS['POSTS'][20]->post_status, 'and becomes a draft, so it is on the list and is not thrown away');
check('' === $GLOBALS['POSTS'][20]->post_title, 'without keeping the placeholder "Auto Draft" as its name');
$np2 = mkpost(21,'auto-draft','0000-00-00 00:00:00'); $np2->post_title = 'Auto Draft';
$_POST = array('post_id'=>21, 'bftd'=>array('dxsec-4'=>array('kinder_score'=>'12')), 'post_title'=>'Jot Singh diagnostic');
$GLOBALS['SENT']=null;
attempt(function(){ BFTD_Autosave::ajax_save(); });
check('Jot Singh diagnostic' === $GLOBALS['POSTS'][21]->post_title, 'a title typed before the first autosave is kept');
check('draft' === $GLOBALS['POSTS'][1]->post_status, 'an ordinary draft stays a draft');
check(false === BFTD_Autosave::promote_auto_draft(5), 'and a published report is never touched');

/* ---- and it refuses to write over somebody ELSE'S save ---- */
$GLOBALS['SAVED'] = 0;
$GLOBALS['META'][1]['_edit_last'] = 9;        // somebody who is not user 7
$_POST = array(
  'post_id'  => 1,
  'modified' => '2026-09-17 09:00:00',        // what this tab saw when it loaded
  'bftd'     => array('dxsec-4'=>array('kinder_score'=>'99')),
);
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(false === $r['ok'] && 409 === $r['status'],
  'a tab whose view of the record is out of date does not autosave');
check(!empty($r['data']['conflict']), 'and says so rather than failing quietly');
check(0 === $GLOBALS['SAVED'], 'so nothing of theirs is written over');

/* A moved timestamp is not by itself a conflict.
 *
 * The same person moves it too: a second tab of their own, or, before core's
 * autosave was switched off on these screens, core writing the draft behind
 * them. Refusing then loses the work this file exists to protect and blames a
 * colleague who was never there, which is exactly what a tutor saw. */
$GLOBALS['SAVED'] = 0;
$GLOBALS['META'][1]['_edit_last'] = 7;        // the person doing the typing
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'] && 1 === $GLOBALS['SAVED'],
  'but a timestamp this same person moved is not somebody else, and the draft saves');

/* And with nobody recorded as the last writer, the write goes ahead rather
 * than refusing on a guess. */
$GLOBALS['SAVED'] = 0;
unset($GLOBALS['META'][1]['_edit_last']);
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'] && 1 === $GLOBALS['SAVED'], 'and an unrecorded last writer is not a conflict either');

/* A tab that is up to date carries on. */
$GLOBALS['SAVED'] = 0;
$GLOBALS['META'][1]['_edit_last'] = 9;
$_POST['modified'] = '2026-09-17 10:00:00';
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'] && 1 === $GLOBALS['SAVED'], 'while one that is up to date saves as normal');

/* Core's autosave is not left running beside ours. It writes the post on a
 * draft the same person owns, which is what moved the timestamp in the first
 * place, and it protects nothing here: a report keeps no words in the title
 * or the content. */
$auto_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-autosave.php');
check(1 === preg_match("/wp_dequeue_script\(\s*'autosave'\s*\)/", $auto_src),
  "core's own autosave is switched off on these screens");
check(1 === preg_match("/add_action\(\s*'admin_enqueue_scripts',\s*array\(\s*__CLASS__,\s*'silence_core_autosave'/", $auto_src),
  'and that is hooked, not merely written');

/* ---- a published report is never written to ---- */
$GLOBALS['SAVED'] = 0;
$_POST = array('post_id'=>5,'bftd'=>array('sec-x'=>array('body'=>'half a sentence')));
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'], 'a published report still keeps a copy');
check('snapshot' === $r['data']['mode'], 'and says it is a copy, not a save');
check('Draft saved, update to take live' === $r['data']['message'], 'and the one line Karl asked for is the message');
check(0 === $GLOBALS['SAVED'], 'the record itself is untouched, which is the whole point');
check(null !== BFTD_Autosave::snapshot(5), 'the copy is beside it');
check(!isset($GLOBALS['META'][5]['_bftd_sec_x__body']), 'and no field of it was written');

/* ---- and nothing at all without the right to edit ---- */
$GLOBALS['CAN'] = false;
$_POST = array('post_id'=>1,'bftd'=>array('sec-x'=>array('body'=>'x')));
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(false === $r['ok'] && 403 === $r['status'], 'somebody who cannot edit cannot autosave either');
$GLOBALS['CAN'] = true;

/* ---- what a copy holds ---- */
$_POST = array(
  'post_id'    => 5,
  'bftd'       => array('dxsec-1'=>array('body'=>'Forty minutes of writing.')),
  'bftd_rows'  => array('session'=>array('activities'=>array())),
  'post_title' => 'Kaine Miller',
  'unrelated'  => 'should not be kept',
);
$GLOBALS['SENT']=null;
$r = attempt(function(){ BFTD_Autosave::ajax_save(); });
check(true === $r['ok'], 'a published report keeps a copy');

$snap = BFTD_Autosave::snapshot(5);
check(is_array($snap) && !empty($snap['fields']), 'there is a snapshot');
check('Forty minutes of writing.' === $snap['fields']['bftd']['dxsec-1']['body'], 'holding what was typed');
check(!isset($snap['fields']['unrelated']), 'and only the fields this plugin owns');

/* Every field the screens name, not a list. A list missed the people pickers,
   and an autosave without them read as "everybody removed". */
foreach (array('bftd_report_staff', 'bftd_report_staff_present', 'bftd_clients', 'bftd_clients_present', 'bftd_staff',
               'bftd_client_primary', 'bftd_subtitle', 'bftd_subtitle_nonce', 'bftd_skills_done', 'bftd_skills_done_present',
               'bftd', 'bftd_rows', 'bftd_items', 'bftd_sched', 'post_title', 'content') as $k) {
  check(BFTD_Autosave::kept_key($k), "the draft carries $k");
}
check(!BFTD_Autosave::kept_key('bftd_nonce') && !BFTD_Autosave::kept_key('bftd_sent') && !BFTD_Autosave::kept_key('action'),
  'but not the form nonce, the field count or anything that is not ours');
check((int) $snap['by'] === 7, 'recorded against whoever was typing');

/* The report itself is untouched. This is the whole point. */
check(!isset($GLOBALS['META'][5]['_bftd_dxsec_1__body']), 'and the report itself was not written to');

/* ---- a copy is only offered if it is newer than the record ---- */
check(null !== BFTD_Autosave::unsaved($GLOBALS['POSTS'][5]), 'unsaved work is offered back');
$GLOBALS['POSTS'][5]->post_modified_gmt = gmdate('Y-m-d H:i:s', time() + 60);
check(null === BFTD_Autosave::unsaved($GLOBALS['POSTS'][5]),
  'a copy older than the last save is not, because it is the same work');
$GLOBALS['POSTS'][5]->post_modified_gmt = '2026-09-17 10:00:00';

/* ---- reopening a published record shows its draft ----
   Nothing is offered back any more: the draft is drawn into the form, by
   running the ordinary save with every write held in memory. The pieces that
   make "held in memory" true are checked here; tests/real-wp shows the result. */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-autosave.php');
check(1 === preg_match("/add_action\\( 'add_meta_boxes', array\\( __CLASS__, 'open_with_draft' \\)/", $src), 'opening a record checks for a draft before anything is drawn');
foreach (array('get_post_metadata', 'update_post_metadata', 'add_post_metadata', 'delete_post_metadata') as $f) {
  check(false !== strpos($src, "'$f'"), "meta reads and writes are held in memory: $f");
}
check(false !== strpos($src, "'wp_insert_post_empty_content'"), 'no post can be created or changed while the draft is drawn');
check(false !== strpos($src, "'pre_wp_mail'"), 'and no mail sent');
foreach (array('class-bftd-notices.php' => 'notices', 'class-bftd-emails.php' => 'emails', 'class-bftd-metaboxes.php' => 'the welcome flag') as $f => $what) {
  check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/' . $f), 'BFTD_Autosave::$dry'), "and $what stand down while it is drawn");
}
check(!method_exists('BFTD_Autosave', 'ajax_restore') && !method_exists('BFTD_Autosave', 'ajax_discard') && !method_exists('BFTD_Autosave', 'render_bar'),
  'there is no offer to put changes back, and no way to throw the draft away unseen');
check(false === BFTD_Autosave::overlay(mkpost(62, 'publish', gmdate('Y-m-d H:i:s', time() + 60))),
  'a published record with no newer draft opens as it is');

/* ---- a save by a person clears it ---- */
$_POST = array('post_id'=>5,'bftd'=>array('x'=>array('y'=>'z')));
attempt(function(){ BFTD_Autosave::ajax_save(); });
$_POST = array('bftd_nonce'=>'x');
BFTD_Autosave::clear_on_save(5, $GLOBALS['POSTS'][5]);
check(null === BFTD_Autosave::snapshot(5), 'saving properly retires the copy it stood in for');

/* A save from somewhere else in wp-admin must not throw our copy away. */
$_POST = array('post_id'=>5,'bftd'=>array('x'=>array('y'=>'z')));
attempt(function(){ BFTD_Autosave::ajax_save(); });
$_POST = array();
BFTD_Autosave::clear_on_save(5, $GLOBALS['POSTS'][5]);
check(null !== BFTD_Autosave::snapshot(5), 'while a save from another screen leaves it alone');

/* ---- the browser side ---- */
$js_raw = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');

/* Comments are stripped before anything is looked for. The comment explaining
   this bug quotes the broken code it replaced, and a check that searched the
   whole file would find the quotation and pass after the real call was
   deleted. That is not hypothetical: it happened while writing these. */
$js = preg_replace('#/\*[\s\S]*?\*/#', '', $js_raw);
$js = preg_replace('#(^|\n)\s*//[^\n]*#', '$1', $js);

/* And narrowed to the autosave block. Three separate blocks in this file hook
   TinyMCE - the uploader and the paste handler have their own - so a check run
   across the whole file goes on passing after the autosave block's own hook is
   deleted, which is what happened first time. */
$start = strpos($js_raw, 'Autosave (Karl, 1.148)');
check(false !== $start, 'the autosave block is findable');
$block_raw = substr($js_raw, $start);
/* The block's own opening is the first match, so the search for the NEXT one
   starts past it. */
$open = strpos($block_raw, '( function ( $ ) {');
$end  = (false === $open) ? false : strpos($block_raw, "\n( function ( $ ) {", $open + 10);
if (false !== $end) $block_raw = substr($block_raw, 0, $end);
$block = preg_replace('#/\*[\s\S]*?\*/#', '', $block_raw);
$block = preg_replace('#(^|\n)\s*//[^\n]*#', '$1', $block);
check(strlen($block) > 1500 && strlen($block) < strlen($js),
  'and is read on its own, not as the whole file');
check(false !== strpos($js, 'BFTD.autosave'), 'the screen only autosaves when the server says it may');
check(false !== strpos($js, 'tinymce.triggerSave()'),
  'and takes the editors with it, which otherwise keep their text in an iframe');

/* ---- the editors have to be able to START one ----------------------------
   The line above only says a snapshot carries the editor text. It says
   nothing about whether an editor can cause a snapshot to be taken, and for
   three versions it could not: the binding was a lone

       if ( window.tinymce ) { tinymce.on( 'AddEditor', ... ) }

   and WordPress prints enqueued footer scripts before it prints TinyMCE's
   own, so that guard was false every time. The branch never ran. A report is
   almost entirely rich text, so autosave only ever fired if the tutor also
   touched a plain field.

   Reproduced in a browser against real TinyMCE, in WordPress's script order:
   typing in an editor produced zero autosaves before the fix and one after.
   These checks are that reproduction written down, so the shape that failed
   cannot come back. */

/* The binding may not depend on TinyMCE already being loaded. */
check(1 === preg_match('/setInterval\([\s\S]{0,200}watchEditors\(\)/', $block),
  'if TinyMCE is not loaded yet, the screen waits for it rather than giving up');

/* Editors that already exist have to be picked up, not only new ones. An
   AddEditor listener alone misses every editor added before it was attached. */
check(1 === preg_match('/tinymce\.editors[\s\S]{0,160}bindEditor/', $block),
  'editors already on the page are bound, not only ones added afterwards');
check(1 === preg_match('/tinymce\.on\( .AddEditor./', $block),
  'and ones added afterwards are bound too');
check(false !== strpos($block, "'tinymce-editor-init'"),
  "and WordPress's own signal is listened for, whenever TinyMCE loaded");

/* Binding twice would fire two snapshots for one keystroke. */
check(1 === preg_match('/bftdWatched/', $block),
  'an editor is never bound twice, however many of those paths reach it');

/* A pasted screenshot is not a keystroke. It arrives as SetContent, and
   pasting work samples is most of what this screen is for. */
foreach (array('input', 'keyup', 'change', 'SetContent', 'ExecCommand') as $ev) {
  check(1 === preg_match('/ed\.on\( \x27[^\x27]*' . $ev . '/', $block),
    "typing, pasting and the toolbar all count as a change: $ev");
}
check(false !== strpos($js, 'sendBeacon'), 'the last few seconds of typing survive the tab closing');
check(1 === preg_match('/conflict[\s\S]{0,200}stopped = true/', $js),
  'and it stops for good once somebody else has saved, rather than overwriting them one keystroke at a time');
check(1 === preg_match("/name: 'modified'/", $js),
  'which it can only tell by sending what it believes the record looked like');
check(1 === preg_match('/if \( d\.modified \) seen = d\.modified;/', $js),
  'and by moving that forward after each save of its own');
check(1 === preg_match("/'draft' === d\.mode \) \{[\s\S]{0,200}else say\( '', '' \)/", $js),
  'a draft that autosaved shows nothing');
check(1 === preg_match("/say\( d\.message \|\| BFTD\.draft_line, 'good' \)/", $js),
  'and a published record shows the one line');
check(1 === preg_match("/'1' === String\( BFTD\.has_draft \)/", $js),
  'which is also shown when the screen opens on a draft (a "0" from the server is a string, and truthy)');
check(1 === preg_match("/data\.unshift\( \{ name: 'bftd_sent'/", $js) && false !== strpos($js, 'prependTo( $form )'),
  'the field count goes first, so a server that stops part way still reads it');
check(0 === preg_match('/bftd-restore/', $js), 'and nothing offers changes back');

/* The header pill describes the record, and a draft autosave changes it. It is
   worked out on the server and handed back, rather than guessed at in the
   browser: whether standing wording counts as content is the rule that decides
   what a family sees, and a second copy of it here could not be tested against
   the one that matters. */
check(1 === preg_match('/if \\( d\\.pills \\)/', $js),
  'a saved draft refreshes the pills from what the server says');
check(0 === preg_match('/section_has_content/', $js),
  'and the browser never works that answer out for itself');
$fields_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
$auto_src   = file_get_contents(BFTD_PATH . 'includes/class-bftd-autosave.php');
check(1 === preg_match('/function pill_state\\(/', $fields_src),
  'with one definition of the three states');
check(preg_match_all('/pill_state\\(/', $fields_src) >= 2 && preg_match_all('/pill_state\\(/', $auto_src) >= 1,
  'used by the header that draws it and by the autosave that refreshes it');
check(false !== strpos($js, 'form.on( ' . chr(39) . 'submit' . chr(39)), 'a save by a person stops it too');

echo $fail ? "\nFAIL\n" : "\nPASS\n";

/* ------------------------------------------------------------------------ */
/* A request PHP cut short must not be saved, and must not be reported saved */
/* ------------------------------------------------------------------------ */

$GLOBALS['SAVED']   = 0;
$GLOBALS['REFUSED'] = 0;
$GLOBALS['SENT']    = null;

$_POST = array(
  'nonce'      => 'ok',
  'post_id'    => 1,
  'bftd_sent'  => 40,
  'bftd'       => array('session' => array('notes' => 'only this arrived')),
);
try { BFTD_Autosave::ajax_save(); } catch (Exception $e) {}

check(0 === $GLOBALS['SAVED'], 'a truncated request writes nothing');
check(1 === $GLOBALS['REFUSED'], 'and is written to the activity log');
check(is_array($GLOBALS['SENT']) && false === $GLOBALS['SENT']['ok'],
  'and the screen is told it failed rather than told it saved');
check(413 === $GLOBALS['SENT']['status'],
  'with the status that means the request was too large, not a generic error');
check(false !== strpos($GLOBALS['SENT']['data']['message'], 'nothing was written'),
  'and a message that says what happened to the work');

/* ---- skills and activities, one copy per record ----
 * The libraries are kept by this autosave too, so WordPress's own, which keeps
 * one copy per person, is switched off on their screens as well. */
class BFTD_Skills { const POST_TYPE = 'bftd_skill'; }
class BFTD_Activities { const POST_TYPE = 'bftd_activity'; }
check(in_array('bftd_skill', BFTD_Autosave::types(), true) && in_array('bftd_activity', BFTD_Autosave::types(), true),
  'skills and activities are kept by this autosave');
check(BFTD_Autosave::is_draft(mkpost(60,'draft','2026-09-17 10:00:00','bftd_activity')) === true,
  'a draft activity is saved for real, like any other draft');

exit($fail ? 1 : 0);
