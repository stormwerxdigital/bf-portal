<?php
/*
 * The four numbers, and what happens when the purchased figure changes.
 *
 * The point of the override is that somebody typing into the purchased box
 * has not saved anything yet, so the server must be able to answer for a
 * figure that exists only on their screen. Without it the tiles keep showing
 * the old total until the page is reloaded, which reads as the box not
 * working.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array(); $GLOBALS['POSTS'] = array();
function get_post_meta($id,$k,$single=false){ return $GLOBALS['META'][$id][$k] ?? ($single?'':array()); }
function update_post_meta($id,$k,$v){ $GLOBALS['META'][$id][$k]=$v; return true; }
function get_post_type($id){ return 'bftd_student'; }
function get_the_title($id){ return 'Student '.$id; }
function get_current_user_id(){ return 1; }
function get_userdata($id){ $u=new stdClass; $u->ID=$id; $u->display_name='Tutor '.$id; return $u; }
function set_transient($k,$v,$t=0){ $GLOBALS['TRANSIENTS'][$k]=$v; return true; }
function get_transient($k){ return $GLOBALS['TRANSIENTS'][$k] ?? false; }

class BFTD_Audit { public static function log($e,$a=array()){} }
class BFTD_CPT {
  public static function staff_ids($sid){ return $GLOBALS['STAFF'][$sid] ?? array(); }
  const STUDENT='bftd_student'; const PROGRESS='bftd_progress'; const SESSION='bftd_session';
  const STUDENT_KEY='_bftd_student_id';
  public static function report_for($sid,$t){ return $sid; }
  public static function sessions_for($rid){ return $GLOBALS['SESSIONS'][$rid] ?? array(); }
  public static function sessions_of($ids, $drafts = false) {
    $out = array();
    foreach ((array) $ids as $i) $out[(int) $i] = array();
    foreach ($GLOBALS['SESSIONS'] as $owner => $list) {
      if (!isset($out[(int) $owner])) continue;
      foreach ($list as $id) { if (($GLOBALS['DRAFT'][$id] ?? false) !== true || $drafts) $out[(int) $owner][] = $id; }
    }
    return $out;
  }
  public static function student_id($sessionId){
    foreach ($GLOBALS['SESSIONS'] as $owner => $ids) { if (in_array($sessionId, $ids, true)) return (int) $owner; }
    return 0;
  }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
/* The bulk counter asks WordPress for every published session belonging to a
   set of students. Modelled rather than left blank: a get_posts that always
   answered "none" would make a counter that counts nothing look correct. */
function get_posts($a){
  if (($a['post_type'] ?? '') === 'bftd_session') {
    $want = array_map('intval', (array) ($a['meta_query'][0]['value'] ?? array()));
    $out = array();
    foreach ($GLOBALS['SESSIONS'] as $owner => $ids) {
      if (!in_array((int) $owner, $want, true)) continue;
      foreach ($ids as $id) { if (($GLOBALS['DRAFT'][$id] ?? false) !== true) $out[] = $id; }
    }
    return $out;
  }
  return $GLOBALS['STUDENT_IDS'] ?? array();
}
class BFTD_Fields {
  public static function get($id,$group,$key,$f=array()){
    if ($group==='session') return $GLOBALS['SESSION_META'][$id][$key] ?? '';
    return $GLOBALS['META'][$id]['_bftd_student__'.$key] ?? '';
  }
}
define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-schedule.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

$s = 10;
$GLOBALS['STUDENT_IDS'] = array($s);
$GLOBALS['META'][$s]['_bftd_student__lessons_bank'] = 8;

/* A weekly slot, and three lessons already taught. */
BFTD_Schedule::save_rules($s, array(
  array('day'=>'1','time'=>'16:00','minutes'=>50,'from'=>BFTD_Time::add_days(BFTD_Time::today(), -28)),
));
$GLOBALS['SESSIONS'] = array($s => array(801,802,803));
$GLOBALS['SESSION_META'] = array(
  801 => array('session_date'=>BFTD_Time::add_days(BFTD_Time::today(),-21),'status'=>'held'),
  802 => array('session_date'=>BFTD_Time::add_days(BFTD_Time::today(),-14),'status'=>'held'),
  803 => array('session_date'=>BFTD_Time::add_days(BFTD_Time::today(),-7),'status'=>'held'),
);

$b = BFTD_Schedule::bank($s);
printf("saved purchased 8: purchased %d, used %d, remaining %d, booked %d, over %d\n",
  $b['purchased'], $b['used'], $b['remaining'], $b['booked'], $b['over']);
