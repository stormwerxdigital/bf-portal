<?php
/*
 * The pay ledger.
 *
 * Everything in here is a rule about money, which is the one area of this
 * plugin where being nearly right is worse than being obviously broken: a
 * lesson that silently pays twice is not noticed until a tutor's own count
 * disagrees with their deposit, and by then it has happened for a quarter.
 *
 * So the rules are tested as rules rather than as functions:
 *
 *   a draft pays nobody, ever;
 *   one lesson makes one entry, however many times it is republished,
 *   rescheduled or corrected;
 *   the rate is frozen onto the entry when it is made, so a raise does not
 *   reach back;
 *   a decided entry does not follow its lesson any more;
 *   a paid entry cannot be decided again;
 *   and nothing pending counts towards what a tutor is owed.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();
$GLOBALS['UMETA'] = array();
$GLOBALS['OPTS']  = array();
$GLOBALS['LOG']   = array();
$GLOBALS['NEXT']  = 1000;

function get_post($id = null) { return $GLOBALS['POSTS'][(int) $id] ?? null; }
function get_post_type($id) { return $GLOBALS['POSTS'][(int) $id]->post_type ?? ''; }
function get_the_title($id) { return $GLOBALS['POSTS'][(int) $id]->post_title ?? ''; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ($s ? '' : array()); }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['META'][$id][$k]); return true; }
function get_user_meta($id, $k, $s = false) { return $GLOBALS['UMETA'][$id][$k] ?? ($s ? '' : array()); }
function update_user_meta($id, $k, $v) { $GLOBALS['UMETA'][$id][$k] = $v; return true; }
function get_option($k, $d = '') { return $GLOBALS['OPTS'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['OPTS'][$k] = $v; return true; }
function get_userdata($id) { return isset($GLOBALS['USERS'][$id]) ? (object) $GLOBALS['USERS'][$id] : null; }
function user_can($id, $cap) { return in_array($cap, $GLOBALS['CAPS'][$id] ?? array(), true); }
function get_current_user_id() { return $GLOBALS['ME'] ?? 0; }
function wp_is_post_revision($id) { return false; }
function register_post_type(...$a) {}
function add_action(...$a) {}
function is_admin() { return true; }
function current_user_can(...$a) { return true; }
function absint($n) { return abs((int) $n); }

function wp_insert_post($a, $wp_error = false) {
  $id = $GLOBALS['NEXT']++;
  $GLOBALS['POSTS'][$id] = (object) array_merge(
    array('ID' => $id, 'post_type' => '', 'post_status' => 'publish', 'post_title' => '', 'post_date' => '2026-09-01 09:00:00'),
    $a
  );
  $GLOBALS['POSTS'][$id]->ID = $id;
  return $id;
}
function wp_update_post($a) {
  $id = (int) $a['ID'];
  foreach ($a as $k => $v) { if ('ID' !== $k) $GLOBALS['POSTS'][$id]->$k = $v; }
  return $id;
}

/* get_posts, modelling the two queries the ledger actually makes: one by a
   single meta value, and one over a BETWEEN on a date string. A stub that
   ignored meta_query would report every entry as being in every period,
   which is the failure this file exists to catch. */
function get_posts($a) {
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (isset($a['post_status']) && $p->post_status !== $a['post_status']) continue;

    if (isset($a['meta_key']) && isset($a['meta_value'])) {
      if ((string) ($GLOBALS['META'][$id][$a['meta_key']] ?? '') !== (string) $a['meta_value']) continue;
    }
    if (!empty($a['meta_query'])) {
      $ok = true;
      foreach ($a['meta_query'] as $q) {
        if (!is_array($q) || !isset($q['key'])) continue;
        $have = (string) ($GLOBALS['META'][$id][$q['key']] ?? '');
        if (($q['compare'] ?? '=') === 'BETWEEN') {
          if ($have < $q['value'][0] || $have > $q['value'][1]) { $ok = false; break; }
        } elseif ($have !== (string) $q['value']) { $ok = false; break; }
      }
      if (!$ok) continue;
    }
    $out[] = $id;
  }
  if (isset($a['posts_per_page']) && $a['posts_per_page'] > 0) $out = array_slice($out, 0, (int) $a['posts_per_page']);
  return $out;
}
function update_meta_cache($t, $ids) { return true; }

