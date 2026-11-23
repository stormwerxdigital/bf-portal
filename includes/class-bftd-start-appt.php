<?php
/**
 * A student's start date comes from an appointment on the Calendar (Karl,
 * 1.217: "since we have booking, start date should be tied to an appointment
 * in the calendar we can link to. if no calendar available, create a new
 * appointment draft").
 *
 * History's Attending from becomes a choice of the student's appointments,
 * or a new one. Linking copies the appointment's date into Attending from,
 * which the Attending to forecast counts from. The link is the
 * appointment's key on the student, as with a session's (1.213).
 *
 * A new appointment is a one-time booking under the Calendar's rules: for a
 * later day it waits in the "schedule changes not sent" banner, a draft
 * until confirmed; for today or earlier it is kept here, with no notice and
 * nothing sent to GoHighLevel.
 *
 * A student with Attending from typed before 1.217 keeps it, and is linked
 * on save to an appointment of theirs on that date if there is one.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Start_Appt {

	const KEY  = '_bftd_start_appt';     // on the student: "Y-m-d H:i"
	const ERR  = 'bftd_start_err_';
	const BACK = 400;                    // days of appointments offered behind today
	const AHEAD = 180;                   // and ahead

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 25, 2 );   // after the fields (10), before the forecast (30)
	}

	public static function key_of( $student_id ) {
		return (string) get_post_meta( (int) $student_id, self::KEY, true );
	}

	/** The student's appointments around today, oldest first, any type (a reading assessment can be the start). */
	public static function choices( $student_id ) {
		$out   = array();
		$today = BFTD_Time::today();
		foreach ( BFTD_Schedule::occurrences( (int) $student_id, BFTD_Time::add_days( $today, -self::BACK ), BFTD_Time::add_days( $today, self::AHEAD ), 400 ) as $o ) {
			if ( 'moved' === $o['status'] ) continue;
			$label = class_exists( 'BFTD_Session_Appt' ) ? BFTD_Session_Appt::label( $o ) : $o['date'] . ' ' . $o['time'];
			if ( 'assessment' === ( $o['type'] ?? '' ) ) $label .= ', reading assessment';
			$out[] = array( 'key' => $o['key'], 'date' => $o['date'], 'label' => $label );
		}
		return $out;
	}

	/** One appointment of the student by its key, or null. */
	public static function appointment( $student_id, $key ) {
		if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}$/', (string) $key, $m ) ) return null;
		foreach ( BFTD_Schedule::occurrences( (int) $student_id, $m[1], $m[1], 50 ) as $o ) {
			if ( $o['key'] === $key && 'moved' !== $o['status'] ) return $o;
		}
		return null;
	}

	/** Link the student's start to an appointment, taking its date. */
	public static function link( $student_id, $key ) {
		$o = self::appointment( $student_id, $key );
		if ( ! $o ) return new WP_Error( 'bftd_start', 'That appointment is not on this student\'s schedule.' );
		update_post_meta( (int) $student_id, self::KEY, $o['key'] );
		BFTD_Fields::set( (int) $student_id, 'student', 'attend_from', $o['date'] );
		return true;
	}

	/** A new one-time appointment for the student's start, then linked. Today or earlier is kept here. */
	public static function create_and_link( $student_id, $date, $time, $minutes, $tutor ) {
		$past = (string) $date <= BFTD_Time::today();
		$r = BFTD_Schedule::add_booking( (int) $student_id, array(
			'from' => $date, 'time' => $time, 'minutes' => $minutes ? (int) $minutes : 50,
			'repeat' => 'once', 'type' => 'tutoring', 'tutor' => (int) $tutor, 'local' => $past ? 1 : 0,
		), $past );
		if ( is_wp_error( $r ) ) return $r;
		return self::link( $student_id, $r['from'] . ' ' . $r['time'] );
	}

	/**
	 * With no link, an appointment of the student's on the Attending from
	 * date already typed, the earliest that day. True once linked.
	 */
	public static function auto_link( $student_id ) {
		if ( '' !== self::key_of( $student_id ) ) return true;
		$from = (string) BFTD_Fields::get( (int) $student_id, 'student', 'attend_from' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) return false;
		foreach ( BFTD_Schedule::occurrences( (int) $student_id, $from, $from, 50 ) as $o ) {
			if ( 'moved' === $o['status'] ) continue;
			return true === self::link( $student_id, $o['key'] );
		}
		return false;
	}

	public static function on_save( $post_id, $post ) {
		if ( BFTD_CPT::STUDENT !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) return;
		if ( ! isset( $_POST['bftd_start_appt'] ) ) return;   // only from the edit screen's form
		$pick = sanitize_text_field( wp_unslash( $_POST['bftd_start_appt'] ) );
		$r    = true;
		$was  = (string) BFTD_Fields::get( $post_id, 'student', 'attend_from' );
		if ( 'new' === $pick ) {
			$n = isset( $_POST['bftd_start_new'] ) && is_array( $_POST['bftd_start_new'] ) ? wp_unslash( $_POST['bftd_start_new'] ) : array();
			$r = self::create_and_link( $post_id, sanitize_text_field( $n['date'] ?? '' ), sanitize_text_field( $n['time'] ?? '' ), absint( $n['minutes'] ?? 50 ), absint( $n['tutor'] ?? 0 ) );
		} elseif ( '' !== $pick && $pick !== self::key_of( $post_id ) ) {
			$r = self::link( $post_id, $pick );
		} elseif ( '' === $pick ) {
			self::auto_link( $post_id );
		}
		if ( is_wp_error( $r ) ) set_transient( self::ERR . get_current_user_id(), $r->get_error_message(), 60 );
		// A new start goes on to GoHighLevel, which was sent the old one earlier in this save.
		if ( $was !== (string) BFTD_Fields::get( $post_id, 'student', 'attend_from' ) && class_exists( 'BFTD_GHL' ) && class_exists( 'BFTD_CRM' ) && BFTD_GHL::configured() ) {
			$rid = BFTD_CRM::linked( $post_id );
			if ( '' !== $rid ) BFTD_GHL::send_changes( $post_id, $rid );
		}
	}

	/** History's Attending from: the linked appointment, or a choice of them, or a new one. */
	public static function render_field( $post, $field ) {
		$sid    = (int) $post->ID;
		$key    = self::key_of( $sid );
		$from   = (string) BFTD_Fields::get( $sid, 'student', 'attend_from' );
		$o      = '' !== $key ? self::appointment( $sid, $key ) : null;
		$list   = self::choices( $sid );
		$tutors = class_exists( 'BFTD_Calendar_View' ) ? BFTD_Calendar_View::tutors_for( get_current_user_id() ) : array();
		$err    = get_transient( self::ERR . get_current_user_id() );
		if ( $err ) delete_transient( self::ERR . get_current_user_id() );
		?>
		<div class="bftd-field bftd-start">
			<label class="bftd-label" for="bftd-start-pick"><?php echo esc_html( $field['label'] ?? 'Attending from' ); ?></label>
			<?php if ( $err ) : ?><p class="bftd-appt-err" role="alert"><?php echo esc_html( $err ); ?></p><?php endif; ?>
			<p class="bftd-start-now"><?php
				if ( $o && class_exists( 'BFTD_Session_Appt' ) ) echo '<strong>' . esc_html( BFTD_Session_Appt::label( $o ) ) . '</strong>';
				elseif ( '' !== $key ) echo '<strong>' . esc_html( BFTD_Time::day( substr( $key, 0, 10 ) ) ) . '</strong> (no longer on the schedule)';
				elseif ( '' !== $from ) echo esc_html( BFTD_Time::day( $from ) ) . '. No appointment linked.';
				else echo 'No start yet. It comes from an appointment.';
			?></p>
			<select id="bftd-start-pick" name="bftd_start_appt">
				<option value=""><?php echo '' !== $key ? 'Keep this one' : 'Choose their first appointment'; ?></option>
				<?php foreach ( $list as $a ) : if ( $a['key'] === $key ) continue; ?>
					<option value="<?php echo esc_attr( $a['key'] ); ?>"><?php echo esc_html( $a['label'] ); ?></option>
				<?php endforeach; ?>
				<option value="new">New appointment…</option>
			</select>
			<div class="bftd-start-new" hidden>
				<p><label class="bftd-label" for="bftd-start-date">Date</label><input type="date" id="bftd-start-date" name="bftd_start_new[date]" value="<?php echo esc_attr( '' !== $from ? $from : BFTD_Time::today() ); ?>"></p>
				<p><label class="bftd-label" for="bftd-start-time">Time</label><input type="time" id="bftd-start-time" name="bftd_start_new[time]" step="300"></p>
				<p><label class="bftd-label" for="bftd-start-min">Minutes</label><input type="number" id="bftd-start-min" name="bftd_start_new[minutes]" value="50" min="15" step="5"></p>
				<?php if ( count( $tutors ) > 1 ) : ?>
					<p><label class="bftd-label" for="bftd-start-tutor">Tutor</label><select id="bftd-start-tutor" name="bftd_start_new[tutor]">
						<option value="0">Assigned tutor</option>
						<?php foreach ( $tutors as $uid => $name ) : ?><option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?>
					</select></p>
				<?php endif; ?>
				<p class="description">A later day waits in the Calendar's banner to be confirmed. Today or earlier is kept here: no notice, nothing sent to GoHighLevel.</p>
			</div>
			<p class="description">Their start, from the Calendar. Attending to is forecast from it.</p>
		</div>
		<?php
	}
}
