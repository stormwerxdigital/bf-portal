<?php
/*
 * The students screen.
 *
 * WordPress gives a post type a flat list, twenty at a time, sorted by
 * whatever column you press. For a library of activities that is right. For
 * a practice it answers none of the questions actually asked of it: who is
 * Rae's, who is paying for this child, who has sessions left. So the screen
 * is a section per tutor.
 *
 * Two things decide whether it is any good at a thousand students, and
 * neither is visible in the markup:
 *
 *   the work does not grow with the number of rows — read one student at a
 *   time, the sessions-left figure alone is several queries a row, and a
 *   thousand rows is a page that never finishes;
 *
 *   a child with two tutors is under both, because either tutor reading
 *   their own list has to find them there.
 *
 * So this builds a practice of a hundred and drives the real methods.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();
$GLOBALS['USERS'] = array();
$GLOBALS['QUERIES'] = 0;
$GLOBALS['PRIMED']  = array();

function add_action(...$a) {} function add_filter(...$a) {}
function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { $p = get_post($id); return $p ? $p->post_type : ''; }
function get_the_title($id) { $p = get_post($id); return $p ? $p->post_title : ''; }
function get_post_status($id) { $p = get_post($id); return $p ? $p->post_status : 'publish'; }
function get_post_meta($id, $k, $s = false) { $v = $GLOBALS['META'][$id][$k] ?? null; return null === $v ? ($s ? '' : array()) : ($s ? $v : (array) $v); }
function get_userdata($id) { return $GLOBALS['USERS'][(int) $id] ?? false; }
function get_current_user_id() { return 1; }
function is_admin() { return true; }
function current_user_can($cap, ...$a) { return $GLOBALS['CAN'] ?? true; }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
function wp_list_pluck($l, $f) { $o = array(); foreach ((array) $l as $r) { $o[] = is_array($r) ? $r[$f] : $r->$f; } return $o; }

/* The two WordPress calls that make a screen like this cheap, counted so the
   test can say whether they are being used at all. */
function update_meta_cache($type, $ids) { $GLOBALS['QUERIES']++; foreach ((array) $ids as $i) $GLOBALS['PRIMED'][$i] = true; return true; }
function cache_users($ids) { $GLOBALS['QUERIES']++; return true; }

function get_posts($a) {
  $GLOBALS['QUERIES']++;
  $type = $a['post_type'] ?? '';
  $out = array();

  if ('bftd_session' === $type) {
    $want = array_map('intval', (array) ($a['meta_query'][0]['value'] ?? array()));
    foreach ($GLOBALS['POSTS'] as $id => $p) {
      if ('bftd_session' !== $p->post_type || 'publish' !== $p->post_status) continue;
      if (!in_array((int) ($GLOBALS['META'][$id]['_bftd_student_id'] ?? 0), $want, true)) continue;
      $out[] = $id;
    }
    return $out;
  }

  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $type) continue;
    if (!empty($a['post__in']) && !in_array($id, $a['post__in'], true)) continue;
    if (!empty($a['meta_query'])) {
      $q = $a['meta_query'][0];
      $have = (array) ($GLOBALS['META'][$id][$q['key']] ?? array());
      $want = array_map('strval', (array) $q['value']);
      if (!array_intersect(array_map('strval', $have), $want)) continue;
    }
    $out[] = $p;
  }
  usort($out, function ($a, $b) { return strcasecmp($a->post_title, $b->post_title); });
  return empty($a['fields']) ? $out : wp_list_pluck($out, 'ID');
}

