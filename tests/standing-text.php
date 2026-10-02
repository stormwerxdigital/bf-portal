<?php
/*
 * Wording that belongs to the practice rather than to one report.
 *
 * The paragraph explaining what the handwriting task measures is the same
 * promise on every report Brilliant Futures sends. It is not one tutor's to
 * reword on a Tuesday, and it should not have to be retyped for every child
 * either.
 *
 * Two things here fail quietly if they are wrong. A screen that hides a field
 * is not a permission, so the save has to refuse it too. And standing text a
 * person never typed is not content, so a section carrying only its own
 * boilerplate must not read as a section somebody has started.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__).'/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['META'] = array(); $GLOBALS['RANK'] = 4;
function get_post_meta($id,$k,$s=false){ return $GLOBALS['META'][$id][$k] ?? ($s?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function get_current_user_id(){ return 7; }

class BFTD_Roles {
  public static function rank($u){ return $GLOBALS['RANK']; }
  public static function tiers(){ return array(
    'bftd_client'=>array('label'=>'Client','rank'=>0),
    'bftd_tutor'=>array('label'=>'Tutor','rank'=>1),
    'bftd_manager'=>array('label'=>'Tutor Manager','rank'=>2),
    'bftd_senior'=>array('label'=>'Senior Manager','rank'=>3),
    'administrator'=>array('label'=>'Administrator','rank'=>4),
  ); }
}
class BFTD_Items { const PRIORITY='priority'; const REVIEW='review'; }
require BFTD_PATH . 'includes/class-bftd-schema.php';
require BFTD_PATH . 'includes/class-bftd-fields.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$dx1    = BFTD_Schema::sections()['dxsec-1']['fields'];
$body   = $dx1['body'];
$method = $dx1['method'];
$notes  = $dx1['body_after'];

/* ---- it is there without anybody typing it ---- */
check(!empty($body['shipped']), 'the section carries standing wording');
$text = strip_tags($body['shipped']);
check(false !== strpos($text, 'the student completed a one minute handwriting task'),
  'which is the practice\'s own words');
check(false !== strpos($body['shipped'], 'https://bftutoring.com/reading-assessments/'),
  'including the link out to the fuller explanation');
check(6 === substr_count($body['shipped'], '<p>'), 'in six paragraphs');

/* It says "the student", which is right for a girl, a boy and a grown adult
   learner without anybody editing it or a name being merged in. */
check(false === strpos($text, 'Kaine'), 'no child is named in text used on every report');
check(1 !== preg_match('/\{[a-z_]+\}/', $body['shipped']), 'and there is no tag left unfilled');
check(1 !== preg_match('/\b(he|she|his|her)\b/', $text), 'nor a pronoun that would be wrong for half of them');

/* An untouched report shows it, because it is the field's value. */
check($body['shipped'] === BFTD_Fields::get(99, 'dxsec-1', 'body', $body),
  'an untouched report already reads it');
BFTD_Fields::set(99, 'dxsec-1', 'body', '<p>Rewritten by somebody senior.</p>');
check('<p>Rewritten by somebody senior.</p>' === BFTD_Fields::get(99, 'dxsec-1', 'body', $body),
  'and a rewrite replaces it');
$GLOBALS['META'] = array();

/* ---- standing text is not content ---- */
/* A section whose only value is its own boilerplate has not been started, and
   showing it to a family would show them an explanation of an assessment
   nobody did. */
check(false === BFTD_Fields::section_has_content(99, 'dxsec-1'),
  'a section carrying only its boilerplate has not been started');
BFTD_Fields::set(99, 'dxsec-1', 'score', '25');
check(true === BFTD_Fields::section_has_content(99, 'dxsec-1'),
  'while a score makes it a section somebody is writing');
$GLOBALS['META'] = array();

/* ---- who may change it ---- */
foreach (array(4 => 'an administrator', 3 => 'a senior manager') as $rank => $who) {
  $GLOBALS['RANK'] = $rank;
  check(true === BFTD_Fields::may_edit($body), "$who may reword it");
}
foreach (array(2 => 'a tutor manager', 1 => 'a tutor', 0 => 'a family', -1 => 'a stranger') as $rank => $who) {
  $GLOBALS['RANK'] = $rank;
  check(false === BFTD_Fields::may_edit($body), "$who may not");
}

/* Everything else on the screen is still everybody's. */
$GLOBALS['RANK'] = 1;
$score = BFTD_Schema::sections()['dxsec-1']['fields']['score'];
check(true === BFTD_Fields::may_edit($score), 'a tutor still enters the score');

/* ---- a hidden field is not a permission ---- */
/* The screen does not offer it, but a screen is not a lock: posting the form
   by hand has to change nothing. */
$fields = file_get_contents(BFTD_PATH . 'includes/class-bftd-fields.php');
check(1 === preg_match('/function save_post[\s\S]{0,1400}if \( ! self::may_edit\( \$field \) \) \{\s*\$after\[ \$key \] = \$before\[ \$key \];\s*continue;/', $fields),
  'the save leaves a field this person may not edit exactly as it was');
