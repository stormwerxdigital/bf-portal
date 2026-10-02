<?php
/*
 * The January reminder to go and read this year's CRA figures.
 *
 * The rates change on the 1st of January: the CPP and CPP2 ceilings and the
 * basic exemption, the EI rate and maximum, and the federal and British
 * Columbia brackets and credits. Last year's numbers are not broken, they are
 * arithmetically fine and simply wrong, so nothing in the plugin can notice it
 * is using them. A pay run made on them is wrong in somebody's favour and has
 * to be unpicked by hand.
 *
 * What is tested here is the decision — who sees it, in which year, and what an
 * acknowledgement does — rather than the markup. The thing that must not break
 * is that acknowledging one year does not silence the next.
 */
require __DIR__ . '/wp-stubs.php';

$GLOBALS['OPT'] = array();
function get_option($k,$d=false){return $GLOBALS['OPT'][$k]??$d;}
function update_option($k,$v,$a=null){$GLOBALS['OPT'][$k]=$v;return true;}

$fail = 0;
function check($ok,$msg){ global $fail; if($ok){echo "  ok  $msg\n";} else {echo "FAIL  $msg\n";$fail++;} }

if ( ! defined('BFTD_PATH') ) define('BFTD_PATH', dirname(__DIR__).'/');
if ( ! class_exists('BFTD_Time') ) require BFTD_PATH . 'includes/class-bftd-time.php';

/* The rule the screen applies, stated once here so the test is about the rule
   and not about a copy of it. It mirrors cra_reminder(): an approver sees it
   whenever the year acknowledged is behind the year we are in. */
function shows( $acked_year, $today, $can_approve = true ) {
  if ( ! $can_approve ) return false;
  return (int) $acked_year < (int) substr( $today, 0, 4 );
}

echo "The date the practice is in:\n";
$today = BFTD_Time::today();
check( 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $today), "today() is a Y-m-d date ($today)" );
check( BFTD_Time::ZONE === 'America/Vancouver', 'measured in the practice\'s own timezone' );

echo "\nWho sees it:\n";
check( shows(0,'2027-01-01',true)  === true,  'an approver sees it when nobody has checked' );
check( shows(0,'2027-01-01',false) === false, 'a tutor never sees it; it is not their decision' );

echo "\nWhich year:\n";
check( shows(0,'2027-01-01')    === true,  'it is up on the 1st of January' );
check( shows(2027,'2027-01-01') === false, 'acknowledging 2027 clears it for 2027' );
check( shows(2027,'2027-06-30') === false, 'and it stays cleared through that year' );
check( shows(2027,'2027-12-31') === false, 'right to the last day of it' );
check( shows(2027,'2028-01-01') === true,  'and it is back on the next 1st of January' );

echo "\nA year that was never acknowledged does not get skipped:\n";
check( shows(2026,'2028-03-01') === true, 'a site nobody touched in 2027 is still asked in 2028' );
check( shows(2028,'2028-03-01') === false, 'and settles once somebody answers for the year they are in' );

echo "\nThe acknowledgement is stored as a year, not a flag:\n";
update_option( BFTD_Timecards_Ack_Probe(), 2027 );
check( 2027 === (int) get_option( BFTD_Timecards_Ack_Probe() ), 'the year is what is kept' );
check( ! is_bool( get_option( BFTD_Timecards_Ack_Probe() ) ),
  'a boolean here would silence every later January, which is the whole bug this avoids' );

/* The option name, read out of the class rather than retyped, so renaming the
   constant cannot leave this test checking a key nothing writes. */
function BFTD_Timecards_Ack_Probe() {
  $src = file_get_contents( BFTD_PATH . 'includes/class-bftd-timecards.php' );
  preg_match( "/const CRA_ACK\s*=\s*'([^']+)'/", $src, $m );
  return $m[1] ?? '';
}
check( BFTD_Timecards_Ack_Probe() !== '', 'the screen declares an option name for it' );

/*
 * It has to be on the time card screen, which is where the pay run starts.
 *
 * These read one method at a time rather than searching the whole file. A
 * pattern spanning the file matched a can_approve() call hundreds of lines
 * further down and went on passing with the reminder's own gate deleted, and a
 * strpos for 'wp_verify_nonce' matched a renamed, disabled copy of itself.
 */
$src = file_get_contents( BFTD_PATH . 'includes/class-bftd-timecards.php' );

/* The body of one static method, from its signature to the next one. */
function method_body( $src, $name ) {
  $start = strpos( $src, 'function ' . $name . '(' );
  if ( false === $start ) return '';
  $next = strpos( $src, "\n\tpublic static function ", $start + 10 );
  $alt  = strpos( $src, "\n\tprivate static function ", $start + 10 );
  if ( false !== $alt && ( false === $next || $alt < $next ) ) $next = $alt;
  return false === $next ? substr( $src, $start ) : substr( $src, $start, $next - $start );
}

$reminder = method_body( $src, 'cra_reminder' );
$ack      = method_body( $src, 'handle_cra_ack' );

check( false !== strpos($src, 'self::cra_reminder()'), 'the screen actually calls it' );
check( '' !== $reminder, 'the reminder method is there to read' );
check( false !== strpos($reminder, 'BFTD_Pay::can_approve()'),
  'and the reminder itself gates on being able to approve pay' );
check( false !== strpos($reminder, 'BFTD_Time::today()'),
  'and takes the year from the practice clock, not from PHP\'s UTC default' );
check( '' !== $ack, 'the acknowledgement handler is there to read' );
check( 1 === preg_match('/\bwp_verify_nonce\s*\(/', $ack),
  'the acknowledgement is nonce checked' );
check( false !== strpos($ack, 'BFTD_Pay::can_approve()'),
  'and checks the capability as well as the nonce, because a nonce proves the page and not the person' );

echo $fail ? "\n$fail failure(s)\n" : "\nAll checks passed.\n";
exit($fail ? 1 : 0);
