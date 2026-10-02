<?php
/*
 * Statutory holiday pay.
 *
 * Three things here decide money and all three are easy to write plausibly
 * and wrongly.
 *
 * THE FIFTEEN DAY TEST. Days, not entries: two lessons on a Tuesday are one
 * day worked. Counting entries instead would qualify a part-time tutor who
 * does not qualify, and nothing on the screen would look wrong.
 *
 * THE AVERAGE DAY'S PAY. Total wages in the thirty days before, over days
 * worked in them. The window is the thirty days BEFORE the holiday, not
 * including it, and not the pay period.
 *
 * THE PREMIUM FOR WORKING ONE. Time and a half to twelve hours, double past
 * it. A tutor cannot reach twelve hours of teaching in a day in practice,
 * which is exactly why that branch would never be exercised by hand and is
 * exercised here.
 *
 * And the one that is not arithmetic: a contractor gets none of this, and
 * running the calculation twice does not pay Christmas twice.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);

$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['UMETA'] = array();
$GLOBALS['OPTS'] = array(); $GLOBALS['NEXT'] = 3000; $GLOBALS['ME'] = 9;

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
function get_userdata($id) { return $GLOBALS['USERS'][$id] ?? null; }
function user_can($id, $cap) { return in_array($cap, $GLOBALS['CAPS'][$id] ?? array(), true); }
function get_current_user_id() { return (int) $GLOBALS['ME']; }
function wp_is_post_revision($id) { return false; }
function register_post_type(...$a) {}
function add_action(...$a) {}
function is_admin() { return true; }
function current_user_can(...$a) { return true; }
function absint($n) { return abs((int) $n); }
function update_meta_cache($t, $ids) { return true; }
function wp_insert_post($a, $e = false) {
  $id = $GLOBALS['NEXT']++;
  $GLOBALS['POSTS'][$id] = (object) array_merge(array('ID'=>$id,'post_type'=>'','post_status'=>'publish','post_title'=>'','post_date'=>'2026-01-01 09:00:00'), $a);
  $GLOBALS['POSTS'][$id]->ID = $id;
  return $id;
}
function wp_update_post($a) { $id=(int)$a['ID']; foreach($a as $k=>$v){ if('ID'!==$k) $GLOBALS['POSTS'][$id]->$k=$v; } return $id; }
function get_posts($a) {
  $out = array();
  foreach ($GLOBALS['POSTS'] as $id => $p) {
    if ($p->post_type !== $a['post_type']) continue;
    if (isset($a['post_status']) && $p->post_status !== $a['post_status']) continue;
    if (isset($a['meta_key']) && isset($a['meta_value'])) {
      if ((string)($GLOBALS['META'][$id][$a['meta_key']] ?? '') !== (string)$a['meta_value']) continue;
    }
    if (!empty($a['meta_query'])) {
      $ok = true;
      foreach ($a['meta_query'] as $q) {
        if (!is_array($q) || !isset($q['key'])) continue;
        $have = (string)($GLOBALS['META'][$id][$q['key']] ?? '');
        if (($q['compare'] ?? '=') === 'BETWEEN') {
          if ($have < $q['value'][0] || $have > $q['value'][1]) { $ok = false; break; }
        } elseif ($have !== (string)$q['value']) { $ok = false; break; }
      }
      if (!$ok) continue;
    }
    $out[] = $id;
  }
  if (isset($a['posts_per_page']) && $a['posts_per_page'] > 0) $out = array_slice($out, 0, (int)$a['posts_per_page']);
  return $out;
}

class BFTD_Audit { public static function log(...$a) {} }
class BFTD_Roles { const ADMIN_CAP='bftd_administer'; const STAFF_CAP='bftd_staff';
  public static function is_staff($id=null){ return user_can($id ?: get_current_user_id(), self::STAFF_CAP); } }
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.str_replace('-','_',$g).'__'.$k; } }
class BFTD_CPT { const SESSION='bftd_session'; const ASSESSMENT='bftd_assessment'; const STUDENT_KEY='_bftd_student_id';
  public static function student_id($id){ return (int)($GLOBALS['META'][$id][self::STUDENT_KEY] ?? 0); } }
class BFTD_Fields {
  public static function get($id,$g,$k){ return $GLOBALS['META'][$id][BFTD_Schema::meta_key($g,$k)] ?? ''; }
  public static function set($id,$g,$k,$v){ $GLOBALS['META'][$id][BFTD_Schema::meta_key($g,$k)] = $v; }
}

require BFTD_PATH . 'includes/class-bftd-pay-period.php';
require BFTD_PATH . 'includes/class-bftd-stat-holidays.php';
require BFTD_PATH . 'includes/class-bftd-pay.php';
require BFTD_PATH . 'includes/class-bftd-stat-pay.php';

$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? "  ok   " : "FAIL  ") . $msg . "\n"; if (!$ok) $fail++; }
function is($got, $want, $msg) { check($got === $want, $msg . ($got === $want ? '' : " (got " . var_export($got, true) . ", wanted " . var_export($want, true) . ")")); }

$GLOBALS['USERS'] = array(
  5 => (object) array('ID'=>5,'display_name'=>'Laurel Sanders'),
  6 => (object) array('ID'=>6,'display_name'=>'Nina Okafor'),
  7 => (object) array('ID'=>7,'display_name'=>'Sam Idowu'),
);
$GLOBALS['CAPS'] = array(5=>array(BFTD_Roles::STAFF_CAP),6=>array(BFTD_Roles::STAFF_CAP),7=>array(BFTD_Roles::STAFF_CAP));

update_option(BFTD_Pay::OPT_SESSION, 4000);
update_option(BFTD_Pay::OPT_DIAGNOSIS, 15000);
update_user_meta(5, BFTD_Pay::META_KIND, BFTD_Pay::EMPLOYEE);
update_user_meta(5, BFTD_Pay::META_STARTED, '2020-01-06');
update_user_meta(6, BFTD_Pay::META_KIND, BFTD_Pay::CONTRACTOR);
update_user_meta(6, BFTD_Pay::META_STARTED, '2020-01-06');
update_user_meta(7, BFTD_Pay::META_KIND, BFTD_Pay::EMPLOYEE);
update_user_meta(7, BFTD_Pay::META_STARTED, '2020-01-06');

/* A published session on a date, already approved. */
function worked($tutor, $date, $kind = 'session', $state = null) {
  $type = ('diagnostic' === $kind) ? BFTD_CPT::ASSESSMENT : BFTD_CPT::SESSION;
  $id = wp_insert_post(array('post_type'=>$type,'post_status'=>'publish','post_title'=>'x'));
  if (BFTD_CPT::ASSESSMENT === $type) {
    BFTD_Fields::set($id, BFTD_Pay::DX_SECTION, 'assessed_by', (string)$tutor);
    BFTD_Fields::set($id, BFTD_Pay::DX_SECTION, 'assessed_on', $date);
  } else {
    BFTD_Fields::set($id,'session','session_date',$date);
    BFTD_Fields::set($id,'session','session_time','16:00');
    BFTD_Fields::set($id,'session','status','held');
    BFTD_Fields::set($id,'session','delivered_by',(string)$tutor);
  }
  $e = BFTD_Pay::record(get_post($id));
  BFTD_Pay::decide($e, $state ?: BFTD_Pay::APPROVED, 9);
  return $e;
}