check($b['purchased'] === 8, 'the saved figure is what the tiles show by default');
check($b['used'] === 3, 'used counts the lessons actually recorded');
check($b['remaining'] === 5, 'remaining is purchased minus used');

/* --- the override: a figure typed but not saved --- */
$b20 = BFTD_Schedule::bank($s, 20);
printf("typed 20:          purchased %d, remaining %d, over %d\n", $b20['purchased'], $b20['remaining'], $b20['over']);
check($b20['purchased'] === 20, 'a typed figure is used instead of the saved one');
check($b20['remaining'] === 17, 'and remaining follows it immediately');
check($b20['used'] === $b['used'], 'while used is untouched, because it is a fact about lessons taught');
check($b20['booked'] > $b['booked'], 'and booked follows it, because the schedule runs as far as the lessons reach');

/* The saved value must not have been changed by asking. */
check((int) BFTD_Fields::get($s,'student','lessons_bank') === 8, 'asking about a figure does not save it');

/* --- edges --- */
check(BFTD_Schedule::bank($s, 0)['remaining'] === 0, 'nothing purchased leaves nothing remaining');
check(BFTD_Schedule::bank($s, 2)['remaining'] === 0, 'remaining never goes below zero, even when overdrawn');
check(BFTD_Schedule::bank($s, -5)['purchased'] === 0, 'a negative figure is read as none');
check(BFTD_Schedule::bank($s, null)['purchased'] === 8, 'and passing nothing falls back to the saved figure');

/* --- the schedule stops when the lessons run out --- */
/* This is the whole model: there is no end date on a weekly slot, so what
   ends it is the bank. A pattern that ran to a horizon would tell a family
   who bought eight that fifty seven lessons were booked. */
check($b['booked'] === $b['remaining'], 'a running weekly slot books exactly the lessons left, got ' . $b['booked'] . ' of ' . $b['remaining']);
check($b['booked'] <= $b['remaining'], 'and can never book more than are paid for');
check($b['unbooked'] === 0, 'so none are left with nowhere to go');
check($b['last_date'] !== '', 'and there is a date the bank runs out on');

/* Buying more extends the schedule, with nothing else to update. */
$more = BFTD_Schedule::bank($s, 20);
check($more['booked'] === 17, 'buying more lessons books more of them, got ' . $more['booked']);
check($more['last_date'] > $b['last_date'], 'and the run out date moves further away');

/* Buying fewer shortens it. */
$fewer = BFTD_Schedule::bank($s, 5);
check($fewer['booked'] === 2, 'and buying fewer shortens the schedule, got ' . $fewer['booked']);

/* Nothing paid for means nothing booked. Truthful, if stark. */
$none = BFTD_Schedule::bank($s, 0);
check($none['booked'] === 0, 'no lessons paid for means no lessons booked');
check($none['last_date'] === '', 'and no run out date to show');

/* --- paid for, but nowhere to go --- */
$idle = 11;
$GLOBALS['STUDENT_IDS'][] = $idle;
$GLOBALS['META'][$idle]['_bftd_student__lessons_bank'] = 6;
$b2 = BFTD_Schedule::bank($idle);          // no weekly pattern at all
check($b2['remaining'] === 6, 'a student with no schedule still has lessons remaining');
check($b2['booked'] === 0, 'but none of them are booked');
check($b2['unbooked'] === 6, 'which is worth saying out loud, so it is counted');

/* ================================================================== */
/* Two lessons on one day are two lessons                              */
/* ================================================================== */
/*
 * "Used" was counted from an array keyed by date, because the schedule needs
 * that shape to ask "was this slot taught". Count it and a catch-up beside
 * the regular slot, or a double session before a holiday, charges for one
 * lesson instead of two. Nobody notices until a family runs out later than
 * the bank said they should.
 */
$S = 77;
$GLOBALS['SESSIONS'][$S] = array(901, 902, 903, 904);
$GLOBALS['SESSION_META'] = array(
  901 => array('session_date' => '2026-07-07', 'status' => 'held'),
  902 => array('session_date' => '2026-07-07', 'status' => 'held'),   // a catch-up the same day
  903 => array('session_date' => '2026-07-09', 'status' => 'missed'), // not taught
  904 => array('session_date' => '2026-07-14', 'status' => 'held'),
);
$GLOBALS['META'][$S] = array('_bftd_student__lessons_bank' => 10);

