<?php
/*
 * Bookings, 1.201 (Karl): a booking is a date and a time, then a choice of
 * one time or every week. Weekly is opted into per booking, a one-time
 * session can go anywhere a tutor is free, sessions are booked whatever has
 * been paid for, and a booked session can be deleted quietly: no cancellation
 * on record, nobody told, its GoHighLevel appointment deleted first.
 */
$GLOBALS['FIRED'] = array();
function do_action($h, ...$a) { $GLOBALS['FIRED'][] = $h; }
require __DIR__ . '/wp-stubs.php';

$GLOBALS['META'] = array(); $GLOBALS['POSTS'] = array();
function get_post_meta($id,$k,$single=false){$v=$GLOBALS['META'][$id][$k]??($single?'':array());return $v;}
function update_post_meta($id,$k,$v){$GLOBALS['META'][$id][$k]=$v;return true;}
function get_post_type($id){return 'bftd_student';}
function get_the_title($id){return 'Student '.$id;}
function get_current_user_id(){return 1;}
function get_userdata($id){$u=new stdClass;$u->ID=$id;$u->display_name='Tutor '.$id;return $u;}
function set_transient($k,$v,$t=0){$GLOBALS['TRANSIENTS'][$k]=$v;return true;}
function get_transient($k){return $GLOBALS['TRANSIENTS'][$k]??false;}
function get_posts($args){
  if (($args['post_type'] ?? '') === 'bftd_session') return array();
  return $GLOBALS['STUDENT_IDS'];
}

$GLOBALS['AUDIT'] = array();
class BFTD_Audit { public static function log($e,$a=array()){ $GLOBALS['AUDIT'][] = $e . ': ' . ($a['summary'] ?? ''); } }
class BFTD_CPT {
  const STUDENT='bftd_student'; const PROGRESS='bftd_progress'; const SESSION='bftd_session'; const STUDENT_KEY='_bftd_student_id';
  public static function report_for($sid,$t){ return 0; }
  public static function sessions_for($rid){ return array(); }
  public static function sessions_of($ids, $drafts = false) { $out = array(); foreach ((array) $ids as $i) $out[(int) $i] = array(); return $out; }
  public static function student_id($sid){ return 0; }
  public static function staff_ids($sid){ return $GLOBALS['STAFF'][$sid] ?? array(); }
}
class BFTD_Schema { public static function meta_key($g,$k){ return '_bftd_'.$g.'__'.$k; } }
class BFTD_Fields { public static function get($id,$group,$key,$f=array()){ return $GLOBALS['META'][$id]['_bftd_student__'.$key] ?? ''; } }

/* GoHighLevel's side and the banner, stood in for: what was asked, in order. */
$GLOBALS['STEPS'] = array(); $GLOBALS['GHL_REFUSES'] = false; $GLOBALS['IN_STEP'] = true;
class BFTD_Bookings {
  public static function delete_quietly($sid, $o) {
    $GLOBALS['STEPS'][] = 'ghl:' . $o['key'] . ':' . (count(BFTD_Schedule::exceptions($sid)) . '/' . count(BFTD_Schedule::rules($sid)));
    return $GLOBALS['GHL_REFUSES'] ? new WP_Error('x', 'GoHighLevel would not delete the appointment') : true;
  }
}
class BFTD_Send_Window {
  public static function in_step($sid) { return $GLOBALS['IN_STEP']; }
  public static function mark_sent($sid) { $GLOBALS['STEPS'][] = 'sent:' . $sid; }
}

define('BFTD_PATH', dirname(__DIR__).'/');
require BFTD_PATH . 'includes/class-bftd-schedule.php';