/* Christmas Day 2026 is a Friday. The thirty days before it are 25 Nov to
   24 Dec inclusive. */
$XMAS = '2026-12-25';

/* ---- a full-time tutor: twenty weekdays in the window ---- */
for ($d = 25; $d <= 30; $d++) worked(5, sprintf('2026-11-%02d', $d));
for ($d = 1; $d <= 16; $d++)  worked(5, sprintf('2026-12-%02d', $d));

$w = BFTD_Stat_Pay::working(5, $XMAS);
is($w['from'], '2026-11-25', 'the window opens thirty days before the holiday');
is($w['to'], '2026-12-24', 'and closes the day before it, not on it');
is($w['days'], 22, 'twenty-two separate days were worked in it');
check($w['qualifies'], 'which is more than fifteen, so they qualify');
is($w['wages'], 22 * 4000, 'wages are what was earned in the window');
is($w['amount'], 4000, 'and an average day\'s pay is the wages over the days');

/* Two lessons on one day is one DAY worked, and two lots of wages. */
worked(5, '2026-12-17');
worked(5, '2026-12-17');
$w = BFTD_Stat_Pay::working(5, $XMAS);
is($w['days'], 23, 'two lessons on the same day count as one day worked');
is($w['wages'], 24 * 4000, 'and as two lots of wages');
is($w['amount'], (int) round((24 * 4000) / 23), 'so the average day goes up, not the day count');

