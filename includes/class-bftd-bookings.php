<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sessions booked into GoHighLevel, booking phase 2 (1.189).
 *
 * The portal already has the schedule (BFTD_Schedule): weekly slots, a
 * session cancelled or moved, and the pattern stopping where the paid
 * sessions run out. That stays the one place a schedule is made. What this
 * adds is its mirror in GoHighLevel, where the reminders come from: each
 * upcoming session is an appointment on its tutor's GoHighLevel calendar
 * (Settings, Calendars), with the caregiver chosen for reminders as the
 * contact, the student's name in the title, and the location and notes.
 *
 * Karl's rules (16 Nov 2026): one caregiver per booking, the appointment's
 * contact; times sent with our offset written out, so a session lands at the
 * right moment whatever GoHighLevel's time zone says.
 *
 * The mirror is kept by comparing what should be booked with what was sent:
 * a new session is created, a changed one updated (a moved session keeps
 * its appointment, rescheduled, so the family hears "moved", not "cancelled"
 * and "booked"), and one no longer on the schedule cancelled. It runs when
 * the student is saved, when a session is cancelled or moved, and hourly to
 * roll the window forward. Draft students are not sent: nothing reaches a
 * family about a draft.
 */
class BFTD_Bookings {

	/** Per student: who is reminded, where, and notes. */
	const PREFS_KEY = '_bftd_booking_prefs';

	/** Per student: occurrence key => what was sent to GoHighLevel. */
	const MAP_KEY = '_bftd_ghl_appts';

	/** Per student: the last sync, for the box. */
	const STATE_KEY = '_bftd_ghl_booking_state';

	/** Per caregiver: their GoHighLevel contact id, once known. */
	const CONTACT_KEY = '_bftd_ghl_contact';

	/** How far ahead sessions are booked into GoHighLevel. */
	const WINDOW_DAYS = 60;

	const VERSION = '2021-04-15';

	/**
	 * Nothing here sends by itself any more (1.200): a change is held until
	 * somebody confirms, and sent in hours, by BFTD_Send_Window, which calls
	 * sync_student() when it is time.
	 */
	public static function init() {}

	/* ------------------------------------------------------------------ */
	/* Who is reminded, where                                              */
	/* ------------------------------------------------------------------ */

	public static function prefs( $sid ) {
		$p = get_post_meta( $sid, self::PREFS_KEY, true );
		return wp_parse_args( is_array( $p ) ? $p : array(), array( 'notify' => 0, 'location' => '', 'notes' => '', 'custom' => 0 ) );
	}

	/** From the student's form. The caregiver must be one of the student's. */
	public static function save_prefs( $sid, $raw ) {
		if ( ! is_array( $raw ) ) return false;
		$notify = absint( $raw['notify'] ?? 0 );
		if ( $notify && ! in_array( $notify, array_map( 'intval', BFTD_CPT::client_ids( $sid ) ), true ) ) $notify = 0;
		$new = array(
			'notify'   => $notify,
			'location' => sanitize_text_field( (string) ( $raw['location'] ?? '' ) ),
			'notes'    => sanitize_textarea_field( (string) ( $raw['notes'] ?? '' ) ),
			// A custom schedule: booked outside the tutor's GoHighLevel availability (1.192).
			'custom'   => empty( $raw['custom'] ) ? 0 : 1,
		);
		$old = self::prefs( $sid );
		if ( $old == $new ) return false;   // phpcs:ignore Universal.Operators.StrictComparisons -- same keys, any order
		update_post_meta( $sid, self::PREFS_KEY, $new );
		$u = $notify ? get_userdata( $notify ) : null;
		BFTD_Audit::log( 'booking_prefs', array( 'post_id' => $sid, 'student_id' => $sid,
			'summary' => 'GoHighLevel reminders for this student go to ' . ( $u ? $u->display_name : 'nobody' ) . '.' ) );
		return true;
	}

