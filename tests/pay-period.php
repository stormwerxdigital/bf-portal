<?php
/*
 * The pay calendar.
 *
 * Two periods a month and twenty-four a year, which sounds like arithmetic
 * until February. A period that "ends on the 30th" is wrong in two months of
 * every year and wrong by a day every fourth February, and the symptom is a
 * lesson taught on the 31st being paid in the wrong half of the wrong month
 * and nobody noticing until a tutor counts their own.
 *
 * So the edges are what is tested: month ends of every length, the turn of
 * the year, and the two boundary days a period is decided by.
 */
require __DIR__ . '/wp-stubs.php';
define('BFTD_PATH', dirname(__DIR__) . '/');
if (!defined('BFTD_URL')) define('BFTD_URL', '');

require BFTD_PATH . 'includes/class-bftd-pay-period.php';

$fail = 0;
function check($ok, $msg) { global $fail; echo ($ok ? "  ok   " : "FAIL  ") . $msg . "\n"; if (!$ok) $fail++; }
function is($got, $want, $msg) { check($got === $want, $msg . ($got === $want ? '' : " (got " . var_export($got, true) . ", wanted " . var_export($want, true) . ")")); }

/* ---- which period a day is in ---- */
is(BFTD_Pay_Period::key_for('2026-09-01'), '2026-09-A', 'the first of the month opens the first period');
is(BFTD_Pay_Period::key_for('2026-09-15'), '2026-09-A', 'the fifteenth is the last day of it');
is(BFTD_Pay_Period::key_for('2026-09-16'), '2026-09-B', 'and the sixteenth opens the second');
is(BFTD_Pay_Period::key_for('2026-09-30'), '2026-09-B', 'which runs to the end of the month');

/* ---- month ends ---- */
is(BFTD_Pay_Period::end('2026-09-B'), '2026-09-30', 'September ends on the 30th');
is(BFTD_Pay_Period::end('2026-10-B'), '2026-10-31', 'October on the 31st');
is(BFTD_Pay_Period::end('2026-02-B'), '2026-02-28', 'February on the 28th');
is(BFTD_Pay_Period::end('2028-02-B'), '2028-02-29', 'and on the 29th in a leap year');
is(BFTD_Pay_Period::end('2100-02-B'), '2100-02-28', 'a century that is not a leap year is not one here either');
is(BFTD_Pay_Period::end('2000-02-B'), '2000-02-29', 'and one that is, is');

is(BFTD_Pay_Period::start('2026-09-A'), '2026-09-01', 'the first period starts on the first');
is(BFTD_Pay_Period::start('2026-09-B'), '2026-09-16', 'the second on the sixteenth');
is(BFTD_Pay_Period::end('2026-09-A'), '2026-09-15', 'and the first ends on the fifteenth');

/* A day that does not exist is not in a period. The 31st of September is the
   kind of thing a badly built date picker will hand this. */
is(BFTD_Pay_Period::key_for('2026-09-31'), '', 'a date that does not exist is in no period at all');
is(BFTD_Pay_Period::key_for('2026-02-30'), '', 'nor is the 30th of February');
is(BFTD_Pay_Period::key_for('not a date'), '', 'nor is something that is not a date');
is(BFTD_Pay_Period::key_for(''), '', 'nor is nothing');

/* ---- stepping ---- */
is(BFTD_Pay_Period::previous('2026-09-B'), '2026-09-A', 'the period before the second half is the first');
is(BFTD_Pay_Period::previous('2026-09-A'), '2026-08-B', 'and before the first half is last month');
is(BFTD_Pay_Period::previous('2026-01-A'), '2025-12-B', 'over the turn of the year');
is(BFTD_Pay_Period::next('2026-12-B'), '2027-01-A', 'and forwards over it too');
is(BFTD_Pay_Period::next('2026-09-A'), '2026-09-B', 'the period after the first half is the second');

/* ---- what it is paid on, and in which tax year ---- */
is(BFTD_Pay_Period::pay_date('2026-09-A'), '2026-09-15', 'the first half is paid on the fifteenth');
is(BFTD_Pay_Period::pay_date('2026-09-B'), '2026-09-30', 'the second on the last day');
is(BFTD_Pay_Period::tax_year('2026-12-B'), 2026, 'December is taxed in its own year');
is(BFTD_Pay_Period::tax_year('2027-01-A'), 2027, 'and January in the next');
is(BFTD_Pay_Period::PER_YEAR, 24, 'twenty-four periods a year, which is what the tax formula asks for');

/* ---- holding a date ---- */
check(BFTD_Pay_Period::holds('2026-09-A', '2026-09-15'), 'a period holds its own last day');
check(!BFTD_Pay_Period::holds('2026-09-A', '2026-09-16'), 'and not the next one');
check(!BFTD_Pay_Period::holds('2026-09-B', '2026-10-01'), 'nor a day in the month after');

/* ---- the picker's list ---- */
$recent = BFTD_Pay_Period::recent(4, '2026-01-A');
is($recent, array('2026-01-A', '2025-12-B', '2025-12-A', '2025-11-B'), 'the picker runs backwards from the period given');
is(count(BFTD_Pay_Period::recent(0, '2026-01-A')), 1, 'and always offers at least one');

/* The picker has to contain the period it is showing. A select whose value
   is not one of its options shows the first option instead, so the heading
   says one fortnight and the rows under it are another. */
$ahead = BFTD_Pay_Period::around('2027-06-A', 6);
check(in_array('2027-06-A', $ahead, true), 'the picker always contains the period being looked at, even a future one');
$behind = BFTD_Pay_Period::around('2020-01-A', 6);
check(in_array('2020-01-A', $behind, true), 'and one long past');
check(in_array(BFTD_Pay_Period::current(), BFTD_Pay_Period::around(''), true), 'and today, when nothing was asked for');

/* ---- how it reads ---- */
check(false !== strpos(BFTD_Pay_Period::label('2026-09-B'), 'September'), 'a period reads as dates, not as a key');
is(BFTD_Pay_Period::valid('2026-13-A'), false, 'there is no thirteenth month');
is(BFTD_Pay_Period::valid('2026-09-C'), false, 'and no third half of one');
is(BFTD_Pay_Period::valid('2026-09-A'), true, 'a real key is a real key');

exit($fail ? 1 : 0);