check(1 === preg_match('/if \( ! self::may_edit\( \$field \) \) \{\s*self::render_locked/', $fields),
  'and the screen shows it rather than offering a box');

/* A tutor still has to be able to read it: it is above their own notes on the
   report they are writing. */
check(false !== strpos($fields, 'function render_locked('), 'a locked field is still shown');
check(false !== strpos($fields, 'Set by '), 'labelled with who it belongs to');
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
check(false !== strpos($css, '.bftd-locked-box'), 'and styled as standing wording rather than a disabled input');

/* ---- empty means what a person sees ---- */
/* An editor left alone still posts a paragraph. Testing the stored string
   against '' meant the wording appeared once and was gone for good the first
   time anybody pressed Update, which is exactly how it was found. */
foreach (array(
  ''               => 'never saved',
  '<p></p>'        => 'an editor left alone',
  "\n"             => 'a stray newline',
  '<p>&nbsp;</p>'  => 'a paragraph holding one space',
  '<p><br></p>'    => 'a paragraph holding a line break',
) as $stored => $what) {
  $GLOBALS['META'] = array();
  if ('' !== $stored) $GLOBALS['META'][99]['_bftd_dxsec_1__body'] = $stored;
  check($body['shipped'] === BFTD_Fields::get(99, 'dxsec-1', 'body', $body),
    "the wording comes back after $what");
}
$GLOBALS['META'] = array();

/* And a zero is still a value, which is the thing this kind of rule breaks. */
check(true === BFTD_Fields::has_value('0'), 'a nought is still an answer');
check(true === BFTD_Fields::has_value(0), 'however it is typed');
check(false === BFTD_Fields::has_value('&nbsp;'), 'while a non-breaking space is not');

/* ---- saving it unchanged keeps it joined to the practice's copy ---- */
/* Store the words and a later change to the practice's wording would reach
   every future report and none of the ones already written. */
check(1 === preg_match(
  '/if \( null !== \$fallback && \$after\[ \$key \] === \$fallback \) \{\s*\$after\[ \$key \] = \x27\x27;/',
  $fields), 'saving the standard wording back stores nothing');

/* ---- and a way back once it has been reworded ---- */
check(false !== strpos($fields, 'function render_reset('), 'a reworded section can be put back');
check(false !== strpos($fields, 'bftd-reset-src'), 'with the wording carried in the page');
check(1 !== preg_match('/data-default=/', $fields),
  'rather than in an attribute, where one apostrophe would break it');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
check(false !== strpos($js, '.bftd-reset'), 'the button does something');
check(false !== strpos($js, 'ed.setContent( html )'), 'filling the editor');
check(1 !== preg_match('/bftd-reset[\s\S]{0,900}\$form\.submit\(\)/', $js),
  'and stopping there, because saving stays a person\'s decision');

/* ---- the section's own headings belong to the section ---- */
/* A tutor typing into Notes should not be able to delete the word Notes, and
   standing wording should not have to carry its own title through every
   rewrite. So the heading is the schema's and the writing is the field's. */
check('Notes' === $notes['label'], 'the notes field is called what the report calls it');
check('Notes' === $notes['heading'], 'and the report draws that heading itself');
check(false !== strpos($method['heading'], 'How does Evidence-Based Literacy Instruction'),
  'the standing answer keeps its own question as a heading');
$view = file_get_contents(BFTD_PATH . 'includes/class-bftd-report-view.php');
check(1 === preg_match('/if \( ! empty\( \$field\[.heading.\] \) \) \{\s*echo \x27<h3>\x27/', $view),
  'and the report prints it, rather than trusting the writing to carry it');

/* ---- what a tutor owns, and what the practice owns ---- */
check(empty($notes['edit_rank']), 'the notes are the tutor\'s, and anybody who can edit writes them');
check(3 === $method['edit_rank'], 'while the standing answer is held at senior manager');
check(!empty($method['shipped']), 'and it is there without anybody typing it');

$mt = strip_tags($method['shipped']);
check(false === strpos($mt, 'Kaine'), 'with no child named in it');
check(1 !== preg_match('/\b(he|she|his|her)\b/', $mt), 'and no pronoun that would be wrong for half of them');
check(false !== strpos($mt, 'the student is ready'), 'it says "the student", as the introduction does');
check(false !== strpos($mt, 'Activity 70'), 'and still says what it always said');

/* Both standing halves behave the same way, because they are the same kind of
   thing and a rule that only holds for one of them is a rule nobody can rely
   on. */
foreach (array('body' => 'the introduction', 'method' => 'the standing answer') as $k => $what) {
  $f = $dx1[$k];
  $GLOBALS['META'] = array();
  check($f['shipped'] === BFTD_Fields::get(99, 'dxsec-1', $k, $f), "$what shows without being typed");
  $GLOBALS['META'][99][BFTD_Schema::meta_key('dxsec-1', $k)] = '<p></p>';
  check($f['shipped'] === BFTD_Fields::get(99, 'dxsec-1', $k, $f), "$what comes back after an untouched save");
  $GLOBALS['RANK'] = 2;
  check(false === BFTD_Fields::may_edit($f), "$what is not a tutor manager's to reword");
  $GLOBALS['RANK'] = 3;
  check(true === BFTD_Fields::may_edit($f), "but a senior manager's");
}
$GLOBALS['META'] = array(); $GLOBALS['RANK'] = 4;

