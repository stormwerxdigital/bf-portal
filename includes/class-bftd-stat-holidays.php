<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The eleven statutory holidays in British Columbia.
 *
 * Written out as rules rather than as a list of dates, because a list has to
 * be maintained and a list that is not maintained is a payroll that quietly
 * stops paying stat holidays in January of whatever year nobody topped it up.
 *
 * Eight of them are arithmetic. Good Friday is not: it is two days before
 * Easter Sunday, and Easter Sunday is the first Sunday after the first full
 * moon on or after the spring equinox, which is why there is an algorithm in
 * here rather than a date.
 *
 * Easter Sunday and Boxing Day are NOT statutory holidays in British
 * Columbia. They are in some other provinces, which is exactly the sort of
 * thing that gets copied across from a national list.
 *
 * Where a fixed-date holiday falls on a weekend, the Act lets an employer and
 * an employee AGREE to substitute another day. An agreement is not a rule, so
 * nothing here moves a holiday off the day it falls on.
 */
class BFTD_Stat_Holidays {

	/**
	 * Every statutory holiday in a calendar year, as date => name.
	 *
	 * In date order, which is not the order they are written in below, so
	 * the list is sorted rather than trusted.
	 */
	public static function in_year( $year ) {
		$year = (int) $year;
		if ( $year < 1900 || $year > 2200 ) return array();

		$out = array(
			self::ymd( $year, 1, 1 )                 => 'New Year\'s Day',
			self::nth_weekday( $year, 2, 1, 3 )      => 'Family Day',
			self::good_friday( $year )               => 'Good Friday',
			self::victoria_day( $year )              => 'Victoria Day',
			self::ymd( $year, 7, 1 )                 => 'Canada Day',
			self::nth_weekday( $year, 8, 1, 1 )      => 'British Columbia Day',
			self::nth_weekday( $year, 9, 1, 1 )      => 'Labour Day',
			self::ymd( $year, 9, 30 )                => 'National Day for Truth and Reconciliation',
			self::nth_weekday( $year, 10, 1, 2 )     => 'Thanksgiving Day',
			self::ymd( $year, 11, 11 )               => 'Remembrance Day',
			self::ymd( $year, 12, 25 )               => 'Christmas Day',
		);

		ksort( $out );
		return $out;
	}

	/** Every one that falls between two dates, inclusive. */
	public static function between( $from, $to ) {
		$from = (string) $from;
		$to   = (string) $to;
		if ( '' === $from || '' === $to || $from > $to ) return array();

		$out = array();
		for ( $y = (int) substr( $from, 0, 4 ); $y <= (int) substr( $to, 0, 4 ); $y++ ) {
			foreach ( self::in_year( $y ) as $ymd => $name ) {
				if ( $ymd >= $from && $ymd <= $to ) $out[ $ymd ] = $name;
			}
		}
		ksort( $out );
		return $out;
	}

	/** What a given day is, or '' if it is an ordinary one. */
	public static function name_for( $ymd ) {
		$ymd  = (string) $ymd;
		$year = (int) substr( $ymd, 0, 4 );
		$all  = self::in_year( $year );
		return isset( $all[ $ymd ] ) ? $all[ $ymd ] : '';
	}

	public static function is_one( $ymd ) {
		return '' !== self::name_for( $ymd );
	}

	/* ------------------------------------------------------------------ */
	/* Where the business is, and which days nothing is booked (1.203)     */
	/* ------------------------------------------------------------------ */

	/** The business's place, read from GoHighLevel's business info. */
	const PLACE = 'bftd_business_place';

	/**
	 * The holiday lists the portal knows, by country and province or state.
	 * Each is a method returning date => name for a year. British Columbia
	 * is the list above, the one payroll uses, so bookings and pay never
	 * disagree about a holiday.
	 */
	public static function known() {
		return array( 'CA-BC' => array( __CLASS__, 'in_year' ) );
	}

