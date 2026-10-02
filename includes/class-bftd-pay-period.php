<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The pay calendar. Two periods a month, twenty-four a year.
 *
 * The practice pays on the fifteenth and on the last day of the month, so the
 * first period runs from the first to the fifteenth and the second from the
 * sixteenth to whatever the last day of that month happens to be. February is
 * why this is a class and not an arithmetic expression written out in six
 * places: a period that ends on "the 30th" is wrong twice a year and wrong by
 * a day every leap year, and a payroll that is wrong by a day is a payroll
 * somebody has to unpick by hand.
 *
 * A period has a key, and the key is the whole identity: 2026-09-A is the
 * first half of September and means the same thing to the ledger, the screen
 * and the pay run. Sortable as a string, readable by a person, and it cannot
 * drift the way a pair of start and end dates stored side by side can.
 *
 * Twenty-four periods a year is also what the tax side needs. CRA's formula
 * asks how many pay periods there are in the year, and semi-monthly is 24 —
 * not 26, which is every two weeks and a different number. Nothing else in
 * here should be inventing that figure.
 */
class BFTD_Pay_Period {

	/** How many of these there are in a year. The tax formula asks. */
	const PER_YEAR = 24;

	const FIRST = 'A';   // the 1st to the 15th
	const SECOND = 'B';  // the 16th to the end of the month

	/**
	 * The period a date falls in.
	 *
	 * Dates are Y-m-d strings throughout the plugin and are read in the
	 * practice's own timezone, because a lesson taught at seven in the
	 * evening in Prince George is not the next day's lesson.
	 */
	public static function key_for( $ymd ) {
		$parts = self::parts( $ymd );
		if ( ! $parts ) return '';
		list( $y, $m, $d ) = $parts;
		return sprintf( '%04d-%02d-%s', $y, $m, ( $d <= 15 ) ? self::FIRST : self::SECOND );
	}

	/** Is this a period key at all? */
	public static function valid( $key ) {
		return (bool) preg_match( '/^\d{4}-(0[1-9]|1[0-2])-[AB]$/', (string) $key );
	}

	public static function start( $key ) {
		if ( ! self::valid( $key ) ) return '';
		list( $y, $m, $half ) = explode( '-', $key );
		return sprintf( '%s-%s-%s', $y, $m, ( self::FIRST === $half ) ? '01' : '16' );
	}

	public static function end( $key ) {
		if ( ! self::valid( $key ) ) return '';
		list( $y, $m, $half ) = explode( '-', $key );
		if ( self::FIRST === $half ) return sprintf( '%s-%s-15', $y, $m );
		return sprintf( '%s-%s-%02d', $y, $m, self::last_day( (int) $y, (int) $m ) );
	}

	/**
	 * The day the period is paid on.
	 *
	 * The same day it ends, which is what the practice does. Kept here rather
	 * than assumed at the call site, because the day money moves is the date
	 * a tax year is decided by and it is the one thing most likely to change.
	 */
	public static function pay_date( $key ) {
		return self::end( $key );
	}

	/** Which period a day belongs to, and the one before and after it. */
	public static function current( $ymd = '' ) {
		$ymd = ( '' !== (string) $ymd ) ? $ymd : BFTD_Time::today();
		return self::key_for( $ymd );
	}

	public static function previous( $key ) {
		if ( ! self::valid( $key ) ) return '';
		list( $y, $m, $half ) = explode( '-', $key );
		if ( self::SECOND === $half ) return sprintf( '%s-%s-%s', $y, $m, self::FIRST );
		$y = (int) $y; $m = (int) $m - 1;
		if ( $m < 1 ) { $m = 12; $y--; }
		return sprintf( '%04d-%02d-%s', $y, $m, self::SECOND );
	}

	public static function next( $key ) {
		if ( ! self::valid( $key ) ) return '';
		list( $y, $m, $half ) = explode( '-', $key );
		if ( self::FIRST === $half ) return sprintf( '%s-%s-%s', $y, $m, self::SECOND );
		$y = (int) $y; $m = (int) $m + 1;
		if ( $m > 12 ) { $m = 1; $y++; }
		return sprintf( '%04d-%02d-%s', $y, $m, self::FIRST );
	}

	/**
	 * How it reads on a screen.
	 *
	 * "1 to 15 September 2026", not "2026-09-A". The key is for the database;
	 * a person approving somebody's pay should be reading dates.
	 */
	public static function label( $key ) {
		if ( ! self::valid( $key ) ) return '';
		$from = self::start( $key );
		$to   = self::end( $key );
		return (int) substr( $from, 8, 2 ) . ' to ' . BFTD_Time::day( $to, 'j F Y' );
	}

	/** A short one, for a dropdown where the year is already obvious. */
	public static function short_label( $key ) {
		if ( ! self::valid( $key ) ) return '';
		return BFTD_Time::day( self::start( $key ), 'j M' ) . ' to ' . BFTD_Time::day( self::end( $key ), 'j M Y' );
	}

	/** The tax year a period is paid in, which is the year of its pay date. */
	public static function tax_year( $key ) {
		$date = self::pay_date( $key );
		return $date ? (int) substr( $date, 0, 4 ) : 0;
	}

	/**
	 * A run of periods ending at this one, newest first.
	 *
	 * What the period picker on the time card screen is filled from. It runs
	 * backwards rather than forwards because nobody approves next April.
	 */
	public static function recent( $count = 12, $from = '' ) {
		$key  = self::valid( $from ) ? $from : self::current();
		$out  = array();
		for ( $i = 0; $i < max( 1, (int) $count ); $i++ ) {
			if ( ! $key ) break;
			$out[] = $key;
			$key   = self::previous( $key );
		}
		return $out;
	}

	/**
	 * A list that is guaranteed to contain the period being looked at.
	 *
	 * The picker runs backwards from today, which is right until somebody
	 * follows a link to a period further ahead than the list reaches. A
	 * select whose current value is not one of its options does not show
	 * that value; it shows the first option, so the screen says one
	 * fortnight and the rows below it are another.
	 */
	public static function around( $showing, $count = 14 ) {
		$showing = self::valid( $showing ) ? $showing : self::current();
		$now     = self::current();

		// Start at whichever is later, so a period ahead of today is the top
		// of the list rather than off it.
		$out = self::recent( $count, ( $now > $showing ) ? $now : $showing );

		// And if it is so far back that the run does not reach it, it goes
		// on the end. A short list is a nuisance; a list missing the thing
		// it is showing is a screen that lies about which fortnight it is.
		if ( ! in_array( $showing, $out, true ) ) $out[] = $showing;

		return $out;
	}

	/** Does a date fall inside a period? */
	public static function holds( $key, $ymd ) {
		if ( ! self::valid( $key ) || ! self::parts( $ymd ) ) return false;
		return $ymd >= self::start( $key ) && $ymd <= self::end( $key );
	}

	private static function parts( $ymd ) {
		$ymd = trim( (string) $ymd );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) ) return null;
		$y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];
		if ( $mo < 1 || $mo > 12 || $d < 1 || $d > self::last_day( $y, $mo ) ) return null;
		return array( $y, $mo, $d );
	}

	/** February, and the reason this is not written inline anywhere. */
	private static function last_day( $y, $m ) {
		return (int) gmdate( 't', gmmktime( 0, 0, 0, (int) $m, 1, (int) $y ) );
	}
}
