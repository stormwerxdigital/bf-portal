<?php
/*
 * The school list (Karl, 1.212): "I want this input field to be a search bar
 * connected to GHL. It will search the Companies object... When a match is
 * found, give the opportunity to link and then add it to a list of schools in
 * our db... If no Match is found in GHL, search our own list too... If no
 * listing found, add the school name to our portal school list. We must
 * maintain a clean list, no duplicates. Admins and sr managers can edit the
 * list." Made-up schools only.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

$META = array(); $TR = array(); $STUDENTS = array(); $ADMIN = true; $SENT = array(); $CALLS = 0; $COMPANIES = array();
function get_post_meta($id, $k = '', $s = false) { global $META; return $META[$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { global $META; $META[$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { global $META; unset($META[$id][$k]); return true; }
function get_transient($k) { global $TR; return $TR[$k] ?? false; }
function set_transient($k, $v, $t = 0) { global $TR; $TR[$k] = $v; return true; }
function delete_transient($k) { global $TR; unset($TR[$k]); return true; }
if (!function_exists('remove_accents')) { function remove_accents($s) { return strtr($s, array('é' => 'e', 'è' => 'e', 'ç' => 'c')); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return trim(strip_tags((string) $s)); } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('add_action')) { function add_action(...$a) {} }
function get_posts($a) {
  global $STUDENTS, $META;
  if (isset($a['meta_key'])) return array_values(array_filter($STUDENTS, function ($s) use ($a, $META) { return (int) ($META[$s][$a['meta_key']] ?? 0) === (int) $a['meta_value']; }));
  return $STUDENTS;
}
class BFTD_CPT { const STUDENT = 'bftd_student'; }
class BFTD_Schema { public static function meta_key($s, $k) { return '_bftd_' . $s . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $s, $k) { return get_post_meta($id, BFTD_Schema::meta_key($s, $k), true); }
  public static function set($id, $s, $k, $v) { return update_post_meta($id, BFTD_Schema::meta_key($s, $k), $v); }
}
class BFTD_Roles { public static function can_administer() { return $GLOBALS['ADMIN']; } public static function is_staff() { return true; } }
class BFTD_CRM { public static function linked($sid) { return 'rec' . $sid; } }
class BFTD_GHL {
  public static function configured() { return true; }
  public static function location() { return 'loc1'; }
  public static function send_changes($sid, $rid) { $GLOBALS['SENT'][] = $sid; }
  public static function request($m, $path, $q = array(), $b = null, $v = 'v3') {
    $GLOBALS['CALLS']++;
    if ('/businesses/' !== $path) return array(404, null, 'no');
    return array(200, array('businesses' => array_slice($GLOBALS['COMPANIES'], $q['skip'], $q['limit'])), '');
  }
  // As in class-bftd-ghl.php.
  public static function school_words($s) {
    $s = strtolower(remove_accents((string) $s));
    $stop = array('school', 'schools', 'district', 'elementary', 'elem', 'high', 'highschool', 'middle', 'secondary', 'academy',
      'the', 'of', 'and', 'sd', 'wa', 'bc', 'ab', 'private', 'public', 'online', 'primary', 'junior', 'senior', 'jr', 'sr', 'k12');
    $out = array();
    foreach (preg_split('/[^a-z0-9]+/', $s) as $w) if (strlen($w) >= 3 && !in_array($w, $stop, true)) $out[] = $w;
    return array_values(array_unique($out));
  }
}
require BFTD_PATH . 'includes/class-bftd-schools.php';
require BFTD_PATH . 'includes/class-bftd-school-billing.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
$school = function ($sid) { return BFTD_Fields::get($sid, 'student', 'school'); };

echo "One of each school:\n";
check(BFTD_Schools::norm('Foothills Elem.') === BFTD_Schools::norm('The Foothills Elementary School'), 'case, punctuation, "the", "school" and short forms make no difference');
check(BFTD_Schools::norm('Foothills Elementary') !== BFTD_Schools::norm('Foothills Middle'), 'but Elementary and Middle are different schools');
check(BFTD_Schools::norm('St. Mary\'s Jr. High') === BFTD_Schools::norm('st marys junior high'), 'apostrophes, Jr. and spacing too');
$a = BFTD_Schools::add('Foothills Elementary');
check($a > 0 && $a === BFTD_Schools::add('  foothills   elem. '), 'adding a name already there gives back the one there');
check(1 === count(BFTD_Schools::all()), 'so the list still has one');
$m = BFTD_Schools::add('Foothills Middle');
check($m !== $a && 2 === count(BFTD_Schools::all()), 'a different school is added');
check(0 === BFTD_Schools::add('  the school  '), 'nothing that is only "the school" is added');

echo "\nGoHighLevel's Companies, copied for an hour:\n";
for ($i = 1; $i <= 130; $i++) $COMPANIES[] = array('id' => 'c' . $i, 'name' => 'Company ' . $i);
$COMPANIES[] = array('id' => 'cbr', 'name' => 'Bow River Elementary');
$COMPANIES[] = array('id' => 'cfe', 'name' => 'Foothills Elementary School');
$c = BFTD_Schools::companies();
check(132 === count($c) && 2 === $CALLS, 'every page is read, 100 at a time, got ' . count($c) . ' in ' . $CALLS . ' requests');
BFTD_Schools::companies(); BFTD_Schools::search('bow');
check(2 === $CALLS, 'and searching uses the copy, not GoHighLevel each time');

echo "\nSearching:\n";
$r = BFTD_Schools::search('bow riv');
check(1 === count($r['ghl']) && 'cbr' === $r['ghl'][0]['ghl_id'] && 0 === $r['ghl'][0]['list_id'], 'a GoHighLevel Company is found by the start of its words, not yet on our list');
check(null === $r['exact'], 'and "bow riv" is not exactly any school, so a new one can be offered');
$r = BFTD_Schools::search('FOOTHILLS ELEM');
check(1 === count($r['ghl']) && $r['ghl'][0]['list_id'] === $a, 'GoHighLevel\'s Foothills Elementary School is recognised as the one on our list');
check(null !== $r['exact'], 'and the name typed is exactly a school, so no new one is offered');
check(2 === count(BFTD_Schools::search('foothills')['list']), 'our list is searched too');
$r = BFTD_Schools::search('Fothills Middle');
check(!$r['ghl'] && !$r['list'] && $r['near'], 'a misspelling finds nothing, but offers close names');
check(array() === BFTD_Schools::search('f')['list'], 'one letter is not a search');

echo "\nLinking a student:\n";
$STUDENTS = array(7, 8, 9);
BFTD_Fields::set(7, 'student', 'school', 'Bow River Elementary');
check(BFTD_Schools::resolve_student(7, 'ghl:cbr') > 0, 'picking a GoHighLevel Company links the student');
$br = BFTD_Schools::of_student(7);
check('cbr' === BFTD_Schools::get($br)['ghl_id'], 'and adds it to our list, linked to the Company');
BFTD_Fields::set(8, 'student', 'school', 'bow river elem');
BFTD_Schools::resolve_student(8, '');
check($br === BFTD_Schools::of_student(8) && 'Bow River Elementary' === $school(8), 'typing the same school another way links the one on the list, and gives its name');
check(in_array(8, $SENT, true), 'and the corrected name goes to GoHighLevel');
BFTD_Fields::set(9, 'student', 'school', 'Prairie Sky School');
$before = count(BFTD_Schools::all());
BFTD_Schools::resolve_student(9, 'new');
check(count(BFTD_Schools::all()) === $before + 1 && 'Prairie Sky School' === BFTD_Schools::get(BFTD_Schools::of_student(9))['name'], 'a school on neither list is added, as typed');
BFTD_Fields::set(9, 'student', 'school', '');
BFTD_Schools::resolve_student(9, '');
check(0 === BFTD_Schools::of_student(9), 'clearing School unlinks the student');

echo "\nEditing the list:\n";
$p = BFTD_Schools::add('Prairie Sky School');
check(is_wp_error(BFTD_Schools::rename($p, 'Foothills Elementary')), 'renaming onto a school already there is refused, with merge offered');
BFTD_Fields::set(9, 'student', 'school', 'Prairie Sky School'); BFTD_Schools::set_student(9, $p);
check(true === BFTD_Schools::rename($p, 'Prairie Sky Elementary') && 'Prairie Sky Elementary' === $school(9), 'a rename changes the name on its students');
check(is_wp_error(BFTD_Schools::remove($p)), 'a school with students cannot be deleted');
check(true === BFTD_Schools::merge($p, $a) && $a === BFTD_Schools::of_student(9) && 'Foothills Elementary' === $school(9) && null === BFTD_Schools::get($p), 'merging moves its students and removes it');
check(true === BFTD_Schools::remove($m) && null === BFTD_Schools::get($m), 'a school with no students can be deleted');
check(is_wp_error(BFTD_Schools::link($a, 'cbr')), 'one Company cannot be linked to two schools');
check(true === BFTD_Schools::link($a, 'cfe') && 'cfe' === BFTD_Schools::get($a)['ghl_id'], 'a school can be linked to its Company');

echo "\nBuilding the list from what students already have:\n";
$STUDENTS = array(7, 8, 9, 20, 21, 22);
BFTD_Fields::set(20, 'student', 'school', 'Cedar Hollow Elem'); BFTD_Fields::set(21, 'student', 'school', 'cedar hollow elementary'); BFTD_Fields::set(22, 'student', 'school', 'Cedar Hollow Middle');
check(3 === BFTD_Schools::import_from_students(), 'each student with a school typed and no link is linked');
check(BFTD_Schools::of_student(20) === BFTD_Schools::of_student(21), 'the same school typed two ways becomes one');
$pairs = BFTD_Schools::possible_duplicates();
$named = array_map(function ($p) { return BFTD_Schools::get($p[0])['name'] . ' / ' . BFTD_Schools::get($p[1])['name']; }, $pairs);
check((bool) array_filter($named, function ($n) { return false !== strpos($n, 'Cedar Hollow') && false !== strpos($n, 'Middle'); }), 'and a close name is flagged for someone to merge or leave, got ' . json_encode($named));
check(0 === BFTD_Schools::import_from_students(), 'running it again changes nothing');

echo "\nWho can edit it:\n";
$ADMIN = false;
check(false === BFTD_Schools::can_edit(), 'only senior managers and administrators');
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-schools.php');
check(false !== strpos($src, "if ( ! self::can_edit() ) wp_die(") && false !== strpos($src, "check_admin_referer( 'bftd_school_' . \$what );"), 'every change to the list checks the nonce and who is asking');
check(false !== strpos($src, "add_submenu_page( BFTD_Admin::MENU_SLUG, 'Schools', 'Schools', BFTD_Roles::ADMIN_CAP,"), 'and the Schools page is theirs alone');

echo "\nThe wiring:\n";
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
check(false !== strpos($mb, "if ( 'school' === \$key && class_exists( 'BFTD_Schools' ) ) { BFTD_Schools::render_field("), 'History\'s School is the search');
$gh = file_get_contents(BFTD_PATH . 'includes/class-bftd-ghl.php');
check(false !== strpos($gh, "BFTD_Schools::resolve_student( \$sid, '', false );"), 'a school changed in GoHighLevel is linked here, without sending it straight back');
$st = file_get_contents(BFTD_PATH . 'includes/class-bftd-students.php');
check(false !== strpos($st, "\$narrow( BFTD_Schools::students_at( \$filters['school'] ) );") && false !== strpos($st, '<option value="0">Every school</option>'), 'the Students list filters by school, alongside status');

echo "\nThe Schools table, saved at once (1.213.2):\n";
$ADMIN = true;
$x = BFTD_Schools::add('Willow Creek Elem');
$y = BFTD_Schools::add('Spruce Hill School');
$z = BFTD_Schools::add('Spruce Hill Secondary');
$STUDENTS[] = 30; BFTD_Fields::set(30, 'student', 'school', 'Spruce Hill Secondary'); BFTD_Schools::set_student(30, $z, false);
list($r, $say) = BFTD_Schools::save_table(array('do' => 'save',
  'name' => array($x => 'Willow Creek Elementary', $y => 'Spruce Hill School'),
  'ghl' => array($x => 'cbr2', $y => ''),
  'into' => array($z => $y)));
check(true === $r && 'Willow Creek Elementary' === BFTD_Schools::get($x)['name'], 'one save renames');
check(true === $r && 'cbr2' === BFTD_Schools::get($x)['ghl_id'], 'links a Company picked');
check(null === BFTD_Schools::get($z) && $y === BFTD_Schools::of_student(30) && 'Spruce Hill School' === $school(30), 'and merges, moving the students');
check('Saved: 1 renamed, 1 linked, 1 merged.' === $say, 'and says what it did, got ' . $say);
list($r, $say) = BFTD_Schools::save_table(array('do' => 'save', 'name' => array($x => 'Willow Creek Elementary')));
check(true === $r && 'Nothing was changed.' === $say, 'saving with nothing changed says so');
list($r) = BFTD_Schools::save_table(array('do' => 'save', 'name' => array($x => 'Spruce Hill School')));
check(is_wp_error($r) && 'Willow Creek Elementary' === BFTD_Schools::get($x)['name'], 'a rename onto another school is refused, saying why');
list($r) = BFTD_Schools::save_table(array('do' => 'unlink:' . $x));
check(true === $r && '' === BFTD_Schools::get($x)['ghl_id'], 'Unlink acts at once');
list($r) = BFTD_Schools::save_table(array('do' => 'delete:' . $x));
check(true === $r && null === BFTD_Schools::get($x), 'and so does Delete');

if (!function_exists('admin_url')) { function admin_url($p = '') { return '/wp-admin/' . $p; } }
if (!function_exists('esc_url')) { function esc_url($u) { return $u; } }
if (!function_exists('wp_nonce_field')) { function wp_nonce_field($a) { echo '<input type="hidden" name="_wpnonce" value="n">'; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!class_exists('BFTD_Students')) { class BFTD_Students { const SLUG = 'bftd-students'; } }
ob_start(); BFTD_Schools::render_page(); $page = ob_get_clean();
$form = (string) substr($page, (int) strpos($page, 'class="bftd-sch-save">'));
$form = substr($form, 0, strpos($form, '</form>'));
check('' !== $form && false !== strpos($form, 'value="bftd_school_save"') && 2 === substr_count($form, '>Save changes</button>'), 'the page has a Save changes button, above and below the table, for one form');
check(count(BFTD_Schools::all()) === substr_count($form, 'name="name['), 'every school\'s name is in that form');
check(false === strpos($page, '>Rename</button>') && false === strpos($page, '>Link</button>') && false === strpos($page, '>Merge</button>'), 'with no separate Rename, Link or Merge buttons left');
check(false === strpos($form, '</span><button') && false !== strpos($form, 'Linked: ') ? true : (false === strpos($form, 'Linked: ')), 'and Unlink sits apart from the linked Company\'s name');

$cv = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
check(false !== strpos($cv, "\$linked = class_exists( 'BFTD_Schools' ) ? BFTD_Schools::get( BFTD_Schools::of_student( \$sid ) ) : null;") && false !== strpos($cv, "'school'     => \$school,"),
  'each Calendar booking carries the school its student is linked to, for the search (1.212.2)');

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
