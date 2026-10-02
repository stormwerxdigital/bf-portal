<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One clock for the whole plugin, and it is Pacific.
 *
 * Brilliant Futures runs out of Prince George, so every date and time anyone
 * sees — a lesson slot, a reassessment date, a message timestamp — means
 * Pacific time. Not the server's idea of time, and not UTC.
 *
 * WHY THIS FILE EXISTS AT ALL. WordPress sets PHP's default timezone to UTC,
 * so the innocent-looking pair
 *
 *     date_i18n( 'j F Y', strtotime( '2026-09-14' ) )
 *
 * is wrong on a Pacific site, and wrong in the worst way: strtotime reads the
 * bare date as midnight UTC, date_i18n renders it in site time, and midnight
 * UTC is five in the afternoon the PREVIOUS DAY in Vancouver. A lesson booked
 * for Monday the fourteenth is announced as Sunday the thirteenth. It is off
 * by one day, only sometimes, and only for dates with no time attached, which
 * is exactly the kind of bug that survives a release.
 *
 * So a plain calendar date never goes through strtotime here. It is turned
 * into a timestamp anchored at midnight IN PACIFIC TIME, and formatted with
 * wp_date against the same zone.
 *
 * PDT OR PST. The zone is America/Vancouver rather than a fixed offset, so it
 * is PDT in summer and PST in winter without anybody thinking about it. A
 * hardcoded minus seven would be an hour out for half the year, and would put
 * an early-morning lesson on the wrong day twice a year.
 */
class BFTD_Time {

	const ZONE = 'America/Vancouver';

	/** The one timezone everything here is measured in. */
	public static function zone() {
		$name = (string) apply_filters( 'bftd_timezone', self::ZONE );
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return new DateTimeZone( self::ZONE );
		}
	}

	/** "PDT" or "PST", whichever it currently is. For labelling a time. */
	public static function abbreviation( $when = null ) {
		$d = new DateTime( '@' . ( null === $when ? time() : (int) $when ) );
		$d->setTimezone( self::zone() );
		return $d->format( 'T' );
	}

	/**
	 * A calendar date, and optionally a wall-clock time, as a timestamp.
	 *
	 * This is the replacement for strtotime() on anything shaped like
	 * 2026-09-14: it anchors the date to Pacific time rather than to UTC.
	 * Returns 0 for anything that is not a date, so callers can tell the
	 * difference between "no date" and "the first of January 1970".
	 */
	public static function stamp( $ymd, $hm = '00:00' ) {
		$ymd = trim( (string) $ymd );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $ymd, $d ) ) return 0;

		// Seconds are optional, because most callers have "16:00" and a few
		// have a full stamp read back out of the database. Dropping them
		// would round every stored moment down to the minute.
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', (string) $hm, $t ) ) {
			$t = array( '', '0', '0', '0' );
		}

		try {
			$dt = new DateTime( 'now', self::zone() );
			$dt->setDate( (int) $d[1], (int) $d[2], (int) $d[3] );
			$dt->setTime( (int) $t[1], (int) $t[2], isset( $t[3] ) ? (int) $t[3] : 0 );
			return $dt->getTimestamp();
		} catch ( Exception $e ) {
			return 0;
		}
	}

	/** Format a timestamp in Pacific time. */
	public static function format( $format, $timestamp = null ) {
		$timestamp = ( null === $timestamp ) ? time() : (int) $timestamp;

		// wp_date arrived in WordPress 5.3 and is the only formatter that
		// takes a timezone; older installs fall back to doing it by hand.
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( $format, $timestamp, self::zone() );
		}
		$d = new DateTime( '@' . $timestamp );
		$d->setTimezone( self::zone() );
		return $d->format( $format );
	}

	/** Format a plain calendar date. The common case, and the one that bites. */
	public static function day( $ymd, $format = 'j F Y' ) {
		$ts = self::stamp( $ymd );
		return $ts ? self::format( $format, $ts ) : '';
	}

	/** Format a date and a wall-clock time together. */
	public static function moment( $ymd, $hm, $format = 'j F Y, g:i A' ) {
		$ts = self::stamp( $ymd, $hm );
		return $ts ? self::format( $format, $ts ) : '';
	}

	/** Today, in Pacific time. Never the server's today. */
	public static function today() {
		return self::format( 'Y-m-d' );
	}

	/** The time of day right now, in Pacific time. */
	public static function clock( $format = 'H:i' ) {
		return self::format( $format );
	}

	/**
	 * Calendar arithmetic that stays on calendar days.
	 *
	 * Adding "+30 days" to a timestamp crosses a daylight saving boundary
	 * twice a year and lands an hour off, which is enough to change the date.
	 * Working in the date's own terms cannot.
	 */
	public static function add_days( $ymd, $days ) {
		$ts = self::stamp( $ymd, '12:00' );      // midday, so an hour either way changes nothing
		if ( ! $ts ) return '';
		return self::format( 'Y-m-d', $ts + ( (int) $days * DAY_IN_SECONDS ) );
	}

	/** Which day of the week a date falls on, 1 for Monday through 7 for Sunday. */
	public static function weekday( $ymd ) {
		$ts = self::stamp( $ymd, '12:00' );
		return $ts ? (int) self::format( 'N', $ts ) : 0;
	}

	/** A mysql-shaped stamp for storing, in Pacific time so logs read locally. */
	public static function mysql() {
		return self::format( 'Y-m-d H:i:s' );
	}

	/**
	 * Read back what mysql() wrote.
	 *
	 * These strings are wall-clock Pacific with no zone on them, so handing
	 * one to strtotime — which reads it as UTC under WordPress — makes
	 * something that happened a minute ago look several hours old.
	 */
	public static function from_mysql( $s ) {
		$s = trim( (string) $s );
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)/', $s, $m ) ) {
			return self::stamp( $s );
		}
		return self::stamp( $m[1], $m[2] );
	}

	/**
	 * A wall-clock time on no particular day: "16:00" as "4:00 PM".
	 *
	 * Deliberately never touches a timezone. A lesson slot is a time of day,
	 * not a moment, and pushing it through a timestamp is how "four o'clock"
	 * becomes "eight in the morning" the day a site is moved off UTC.
	 */
	public static function time_of_day( $hm, $format = '' ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})/', trim( (string) $hm ), $t ) ) return (string) $hm;

		$h = (int) $t[1] % 24;
		$m = (int) $t[2] % 60;

		if ( ! $format ) $format = get_option( 'time_format' );
		if ( ! $format ) $format = 'g:i A';

		// A fixed, zone-free day is enough to format an hour and a minute.
		$d = DateTime::createFromFormat( 'Y-m-d H:i', '2000-01-01 ' . sprintf( '%02d:%02d', $h, $m ), new DateTimeZone( 'UTC' ) );
		return $d ? $d->format( $format ) : sprintf( '%02d:%02d', $h, $m );
	}
}