	/** Where the session is: as typed, or the student's joining link. */
	public static function location( $sid ) {
		$p = self::prefs( $sid );
		if ( '' !== $p['location'] ) return $p['location'];
		return trim( (string) BFTD_Fields::get( $sid, 'student', 'join_url' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Times                                                               */
	/* ------------------------------------------------------------------ */

	/** Our offset on a date, in minutes: the one set on Calendars, else the site's time zone. */
	public static function offset_on( $date ) {
		$o = BFTD_Calendars::offset();
		if ( null !== $o ) return $o;
		$zone = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		$at   = strtotime( $date . ' 12:00:00 UTC' );
		$m    = BFTD_Calendars::zone_offset( $zone, $at );
		return null === $m ? 0 : $m;
	}

	/** "2026-11-17T16:00:00-07:00", the offset written out. */
	public static function iso( $date, $hhmm, $add_minutes = 0 ) {
		$off   = self::offset_on( $date );
		$local = strtotime( $date . ' ' . $hhmm . ':00 UTC' ) + 60 * (int) $add_minutes;   // wall time, as if UTC
		$sign  = $off < 0 ? '-' : '+';
		return gmdate( 'Y-m-d\TH:i:s', $local ) . $sign . sprintf( '%02d:%02d', intdiv( abs( $off ), 60 ), abs( $off ) % 60 );
	}

	/* ------------------------------------------------------------------ */
	/* The caregiver as a GoHighLevel contact                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The caregiver's GoHighLevel contact: the one the sync already knows
	 * for this student, else the one with their email, else made now.
	 *
	 * @return string|WP_Error contact id
	 */
	public static function contact_for( $sid, $uid ) {
		$u = get_userdata( (int) $uid );
		if ( ! $u ) return new WP_Error( 'bftd_nobody', 'the caregiver chosen for reminders no longer has an account' );
		$email = strtolower( trim( (string) $u->user_email ) );
		if ( ! is_email( $email ) ) return new WP_Error( 'bftd_no_email', $u->display_name . ' has no email, so GoHighLevel cannot remind them' );

		$known = (string) get_user_meta( $u->ID, self::CONTACT_KEY, true );
		if ( '' !== $known ) return $known;

		$people = BFTD_GHL::people( $sid );
		foreach ( (array) ( $people['contacts'] ?? array() ) as $c ) {
			if ( $email === ( $c['email'] ?? '' ) && '' !== (string) $c['id'] ) {
				update_user_meta( $u->ID, self::CONTACT_KEY, (string) $c['id'] );
				return (string) $c['id'];
			}
		}

		list( , $data, $err ) = BFTD_GHL::request( 'GET', '/contacts/search/duplicate', array( 'locationId' => BFTD_GHL::location(), 'email' => $email ), null, '2021-07-28' );
		$id = '' === $err ? (string) ( $data['contact']['id'] ?? '' ) : '';
		if ( '' === $id ) {
			list( , $data, $err ) = BFTD_GHL::request( 'POST', '/contacts/', array(), array(
				'locationId' => BFTD_GHL::location(),
				'firstName'  => (string) $u->first_name,
				'lastName'   => (string) $u->last_name,
				'name'       => (string) $u->display_name,
				'email'      => $email,
			), '2021-07-28' );
			if ( '' !== $err ) return new WP_Error( 'bftd_contact', 'GoHighLevel would not add ' . $u->display_name . ' as a contact: ' . $err );
			$id = (string) ( $data['contact']['id'] ?? $data['id'] ?? '' );
			if ( '' === $id ) return new WP_Error( 'bftd_contact', 'GoHighLevel did not say which contact it made for ' . $u->display_name );
			BFTD_Audit::log( 'crm_contact_made', array( 'post_id' => $sid, 'student_id' => $sid,
				'summary' => $u->display_name . ' was added to GoHighLevel as a contact, for session reminders.' ) );
		}
		update_user_meta( $u->ID, self::CONTACT_KEY, $id );
		return $id;
	}

	/* ------------------------------------------------------------------ */
	/* The mirror                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * What GoHighLevel should hold for this student: key => appointment.
	 * Booked sessions from today through the window, each on its tutor's
	 * calendar. Sessions that cannot go (no calendar) are listed in $why.
	 */
	public static function wanted( $sid, $contact, &$why ) {
		$why   = array();
		$out   = array();
		$read  = BFTD_Calendars::read();
		$name  = (string) get_post_field( 'post_title', $sid );
		$where = self::location( $sid );
		$today = BFTD_Time::today();
		$until = BFTD_Time::add_days( $today, self::WINDOW_DAYS );
		foreach ( BFTD_Schedule::occurrences( $sid, $today, $until, 200 ) as $o ) {
			if ( 'booked' !== $o['status'] ) continue;
			$cal = BFTD_Calendars::calendar_for( (int) $o['tutor'] );
			if ( '' === $cal ) {
				$u = $o['tutor'] ? get_userdata( (int) $o['tutor'] ) : null;
				$why[ $o['key'] ] = $u ? $u->display_name . ' has no GoHighLevel calendar linked' : 'no tutor is assigned';
				continue;
			}
			$members = $read['calendars'][ $cal ]['members'] ?? array();
			$out[ $o['key'] ] = array(
				'calendar' => $cal,
				'user'     => (string) ( $members[0] ?? '' ),
				'contact'  => (string) $contact,
				'start'    => self::iso( $o['date'], $o['time'] ),
				'end'      => self::iso( $o['date'], $o['time'], (int) $o['minutes'] ),
				// The type leads the title, so it reads right in GoHighLevel (1.203).
				'title'    => ( 'assessment' === ( $o['type'] ?? '' ) ? 'Reading assessment: ' : 'Reading session: ' ) . $name,
				'address'  => $where,
				'from'     => (string) ( $o['from_key'] ?? '' ),
				// A one-time session is booked at whatever time was picked,
				// outside the tutor's GoHighLevel hours too (Karl, 1.201); the
				// portal still stops it running over another session.
				'custom'   => ( self::prefs( $sid )['custom'] || ! empty( $o['once'] ) ) ? 1 : 0,
			);
		}
		return $out;
	}

	/** The fields that, when they change, mean the appointment is changed. */
	private static function same( $a, $b ) {
		foreach ( array( 'calendar', 'contact', 'start', 'end', 'title', 'address' ) as $k ) {
			if ( (string) ( $a[ $k ] ?? '' ) !== (string) ( $b[ $k ] ?? '' ) ) return false;
		}
		return true;
	}

	private static function body( $w, $notify = true ) {
		$b = array(
			'calendarId'               => $w['calendar'],
			'locationId'               => BFTD_GHL::location(),
			'contactId'                => $w['contact'],
			'startTime'                => $w['start'],
			'endTime'                  => $w['end'],
			'title'                    => $w['title'],
			'appointmentStatus'        => 'confirmed',
			'meetingLocationType'      => 'custom',
			'address'                  => $w['address'],
			// GoHighLevel's availability and booking rules decide (Karl, 1.192):
			// a time outside the tutor's hours, or over the calendar's limits,
			// is turned away there, and the session says why here. A student
			// on a custom schedule is booked whatever GoHighLevel's hours say.
			// The portal's own two rules (no two sessions at once, ten a day)
			// apply either way, when the schedule is saved or a session moved.
			'ignoreDateRange'          => ! empty( $w['custom'] ),
			'ignoreFreeSlotValidation' => ! empty( $w['custom'] ),
			'toNotify'                 => (bool) $notify,
		);
		if ( '' !== $w['user'] ) $b['assignedUserId'] = $w['user'];
		return $b;
	}

	/**
	 * Make GoHighLevel hold what this student's schedule says.
	 *
	 * @return array the state: when, error, made, moved, cancelled, waiting (key => why)
	 */
	public static function sync_student( $sid ) {
		$sid   = (int) $sid;
		$state = array( 'when' => time(), 'error' => '', 'made' => 0, 'moved' => 0, 'cancelled' => 0, 'waiting' => array() );
		if ( ! BFTD_GHL::configured() || BFTD_CPT::STUDENT !== get_post_type( $sid ) ) return $state;
		$map = get_post_meta( $sid, self::MAP_KEY, true );
		$map = is_array( $map ) ? $map : array();

		$prefs = self::prefs( $sid );
		$live  = 'publish' === get_post_status( $sid );
		// In the trash: everything still ahead is cancelled (1.200; it was
		// done the moment the student was trashed, at any hour).
		$gone  = 'trash' === get_post_status( $sid );
		if ( ! $gone && ( ! $live || ! $prefs['notify'] ) ) {
			// Nothing is sent; anything sent before stays until the schedule changes it.
			$state['error'] = ! $live ? 'Not sent to GoHighLevel while the student is a draft.' : 'Not sent to GoHighLevel: choose who is reminded first.';
			if ( ! $map ) { update_post_meta( $sid, self::STATE_KEY, $state ); return $state; }
			if ( ! $live ) { update_post_meta( $sid, self::STATE_KEY, $state ); return $state; }
		}

		$contact = '';
		if ( $prefs['notify'] && ! $gone ) {
			$c = self::contact_for( $sid, $prefs['notify'] );
			if ( is_wp_error( $c ) ) {
				$state['error'] = 'Not sent to GoHighLevel: ' . $c->get_error_message() . '.';
				update_post_meta( $sid, self::STATE_KEY, $state );
				return $state;
			}
			$contact = $c;
		}

		$why    = array();
		$wanted = $contact ? self::wanted( $sid, $contact, $why ) : array();
		$state['waiting'] = $why;
		$today  = BFTD_Time::today();

		// A moved session takes its old appointment with it.
		foreach ( $wanted as $key => $w ) {
			if ( ! isset( $map[ $key ] ) && '' !== $w['from'] && isset( $map[ $w['from'] ] ) && ! isset( $wanted[ $w['from'] ] ) ) {
				$map[ $key ] = $map[ $w['from'] ];
				unset( $map[ $w['from'] ] );
			}
		}

		// Only the next session is confirmed to the family; the rest are booked
		// quietly, so turning reminders on is one email, not one per session
		// (Karl, 1.191). A move is always told.
		$next = $wanted ? (string) array_key_first( $wanted ) : '';

		$fail = array();
		foreach ( $wanted as $key => $w ) {
			$had = $map[ $key ] ?? null;
			if ( $had && self::same( $had, $w ) ) continue;
			if ( $had && ! empty( $had['id'] ) ) {
				list( , , $err ) = BFTD_GHL::request( 'PUT', '/calendars/events/appointments/' . rawurlencode( $had['id'] ), array(), self::body( $w ), self::VERSION );
				if ( '' !== $err ) { $fail[] = $err; $state['waiting'][ $key ] = 'GoHighLevel would not move it: ' . $err; continue; }
				$map[ $key ] = array_merge( $w, array( 'id' => $had['id'] ) );
				$state['moved']++;
			} else {
				list( , $data, $err ) = BFTD_GHL::request( 'POST', '/calendars/events/appointments', array(), self::body( $w, $key === $next ), self::VERSION );
				$id = (string) ( $data['id'] ?? $data['appointment']['id'] ?? $data['event']['id'] ?? '' );
				if ( '' !== $err || '' === $id ) {
					$fail[] = '' !== $err ? $err : 'no appointment id came back';
					$state['waiting'][ $key ] = 'GoHighLevel would not book it: ' . end( $fail );
					continue;
				}
				$map[ $key ] = array_merge( $w, array( 'id' => $id ) );
				$state['made']++;
			}
		}

		// Sent before and no longer on the schedule: cancelled, if it is still ahead.
		foreach ( $map as $key => $had ) {
			if ( isset( $wanted[ $key ] ) ) continue;
			$date = substr( (string) $key, 0, 10 );
			if ( $date >= $today && ! empty( $had['id'] ) ) {
				list( , , $err ) = BFTD_GHL::request( 'PUT', '/calendars/events/appointments/' . rawurlencode( $had['id'] ), array(), array( 'appointmentStatus' => 'cancelled' ), self::VERSION );
				if ( '' !== $err ) { $fail[] = $err; continue; }
				$state['cancelled']++;
			}
			unset( $map[ $key ] );
		}

		update_post_meta( $sid, self::MAP_KEY, $map );
		if ( $fail ) $state['error'] = 'GoHighLevel did not take ' . count( $fail ) . ' of the changes: ' . $fail[0];
		if ( $state['made'] || $state['moved'] || $state['cancelled'] ) {
			$bits = array();
			if ( $state['made'] )      $bits[] = $state['made'] . ' booked';
			if ( $state['moved'] )     $bits[] = $state['moved'] . ' changed';
			if ( $state['cancelled'] ) $bits[] = $state['cancelled'] . ' cancelled';
			BFTD_Audit::log( 'crm_booked', array( 'post_id' => $sid, 'student_id' => $sid, 'summary' => 'Sessions in GoHighLevel: ' . implode( ', ', $bits ) . '.' ) );
		}
		update_post_meta( $sid, self::STATE_KEY, $state );
		return $state;
	}

	/**
	 * Delete one session's appointment in GoHighLevel, now and quietly
	 * (1.201). Deleted, not cancelled: no status change for a workflow to
	 * act on, and nothing sent. The appointment is found under the session's
	 * key, or under the date it was moved from if the move has not been sent.
	 * Already gone in GoHighLevel counts as done.
	 *
	 * @return true|WP_Error
	 */
	public static function delete_quietly( $sid, $o ) {
		$map = get_post_meta( $sid, self::MAP_KEY, true );
		$map = is_array( $map ) ? $map : array();
		$at  = '';
		foreach ( array( (string) $o['key'], (string) ( $o['from_key'] ?? '' ) ) as $k ) {
			if ( '' !== $k && isset( $map[ $k ] ) ) { $at = $k; break; }
		}
		if ( '' === $at ) return true;   // never sent, so nothing there to delete

		$id = (string) ( $map[ $at ]['id'] ?? '' );
		if ( '' !== $id && BFTD_GHL::configured() ) {
			list( $code, , $err ) = BFTD_GHL::request( 'DELETE', '/calendars/events/' . rawurlencode( $id ), array(), null, self::VERSION );
			if ( '' !== $err && 404 !== (int) $code ) {
				return new WP_Error( 'bftd_ghl_delete', 'GoHighLevel would not delete the appointment, so the session is still booked: ' . $err );
			}
		}
		unset( $map[ $at ] );
		update_post_meta( $sid, self::MAP_KEY, $map );
		BFTD_Audit::log( 'crm_booked', array( 'post_id' => $sid, 'student_id' => $sid, 'summary' => 'Sessions in GoHighLevel: 1 deleted, with no notification.' ) );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* On the student's Sessions and schedule box                          */
	/* ------------------------------------------------------------------ */

	/** Beside a booked session in the list: whether GoHighLevel has it. */
	public static function occ_mark( $sid, $o ) {
		if ( 'booked' !== $o['status'] || ! BFTD_GHL::configured() ) return '';
		$map = get_post_meta( $sid, self::MAP_KEY, true );
		if ( is_array( $map ) && isset( $map[ $o['key'] ] ) ) return '<span class="bftd-occ-ghl">In GoHighLevel</span>';
		$state = get_post_meta( $sid, self::STATE_KEY, true );
		$why   = is_array( $state ) ? ( $state['waiting'][ $o['key'] ] ?? '' ) : '';
		return '' !== $why ? '<span class="bftd-occ-ghl is-not">Not in GoHighLevel: ' . esc_html( $why ) . '</span>' : '';
	}

	public static function render_box( $post ) {
		if ( ! BFTD_GHL::configured() ) return;
		$p       = self::prefs( $post->ID );
		$clients = BFTD_CPT::client_ids( $post->ID );
		$join    = trim( (string) BFTD_Fields::get( $post->ID, 'student', 'join_url' ) );
		$state   = get_post_meta( $post->ID, self::STATE_KEY, true );
		?>
		<h4>Reminders from GoHighLevel</h4>
		<p class="description">Each session below is booked on its tutor's GoHighLevel calendar, which sends the reminders. Choose who
			is reminded; they are the appointment's contact in GoHighLevel. Sessions are booked <?php echo (int) self::WINDOW_DAYS; ?> days
			ahead, and a cancelled or rescheduled one changes there too.</p>
		<div class="bftd-grid">
			<div class="bftd-field">
				<label class="bftd-label" for="bftd-booking-notify">Who is reminded</label>
				<select id="bftd-booking-notify" name="bftd_booking[notify]">
					<option value="0">Nobody yet, so nothing is booked</option>
					<?php foreach ( $clients as $uid ) : $u = get_userdata( $uid ); if ( ! $u ) continue; ?>
						<option value="<?php echo (int) $uid; ?>"<?php selected( (int) $p['notify'], (int) $uid ); ?>><?php echo esc_html( $u->display_name . ( is_email( $u->user_email ) ? '' : ' (no email)' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="bftd-field">
				<label class="bftd-label" for="bftd-booking-location">Where</label>
				<input type="text" id="bftd-booking-location" name="bftd_booking[location]" value="<?php echo esc_attr( $p['location'] ); ?>"
					placeholder="<?php echo esc_attr( '' !== $join ? $join : 'An address, or a link' ); ?>">
				<p class="description"><?php echo '' !== $join ? 'Left empty, the joining link is used.' : 'An address, or a meeting link.'; ?></p>
			</div>
			<div class="bftd-field">
				<label class="bftd-label" for="bftd-booking-notes">Notes for the booking</label>
				<textarea id="bftd-booking-notes" name="bftd_booking[notes]" rows="2"><?php echo esc_textarea( $p['notes'] ); ?></textarea>
				<p class="description">Kept here with the schedule.</p>
			</div>
			<div class="bftd-field">
				<span class="bftd-label">GoHighLevel availability</span>
				<label><input type="checkbox" name="bftd_booking[custom]" value="1"<?php checked( ! empty( $p['custom'] ) ); ?>>
					Custom schedule: book outside the tutor's GoHighLevel hours</label>
				<p class="description">Left unticked, GoHighLevel's availability and booking rules decide, and a time it turns away
					says so beside the session. One-time sessions are always booked at the time picked. Either way a tutor never has
				two sessions at once, or more than <?php echo (int) BFTD_Schedule::daily_cap(); ?> in a day.</p>
			</div>
		</div>
		<?php
		$held = class_exists( 'BFTD_Send_Window' ) ? ( BFTD_Send_Window::pending()[ $post->ID ] ?? null ) : null;
		if ( $held ) : ?>
			<p class="bftd-cal-warn"><?php echo esc_html( 'queued' === $held['state']
				? 'Changes confirmed, to be sent to GoHighLevel at ' . BFTD_Send_Window::when( (int) $held['send_at'] ) . '.'
				: 'Changes not sent to GoHighLevel yet. Families are told once somebody confirms them on the Calendar.' ); ?>
				<?php if ( 'queued' !== $held['state'] ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Calendar_View::SLUG ) ); ?>">Open the Calendar to confirm</a><?php endif; ?></p>
		<?php endif; ?>
		<?php if ( is_array( $state ) && ! empty( $state['when'] ) ) : ?>
			<p class="<?php echo '' !== $state['error'] ? 'bftd-cal-warn' : 'description'; ?>"><?php
				echo esc_html( '' !== $state['error'] ? $state['error'] : 'In step with GoHighLevel.' );
				echo esc_html( ' Checked ' . BFTD_Time::format( 'j M Y, g:i a', (int) $state['when'] ) . '.' );
			?></p>
		<?php endif; ?>
		<?php
	}
}