/* ---- a part-time tutor: the case the Act actually excludes ---- */
foreach (array('11-26','12-01','12-03','12-08','12-10','12-15','12-17','12-22') as $md) {
  worked(7, '2026-' . $md);
}
$p = BFTD_Stat_Pay::working(7, $XMAS);
is($p['days'], 8, 'a tutor teaching twice a week reaches eight days in thirty');
check(!$p['qualifies'], 'and does not qualify, which is the Act and not a bug');
check(false !== strpos($p['why'], '8 of the thirty days'), 'the reason says how many days they had');
check(false !== strpos($p['why'], '15'), 'and how many the Act asks for');
is($p['amount'], 0, 'so nothing is owed');

/* ---- a contractor ---- */
worked(6, '2026-12-02');
$c = BFTD_Stat_Pay::working(6, $XMAS);
check(!$c['qualifies'], 'a contractor never qualifies');
check(false !== stripos($c['why'], 'contractor'), 'and is told why in one word');

/* ---- somebody too new ---- */
update_user_meta(7, BFTD_Pay::META_STARTED, '2026-12-10');
$n = BFTD_Stat_Pay::working(7, $XMAS);
check(!$n['qualifies'], 'somebody employed less than thirty days does not qualify');
check(false !== strpos($n['why'], 'less than thirty days'), 'and is told that rather than a day count');
update_user_meta(7, BFTD_Pay::META_STARTED, '2020-01-06');

/* An unknown start date is a question, not a refusal dressed up as an answer. */
update_user_meta(7, BFTD_Pay::META_STARTED, '');
$u = BFTD_Stat_Pay::working(7, $XMAS);
check(!$u['qualifies'], 'an unknown start date does not qualify somebody');
check(false !== stripos($u['why'], 'no start date'), 'and says the field is blank rather than inventing an answer');
update_user_meta(7, BFTD_Pay::META_STARTED, '2020-01-06');

/* ---- what is still undecided ---- */
worked(5, '2026-12-18', 'session', BFTD_Pay::PENDING);
$w = BFTD_Stat_Pay::working(5, $XMAS);
is($w['undecided'], 1, 'an entry nobody has ruled on is reported, not counted');
is($w['days'], 23, 'and does not add a day');

/* A declined one is counted as nothing at all. */
worked(5, '2026-12-19', 'session', BFTD_Pay::DECLINED);
$w = BFTD_Stat_Pay::working(5, $XMAS);
is($w['days'], 23, 'a declined entry adds no day');
is($w['undecided'], 1, 'and is not reported as undecided either');

/* ---- working the holiday itself ----
 *
 * A session is an hour and a diagnostic is two and a half. Time and a half
 * means half again ON TOP of the ordinary entry the tutor already has, so
 * the extra owed on a piece of work below the twelfth hour is half of it.
 */
function on_xmas($tutor, $what, $n = 1) {
  for ($i = 0; $i < $n; $i++) worked($tutor, '2026-12-25', $what);
}

on_xmas(5, 'session');
$w = BFTD_Stat_Pay::working(5, $XMAS);
is($w['worked'], 100, 'a session taught on the holiday is one hour');
is($w['extra'], 2000, 'and earns half its rate again on top of itself');

on_xmas(5, 'diagnostic');
$w = BFTD_Stat_Pay::working(5, $XMAS);
is($w['worked'], 350, 'a diagnostic on top of it is two and a half hours more');
is($w['extra'], 2000 + 7500, 'earning half of its own rate again, at a different hourly rate');

/* Nothing taught on the day at all. */
$q = BFTD_Stat_Pay::working(7, '2026-11-11');
is($q['worked'], 0, 'a holiday nobody worked is no hours');
is($q['extra'], 0, 'and no premium');