function get_edit_post_link($id){ return 'https://example.test/wp-admin/post.php?post='.(int)$id.'&action=edit'; }
class BFTD_Sessions { public static function records($ids) { $o=array(); foreach((array)$ids as $i) $o[(int)$i]=array('draft'=>(1001===(int)$i)?3:0); return $o; } }
class BFTD_Admin { const MENU_SLUG = 'bftd'; const HUB_SLUG = 'bftd-student'; }
class BFTD_Roles {
  const STAFF_CAP = 'bftd_manage_students';
  public static function is_staff($u = null) { return true; }
  public static function can_manage($u = null) { return $GLOBALS['MANAGE'] ?? true; }
  public static function relationship($u) { return 'Parent or guardian'; }
  public static function role_name($u) { return 'Tutor'; }
  public static function post_types() { return array('bftd_session'); }
  public static function plural_for($p) { return $p . 's'; }
}
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . $g . '__' . $k; } }
class BFTD_Fields {
  public static function get($id, $g, $k, $f = array()) { return $GLOBALS['META'][$id]['_bftd_' . $g . '__' . $k] ?? ''; }
}
class BFTD_Access {
  public static function visible_student_ids($u) { return $GLOBALS['VISIBLE']; }
  public static function can_staff_view(...$a) { return true; }
}
/* GoHighLevel offers, stood in for: what the sync stored, per student (1.177). */
class BFTD_GHL { public static function configured() { return $GLOBALS['GHL_ON'] ?? true; } public static function offers_for($sid) { $GLOBALS['GHL_ASKED'][] = (int) $sid; return $GLOBALS['GHL_OFFERS'][(int) $sid] ?? array(); } }
class BFTD_Settings { const PAGE_SLUG = 'bftd-settings'; }
class BFTD_CRM { public static function linked($sid) { return (string) ($GLOBALS['META'][(int) $sid]['_bftd_crm_id'] ?? ''); } }
require BFTD_PATH . 'includes/class-bftd-cpt.php';
require BFTD_PATH . 'includes/class-bftd-schedule.php';
require BFTD_PATH . 'includes/class-bftd-students.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* ---- a practice of a hundred ---- */
$TUTORS = array(5 => 'Rae Tanaka', 6 => 'Jo Patel', 7 => 'Avery Lin');
foreach ($TUTORS as $uid => $n) $GLOBALS['USERS'][$uid] = (object) array('ID' => $uid, 'display_name' => $n);
for ($c = 100; $c < 140; $c++) $GLOBALS['USERS'][$c] = (object) array('ID' => $c, 'display_name' => 'Client ' . $c, 'user_email' => 'c' . $c . '@example.test');

$GLOBALS['VISIBLE'] = array();
$sid = 1000;
$next_session = 5000;
for ($i = 0; $i < 100; $i++) {
  $sid++;
  $GLOBALS['POSTS'][$sid] = (object) array('ID' => $sid, 'post_type' => 'bftd_student', 'post_status' => 'publish',
    'post_title' => sprintf('Student %03d', $i));
  $tutor  = array_keys($TUTORS)[$i % 3];
  $client = 100 + ($i % 40);
  $GLOBALS['META'][$sid] = array(
    '_bftd_assigned_staff'   => array($tutor),
    '_bftd_client_user_id'   => array($client),
    '_bftd_client_primary'   => $client,
    '_bftd_student__status'  => ($i % 5 === 0) ? 'prospect' : 'active',
    '_bftd_student__lessons_bank' => 20,
  );
  $GLOBALS['VISIBLE'][] = $sid;

  // Four sessions each: three attended, one cancelled. Four hundred in all.
  foreach (array('held', 'held', 'held', 'missed') as $k => $st) {
    $p = $next_session++;
    $GLOBALS['POSTS'][$p] = (object) array('ID' => $p, 'post_type' => 'bftd_session', 'post_status' => 'publish', 'post_title' => 's');
    $GLOBALS['META'][$p] = array(
      '_bftd_student_id' => $sid,
      '_bftd_session__session_date' => '2026-0' . (1 + $k) . '-05',
      '_bftd_session__status' => $st,
    );
  }
}

/* One child with two tutors. */
$both = 1050;
$GLOBALS['META'][$both]['_bftd_assigned_staff'] = array(5, 6);
/* And one with none. */
$orphan = 1051;
$GLOBALS['META'][$orphan]['_bftd_assigned_staff'] = array();

/* ---- the cost ---- */
$GLOBALS['QUERIES'] = 0;
$ids  = BFTD_Students::student_ids(array('q' => '', 'tutor' => 0, 'client' => 0, 'status' => ''));
$rows = BFTD_Students::rows($ids);
$cost = $GLOBALS['QUERIES'];