class BFTD_Audit { public static function log($what, $args = array()) { $GLOBALS['LOG'][] = $what; } }
class BFTD_Roles {
  const ADMIN_CAP = 'bftd_administer';
  const STAFF_CAP = 'bftd_staff';
  public static function is_staff($id = null) { return user_can($id ?: get_current_user_id(), self::STAFF_CAP); }
}
class BFTD_Schema { public static function meta_key($g, $k) { return '_bftd_' . str_replace('-', '_', $g) . '__' . $k; } }
class BFTD_CPT {
  const SESSION = 'bftd_session'; const ASSESSMENT = 'bftd_assessment'; const STUDENT_KEY = '_bftd_student_id';
  public static function student_id($post_id) { return (int) ($GLOBALS['META'][$post_id][self::STUDENT_KEY] ?? 0); }
}

/* Fields, reading the same meta keys the real one builds. */
class BFTD_Fields {
  public static function get($post_id, $section, $key) {
    return $GLOBALS['META'][$post_id][BFTD_Schema::meta_key($section, $key)] ?? '';
  }
  public static function set($post_id, $section, $key, $value) {
    $GLOBALS['META'][$post_id][BFTD_Schema::meta_key($section, $key)] = $value;
  }
}

require BFTD_PATH . 'includes/class-bftd-pay-period.php';
require BFTD_PATH . 'includes/class-bftd-pay.php';

$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? "  ok   " : "FAIL  ") . $msg . "\n"; if (!$ok) $fail++; }
function is($got, $want, $msg) { check($got === $want, $msg . ($got === $want ? '' : " (got " . var_export($got, true) . ", wanted " . var_export($want, true) . ")")); }

$GLOBALS['USERS'] = array(
  7 => array('ID' => 7, 'display_name' => 'Laurel Sanders'),
  8 => array('ID' => 8, 'display_name' => 'Nina Okafor'),
  9 => array('ID' => 9, 'display_name' => 'The Boss'),
);
$GLOBALS['CAPS'] = array(
  7 => array(BFTD_Roles::STAFF_CAP),
  8 => array(BFTD_Roles::STAFF_CAP),
  9 => array(BFTD_Roles::STAFF_CAP, BFTD_Roles::ADMIN_CAP),
);
$GLOBALS['ME'] = 9;

update_option(BFTD_Pay::OPT_SESSION, 4000);
update_option(BFTD_Pay::OPT_DIAGNOSIS, 15000);
update_user_meta(7, BFTD_Pay::META_SESSION, '4500');

/* ---- rates ---- */
is(BFTD_Pay::rate_for(7, BFTD_Pay::TAUGHT), 4500, 'a tutor with their own rate is paid it');
is(BFTD_Pay::rate_for(8, BFTD_Pay::TAUGHT), 4000, 'a tutor without one falls back to the practice default');
is(BFTD_Pay::rate_for(7, BFTD_Pay::DIAGNOSTIC), 15000, 'and falls back for a rate they have not been given');
is(BFTD_Pay::rate_for(7, BFTD_Pay::CANCELLED), 4500, 'a cancelled lesson is worth a lesson');
is(BFTD_Pay::rate_for(7, BFTD_Pay::RESCHEDULED), 4500, 'and so is a moved one');

/* Cents, through a string, because 75.10 * 100 is not 7510 in binary. */
is(BFTD_Pay::cents('75.10'), 7510, 'seventy-five dollars ten is 7510 cents and not 7509');
is(BFTD_Pay::cents('$1,234.56'), 123456, 'a rate typed with a dollar sign and a comma still reads');
is(BFTD_Pay::cents(''), 0, 'nothing is nothing');
is(BFTD_Pay::cents('abc'), 0, 'and neither is a word');
is(BFTD_Pay::money(123456), '$1,234.56', 'and comes back out as money');

/* ---- a lesson makes an entry ---- */
function lesson($tutor, $date, $status = 'held', $time = '16:00') {
  $id = wp_insert_post(array('post_type' => BFTD_CPT::SESSION, 'post_status' => 'publish', 'post_title' => 'A lesson'));
  BFTD_Fields::set($id, 'session', 'session_date', $date);
  BFTD_Fields::set($id, 'session', 'session_time', $time);
  BFTD_Fields::set($id, 'session', 'status', $status);
  BFTD_Fields::set($id, 'session', 'delivered_by', (string) $tutor);
  return $id;
}

$l1 = lesson(7, '2026-09-03');
$e1 = BFTD_Pay::record(get_post($l1));
check($e1 > 0, 'publishing a lesson makes an entry');
$r = BFTD_Pay::row($e1);
is($r['tutor'], 7, 'against the tutor who taught it');
is($r['amount'], 4500, 'at that tutor\'s own rate');
is($r['state'], BFTD_Pay::PENDING, 'waiting on a decision');
is($r['kind'], BFTD_Pay::TAUGHT, 'and marked as a lesson');

