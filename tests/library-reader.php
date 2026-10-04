<?php
/*
 * Tutors read the skills and activities library; tutor managers and above
 * write it.
 *
 * A tutor holds the list capability and nothing that changes a record, so
 * the list shows them names linked to a page that reads one record, with no
 * checkboxes and no bulk actions. Someone who may write gets the list exactly
 * as WordPress draws it, and every name goes to the editor.
 */
define('ABSPATH', '/');
define('BFTD_PATH', dirname(__DIR__) . '/');
require __DIR__ . '/wp-stubs.php';
$GLOBALS['CAN'] = array();
function current_user_can($c, $id = 0) { return !empty($GLOBALS['CAN'][$c]); }
function get_post_type_object($t) { return (object) array('cap' => (object) array('edit_others_posts' => 'edit_others_' . ('bftd_skill' === $t ? 'bftd_skills' : 'bftd_activities'))); }
function get_edit_post_link($id, $c = '') { return current_user_can('edit_post') ? 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit' : ''; }
function get_the_title($id) { return 'Blending <two> sounds'; }
$GLOBALS['SCREEN'] = null;
function get_current_screen() { return $GLOBALS['SCREEN']; }
class BFTD_Admin { const MENU_SLUG = 'bftd'; }
class BFTD_Roles { const STAFF_CAP = 'bftd_manage_students'; }
require BFTD_PATH . 'includes/class-bftd-library-view.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$cols = array('cb' => '<input>', 'bftd_number' => 'No.', 'title' => 'Name', 'date' => 'Date');

/* ---- a tutor ---- */
$GLOBALS['CAN'] = array('edit_bftd_skills' => true, 'edit_bftd_activities' => true);
foreach (array('bftd_skill', 'bftd_activity') as $type) {
  $got = BFTD_Library_View::reader_columns($cols, $type);
  check(array('bftd_number', 'bftd_read', 'date') === array_keys($got) && 'Name' === $got['bftd_read'],
    "a tutor's $type list has no checkboxes, and the name is a column that links somewhere they may go");
  check(!BFTD_Library_View::writes($type), "a tutor does not write the $type library");
  check('bftd_read' === BFTD_Library_View::reader_primary('title', 'edit-' . $type), 'and that name carries the row');
}
ob_start(); BFTD_Library_View::read_cell(63); $cell = ob_get_clean();
check(1 === preg_match('#<a class="row-title" href="[^"]*admin\.php\?page=bftd-library-item&(amp;)?item=63">Blending &lt;two&gt; sounds</a>#', $cell),
  'the name opens the read page, escaped');
check(false !== strpos(BFTD_Library_View::link_for(63), 'page=bftd-library-item&item=63'), 'and a suggestion in the search box goes there too');
$GLOBALS['SCREEN'] = (object) array('post_type' => 'bftd_skill');
check(array() === BFTD_Library_View::reader_bulk(array('edit' => 'Edit', 'trash' => 'Trash')), 'a tutor is offered no bulk actions');
$GLOBALS['SCREEN'] = (object) array('post_type' => 'bftd_student');
check(array('edit' => 'Edit') === BFTD_Library_View::reader_bulk(array('edit' => 'Edit')), 'and no other list is touched');

/* ---- a tutor manager, who writes it ---- */
$GLOBALS['CAN'] = array('edit_others_bftd_skills' => true, 'edit_others_bftd_activities' => true, 'edit_post' => true);
foreach (array('bftd_skill', 'bftd_activity') as $type) {
  check($cols === BFTD_Library_View::reader_columns($cols, $type), "someone who writes the $type library gets the list as WordPress draws it");
  check('title' === BFTD_Library_View::reader_primary('title', 'edit-' . $type), 'with the title carrying the row');
}
check(false !== strpos(BFTD_Library_View::link_for(63), 'post.php?post=63&action=edit'), 'and a name goes to the editor');
$GLOBALS['SCREEN'] = (object) array('post_type' => 'bftd_skill');
check(array('edit' => 'Edit') === BFTD_Library_View::reader_bulk(array('edit' => 'Edit')), 'keeping the bulk actions');

/* ---- the page is reachable ---- */
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-library-view.php');
check(1 === preg_match("/add_action\( 'admin_enqueue_scripts', array\( __CLASS__, 'hide_row' \), 0 \)/", $src),
  'its menu row comes out after WordPress checks access, never during admin_menu');
check(0 === preg_match("/function menu\(\)[^}]*remove_submenu_page/s", $src), 'and not in menu()');
exit($fail ? 1 : 0);
