<?php
/*
 * The clock. Everything anyone sees is Pacific.
 *
 * These assertions are all about the class of bug that only appears once a
 * site stops being on UTC: a plain calendar date read as midnight UTC and
 * then rendered in Pacific time lands on the previous evening, so the date
 * shown is a day early. It is silent, it is intermittent, and it is wrong on
 * exactly the things families read: lesson dates and month headings.
 */
require __DIR__ . '/wp-stubs.php';

$fail = 0;
function check($ok,$msg){ global $fail; if($ok) echo "  ok  $msg\n"; else { echo "FAIL  $msg\n"; $fail++; } }

/* --- a plain date keeps its own day --- */
foreach ( array('2026-01-15','2026-06-15','2026-09-14','2026-12-31','2026-03-08','2026-11-01') as $d ) {
  check( BFTD_Time::day($d, 'Y-m-d') === $d, "$d is still $d after being formatted" );
}

/* The exact shape of the bug: the old way against the new way. */
$old = date('Y-m-d', strtotime('2026-09-14') - 7 * 3600);   // what a Pacific render of a UTC midnight gives
check( $old === '2026-09-13', 'the old way really did land a day early' );
check( BFTD_Time::day('2026-09-14','Y-m-d') === '2026-09-14', 'and the new way does not' );

/* A month heading must name its own month. */
check( BFTD_Time::day('2026-09-01','F Y') === 'September 2026', 'the first of a month is in that month' );
check( BFTD_Time::day('2026-01-01','F Y') === 'January 2026', 'including across a year boundary' );

/* --- a lesson time is a time of day, not a moment --- */
check( BFTD_Time::time_of_day('16:00') === '4:00 PM', 'four in the afternoon reads as four in the afternoon' );
check( BFTD_Time::time_of_day('09:30') === '9:30 AM', 'and half past nine as half past nine' );
check( BFTD_Time::time_of_day('00:00') === '12:00 AM', 'midnight is not noon' );
check( BFTD_Time::time_of_day('12:00') === '12:00 PM', 'and noon is not midnight' );
check( BFTD_Time::time_of_day('') === '', 'nothing in, nothing out' );

/* --- Pacific means PDT in summer and PST in winter --- */
check( BFTD_Time::abbreviation(BFTD_Time::stamp('2026-07-01')) === 'PDT', 'July is PDT' );
check( BFTD_Time::abbreviation(BFTD_Time::stamp('2026-01-01')) === 'PST', 'January is PST' );

/* --- date arithmetic survives the clocks changing --- */
/* Daylight saving in 2026: forward 8 March, back 1 November. */
check( BFTD_Time::add_days('2026-03-07', 1) === '2026-03-08', 'the day the clocks go forward is still one day away' );
check( BFTD_Time::add_days('2026-03-08', 1) === '2026-03-09', 'and the day after it' );
check( BFTD_Time::add_days('2026-10-31', 1) === '2026-11-01', 'the day the clocks go back is still one day away' );
check( BFTD_Time::add_days('2026-11-01', 1) === '2026-11-02', 'and the day after it' );
check( BFTD_Time::add_days('2026-12-31', 1) === '2027-01-01', 'and a new year is one day after the old one' );
check( BFTD_Time::add_days('2026-09-14', -1) === '2026-09-13', 'counting backwards works too' );

/* Thirty days is thirty days, either side of a change. */
check( BFTD_Time::add_days('2026-02-20', 30) === '2026-03-22', 'thirty days across the spring change' );
check( BFTD_Time::add_days('2026-10-20', 30) === '2026-11-19', 'thirty days across the autumn change' );

/* --- weekdays --- */
check( BFTD_Time::weekday('2026-09-14') === 1, 'the fourteenth of September 2026 is a Monday' );
check( BFTD_Time::weekday('2026-09-20') === 7, 'and the twentieth is a Sunday' );

/* --- what was stored is what is read back --- */
$m = BFTD_Time::mysql();
check( preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $m) === 1, 'a stored stamp has the shape the database wants' );
check( abs( BFTD_Time::from_mysql($m) - time() ) < 5, 'and reading it back gives the moment it was written' );

/* --- rubbish in --- */
check( BFTD_Time::stamp('') === 0, 'an empty date is no date, not 1970' );
check( BFTD_Time::stamp('not a date') === 0, 'and neither is a sentence' );
check( BFTD_Time::day('') === '', 'which formats as nothing at all' );

echo $fail ? "\nFAIL\n" : "\nPASS\n";
exit($fail ? 1 : 0);