/* ---- past the twelfth hour ----
 *
 * Thirteen sessions in a day does not happen, which is exactly why this
 * branch would never be reached by hand. Twelve hours at time and a half,
 * the thirteenth at double.
 */
$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['NEXT'] = 5000;
update_user_meta(5, BFTD_Pay::META_STARTED, '2020-01-06');
on_xmas(5, 'session', 13);
$long = BFTD_Stat_Pay::on_the_day(5, $XMAS);
is($long['hours'], 1300, 'thirteen sessions is thirteen hours');
is($long['extra'], (12 * 2000) + 4000, 'twelve of them at half again, the thirteenth at double');

/* And one piece of work that straddles the twelfth hour: eleven sessions,
   then a diagnostic that starts at eleven hours and ends at thirteen and a
   half. One hour of it is below the line and one and a half above. */
$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['NEXT'] = 6000;
on_xmas(5, 'session', 11);
on_xmas(5, 'diagnostic');
$straddle = BFTD_Stat_Pay::on_the_day(5, $XMAS);
is($straddle['hours'], 1350, 'eleven sessions and a diagnostic is thirteen and a half hours');
is($straddle['extra'], (11 * 2000) + 12000,
  'and the diagnostic is split at the twelfth hour rather than falling entirely one side of it');

/* ---- running it ---- */
$GLOBALS['POSTS'] = array(); $GLOBALS['META'] = array(); $GLOBALS['NEXT'] = 7000;
for ($d = 25; $d <= 30; $d++) worked(5, sprintf('2026-11-%02d', $d));
for ($d = 1; $d <= 16; $d++)  worked(5, sprintf('2026-12-%02d', $d));

$made = BFTD_Stat_Pay::run('2026-12-B', array(5, 6, 7));
is($made['paid'], 1, 'running the period makes one statutory holiday entry');
is($made['refused'], 2, 'and refuses the contractor and the tutor with too few days');

$xmas_entries = array();
foreach (BFTD_Pay::entries_in('2026-12-B') as $id) {
  $r = BFTD_Pay::row($id);
  if (BFTD_Pay::STAT === $r['kind']) $xmas_entries[] = $r;
}
is(count($xmas_entries), 1, 'there is one of it on the ledger');
is($xmas_entries[0]['tutor'], 5, 'against the tutor who qualified');
is($xmas_entries[0]['on'], '2026-12-25', 'dated the holiday');
is($xmas_entries[0]['state'], BFTD_Pay::PENDING, 'and waiting on an approver, like everything else');
check(BFTD_Pay::needs_a_decision($xmas_entries[0]['kind']), 'flagged as needing a decision');

/* Run it again. Christmas is not paid twice. */
BFTD_Stat_Pay::run('2026-12-B', array(5, 6, 7));
BFTD_Stat_Pay::run('2026-12-B', array(5, 6, 7));
$again = 0;
foreach (BFTD_Pay::entries_in('2026-12-B') as $id) {
  if (BFTD_Pay::STAT === BFTD_Pay::row($id)['kind']) $again++;
}
is($again, 1, 'running it three times still pays Christmas once');

/* A period with no statutory holiday in it produces nothing. */
$none = BFTD_Stat_Pay::run('2026-06-A', array(5, 6, 7));
is($none['paid'], 0, 'a period with no holiday in it pays nothing');
is($none['refused'], 0, 'and refuses nobody, because there was nothing to refuse');

/* Once approved, a rerun does not move it. */
$id = 0;
foreach (BFTD_Pay::entries_in('2026-12-B') as $e) { if (BFTD_Pay::STAT === BFTD_Pay::row($e)['kind']) $id = $e; }
BFTD_Pay::decide($id, BFTD_Pay::APPROVED, 9);
$was = BFTD_Pay::row($id)['amount'];
worked(5, '2026-12-20', 'diagnostic');
BFTD_Stat_Pay::run('2026-12-B', array(5, 6, 7));
is(BFTD_Pay::row($id)['amount'], $was, 'an approved statutory holiday does not recalculate under an approver');
check('' !== BFTD_Pay::row($id)['changed'], 'but says that what it was worked out from has changed');

exit($fail ? 1 : 0);