check(100 === count($rows), 'every student is on the screen, got ' . count($rows));
/* The number that matters. Per-student it would be several hundred. */
check($cost <= 6, 'a hundred students cost ' . $cost . ' queries, not one per row');
$missed = 0;
foreach ($ids as $id) { if (empty($GLOBALS['PRIMED'][$id])) $missed++; }
check(0 === $missed, 'with every student\'s meta fetched in one go, ' . $missed . ' were not');

/* And the same work for twice as many students must not be twice the
   queries, or the screen is linear in the thing that grows. */
$GLOBALS['QUERIES'] = 0;
BFTD_Students::rows(array_slice($ids, 0, 10));
$small = $GLOBALS['QUERIES'];
$GLOBALS['QUERIES'] = 0;
BFTD_Students::rows($ids);
check($GLOBALS['QUERIES'] === $small, 'ten rows and a hundred rows cost the same, got ' . $small . ' and ' . $GLOBALS['QUERIES']);

/* ---- what a row says ---- */
$one = $rows[1001];
/* Sessions written up but not published show on the row, so a student marked
   Active with drafts waiting does not look like one with none. Counted by the
   Sessions screen's own batch, stood in for here. */
check(3 === $one['drafts'], 'the row knows how many sessions are still drafts, got ' . var_export($one['drafts'], true));
$draw = new ReflectionMethod('BFTD_Students', 'row'); $draw->setAccessible(true);
ob_start(); $draw->invoke(null, $one); $tr = ob_get_clean();
check(1 === preg_match('#class="bftd-stu-drafts" href="[^"]*post_status=draft[^"]*bftd_student=1001">3 sessions in draft</a>#', $tr),
  'and says so under the status, linked to those drafts');
ob_start(); $draw->invoke(null, $rows[1002]); $tr2 = ob_get_clean();
check(false === strpos($tr2, 'bftd-stu-drafts'), 'while a student with none says nothing');

/* GoHighLevel people waiting to be added (1.177): caregivers under Caregivers,
   tutors under Tutors, each linked to this student on the Settings tab. */
$GLOBALS['GHL_OFFERS'][1001] = array(
  array('kind' => 'create'), array('kind' => 'link'), array('kind' => 'assign'),
);
ob_start(); $draw->invoke(null, $one); $g = ob_get_clean();
check(1 === preg_match('#<td data-l="Caregivers">.*<a class="bftd-stu-ghl" href="[^"]*page=bftd-settings&(amp;|\#038;)?tab=family\#bftd-offers-1001">2 to add from GoHighLevel</a></td>#s', $g),
  'two caregivers waiting in GoHighLevel show under Caregivers, linked to this student on the tab');
check(1 === preg_match('#<td data-l="Tutors">.*<a class="bftd-stu-ghl"[^>]*>1 to add from GoHighLevel</a></td>#s', $g),
  'and the tutor waiting shows under Tutors');
$GLOBALS['GHL_OFFERS'][1001] = array(array('kind' => 'assign'));
ob_start(); $draw->invoke(null, $one); $g2 = ob_get_clean();
check(1 === substr_count($g2, 'bftd-stu-ghl') && 1 === preg_match('#<td data-l="Tutors">.*bftd-stu-ghl#s', $g2),
  'a tutor alone adds nothing under Caregivers');
$GLOBALS['MANAGE'] = false; $GLOBALS['GHL_ASKED'] = array();
ob_start(); $draw->invoke(null, $one); $g3 = ob_get_clean();
check(false === strpos($g3, 'bftd-stu-ghl'), 'a tutor reading the list sees no GoHighLevel offers');
check(array() === $GLOBALS['GHL_ASKED'], 'and is not made to wait for them to be worked out');
$GLOBALS['MANAGE'] = true; $GLOBALS['GHL_OFFERS'] = array();

/* Linked to GoHighLevel or not, beside the name, to the student's row on
   Settings, Link students (1.178). */
check(1 === preg_match('#</td>\s*<td data-l="Linked"><a class="bftd-stu-crm" href="[^"]*page=bftd-settings&(amp;|\#038;)?tab=link\#bftd-link-1001" title="Not linked to GoHighLevel">Not linked</a></td>\s*<td data-l="Client">#', $g),
  'a student not linked says so in its own Linked column, after the name, linked to its row on Link students (1.184)');
