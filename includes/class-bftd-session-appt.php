<?php
/**
 * A session's date and time come from its Calendar appointment (Karl, 1.213).
 *
 * "lets set it so that the date and time we use in our sessions actually
 * comes from a calendar appointment. And if we are creating a new session or
 * working in draft, we can link the appropriate appointment if one exists or
 * create a new draft appointment and link it."
 *
 * The link is the appointment's key on the session (date and time, as the
 * schedule names its slots). Linking copies the appointment's date and time
 * onto the session. A session is published only once it is linked; sessions
 * published before 1.213 keep their dates and can be linked when opened.
 *
 * A new appointment made from a session is a one-time booking under the
 * Calendar's rules. One for a later day is held in the "schedule changes not
 * sent" banner like any other. One for today or earlier is kept here: no
 * banner, no notice, and never sent to GoHighLevel, since the lesson has
 * already happened.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Session_Appt {

	const KEY      = '_bftd_appt_key';   // on the session: "Y-m-d H:i"
	const BACK     = 30;                 // days of appointments offered behind today
	const AHEAD    = 14;                 // and ahead of it
	const ERR      = 'bftd_appt_err_';   // a transient per user: why a link or a new appointment failed

	public static function init() {
		add_action( 'wp_ajax_bftd_session_appts', array( __CLASS__, 'ajax_list' ) );
	}

	public static function key_of( $session_id ) {
		return (string) get_post_meta( (int) $session_id, self::KEY, true );
	}

	/** Session ids of the student by the appointment each is linked to: key => session id. */
	public static function linked_keys( $student_id ) {
		$report = BFTD_CPT::report_for( (int) $student_id, BFTD_CPT::PROGRESS );
		if ( ! $report ) return array();
		$out = array();
		foreach ( BFTD_CPT::sessions_for( $report, true ) as $sid ) {
			$k = self::key_of( $sid );
			if ( '' !== $k ) $out[ $k ] = (int) $sid;
		}
		return $out;
	}

	/** One appointment of the student by its key, or null. Reading assessments are not sessions. */
	public static function appointment( $student_id, $key ) {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}$/', (string) $key, $m ) ) return null;
		foreach ( BFTD_Schedule::occurrences( (int) $student_id, $m[1], $m[1], 50 ) as $o ) {
			if ( $o['key'] === $key && 'assessment' !== ( $o['type'] ?? '' ) ) return $o;
		}
		return null;
	}

	/** "Fri 13 Nov, 10 am to 10:50 am, Laurel Sanders" and, when it is not a plain booking, what became of it. */
	public static function label( $o ) {
		$end = BFTD_Schedule::minutes_of( $o['time'] ) + (int) $o['minutes'];
		$u   = ! empty( $o['tutor'] ) ? get_userdata( (int) $o['tutor'] ) : null;
		$say = BFTD_Time::day( $o['date'] ) . ', ' . BFTD_Schedule::pretty_time( $o['time'] ) . ' to '
			. BFTD_Schedule::pretty_time( sprintf( '%02d:%02d', intdiv( $end, 60 ) % 24, $end % 60 ) )
			. ( $u ? ', ' . $u->display_name : '' );
		$was = array( 'cancelled' => 'cancelled', 'moved' => 'rescheduled', 'held' => 'attended', 'noshow' => 'no show' );
		return isset( $was[ $o['status'] ] ) ? $say . ' (' . $was[ $o['status'] ] . ')' : $say;
	}

	/**
	 * The appointments this session could be linked to: the student's, from
	 * a month back to a fortnight ahead, not reading assessments, and not
	 * already linked to another session. Nearest today first.
	 */
	public static function choices( $student_id, $session_id = 0 ) {
		$student_id = (int) $student_id;
		if ( ! $student_id ) return array();
		$today = BFTD_Time::today();
		$taken = self::linked_keys( $student_id );
		$mine  = self::key_of( $session_id );
		$out   = array();
		foreach ( BFTD_Schedule::occurrences( $student_id, BFTD_Time::add_days( $today, -self::BACK ), BFTD_Time::add_days( $today, self::AHEAD ), 200 ) as $o ) {
			if ( 'assessment' === ( $o['type'] ?? '' ) ) continue;
			if ( isset( $taken[ $o['key'] ] ) && $taken[ $o['key'] ] !== (int) $session_id ) continue;
			$out[] = array( 'key' => $o['key'], 'date' => $o['date'], 'time' => $o['time'], 'label' => self::label( $o ), 'mine' => $o['key'] === $mine );
		}
		usort( $out, function ( $a, $b ) use ( $today ) {
			$da = abs( strtotime( $a['date'] ) - strtotime( $today ) );
			$db = abs( strtotime( $b['date'] ) - strtotime( $today ) );
			return $da === $db ? strcmp( $a['key'], $b['key'] ) : $da - $db;
		} );
		return $out;
	}

	/** Link a session to one of its student's appointments, taking its date and time. */
	public static function link( $session_id, $student_id, $key ) {
		$o = self::appointment( $student_id, $key );
		if ( ! $o ) return new WP_Error( 'bftd_appt', 'That appointment is not on this student\'s schedule.' );
		$taken = self::linked_keys( $student_id );
		if ( isset( $taken[ $key ] ) && $taken[ $key ] !== (int) $session_id ) {
			return new WP_Error( 'bftd_appt', 'That appointment already has a session. Open that one instead.' );
		}
		update_post_meta( (int) $session_id, self::KEY, $o['key'] );
		BFTD_Fields::set( $session_id, 'session', 'session_date', $o['date'] );
		BFTD_Fields::set( $session_id, 'session', 'session_time', $o['time'] );
		return true;
	}

	/**
	 * A new one-time appointment for the student, then linked. Today or
	 * earlier is kept here, unannounced and never sent to GoHighLevel.
	 */
	public static function create_and_link( $session_id, $student_id, $date, $time, $minutes, $tutor ) {
		$past = (string) $date <= BFTD_Time::today();
		$r = BFTD_Schedule::add_booking( (int) $student_id, array(
			'from' => $date, 'time' => $time, 'minutes' => $minutes ? (int) $minutes : 50,
			'repeat' => 'once', 'type' => 'tutoring', 'tutor' => (int) $tutor, 'local' => $past ? 1 : 0,
		), $past );
		if ( is_wp_error( $r ) ) return $r;
		return self::link( $session_id, $student_id, $r['from'] . ' ' . $r['time'] );
	}

	/**
	 * From the session screen's form, on save: the appointment picked, or a
	 * new one. Run before the publish checks, so a session linked as it is
	 * published counts as linked.
	 */
	public static function save_from_form( $session_id ) {
		if ( ! isset( $_POST['bftd_appt'] ) ) return;
		$student = BFTD_CPT::student_id( $session_id );
		$pick    = sanitize_text_field( wp_unslash( $_POST['bftd_appt'] ) );
		if ( '' === $pick || ! $student ) return;
		if ( 'new' === $pick ) {
			$n = isset( $_POST['bftd_appt_new'] ) && is_array( $_POST['bftd_appt_new'] ) ? wp_unslash( $_POST['bftd_appt_new'] ) : array();
			$tutor = absint( $n['tutor'] ?? 0 );
			if ( ! $tutor ) $tutor = (int) BFTD_Fields::get( $session_id, 'session', 'delivered_by' );
			$r = self::create_and_link( $session_id, $student, sanitize_text_field( $n['date'] ?? '' ), sanitize_text_field( $n['time'] ?? '' ), absint( $n['minutes'] ?? 50 ), $tutor );
		} elseif ( $pick !== self::key_of( $session_id ) ) {
			$r = self::link( $session_id, $student, $pick );
		} else {
			return;
		}
		if ( is_wp_error( $r ) ) set_transient( self::ERR . get_current_user_id(), $r->get_error_message(), 60 );
	}

	/**
	 * Not published without its appointment. Held as a draft, with every word
	 * kept, like the other publish checks. A session already published before
	 * this save is left as it is.
	 */
	public static function hold_unlinked( $session_id, $post ) {
		if ( 'publish' !== $post->post_status ) return false;
		if ( '' !== self::key_of( $session_id ) ) return false;
		if ( isset( $_POST['original_post_status'] ) && 'publish' === $_POST['original_post_status'] ) return false;
		// A lesson already given is linked to that day's appointment, or one is
		// made for it, and then it publishes (Karl, 1.213.1).
		$auto = self::auto_link( $session_id );
		if ( true === $auto ) return false;
		// Why it could not be done, when the session had a date to try.
		if ( is_wp_error( $auto ) && '' !== (string) BFTD_Fields::get( $session_id, 'session', 'session_date' ) ) {
			set_transient( self::ERR . get_current_user_id(), $auto->get_error_message(), 60 );
		}

		remove_action( 'save_post', array( 'BFTD_MetaBoxes', 'save' ), 10 );
		wp_update_post( array( 'ID' => $session_id, 'post_status' => 'draft' ) );
		add_action( 'save_post', array( 'BFTD_MetaBoxes', 'save' ), 10, 2 );

		set_transient( 'bftd_held_' . get_current_user_id(), 'unlinked|', 60 );
		return true;
	}

	/**
	 * A session dated today or earlier with no appointment (Karl, 1.213.1:
	 * "IF the appointment date is the past and there is no appointment,
	 * create a new appointment with no notification to GHL. And then a
	 * session can be published"). Its student's appointment that day, not yet
	 * linked and nearest its time, is linked; with none, a new one is made at
	 * the session's own time and tutor, kept here: no banner, no notice,
	 * never sent to GoHighLevel. True once linked; otherwise why not.
	 */
	public static function auto_link( $session_id ) {
		if ( '' !== self::key_of( $session_id ) ) return true;
		$student = (int) BFTD_CPT::student_id( $session_id );
		$date    = (string) BFTD_Fields::get( $session_id, 'session', 'session_date' );
		$time    = (string) BFTD_Fields::get( $session_id, 'session', 'session_time' );
		if ( ! $student || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) return new WP_Error( 'bftd_appt', 'The session has no student or date.' );
		if ( $date > BFTD_Time::today() ) return new WP_Error( 'bftd_appt', 'A session for a later day needs its appointment chosen.' );

		$taken = self::linked_keys( $student );
		$best  = null;
		$want  = preg_match( '/^\d{2}:\d{2}$/', $time ) ? BFTD_Schedule::minutes_of( $time ) : 0;
		foreach ( BFTD_Schedule::occurrences( $student, $date, $date, 50 ) as $o ) {
			if ( 'assessment' === ( $o['type'] ?? '' ) || isset( $taken[ $o['key'] ] ) ) continue;
			$gap = abs( BFTD_Schedule::minutes_of( $o['time'] ) - $want );
			if ( null === $best || $gap < $best[0] ) $best = array( $gap, $o['key'] );
		}
		if ( $best ) return self::link( $session_id, $student, $best[1] );

		if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) return new WP_Error( 'bftd_appt', 'The session has no time to make its appointment at.' );
		$tutor = (int) BFTD_Fields::get( $session_id, 'session', 'delivered_by' );
		return self::create_and_link( $session_id, $student, $date, $time, 50, $tutor );
	}

	public static function ajax_list() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$student = absint( $_POST['student_id'] ?? 0 );
		if ( ! $student || ! BFTD_Roles::is_staff() || ! BFTD_Access::can_staff_view( $student ) ) wp_send_json_success( array( 'appts' => array() ) );
		wp_send_json_success( array( 'appts' => self::choices( $student, absint( $_POST['session_id'] ?? 0 ) ) ) );
	}

	/** The session screen's Appointment, in place of typed Date and Time. */
	public static function render( $post ) {
		$student = BFTD_CPT::student_id( $post->ID );
		if ( ! $student && isset( $_GET['student'] ) ) $student = absint( $_GET['student'] );
		$key     = self::key_of( $post->ID );
		$asked   = isset( $_GET['appt'] ) ? sanitize_text_field( wp_unslash( $_GET['appt'] ) ) : '';
		$date    = (string) BFTD_Fields::get( $post->ID, 'session', 'session_date' );
		$time    = (string) BFTD_Fields::get( $post->ID, 'session', 'session_time' );
		$o       = '' !== $key && $student ? self::appointment( $student, $key ) : null;
		$locked  = 'publish' === $post->post_status && '' !== $key;
		$list    = $student ? self::choices( $student, $post->ID ) : array();
		$tutors  = class_exists( 'BFTD_Calendar_View' ) ? BFTD_Calendar_View::tutors_for( get_current_user_id() ) : array();
		$err     = get_transient( self::ERR . get_current_user_id() );
		if ( $err ) delete_transient( self::ERR . get_current_user_id() );
		?>
		<div class="bftd-box bftd-appt" data-session="<?php echo (int) $post->ID; ?>">
			<h4>Appointment</h4>
			<?php if ( $err ) : ?><p class="bftd-appt-err" role="alert"><?php echo esc_html( $err ); ?></p><?php endif; ?>
			<p class="bftd-appt-now"><?php
				if ( $o ) echo '<strong>' . esc_html( self::label( $o ) ) . '</strong>';
				elseif ( '' !== $key ) echo '<strong>' . esc_html( BFTD_Time::day( substr( $key, 0, 10 ) ) . ', ' . BFTD_Schedule::pretty_time( substr( $key, 11 ) ) ) . '</strong> (no longer on the schedule)';
				elseif ( '' !== $date ) echo esc_html( BFTD_Time::day( $date ) . ( $time ? ', ' . BFTD_Schedule::pretty_time( $time ) : '' ) ) . '. No appointment linked.';
				else echo 'No appointment linked yet. The session\'s date and time come from it.';
			?></p>
			<?php // Read by the attendance fill (1.211); not posted, the date is the appointment's. ?>
			<input type="hidden" id="bftd_session_session_date" value="<?php echo esc_attr( $date ); ?>">
			<?php if ( ! $locked ) : ?>
				<label class="bftd-label" for="bftd-appt-pick"><?php echo '' !== $key ? 'Change the appointment' : 'Link an appointment'; ?></label>
				<select id="bftd-appt-pick" name="bftd_appt">
					<option value=""><?php echo '' !== $key ? 'Keep this one' : ( $student ? 'Choose an appointment' : 'Choose the student first' ); ?></option>
					<?php foreach ( $list as $a ) : if ( $a['mine'] ) continue; ?>
						<option value="<?php echo esc_attr( $a['key'] ); ?>" data-date="<?php echo esc_attr( $a['date'] ); ?>"<?php selected( $asked, $a['key'] ); ?>><?php echo esc_html( $a['label'] ); ?></option>
					<?php endforeach; ?>
					<option value="new">New appointment…</option>
				</select>
				<div class="bftd-appt-new" hidden>
					<div class="bftd-grid">
						<p><label class="bftd-label" for="bftd-appt-date">Date</label><input type="date" id="bftd-appt-date" name="bftd_appt_new[date]" value="<?php echo esc_attr( BFTD_Time::today() ); ?>"></p>
						<p><label class="bftd-label" for="bftd-appt-time">Time</label><input type="time" id="bftd-appt-time" name="bftd_appt_new[time]" step="300"></p>
						<p><label class="bftd-label" for="bftd-appt-min">Minutes</label><input type="number" id="bftd-appt-min" name="bftd_appt_new[minutes]" value="50" min="15" step="5"></p>
						<?php if ( count( $tutors ) > 1 ) : ?>
							<p><label class="bftd-label" for="bftd-appt-tutor">Tutor</label><select id="bftd-appt-tutor" name="bftd_appt_new[tutor]">
								<option value="0">Who taught it</option>
								<?php foreach ( $tutors as $uid => $name ) : ?><option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?>
							</select></p>
						<?php endif; ?>
					</div>
					<p class="description">Added to the student's schedule under the Calendar's rules. For a later day it waits in the Calendar's banner to be confirmed; for today or earlier it is kept here, with no notice and nothing sent to GoHighLevel.</p>
				</div>
				<p class="description">Saving links it, and the session takes its date and time. A session is published only once it is linked.</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
