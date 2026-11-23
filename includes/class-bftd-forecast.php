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
 * The sessions still to come are the ones purchased less the ones used.
 * They fall first on the student's bookings, which is where the bank already
 * says how far the paid sessions reach. Any left over, or all of them for a
 * student with no bookings yet, are counted forward at Sessions per week
 * from the last booking, or from Attending from (today, if that is past),
 * skipping the days the practice is closed.
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
	}

	public static function per_week( $student_id ) {
		$n = (int) BFTD_Fields::get( (int) $student_id, 'student', 'per_week' );
		return $n >= 1 && $n <= 5 ? $n : 2;
	}

	/** The forecast last session, Y-m-d, or '' with nothing purchased. */
	public static function forecast( $student_id ) {
		$student_id = (int) $student_id;
		$purchased  = (int) BFTD_Fields::get( $student_id, 'student', 'lessons_bank' );
		if ( $purchased <= 0 ) return '';
		$bank = BFTD_Schedule::bank( $student_id );

		// All used: the last one given.
		if ( $bank['remaining'] <= 0 ) {
			$taught = array_keys( BFTD_Schedule::taught_dates( $student_id ) );
			return $taught ? max( $taught ) : '';
		}
		if ( 0 === (int) $bank['unbooked'] && '' !== $bank['last_date'] ) return $bank['last_date'];

		$today = BFTD_Time::today();
		if ( '' !== $bank['last_date'] ) {
			$start = BFTD_Time::add_days( $bank['last_date'], 1 );
		} else {
			$from  = (string) BFTD_Fields::get( $student_id, 'student', 'attend_from' );
			$start = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) && $from > $today ? $from : $today;
		}
		return self::count_forward( $start, (int) $bank['unbooked'], self::per_week( $student_id ) );
	}

	/**
	 * The date of the $n-th session from $start, at $per sessions a week,
	 * a closed day's session moved to the next open day.
	 */
	public static function count_forward( $start, $n, $per ) {
		if ( $n <= 0 ) return '';
		$days = self::PATTERNS[ max( 1, min( 5, (int) $per ) ) ];
		$last = '';
		$done = 0;
		for ( $week = 0; $week < 520 && $done < $n; $week++ ) {
			foreach ( $days as $off ) {
				$d = BFTD_Time::add_days( $start, 7 * $week + $off );
				if ( '' !== $last && $d <= $last ) $d = BFTD_Time::add_days( $last, 1 );
				for ( $i = 0; $i < 30 && '' !== BFTD_Schedule::closed_on( $d ); $i++ ) $d = BFTD_Time::add_days( $d, 1 );
				$last = $d;
				if ( ++$done >= $n ) break;
			}
		}
		return $last;
	}

	/** Write the forecast into an empty Attending to, or one this filled in before. */
	public static function apply( $student_id ) {
		$student_id = (int) $student_id;
		if ( ! $student_id ) return;
		$now    = (string) BFTD_Fields::get( $student_id, 'student', 'attend_to' );
		$filled = (string) get_post_meta( $student_id, self::AUTO_KEY, true );
		if ( '' !== $now && $now !== $filled ) return;   // typed by somebody: theirs
		$want = self::forecast( $student_id );
		if ( $want === $now ) return;
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