check(false === strpos(explode('</td>', $g)[0], 'bftd-stu-crm'), 'and not beside the name any more');
$GLOBALS['META'][1001]['_bftd_crm_id'] = 'REC1';
$lk = BFTD_Students::rows(array(1001))[1001];
check('REC1' === $lk['crm'], 'the row knows its GoHighLevel record');
ob_start(); $draw->invoke(null, $lk); $g4 = ob_get_clean();
check(1 === preg_match('#<a class="bftd-stu-crm is-linked" href="[^"]*\#bftd-link-1001" title="Linked to GoHighLevel">Linked</a>#', $g4),
  'and a linked one says Linked');
$GLOBALS['MANAGE'] = false;
ob_start(); $draw->invoke(null, $lk); $g5 = ob_get_clean();
$GLOBALS['MANAGE'] = true; $GLOBALS['GHL_ON'] = false;
ob_start(); $draw->invoke(null, $lk); $g6 = ob_get_clean();
$GLOBALS['GHL_ON'] = true; unset($GLOBALS['META'][1001]['_bftd_crm_id']);
check(false === strpos($g5, 'bftd-stu-crm') && false === strpos($g5, 'data-l="Linked"'), 'a tutor does not see it, nor an empty column');
check(false === strpos($g6, 'bftd-stu-crm') && false === strpos($g6, 'data-l="Linked"'), 'nor does anyone before GoHighLevel is connected');
$secm = new ReflectionMethod('BFTD_Students', 'section'); $secm->setAccessible(true);
foreach (array(true, false) as $mg) {
  $GLOBALS['MANAGE'] = $mg;
  ob_start(); $secm->invoke(null, 0, array('name' => 'Unassigned', 'rows' => array($one)), array('tutor' => 0)); $tb = ob_get_clean();
  $heads = preg_match_all('#<th[ >]#', $tb); $cells = substr_count(explode('</tr>', explode('<tbody>', $tb)[1])[0], '<td');
  check($heads === $cells && ($mg ? 1 : 0) === preg_match('#<th>Student</th>\s*<th>Linked</th>\s*<th>Client</th>#', $tb),
    ($mg ? 'a manager has the Linked heading beside Student' : 'a tutor has no Linked heading') . ', and a cell for every heading, got ' . $heads . '/' . $cells);
  check(1 === preg_match('#<div class="bftd-stu-scroll" tabindex="0" role="region" aria-label="Unassigned, students">\s*<table class="bftd-stu-t">.*</table>\s*</div>#s', $tb), 'the table sits in a region that scrolls sideways, reachable from the keyboard');
}
$GLOBALS['MANAGE'] = true;
$css = file_get_contents(BFTD_PATH . 'assets/css/bftd-admin.css');
/* The row's actions are small buttons with space between, not words split by pipes (1.195). */
check(1 === preg_match('/\.bftd-stu-acts\{display:flex;flex-wrap:wrap;gap:5px 6px;/', $css) && false === strpos($css, '.bftd-stu-acts a:not(:last-child):after{content:" | "'), 'the row\'s actions are spaced buttons, not words split by pipes');
check(1 === preg_match('/\.bftd-stu-acts a\{display:inline-flex;align-items:center;min-height:24px;padding:0 8px;white-space:nowrap;/', $css), 'each a box of its own that never breaks across a line');
check(false !== strpos($css, '.bftd-stu-t td.bftd-stu-name{width:250px;min-width:250px}'), 'and the name column has room for them two to a line, not one under another (1.196)');

check(false !== strpos($css, '.bftd-stu-scroll{overflow-x:auto;') && false !== strpos($css, '@media (min-width:783px){ .bftd-stu-t{min-width:1100px} }') && false !== strpos($css, '@media (max-width:782px){ .bftd-stu-scroll{overflow:visible} }'),
  'it scrolls rather than squeezing its columns, except on a phone, where rows are cards');
/* The student record's own state, in its own column, in the same pills the
   Sessions screen uses for a session. */
check(1 === preg_match('#<td data-l="Record"><span class="bftd-pill is-active">Published</span></td>#', $tr),
  'a finished student record reads Published in the Record column');