$fail = 0;
function check($ok, $msg) { global $fail; if ($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }
function booked($sid, $from = null) {
  $out = array();
  foreach (BFTD_Schedule::occurrences($sid, $from ?: BFTD_Time::today()) as $o) if ('booked' === $o['status']) $out[] = $o;
  return $out;
}

$T  = BFTD_Time::today();
$D3 = BFTD_Time::add_days($T, 3);
$D10 = BFTD_Time::add_days($T, 10);
$dow = function ($d) { return (string) gmdate('N', strtotime($d . ' 12:00:00 UTC')); };

/* ---- a row, cleaned ---- */
$r = BFTD_Schedule::clean_rule(array('repeat' => 'once', 'from' => $D3, 'time' => '19:30', 'minutes' => 45, 'day' => '1'), 'a');
check($r && 'once' === $r['repeat'] && $dow($D3) === $r['day'] && $D3 === $r['from'], 'a one-time booking takes its weekday from its date, whatever day came with it');
check(null === BFTD_Schedule::clean_rule(array('repeat' => 'once', 'from' => '', 'time' => '19:30', 'day' => '1'), 'a'), 'a one-time booking with no date is not a booking, even with a weekday');
$w = BFTD_Schedule::clean_rule(array('repeat' => 'weekly', 'from' => $D3, 'time' => '16:00', 'until' => $D10), 'b');
check('weekly' === $w['repeat'] && $dow($D3) === $w['day'] && $D10 === $w['until'], 'a weekly booking starts on its date and can end on another');
check('' === BFTD_Schedule::clean_rule(array('repeat' => 'once', 'from' => $D3, 'time' => '16:00', 'until' => $D10), 'c')['until'], 'a one-time booking has no end date');
check('' === BFTD_Schedule::clean_rule(array('repeat' => 'weekly', 'from' => $D10, 'time' => '16:00', 'until' => $D3), 'c')['until'], 'an end before the start is dropped rather than ending it before it begins');
$wed = '2026-01-07'; // a Wednesday
$old = BFTD_Schedule::clean_rule(array('day' => '1', 'from' => $wed, 'time' => '16:00'), 'd');
check('weekly' === $old['repeat'] && '1' === $old['day'] && '2026-01-12' === $old['from'], 'a row from before 1.201 (a weekday, and a starting date not on it) stays weekly on its weekday, from the first date on it');
check('weekly' === BFTD_Schedule::clean_rule(array('day' => '2', 'from' => '', 'time' => '16:00'), 'e')['repeat'], 'and one with no starting date is still a weekly slot');

/* ---- one time is one session ---- */
$GLOBALS['STUDENT_IDS'] = array(10, 11);
$GLOBALS['STAFF'] = array(10 => array(7), 11 => array(7));
$GLOBALS['META'][10]['_bftd_student__lessons_bank'] = 0;
BFTD_Schedule::save_rules(10, array(array('repeat' => 'once', 'from' => $D3, 'time' => '19:30', 'minutes' => 45)));
$b = booked(10);
check(1 === count($b) && $D3 === $b[0]['date'] && '19:30' === $b[0]['time'] && true === $b[0]['once'], 'a one-time booking is one session, on its date, and says it is one time');
check('' !== $b[0]['rule'] && $b[0]['rule'] === BFTD_Schedule::rules(10)[0]['id'], 'and knows which booking it came from');
check(1 === BFTD_Schedule::bank(10)['booked'] && 1 === BFTD_Schedule::bank(10)['unpaid'], 'booked with nothing paid for, and counted as not paid for yet');

BFTD_Schedule::save_rules(10, array(
  array('repeat' => 'once', 'from' => $D3, 'time' => '19:30', 'minutes' => 45),
  array('repeat' => 'weekly', 'from' => $T, 'time' => '16:00', 'until' => BFTD_Time::add_days($T, 20)),
));
$weekly = array_values(array_filter(booked(10), function ($o) { return ! $o['once']; }));
check(3 === count($weekly), 'a weekly booking repeats only until its end date, got ' . count($weekly));

/* ---- no double booking ---- */
BFTD_Schedule::save_rules(11, array(array('repeat' => 'once', 'from' => $D3, 'time' => '19:45', 'minutes' => 50)));
check(array() === BFTD_Schedule::rules(11), 'a one-time session that runs over the tutor\'s other session is turned away');
$warn = implode(' ', (array) get_transient('bftd_sched_warn_1'));
check(false !== strpos($warn, 'cannot take two sessions at once'), 'and the person saving is told why');
BFTD_Schedule::save_rules(11, array(array('repeat' => 'once', 'from' => $D3, 'time' => '20:15', 'minutes' => 50)));
check(1 === count(BFTD_Schedule::rules(11)), 'back to back after it is fine');

/* ---- deleting, quietly ---- */
$GLOBALS['STEPS'] = array(); $GLOBALS['FIRED'] = array();
$key = $weekly[1]['key'];
$r = BFTD_Schedule::delete_occurrence(10, $key);
$after = BFTD_Schedule::occurrences(10, $T);
$keys = array_map(function ($o) { return $o['key']; }, $after);
check(true === $r && ! in_array($key, $keys, true), 'one date of a weekly booking is deleted, and leaves nothing behind: not even a cancelled marker');
check(2 === count(array_filter($after, function ($o) { return ! $o['once'] && 'booked' === $o['status']; })), 'and the rest of the weekly booking stays');
check('ghl:' . $key . ':0/2' === ($GLOBALS['STEPS'][0] ?? ''), 'GoHighLevel is asked first, before anything here changes');
check(in_array('sent:10', $GLOBALS['STEPS'], true), 'a student in step with GoHighLevel stays in step, so the delete is not held in the banner');
check(in_array('bftd_schedule_changed', $GLOBALS['FIRED'], true), 'the calendar and banner still hear of it');
check(false !== strpos(implode(' ', $GLOBALS['AUDIT']), 'deleted, with nobody told'), 'and the history says so');

$GLOBALS['STEPS'] = array(); $GLOBALS['IN_STEP'] = false;
$once = array_values(array_filter(booked(10), function ($o) { return $o['once']; }))[0];
$r = BFTD_Schedule::delete_occurrence(10, $once['key']);
check(true === $r && 1 === count(BFTD_Schedule::rules(10)) && 'weekly' === BFTD_Schedule::rules(10)[0]['repeat'], 'deleting a one-time session removes its booking row');
check(! in_array('sent:10', $GLOBALS['STEPS'], true), 'a student with other changes waiting keeps them waiting');
$GLOBALS['IN_STEP'] = true;

$GLOBALS['GHL_REFUSES'] = true;
$before = array(BFTD_Schedule::rules(10), BFTD_Schedule::exceptions(10));
$first = booked(10)[0];
$r = BFTD_Schedule::delete_occurrence(10, $first['key']);
check(is_wp_error($r) && $before === array(BFTD_Schedule::rules(10), BFTD_Schedule::exceptions(10)), 'if GoHighLevel will not delete it, nothing here changes, so the two never disagree');
$GLOBALS['GHL_REFUSES'] = false;

BFTD_Schedule::set_exception(10, $first['key'], 'cancel', '', '', 'Away');
check(is_wp_error(BFTD_Schedule::delete_occurrence(10, $first['key'])), 'a session that is not booked (here, cancelled) has nothing to delete');

/* ---- where it is wired ---- */
$sc = file_get_contents(BFTD_PATH . 'includes/class-bftd-schedule.php');
$mb = file_get_contents(BFTD_PATH . 'includes/class-bftd-metaboxes.php');
$js = file_get_contents(BFTD_PATH . 'assets/js/bftd-admin.js');
$cv = file_get_contents(BFTD_PATH . 'includes/class-bftd-calendar-view.php');
check(false !== strpos($sc, "array( 'cancel', 'move', 'clear', 'delete' )") && false !== strpos($sc, "'removed' => \$removed,"), 'the session list can delete, and tells the form which one-time row went');
check(false !== strpos($mb, "\$repeat = isset( \$r['repeat'] ) ? \$r['repeat'] : ( \$r ? 'weekly' : 'once' );"), 'a new booking row starts as one time: weekly is chosen, never assumed');
check(false !== strpos($js, "repeat:  \$r.find( '.bftd-s-repeat' ).val(),") && false !== strpos($js, "'.bftd-occ-delete'"), 'the preview books what the form says, and Delete is wired');
check(false !== strpos($cv, "array( 'move', 'cancel', 'delete' )"), 'an appointment made in GoHighLevel can be deleted from the calendar too');

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
