<?php
/**
 * "Attending to", forecast (Karl, 1.214).
 *
 * "If # sessions is indicated and start date is provided, calculate and
 * insert last date Attending to", "when we have entered a number of
 * purchased sessions, you can forecast the last session date based on our
 * calendar settings", and "it will need to factor in the frequency of
 * sessions, 1-5x per week. Default should be 2 sessions per week."
 *
 * Every session purchased, counted from Attending from: on the student's
 * bookings where the Calendar has them, at Sessions per week where it does
 * not, with no session on a day the practice is closed (1.216.2; see
 * forecast()). 1.214 counted only the sessions not yet written up, from
 * today, which put nearly every student's date months late.
 *
 * Only an empty Attending to, or one this filled in, is written. A date
 * somebody typed is theirs and is never changed.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Forecast {

	const AUTO_KEY = '_bftd_attend_to_auto';   // the date this last filled in

	/** Days of the week a session falls on, by sessions per week, counted from the first. */
	const PATTERNS = array( 1 => array( 0 ), 2 => array( 0, 3 ), 3 => array( 0, 2, 4 ), 4 => array( 0, 1, 3, 4 ), 5 => array( 0, 1, 2, 3, 4 ) );

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 30, 2 );   // after the student's fields (10), and a session's
		add_action( 'bftd_schedule_changed', array( __CLASS__, 'apply' ) );
		// Once after 1.216.2: every student's forecast worked out again the new way.
		add_action( 'admin_init', array( __CLASS__, 'redo_all' ) );
		// What Attending to will be, the moment a start is chosen (Karl, 1.217.5).
		add_action( 'wp_ajax_bftd_forecast_preview', array( __CLASS__, 'ajax_preview' ) );
	}

	const PASS = 'bftd_forecast_pass';

	public static function redo_all() {
		if ( '3' === (string) get_option( self::PASS, '' ) ) return;   // 3 since 1.217: starts linked, Sessions per week from the schedule
		update_option( self::PASS, '3', false );
		foreach ( get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $sid ) {
			if ( class_exists( 'BFTD_Start_Appt' ) ) BFTD_Start_Appt::auto_link( $sid );
			self::apply( $sid );
		}
	}

	public static function per_week( $student_id ) {
		$n = (int) BFTD_Fields::get( (int) $student_id, 'student', 'per_week' );
		return $n >= 1 && $n <= 5 ? $n : 2;
	}

	/**
	 * The forecast last session, Y-m-d, or '' without both a number of
	 * sessions purchased and an Attending from (1.216.2, Karl: "Your
	 * attending to date is wrong for all students it appears. You must
	 * account for our calendar, holidays and school year settings").
	 *
	 * Counted from Attending from, every session purchased, whether or not
	 * it has been written up in the portal yet: the student's own bookings
	 * on the Calendar where it has them (each one a session, cancelled ones
	 * too, since a cancellation spends one; a rescheduled one counts where it
	 * went; reading assessments are not sessions), and Sessions per week
	 * before the first booking and after the last. Days the practice is
	 * closed (Sundays, statutory holidays where the business is, our
	 * Christmas holidays and spring break) have no session, so they push the
	 * last one later rather than crowding onto the next open day.
	 */
	public static function forecast( $student_id, $from_now = null, $bought_now = null ) {
		$student_id = (int) $student_id;
		// A start or a number not saved yet, for the screen to show what it
		// will be (1.217.5); the saved ones otherwise.
		$n          = null !== $bought_now ? (int) $bought_now : (int) BFTD_Fields::get( $student_id, 'student', 'lessons_bank' );
		$from       = null !== $from_now ? (string) $from_now : (string) BFTD_Fields::get( $student_id, 'student', 'attend_from' );
		if ( $n <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) return '';
		$per = self::per_week( $student_id );

		$booked = array();
		foreach ( BFTD_Schedule::occurrences( $student_id, $from, BFTD_Time::add_days( $from, 1100 ), 600 ) as $o ) {
			if ( 'moved' === $o['status'] || ! BFTD_Schedule::counts( $o ) ) continue;
			$booked[] = $o['date'];
		}
		sort( $booked );

		// The weekdays the student actually comes, from their weekly bookings:
		// with two a week that is the first AND the second appointment's day
		// (Karl, 1.216.2: "For an accurate end date, you need the date of his
		// second appointment"). Only with no weekly booking is Sessions per
		// week spread over the week from the start day instead.
		$days = self::booked_weekdays( $student_id );
		$fwd  = function ( $start, $count, $before = '' ) use ( $days, $per ) {
			return $days ? self::dates_on( $start, $count, $days, $before ) : self::dates_forward( $start, $count, $per, $before );
		};

		$dates = $booked ? $fwd( $from, $n, $booked[0] ) : $fwd( $from, $n );   // before the first booking
		foreach ( $booked as $d ) {
			if ( count( $dates ) >= $n ) break;
			$dates[] = $d;
		}
		if ( count( $dates ) < $n ) {
			$after = $booked ? BFTD_Time::add_days( end( $booked ), 1 ) : $from;
			$dates = array_merge( $dates, $fwd( $after, $n - count( $dates ) ) );
		}
		return $dates ? (string) $dates[ min( $n, count( $dates ) ) - 1 ] : '';
	}

	/** ISO weekdays (1 Monday to 6 Saturday) of the student's weekly bookings, sorted; empty with none. */
	public static function booked_weekdays( $student_id ) {
		$out = array();
		foreach ( BFTD_Schedule::rules( (int) $student_id ) as $r ) {
			if ( BFTD_Schedule::is_once( $r ) || 'assessment' === ( $r['type'] ?? '' ) ) continue;
			$d = (int) ( $r['day'] ?? 0 );
			if ( $d >= 1 && $d <= 7 ) $out[ $d ] = $d;
		}
		sort( $out );
		return array_values( $out );
	}

	/** Session dates from $start on the given weekdays, open days only, at most $n, none on or after $before. */
	public static function dates_on( $start, $n, $weekdays, $before = '' ) {
		$out = array();
		$on  = array_flip( (array) $weekdays );
		for ( $i = 0, $d = $start; $i < 3700 && count( $out ) < $n; $i++, $d = BFTD_Time::add_days( $d, 1 ) ) {
			if ( '' !== $before && $d >= $before ) break;
			if ( ! isset( $on[ (int) gmdate( 'N', strtotime( $d . ' 12:00:00 UTC' ) ) ] ) ) continue;
			if ( '' !== BFTD_Schedule::closed_on( $d ) ) continue;   // closed: no session that day
			$out[] = $d;
		}
		return $out;
	}

	/**
	 * Session dates from $start at $per a week, the practice's open days
	 * only, at most $n of them, and none on or after $before when given.
	 */
	public static function dates_forward( $start, $n, $per, $before = '' ) {
		$out  = array();
		if ( $n <= 0 ) return $out;
		$days = self::PATTERNS[ max( 1, min( 5, (int) $per ) ) ];
		for ( $week = 0; $week < 520 && count( $out ) < $n; $week++ ) {
			foreach ( $days as $off ) {
				$d = BFTD_Time::add_days( $start, 7 * $week + $off );
				if ( '' !== $before && $d >= $before ) return $out;
				if ( '' !== BFTD_Schedule::closed_on( $d ) ) continue;   // closed: no session that day
				$out[] = $d;
				if ( count( $out ) >= $n ) break;
			}
		}
		return $out;
	}

	/** The $n-th session date from $start at $per a week (open days only). */
	public static function count_forward( $start, $n, $per ) {
		$d = self::dates_forward( $start, $n, $per );
		return $d ? (string) end( $d ) : '';
	}

	/**
	 * The forecast for a start and a number of sessions on the screen, not
	 * saved yet: Y-m-d and the same as a date, or empty. Nothing is saved.
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$sid = absint( $_POST['student_id'] ?? 0 );
		if ( ! $sid || ! BFTD_Roles::is_staff() || ! current_user_can( 'edit_post', $sid ) ) wp_send_json_error( array(), 403 );
		$from   = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$bought = isset( $_POST['bought'] ) && '' !== $_POST['bought'] ? absint( $_POST['bought'] ) : null;
		$d = self::forecast( $sid, $from, $bought );
		wp_send_json_success( array( 'date' => $d, 'label' => '' !== $d ? BFTD_Time::format( 'j M Y', BFTD_Time::stamp( $d, '12:00' ) ) : '' ) );
	}

	/** Write the forecast into an empty Attending to, or one this filled in before. */
	public static function apply( $student_id ) {
		$student_id = (int) $student_id;
		if ( ! $student_id ) return;
		// Sessions per week is what the student's basic weekly schedule says,
		// once they have one (Karl, 1.217: "we will set a basic schedule
		// instead of linking each appointment"), so the two never disagree.
		$weekly = count( self::booked_weekdays( $student_id ) );
		if ( $weekly >= 1 && $weekly <= 5 && $weekly !== self::per_week( $student_id ) ) {
			BFTD_Fields::set( $student_id, 'student', 'per_week', (string) $weekly );
		}
		$now    = (string) BFTD_Fields::get( $student_id, 'student', 'attend_to' );
		$filled = (string) get_post_meta( $student_id, self::AUTO_KEY, true );
		$want   = self::forecast( $student_id );
		// A date that is the forecast is the forecast's, even one the screen
		// filled in before it was saved (1.217.5), so it keeps following.
		if ( '' !== $now && $now !== $filled && $now !== $want ) return;   // typed by somebody: theirs
		if ( $want === $now ) {
			if ( '' !== $want && $want !== $filled ) update_post_meta( $student_id, self::AUTO_KEY, $want );
			return;
		}
		BFTD_Fields::set( $student_id, 'student', 'attend_to', $want );
		if ( '' === $want ) delete_post_meta( $student_id, self::AUTO_KEY );
		else update_post_meta( $student_id, self::AUTO_KEY, $want );
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( BFTD_CPT::STUDENT === $post->post_type ) self::apply( $post_id );
		elseif ( BFTD_CPT::SESSION === $post->post_type ) self::apply( BFTD_CPT::student_id( $post_id ) );
	}
}