/* ---- ONE LESSON, ONE ENTRY ---- */
$again = BFTD_Pay::record(get_post($l1));
is($again, $e1, 'publishing the same lesson again is the same entry');
is(count(BFTD_Pay::entries_in('2026-09-A')), 1, 'and there is still only one of it');

/* Rescheduled twice, then taught. Still one entry, and still one payment. */
BFTD_Fields::set($l1, 'session', 'status', 'rescheduled');
BFTD_Fields::set($l1, 'session', 'session_date', '2026-09-08');
BFTD_Pay::record(get_post($l1));
BFTD_Fields::set($l1, 'session', 'session_date', '2026-09-10');
BFTD_Pay::record(get_post($l1));
BFTD_Fields::set($l1, 'session', 'status', 'held');
BFTD_Pay::record(get_post($l1));
is(count(BFTD_Pay::entries_in('2026-09-A')), 1, 'a lesson moved twice and then taught is paid once');
is(BFTD_Pay::row($e1)['on'], '2026-09-10', 'and a pending entry follows its lesson to the new date');
is(BFTD_Pay::row($e1)['kind'], BFTD_Pay::TAUGHT, 'and to what it turned out to be');

/* Moved into the next period, the entry moves with it. */
BFTD_Fields::set($l1, 'session', 'session_date', '2026-09-22');
BFTD_Pay::record(get_post($l1));
is(count(BFTD_Pay::entries_in('2026-09-A')), 0, 'moved into the second half, it leaves the first');
is(count(BFTD_Pay::entries_in('2026-09-B')), 1, 'and arrives in the second');
BFTD_Fields::set($l1, 'session', 'session_date', '2026-09-03');
BFTD_Pay::record(get_post($l1));

/* ---- a raise does not reach back ---- */
update_user_meta(7, BFTD_Pay::META_SESSION, '6000');
$l2 = lesson(7, '2026-09-04');
$e2 = BFTD_Pay::record(get_post($l2));
is(BFTD_Pay::row($e2)['amount'], 6000, 'a lesson after a raise is paid the new rate');
BFTD_Pay::decide($e1, BFTD_Pay::APPROVED);
is(BFTD_Pay::row($e1)['amount'], 4500, 'and one approved before it keeps the old one');

/* ---- a decided entry stops following its lesson ---- */
BFTD_Fields::set($l1, 'session', 'status', 'missed');
BFTD_Fields::set($l1, 'session', 'session_date', '2026-09-05');
BFTD_Pay::record(get_post($l1));
is(BFTD_Pay::row($e1)['on'], '2026-09-03', 'an approved entry does not move when its lesson does');
is(BFTD_Pay::row($e1)['kind'], BFTD_Pay::TAUGHT, 'nor change what it was for');
check('' !== BFTD_Pay::row($e1)['changed'], 'but it says the lesson changed under it');

/* ---- what needs a decision ---- */
check(BFTD_Pay::needs_a_decision(BFTD_Pay::CANCELLED), 'a cancelled lesson needs somebody to say yes');
check(BFTD_Pay::needs_a_decision(BFTD_Pay::RESCHEDULED), 'and so does a moved one');
check(!BFTD_Pay::needs_a_decision(BFTD_Pay::TAUGHT), 'a taught lesson is the ordinary case');
check(!BFTD_Pay::needs_a_decision(BFTD_Pay::DIAGNOSTIC), 'and so is a diagnostic');

/* ---- an entry nobody can attribute is not made ---- */
$orphan = lesson(0, '2026-09-06');
is(BFTD_Pay::record(get_post($orphan)), 0, 'a lesson with no tutor on it makes no entry');
$undated = lesson(7, '');
is(BFTD_Pay::record(get_post($undated)), 0, 'and neither does one with no date');
$impossible = lesson(7, '2026-09-31');
is(BFTD_Pay::record(get_post($impossible)), 0, 'nor one dated the 31st of September');

/* ---- a diagnostic ---- */
$dx = wp_insert_post(array('post_type' => BFTD_CPT::ASSESSMENT, 'post_status' => 'publish', 'post_title' => 'Initial'));
BFTD_Fields::set($dx, BFTD_Pay::DX_SECTION, 'assessed_by', '8');
BFTD_Fields::set($dx, BFTD_Pay::DX_SECTION, 'assessed_on', '2026-09-09');
$e3 = BFTD_Pay::record(get_post($dx));
check($e3 > 0, 'publishing a diagnostic makes an entry too');
is(BFTD_Pay::row($e3)['amount'], 15000, 'at the diagnostic rate');
is(BFTD_Pay::row($e3)['tutor'], 8, 'against whoever assessed it');

