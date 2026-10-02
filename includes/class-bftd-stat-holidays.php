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