$GLOBALS['POSTS'][1002]->post_status = 'draft';
$draft_row = BFTD_Students::rows(array(1002))[1002];
ob_start(); $draw->invoke(null, $draft_row); $tr3 = ob_get_clean();
$GLOBALS['POSTS'][1002]->post_status = 'publish';
check(1 === preg_match('#<td data-l="Record"><span class="bftd-pill is-past">Draft</span>(<a class="bftd-stu-trash".*?</a>)?</td>#s', $tr3),
  'and one still in draft reads Draft');
check(false === strpos($tr3, 'bftd-stu-draft"'), 'said once, in the column, not again beside the name');

/* A red trash can on a draft student, for managers and up (1.179). */
check(1 === preg_match('#<td data-l="Record"><span class="bftd-pill is-past">Draft</span><a class="bftd-stu-trash" href="[^"]*admin-post\.php\?action=bftd_trash_student(&|&amp;|&\#038;)student=1002[^"]*_wpnonce=[^"]*" title="Delete" aria-label="Delete Student 001, a draft student" onclick="return confirm\(&quot;Move Student 001 to the trash\? It can be brought back from there\.&quot;\)"><span class="dashicons dashicons-trash"#', $tr3),
  'a draft student has a trash can beside Draft, asking before it moves them to the trash');
check(false === strpos($tr, 'bftd-stu-trash'), 'a published student has none');
$GLOBALS['MANAGE'] = false; ob_start(); $draw->invoke(null, $draft_row); $t1 = ob_get_clean(); $GLOBALS['MANAGE'] = true;
$GLOBALS['CAN'] = false; ob_start(); $draw->invoke(null, $draft_row); $t2 = ob_get_clean(); $GLOBALS['CAN'] = true;
check(false === strpos($t1, 'bftd-stu-trash'), 'a tutor sees no trash can');
check(false === strpos($t2, 'bftd-stu-trash'), 'nor does anyone who may not delete the student');
$src = file_get_contents(BFTD_PATH . 'includes/class-bftd-students.php');
$h = substr($src, strpos($src, 'public static function trash_request()'));
$h = substr($h, 0, strpos($h, "\n\t}\n"));
check(false !== strpos($h, 'check_admin_referer') && strpos($h, 'check_admin_referer') < strpos($h, 'wp_trash_post'), 'pressing it checks the nonce before anything moves');
check(1 === preg_match('#\'draft\' !== \$post->post_status\s*\|\| ! BFTD_Roles::can_manage\(\) \|\| ! current_user_can\( \'delete_post\', \$sid \)#', $h), 'and checks again that it is a draft, and that this person is a manager who may delete it');
check(false === strpos($h, 'wp_delete_post'), 'and only ever moves it to the trash, never deletes it for good');
$sec = new ReflectionMethod('BFTD_Students', 'section'); $sec->setAccessible(true);
ob_start(); $sec->invoke(null, 0, array('name' => 'Unassigned', 'rows' => array($one)), array('tutor' => 0)); $table = ob_get_clean();
check(1 === preg_match('#<th>Status</th>\s*<th>Record</th>#', $table), 'with Record as a column of its own beside Status');
check(preg_match_all('#<th[ >]#', $table) === substr_count(explode('</tr>', explode('<tbody>', $table)[1])[0], '<td'),
  'and every row has a cell for every heading');
check('Student 000' === $one['name'], 'the row is named');
check('Client 100' === $one['client'], 'and names the client');
check(isset($one['tutors'][5]), 'and the tutor');
check('prospect' === $one['status'], 'and the status');
/* Three attended and one cancelled is four hours spent out of twenty. */
check(4 === $one['used'] && 16 === $one['left'],
  'and what is left of the block, counting the cancellation, got ' . $one['used'] . ' used and ' . $one['left'] . ' left');

/* The caregivers column says who can open this child's portal, and the client
   is one of them. It used to leave them out on the reasoning that they were
   named in the column beside it, which made a family where the client is the
   only caregiver read as a dash: nobody can see it, which is the opposite of
   true and true of most families here. */
check(1 === count($one['care']), 'the client is listed among the people with access, got ' . json_encode($one['care']));
check($one['care'][0]['client'], 'and marked as the client, so the same name twice reads as deliberate');