	/**
	 * "CA" and "BC" from whatever GoHighLevel holds: codes, or names such
	 * as "Canada" and "British Columbia". Unknown names are kept, uppercased,
	 * so the screen can say what was found.
	 */
	public static function place_codes( $country, $state ) {
		$country = strtoupper( trim( (string) $country ) );
		$state   = strtoupper( trim( (string) $state ) );
		$countries = array( 'CANADA' => 'CA', 'UNITED STATES' => 'US', 'USA' => 'US', 'UNITED STATES OF AMERICA' => 'US' );
		if ( isset( $countries[ $country ] ) ) $country = $countries[ $country ];
		$provinces = array(
			'BRITISH COLUMBIA' => 'BC', 'ALBERTA' => 'AB', 'SASKATCHEWAN' => 'SK', 'MANITOBA' => 'MB', 'ONTARIO' => 'ON',
			'QUEBEC' => 'QC', 'QUÉBEC' => 'QC', 'NEW BRUNSWICK' => 'NB', 'NOVA SCOTIA' => 'NS', 'PRINCE EDWARD ISLAND' => 'PE',
			'NEWFOUNDLAND AND LABRADOR' => 'NL', 'YUKON' => 'YT', 'NORTHWEST TERRITORIES' => 'NT', 'NUNAVUT' => 'NU',
		);
		if ( isset( $provinces[ $state ] ) ) $state = $provinces[ $state ];
		return array( $country, $state );
	}

	/** The place as stored: country, state, label, when. Empty before the first read. */
	public static function place() {
		$p = function_exists( 'get_option' ) ? get_option( self::PLACE, array() ) : array();
		return is_array( $p ) ? $p : array();
	}

	/** The list for the business's place, or null when there is none for it. */
	public static function list_for_place() {
		$p   = self::place();
		$key = ( $p['country'] ?? '' ) . '-' . ( $p['state'] ?? '' );
		$all = self::known();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** The holiday on this date where the business is, or ''. */
	public static function holiday_here( $ymd ) {
		static $years = array();
		$fn = self::list_for_place();
		if ( ! $fn ) return '';
		$y  = (int) substr( (string) $ymd, 0, 4 );
		$ck = $y . '|' . implode( '-', (array) $fn );
		if ( ! isset( $years[ $ck ] ) ) $years[ $ck ] = call_user_func( $fn, $y );
		return isset( $years[ $ck ][ $ymd ] ) ? (string) $years[ $ck ][ $ymd ] : '';
	}

	/* ------------------------------------------------------------------ */
	/* Our own school year's breaks (Karl, 1.203)                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Brilliant Futures' own school year, not a school district's: the
	 * Christmas holidays and spring break move every year, so each school
	 * year's dates are entered in Settings, Calendars. Nothing is booked in
	 * either break.
	 */
	const BREAKS = 'bftd_school_breaks';

	/** The breaks, named, for display and for the check. */
	public static function break_names() {
		return array( 'xmas' => 'Christmas holidays', 'spring' => 'Spring break' );
	}

	/** Every school year entered: starting year => xmas_from, xmas_to, spring_from, spring_to. Sorted. */
	public static function school_years() {
		$all = function_exists( 'get_option' ) ? get_option( self::BREAKS, array() ) : array();
		$all = is_array( $all ) ? $all : array();
		ksort( $all );
		return $all;
	}

	/**
	 * Saved from the form: rows of year and the four dates. A year with no
	 * dates is dropped; a break whose end is before its start is dropped.
	 *
	 * @return array the school years kept
	 */
	public static function save_school_years( $rows ) {
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$year = (int) ( $row['year'] ?? 0 );
			if ( $year < 2000 || $year > 2200 ) continue;
			$keep = array();
			foreach ( array_keys( self::break_names() ) as $b ) {
				$from = self::date_or_blank( $row[ $b . '_from' ] ?? '' );
				$to   = self::date_or_blank( $row[ $b . '_to' ] ?? '' );
				if ( '' === $from || '' === $to || $to < $from ) { $from = ''; $to = ''; }
				$keep[ $b . '_from' ] = $from;
				$keep[ $b . '_to' ]   = $to;
			}
			if ( '' === implode( '', $keep ) ) continue;
			$out[ $year ] = $keep;
		}
		ksort( $out );
		if ( function_exists( 'update_option' ) ) update_option( self::BREAKS, $out, false );
		return $out;
	}