check(3 === BFTD_Schedule::taught_count($S),
  'three lessons were attended, and three is what is counted');
check(2 === count(BFTD_Schedule::taught_dates($S)),
  'while the slot map still has one entry per date, which is what it is for');

/* ================================================================== */
/* What the bank spends                                                */
/* ================================================================== */
/*
 * A cancelled session counts. The tutor held the hour and nobody else could
 * have it, which is what the family is buying. It used to be a "counts
 * against allowance" dropdown on the session, answered by whoever wrote it
 * up — a policy that was a different answer on each record.
 *
 * A rescheduled one does not: it is the same session on another day and it is
 * charged when it happens. Charging both would charge twice for one hour.
 */
$bank = BFTD_Schedule::bank($S);
check(4 === $bank['used'], 'three taught and one cancelled is four spent, got ' . $bank['used']);
check(6 === $bank['remaining'], 'and what is left follows from it, got ' . $bank['remaining']);

/* Taught still means taught. The two are different questions — how many
   lessons the child had, and how many hours the family has spent — and the
   moment one method answers both, a report of lessons taught starts counting
   the ones that did not happen. */
check(3 === BFTD_Schedule::taught_count($S), 'while three is still the number of lessons they had');
check(1 === BFTD_Schedule::cancelled_count($S), 'and one the number cancelled');

$GLOBALS['SESSION_META'][903]['status'] = 'rescheduled';
check(3 === BFTD_Schedule::used_count($S), 'moving a session spends nothing, got ' . BFTD_Schedule::used_count($S));
$GLOBALS['SESSION_META'][903]['status'] = 'missed';

$GLOBALS['SESSION_META'][904]['status'] = 'missed';
check(2 === BFTD_Schedule::taught_count($S), 'a cancelled lesson is not a lesson taught');
check(4 === BFTD_Schedule::used_count($S), 'but it is an hour spent, got ' . BFTD_Schedule::used_count($S));
$GLOBALS['SESSION_META'][904]['status'] = 'held';

/* A lesson with no date is not a lesson yet, cancelled or otherwise. */
$GLOBALS['SESSIONS'][$S][] = 905;
$GLOBALS['SESSION_META'][905] = array('session_date' => '', 'status' => 'held');
check(3 === BFTD_Schedule::taught_count($S), 'a lesson with no date has not happened');
$GLOBALS['SESSION_META'][905]['status'] = 'missed';
check(1 === BFTD_Schedule::cancelled_count($S), 'and an undated cancellation has not either');
array_pop($GLOBALS['SESSIONS'][$S]);

/* ================================================================== */
/* The same figure, one student or a screenful                         */
/* ================================================================== */
/*
 * A management list of a thousand children cannot ask this a thousand times,
 * so there is a bulk counter. A bulk counter that agrees with the single one
 * today and drifts next month is a bank that says a different number on the
 * list than on the record, and the list is where somebody decides to ask a
 * family for money. So the single one IS the bulk one, with a single id in
 * it, and that is what this checks.
 */
$GLOBALS['SESSIONS'][88] = array(910, 911);
$GLOBALS['SESSION_META'][910] = array('session_date' => '2026-07-07', 'status' => 'held');
$GLOBALS['SESSION_META'][911] = array('session_date' => '2026-07-14', 'status' => 'missed');

$bulk = BFTD_Schedule::used_counts(array($S, 88, 999));
check($bulk[$S] === BFTD_Schedule::used_count($S), 'one student counted in bulk matches counting them alone');
check($bulk[88] === 2, 'a second student is counted separately, got ' . $bulk[88]);
check(isset($bulk[999]) && 0 === $bulk[999], 'and a student with nothing is answered with nought, not left out');
check(array() === BFTD_Schedule::used_counts(array()), 'nobody asked about is nobody answered for');

/* And the policy is stated once. taught + cancelled is what used means, so
   if the bulk counter ever decides something different the two stop
   agreeing. */
check(BFTD_Schedule::used_count($S) === BFTD_Schedule::taught_count($S) + BFTD_Schedule::cancelled_count($S),
  'used is exactly the sessions taught plus the sessions cancelled');

/* And the counting reads the count, not the map. */
$sched = file_get_contents(BFTD_PATH . 'includes/class-bftd-schedule.php');
check(0 === preg_match('/count\(\s*self::taught_dates\(/', $sched),
  'nothing counts the date map any more');
check(2 === preg_match_all('/self::used_count\(/', $sched),
  'both places that work out the bank ask the same method');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