/* ---- totals ---- */
$rows = array_map(array('BFTD_Pay', 'row'), BFTD_Pay::entries_in('2026-09-A'));
$t = BFTD_Pay::totals($rows);
is($t['due'], 4500, 'only what is approved counts towards what is owed');
is($t['pending'], 21000, 'what is pending is counted separately');
is($t['waiting'], 2, 'and says how many decisions are outstanding');

BFTD_Pay::decide($e3, BFTD_Pay::DECLINED);
$t = BFTD_Pay::totals(array_map(array('BFTD_Pay', 'row'), BFTD_Pay::entries_in('2026-09-A')));
is($t['due'], 4500, 'a declined entry adds nothing');
is($t['declined'], 15000, 'and is counted as declined');

/* ---- a paid entry is closed ---- */
update_post_meta($e2, BFTD_Pay::key('state'), BFTD_Pay::PAID);
is(BFTD_Pay::decide($e2, BFTD_Pay::DECLINED), false, 'an entry that has been paid cannot be decided again');
is(BFTD_Pay::row($e2)['state'], BFTD_Pay::PAID, 'and stays paid');

/* ---- who may say yes ---- */
check(BFTD_Pay::can_approve(9), 'a senior manager can approve pay');
check(!BFTD_Pay::can_approve(7), 'a tutor cannot');
check(!BFTD_Pay::can_approve(8), 'and neither can another tutor');

/* ---- periods hold the right entries ---- */
is(count(BFTD_Pay::entries_in('2026-10-A')), 0, 'a period with nothing in it has nothing in it');
is(count(BFTD_Pay::entries_in('nonsense')), 0, 'and a period that is not one has nothing either');
is(count(BFTD_Pay::entries_in('2026-09-A', 8)), 1, 'asking for one tutor gives one tutor');
is(count(BFTD_Pay::entries_in('2026-09-A', 7)), 2, 'and the other gives the other');

/* ---- DRAFTS PAY NOBODY ----
 *
 * The rule this whole feature stands on, and the one a plausible
 * implementation gets wrong: hooked on save_post rather than on the
 * transition, every keystroke a tutor saves into a draft would be a lesson's
 * pay. So the transition itself is driven here, with the statuses WordPress
 * actually passes it. */
$d = lesson(7, '2026-11-04');
$GLOBALS['POSTS'][$d]->post_status = 'draft';

BFTD_Pay::on_transition('draft', 'auto-draft', get_post($d));
is(count(BFTD_Pay::entries_in('2026-11-A')), 0, 'saving a new draft pays nobody');

BFTD_Pay::on_transition('draft', 'draft', get_post($d));
is(count(BFTD_Pay::entries_in('2026-11-A')), 0, 'and saving it again pays nobody');

BFTD_Pay::on_transition('pending', 'draft', get_post($d));
is(count(BFTD_Pay::entries_in('2026-11-A')), 0, 'submitting it for review pays nobody');

$GLOBALS['POSTS'][$d]->post_status = 'publish';
BFTD_Pay::on_transition('publish', 'draft', get_post($d));
is(count(BFTD_Pay::entries_in('2026-11-A')), 1, 'publishing it is what pays');

BFTD_Pay::on_transition('publish', 'publish', get_post($d));
is(count(BFTD_Pay::entries_in('2026-11-A')), 1, 'and publishing it again does not pay twice');

/* Unpublished and published again. The lesson happened once. */
$GLOBALS['POSTS'][$d]->post_status = 'draft';
BFTD_Pay::on_transition('draft', 'publish', get_post($d));
$GLOBALS['POSTS'][$d]->post_status = 'publish';
BFTD_Pay::on_transition('publish', 'draft', get_post($d));
is(count(BFTD_Pay::entries_in('2026-11-A')), 1, 'taken down and put back up, it is still one entry');

/* Nothing else on the site reaches the ledger. */
$other = wp_insert_post(array('post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'A blog post'));
BFTD_Pay::on_transition('publish', 'draft', get_post($other));
is(count(BFTD_Pay::entries_in('2026-11-A')), 1, 'publishing something that is not a lesson pays nobody');

exit($fail ? 1 : 0);