	private static function date_or_blank( $d ) {
		$d = trim( (string) $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}

	/** The school break this date falls in, or ''. */
	public static function break_on( $ymd ) {
		foreach ( self::school_years() as $y ) {
			foreach ( self::break_names() as $b => $name ) {
				$from = (string) ( $y[ $b . '_from' ] ?? '' );
				$to   = (string) ( $y[ $b . '_to' ] ?? '' );
				if ( '' !== $from && $ymd >= $from && $ymd <= $to ) return $name;
			}
		}
		return '';
	}

	/**
	 * Nothing is booked on this date, Sundays aside: a statutory holiday
	 * where the business is, or one of our school breaks. The name, or ''.
	 */
	public static function no_bookings( $ymd ) {
		$h = self::holiday_here( $ymd );
		return '' !== $h ? $h : self::break_on( (string) $ymd );
	}

	/**
	 * Why nothing can be booked on this date, or '' if it is open (Karl,
	 * 1.203): Sundays, the statutory holidays where the business is, and
	 * our own Christmas holidays and spring break.
	 */
	public static function closed_because( $ymd ) {
		$ymd = (string) $ymd;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ymd ) ) return '';
		if ( '7' === gmdate( 'N', strtotime( $ymd . ' 12:00:00 UTC' ) ) ) return 'Sunday';
		return self::no_bookings( $ymd );
	}

	/* ------------------------------------------------------------------ */
	/* The arithmetic                                                      */
	/* ------------------------------------------------------------------ */

	private static function ymd( $y, $m, $d ) {
		return sprintf( '%04d-%02d-%02d', $y, $m, $d );
	}

	/**
	 * The nth given weekday of a month. Weekday is 1 for Monday, as ISO has
	 * it, because every one of these is a Monday and 0-for-Sunday is the
	 * convention that makes that read wrong.
	 */
	private static function nth_weekday( $year, $month, $weekday, $nth ) {
		$first = gmmktime( 0, 0, 0, $month, 1, $year );
		$dow   = (int) gmdate( 'N', $first );
		$day   = 1 + ( ( $weekday - $dow + 7 ) % 7 ) + ( ( $nth - 1 ) * 7 );
		return self::ymd( $year, $month, $day );
	}

	/** The Monday on or before the 24th of May. */
	private static function victoria_day( $year ) {
		$ts = gmmktime( 0, 0, 0, 5, 24, $year );
		return gmdate( 'Y-m-d', $ts - ( ( (int) gmdate( 'N', $ts ) - 1 ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Two days before Easter Sunday.
	 *
	 * The anonymous Gregorian computus. It is written out rather than handed
	 * to easter_date(), which is part of the optional calendar extension and
	 * is not on every host: a payroll that pays Good Friday on some servers
	 * and not others is worse than one that does not pay it at all.
	 */
	private static function good_friday( $year ) {
		$a = $year % 19;
		$b = intdiv( $year, 100 );
		$c = $year % 100;
		$d = intdiv( $b, 4 );
		$e = $b % 4;
		$f = intdiv( $b + 8, 25 );
		$g = intdiv( $b - $f + 1, 3 );
		$h = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i = intdiv( $c, 4 );
		$k = $c % 4;
		$l = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;

		$easter = gmmktime( 0, 0, 0, $month, $day, $year );
		return gmdate( 'Y-m-d', $easter - ( 2 * DAY_IN_SECONDS ) );
	}
}