$GLOBALS['META'][$ids[0]]['_bftd_client_user_id'] = array(100, 401);
$GLOBALS['USERS'][401] = (object) array('ID' => 401, 'display_name' => 'Sam Reid', 'user_email' => 's@example.test');
$two = BFTD_Students::rows(array($ids[0]));
check(2 === count($two[$ids[0]]['care']), 'a second caregiver is listed beside them');
check(false === $two[$ids[0]]['care'][1]['client'], 'and is not marked as the client');
$GLOBALS['META'][$ids[0]]['_bftd_client_user_id'] = array(100);

/* The LAST student, not the first. A counter that fetched only the head of
   the list would have every other row reading twenty of twenty left, which
   is a screen telling a practice nobody has used anything. */
$last = $rows[$ids[count($ids) - 1]];
check(4 === $last['used'] && 16 === $last['left'],
  'and so does the last row, not only the first, got ' . $last['used'] . ' used');
$spent = 0;
foreach ($rows as $r) { if (4 === $r['used']) $spent++; }
check(100 === $spent, 'every row is counted, got ' . $spent);

/* Lessons taught before any of this existed are an offset on the record, and
   they are spent as surely as the ones with a session behind them. */
$GLOBALS['META'][$ids[0]]['_bftd_lessons_used_before'] = 6;
$withOffset = BFTD_Students::rows(array($ids[0]));
check(10 === $withOffset[$ids[0]]['used'] && 10 === $withOffset[$ids[0]]['left'],
  'a student who joined with lessons behind them has them counted, got ' . $withOffset[$ids[0]]['used']);
unset($GLOBALS['META'][$ids[0]]['_bftd_lessons_used_before']);

/* ---- the sections ---- */
$groups = BFTD_Students::by_tutor($rows);
check(4 === count($groups), 'three tutors and one section for the unassigned, got ' . count($groups));

$names = array();
foreach ($groups as $uid => $g) $names[] = $g['name'];
check(array('Avery Lin', 'Jo Patel', 'Rae Tanaka', 'Nobody assigned') === $names,
  'tutors by name, and nobody-assigned last because it is a list of things to fix: ' . json_encode($names));

/* A child with two tutors is under both. Either tutor reading their own list
   has to find them there, and a screen that picks one silently hides a
   student from somebody who teaches them. */
$in = array();
foreach ($groups as $uid => $g) {
  foreach ($g['rows'] as $r) { if ($r['id'] === $both) $in[] = $g['name']; }
}
check(2 === count($in), 'a child with two tutors is in both sections, got ' . json_encode($in));

/* And one with none is in exactly one place, rather than nowhere. */
$found = 0;
foreach ($groups as $g) foreach ($g['rows'] as $r) if ($r['id'] === $orphan) $found++;
check(1 === $found, 'a child with no tutor is still on the screen, got ' . $found);
check(isset($groups[0]) && 'Nobody assigned' === $groups[0]['name'], 'in the section that says so');

/* ---- the filters ---- */
$f = function ($over) { return array_merge(array('q' => '', 'tutor' => 0, 'client' => 0, 'status' => ''), $over); };

$byTutor = BFTD_Students::student_ids($f(array('tutor' => 7)));
check(count($byTutor) > 0 && count($byTutor) < 100, 'a tutor filter narrows the screen, got ' . count($byTutor));
foreach ($byTutor as $id) {
  if (!in_array(7, array_map('intval', (array) $GLOBALS['META'][$id]['_bftd_assigned_staff']), true)) {
    check(false, 'and only to their students'); break;
  }
}

$byStatus = BFTD_Students::student_ids($f(array('status' => 'prospect')));
check(20 === count($byStatus), 'a status filter narrows to that status, got ' . count($byStatus));

$both_f = BFTD_Students::student_ids($f(array('tutor' => 7, 'status' => 'prospect')));
check(count($both_f) < count($byStatus), 'and two filters narrow each other, got ' . count($both_f));

$byClient = BFTD_Students::student_ids($f(array('client' => 100)));
check(count($byClient) > 0, 'a client filter finds the children they pay for, got ' . count($byClient));

/* Nobody matching is an empty screen, not the whole practice. */
$none = BFTD_Students::student_ids($f(array('tutor' => 999)));
check(array() === $none, 'a filter matching nobody shows nobody, got ' . count($none));

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