/* The reset button is offered for any field with standing wording, not just
   the first one it was written for. */
check(1 === preg_match('/\$fallback = self::fallback\(.*?\);\s*if \( null !== \$fallback \) self::render_reset\(/s', $fields),
  'every field with standing wording gets a way back to it');

/* ---- the order of the fields is the order of the section ---- */
/* One list drives the editor and the report, so a tutor filling a section in
   meets it in the order a family reads it. The work is evidence for the
   result, so it sits with the result and ahead of the notes written about it. */
$order = array_keys($dx1);
$at = array_flip($order);
check(isset($at['images'], $at['marking'], $at['body_after']), 'the section has all three');
check($at['marking'] < $at['images'], 'the work samples follow the marking');
check($at['images'] < $at['body_after'], 'and come before the notes');
check($at['body_after'] < $at['method'], 'with the standing answer after the notes');
check($at['score_table'] < $at['marking'], 'and the results table before either');

/* A blanket rule elsewhere puts work samples last on every skill area. It
   must not reach into a section that has said where its own fields go. */
$schema_src = file_get_contents(BFTD_PATH . 'includes/class-bftd-schema.php');
check(1 === preg_match('/foreach \( array_keys\( \$skills \) as \$sid \) \{\s*if \( ! empty\( \$s\[ \$sid \]\[.own_order.\] \) \) continue;/', $schema_src),
  'the blanket rule stands aside for a section that sets its own order');
check(!empty(BFTD_Schema::sections()['dxsec-1']['own_order']), 'and this one does');

/* All six have been reviewed now. Each states its own order, and in every one
   of them the work samples come before the notes a tutor writes about them,
   which was the point of the reordering. */
foreach (array('dxsec-1','dxsec-2','dxsec-3','dxsec-4','dxsec-5','dxsec-6') as $sid) {
  $k = array_keys(BFTD_Schema::sections()[$sid]['fields']);
  check(in_array('images', $k, true) && in_array('body_after', $k, true)
    && array_search('images', $k, true) < array_search('body_after', $k, true),
    "$sid puts the work samples before the notes");
  check('method' === end($k), "and closes with how the method addresses it: $sid");
}

/* ---- one place owns the words ---- */
check(false !== strpos(file_get_contents(BFTD_PATH . 'includes/class-bftd-schema.php'), 'function alphabet_intro('),
  'the wording is written in one findable place');
$sample = file_get_contents(BFTD_PATH . 'includes/class-bftd-sample.php');
check(false === strpos($sample, 'As part of the assessment, the student completed'),
  'and the sample does not keep a second copy of it to drift from');
check(false === strpos($sample, 'How does Evidence-Based Literacy Instruction support handwriting'),
  'nor of the standing answer');
if (!defined("BFTD_URL")) define("BFTD_URL","");
require_once BFTD_PATH . 'includes/class-bftd-sample.php';
$dx1_sample = BFTD_Sample::get('diagnostic')['fields']['dxsec-1'];
check(false === strpos($dx1_sample['body_after'], '<h3>'),
  'nor of a heading the report now draws itself');
check(!isset($dx1_sample['method']),
  'and the standing answer is not copied into the sample at all');

/* ---- and the sample still SHOWS the words ----------------------------------
   Every check above is about the sample not holding a copy. None of them is
   about the sample holding the wording at all, and for a while it did not:
   the fixture layer resolved an unfilled field through $field['default'] only,
   so when the standing wording moved to 'shipped' the sample quietly rendered
   both sections with no introduction and no standing answer. Nothing errored,
   every test passed, and the sample is the thing shown to families and to new
   tutors.

   So the pair is checked together from here on. Absence of a copy, presence of
   the words. */
class BFTD_Settings_Absent {}
BFTD_Fields::use_fixture(BFTD_Sample::get('diagnostic')['fields']);
foreach (array(
  'dxsec-1' => array('one minute handwriting task', 'motor memory'),
  'dxsec-2' => array('phoneme segmentation task',   'Short term memory'),
) as $sid => $words) {
  $sec = BFTD_Schema::section($sid);
  $intro  = BFTD_Fields::get(0, $sid, 'body',   $sec['fields']['body']);
  $method = BFTD_Fields::get(0, $sid, 'method', $sec['fields']['method']);
  check(false !== strpos(strip_tags($intro), $words[0]),
    "the sample resolves $sid's introduction, rather than showing the section without one");
  check(false !== strpos(strip_tags($method), $words[1]),
    "and its standing answer");
}

/* What the tutor wrote is still the tutor's, not overwritten by the wording. */
$notes = BFTD_Fields::get(0, 'dxsec-1', 'body_after', BFTD_Schema::section('dxsec-1')['fields']['body_after']);
check(false !== strpos($notes, 'Kaine'), 'while the notes in the sample are still about the child');
BFTD_Fields::clear_fixture();

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
