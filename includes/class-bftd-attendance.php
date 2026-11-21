<?php
/**
 * Attendance marked from the Calendar (Karl, 1.211).
 *
 * The session record is where attendance lives. A booking on the Calendar can
 * be marked Attended, Late start, No show or Missed, and:
 *
 *   - when the student has a session on that date, the mark is written to
 *     the session's What happened, draft or published;
 *   - when there is no session yet, the mark is kept on the booking, and the
 *     session screen offers it once a session for that student and date is
 *     being recorded. Saving that session drops the booking's mark, so there
 *     is one answer and it is on the session.
 *
 * Nothing here spends a paid session. Only a published session does that, as
 * before (Karl: "only once the session is published").
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Attendance {

	/** On the student: date => array( status, missed_by, missed_why, by, at ). */
	const MARKS_KEY = '_bftd_attendance_marks';

	public static function init() {
		add_action( 'wp_ajax_bftd_calendar_attend', array( __CLASS__, 'ajax_attend' ) );
		add_action( 'wp_ajax_bftd_attendance_mark', array( __CLASS__, 'ajax_mark' ) );
		// After the session's own fields are saved.
		add_action( 'save_post', array( __CLASS__, 'session_saved' ), 50, 2 );   // after the session's own fields (priority 10)
	}

	/** What can be marked from the Calendar, in the session screen's words. */
	public static function choices() {
		return array( 'held' => 'Attended', 'late' => 'Late start', 'noshow' => 'No show', 'missed' => 'Missed' );
	}

	public static function marks( $student_id ) {
		$m = get_post_meta( (int) $student_id, self::MARKS_KEY, true );
		return is_array( $m ) ? $m : array();
	}

	public static function mark_for( $student_id, $date ) {
		$m = self::marks( $student_id );
		return isset( $m[ $date ] ) && is_array( $m[ $date ] ) ? $m[ $date ] : null;
	}

	public static function forget( $student_id, $date ) {
		$m = self::marks( $student_id );
		if ( ! isset( $m[ $date ] ) ) return;
		unset( $m[ $date ] );
		if ( $m ) update_post_meta( (int) $student_id, self::MARKS_KEY, $m );
		else delete_post_meta( (int) $student_id, self::MARKS_KEY );
	}

	/**
	 * date => array( id, status, draft ) for every session the student has,
	 * drafts included: a mark goes to the session whatever state it is in.
	 */
	public static function sessions_by_date( $student_id ) {
		$report = BFTD_CPT::report_for( (int) $student_id, BFTD_CPT::PROGRESS );
		if ( ! $report ) return array();
		$out = array();
		foreach ( BFTD_CPT::sessions_for( $report, true ) as $sid ) {
			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			if ( ! $date ) continue;
			// The latest record wins a shared day, as with taught_dates().
			$out[ $date ] = array(
				'id'     => (int) $sid,
				'status' => (string) BFTD_Fields::get( $sid, 'session', 'status' ),
				'draft'  => 'publish' !== get_post_status( $sid ),
			);
		}
		return $out;
	}

	/**
	 * What a booking says about attendance, for the Calendar: from the
	 * session when there is one, else from the booking's own mark.
	 */
	public static function for_booking( $sessions, $marks, $date ) {
		if ( isset( $sessions[ $date ] ) ) {
			return array( 'attend' => $sessions[ $date ]['status'], 'attend_on' => 'session', 'session_draft' => $sessions[ $date ]['draft'] );
		}
		if ( isset( $marks[ $date ]['status'] ) ) {
			return array( 'attend' => (string) $marks[ $date ]['status'], 'attend_on' => 'booking', 'session_draft' => false );
		}
		return array( 'attend' => '', 'attend_on' => '', 'session_draft' => false );
	}

	/**
	 * Record a mark. Returns 'session' or 'booking' (where it went), or a
	 * WP_Error saying what is missing.
	 */
	public static function mark( $student_id, $date, $status, $missed_by = '', $missed_why = '', $viewer = 0, $key = '' ) {
		$student_id = (int) $student_id;
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date ) ) return new WP_Error( 'bftd_date', 'That is not a date.' );
		if ( $date > BFTD_Time::today() ) return new WP_Error( 'bftd_future', 'A session that has not happened yet cannot be marked.' );
		if ( '' !== $status && ! isset( self::choices()[ $status ] ) ) return new WP_Error( 'bftd_status', 'Choose what happened.' );

		if ( 'missed' === $status ) {
			$missed_by  = (string) $missed_by;
			$missed_why = trim( (string) $missed_why );
			$people     = BFTD_MetaBoxes::cancelled_by_options( 0, $student_id );
			if ( '' === $missed_by || ! isset( $people[ $missed_by ] ) ) return new WP_Error( 'bftd_who', 'Say who cancelled it.' );
			if ( '' === $missed_why ) return new WP_Error( 'bftd_why', 'Say why it was missed. The family sees this.' );
		} else {
			$missed_by = $missed_why = '';
		}

		$sessions = self::sessions_by_date( $student_id );
		// The session linked to this appointment wins over one that only
		// shares its date (1.213).
		if ( '' !== $key && class_exists( 'BFTD_Session_Appt' ) ) {
			$linked = BFTD_Session_Appt::linked_keys( $student_id );
			if ( isset( $linked[ $key ] ) ) $sessions[ $date ] = array( 'id' => $linked[ $key ], 'status' => '', 'draft' => false );
		}
		if ( isset( $sessions[ $date ] ) ) {
			if ( '' === $status ) return new WP_Error( 'bftd_status', 'Choose what happened. The session keeps an answer.' );
			$sid = $sessions[ $date ]['id'];
			BFTD_Fields::set( $sid, 'session', 'status', $status );
			if ( 'missed' === $status ) {
				BFTD_Fields::set( $sid, 'session', 'missed_by', $missed_by );
				BFTD_Fields::set( $sid, 'session', 'missed_why', $missed_why );
			}
			self::forget( $student_id, $date );
			if ( class_exists( 'BFTD_Audit' ) ) {
				BFTD_Audit::log( 'session_updated', array( 'object_id' => $sid, 'summary' => 'What happened set to ' . self::choices()[ $status ] . ' from the Calendar.' ) );
			}
			return 'session';
		}

		if ( '' === $status ) { self::forget( $student_id, $date ); return 'booking'; }
		$m = self::marks( $student_id );
		$m[ $date ] = array( 'status' => $status, 'missed_by' => $missed_by, 'missed_why' => $missed_why, 'by' => (int) $viewer, 'at' => time() );
		update_post_meta( $student_id, self::MARKS_KEY, $m );
		return 'booking';
	}

	/** Whoever can change the booking can mark it: its tutor, or someone who can edit the student. */
	public static function can_mark( $student_id, $date, $viewer ) {
		if ( user_can( (int) $viewer, 'edit_post', (int) $student_id ) ) return true;
		foreach ( BFTD_Schedule::occurrences( (int) $student_id, $date, $date, 20 ) as $o ) {
			if ( (int) $o['tutor'] === (int) $viewer ) return true;
		}
		return false;
	}

	public static function ajax_attend() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$viewer = get_current_user_id();
		$sid    = absint( $_POST['student_id'] ?? 0 );
		$date   = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		if ( ! $sid || BFTD_CPT::STUDENT !== get_post_type( $sid ) || ! BFTD_Roles::is_staff( $viewer ) || ! self::can_mark( $sid, $date, $viewer ) ) {
			wp_send_json_error( array( 'message' => 'Only the tutor or a manager can mark this session.' ), 403 );
		}
		$r = self::mark(
			$sid, $date,
			sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ),
			sanitize_text_field( wp_unslash( $_POST['missed_by'] ?? '' ) ),
			sanitize_textarea_field( wp_unslash( $_POST['missed_why'] ?? '' ) ),
			$viewer,
			sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) )
		);
		if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ), 400 );
		wp_send_json_success( array( 'where' => $r ) );
	}

	/** The session screen asks: is there a mark on the booking for this student and date? */
	public static function ajax_mark() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$sid  = absint( $_POST['student_id'] ?? 0 );
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		if ( ! $sid || ! BFTD_Roles::is_staff() || ! BFTD_Access::can_staff_view( $sid ) ) wp_send_json_success( array( 'mark' => null ) );
		$m = self::mark_for( $sid, $date );
		if ( $m ) $m['label'] = self::choices()[ $m['status'] ] ?? '';
		wp_send_json_success( array( 'mark' => $m ) );
	}

	/** Once a session for that student and date is saved, the booking's mark has done its job. */
	public static function session_saved( $post_id, $post ) {
		if ( BFTD_CPT::SESSION !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		$student = (int) get_post_meta( $post_id, BFTD_CPT::STUDENT_KEY, true );
		$date    = (string) BFTD_Fields::get( $post_id, 'session', 'session_date' );
		if ( $student && $date && self::mark_for( $student, $date ) ) self::forget( $student, $date );
	}
}
