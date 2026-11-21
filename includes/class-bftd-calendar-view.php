<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The calendar: every session by day, week or month (Karl, 1.193).
 *
 * Managers, senior managers and administrators see every tutor, with a
 * filter, the way GoHighLevel's Calendar View does; a tutor sees their own
 * sessions only. What is shown is the portal's schedule (BFTD_Schedule), the
 * one place sessions are made, so it is always the same as each student's
 * list: booked, taught and cancelled. Alongside it, appointments made in
 * GoHighLevel itself on a tutor's linked calendar (an assessment booked
 * through a link, say), marked as GoHighLevel's and not changed here.
 *
 * A session can be rescheduled or cancelled from here, through the same
 * action the student's list uses, so the same rules apply (no two sessions
 * at once, ten a day) and GoHighLevel's copy follows.
 */
class BFTD_Calendar_View {

	const SLUG = 'bftd-calendar';

	/** GoHighLevel's appointments for a calendar and range, kept five minutes. */
	const CACHE = 'bftd_cal_ghl_';

	/** The most days one request may ask for: a month view's six weeks. */
	const MAX_DAYS = 42;

	/** A staff member's chosen calendar colour, a key of colours() (1.209.1). */
	const COLOUR_KEY = 'bftd_calendar_colour';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_bftd_calendar_events', array( __CLASS__, 'ajax_events' ) );
		add_action( 'wp_ajax_bftd_calendar_ghl', array( __CLASS__, 'ajax_ghl' ) );
		add_action( 'wp_ajax_bftd_calendar_book', array( __CLASS__, 'ajax_book' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'colour_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'colour_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_colour' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_colour' ) );
	}

	/**
	 * The eight calendar colours, numbered as the stylesheet numbers them
	 * (data-t 0 to 7). Staff without a set colour get the one for their place
	 * in the tutor list, as before 1.209.1.
	 */
	public static function colours() {
		return array( 0 => 'Purple', 1 => 'Blue', 2 => 'Green', 3 => 'Orange', 4 => 'Plum', 5 => 'Teal', 6 => 'Slate', 7 => 'Olive' );
	}

	/** Each staff member's colour number, by user id, for ids in list order. */
	public static function colour_map( $user_ids ) {
		$out = array();
		$i   = 0;
		foreach ( $user_ids as $uid ) {
			$set = get_user_meta( (int) $uid, self::COLOUR_KEY, true );
			$out[ (int) $uid ] = ( is_numeric( $set ) && isset( self::colours()[ (int) $set ] ) ) ? (int) $set : $i % 8;
			$i++;
		}
		return $out;
	}

	/**
	 * Calendar colour on a staff member's profile. Set by senior managers and
	 * administrators only (Karl, 1.209.1: "We need to be able to set the color
	 * per staff member for Sr Managers and Admins").
	 */
	public static function colour_field( $user ) {
		if ( ! BFTD_Roles::can_administer() || ! BFTD_Roles::is_staff( $user->ID ) ) return;
		$set = get_user_meta( $user->ID, self::COLOUR_KEY, true );
		?>
		<h2>Calendar</h2>
		<table class="form-table" role="presentation"><tr>
			<th><label for="bftd-calendar-colour">Calendar colour</label></th>
			<td><select id="bftd-calendar-colour" name="bftd_calendar_colour">
				<option value="">By place in the tutor list</option>
				<?php foreach ( self::colours() as $k => $name ) : ?>
					<option value="<?php echo (int) $k; ?>"<?php selected( (string) $set, (string) $k ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description">The colour of this person's bookings and swatch on the Calendar.</p></td>
		</tr></table>
		<?php
	}

	public static function save_colour( $user_id ) {
		if ( ! BFTD_Roles::can_administer() || ! current_user_can( 'edit_user', $user_id ) ) return;
		if ( ! BFTD_Roles::is_staff( $user_id ) || ! isset( $_POST['bftd_calendar_colour'] ) ) return;
		$v = sanitize_text_field( wp_unslash( $_POST['bftd_calendar_colour'] ) );
		if ( '' === $v ) { delete_user_meta( $user_id, self::COLOUR_KEY ); return; }
		if ( ctype_digit( $v ) && isset( self::colours()[ (int) $v ] ) ) update_user_meta( $user_id, self::COLOUR_KEY, (int) $v );
	}

	/**
	 * The students this person may book for: id => name. Everyone for a
	 * manager; for a tutor, the students they can edit (their own).
	 */
	public static function bookable( $viewer ) {
		$ids = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( (array) $ids as $sid ) {
			if ( ! user_can( (int) $viewer, 'edit_post', (int) $sid ) ) continue;
			$out[ (int) $sid ] = (string) get_post_field( 'post_title', $sid ) . ( 'publish' === get_post_status( $sid ) ? '' : ' (draft)' );
		}
		return $out;
	}

	/**
	 * A booking added from the calendar (Karl, 1.203): a student, then the
	 * same choices as the student's own form. Held to the same rules, and
	 * sent to GoHighLevel the same way: once confirmed, in hours.
	 */
	public static function ajax_book() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$viewer = get_current_user_id();
		$sid    = absint( $_POST['student_id'] ?? 0 );
		if ( ! $sid || BFTD_CPT::STUDENT !== get_post_type( $sid ) || ! user_can( $viewer, 'edit_post', $sid ) ) {
			wp_send_json_error( array( 'message' => 'Pick one of your students.' ), 403 );
		}
		$tutor = absint( $_POST['tutor'] ?? 0 );
		// Another tutor for a booking is a manager's choice, as on the calendar's edit.
		if ( $tutor && ( ! BFTD_Roles::can_manage( $viewer ) || ! BFTD_Roles::is_staff( $tutor ) ) ) $tutor = 0;
		$r = BFTD_Schedule::add_booking( $sid, array(
			'from'    => sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ),
			'time'    => sanitize_text_field( wp_unslash( $_POST['time'] ?? '' ) ),
			'minutes' => absint( $_POST['minutes'] ?? 50 ),
			'repeat'  => 'weekly' === ( $_POST['repeat'] ?? '' ) ? 'weekly' : 'once',
			'until'   => sanitize_text_field( wp_unslash( $_POST['until'] ?? '' ) ),
			'type'    => sanitize_key( wp_unslash( $_POST['type'] ?? '' ) ),
			'tutor'   => $tutor,
		) );
		if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ), 409 );
		wp_send_json_success( array( 'booking' => $r ) );
	}

	public static function menu() {
		add_submenu_page( BFTD_Admin::MENU_SLUG, 'Calendar', 'Calendar', BFTD_Roles::STAFF_CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) return;
		wp_enqueue_script( 'bftd-calendar', BFTD_URL . 'assets/js/bftd-calendar.js', array(), BFTD_VERSION, true );
	}

	/**
	 * The tutors this person may see: everyone on staff for a manager,
	 * themselves for a tutor. user id => name.
	 */
	public static function tutors_for( $viewer ) {
		$viewer = (int) $viewer;
		if ( BFTD_Roles::can_manage( $viewer ) ) {
			$out = array();
			foreach ( BFTD_Roles::staff_users() as $u ) $out[ (int) $u->ID ] = (string) $u->display_name;
			return $out;
		}
		$u = get_userdata( $viewer );
		return ( $u && BFTD_Roles::is_staff( $viewer ) ) ? array( $viewer => (string) $u->display_name ) : array();
	}

	/** "16:50", from a start and a length. */
	private static function plus( $hhmm, $minutes ) {
		$m = BFTD_Schedule::minutes_of( $hhmm ) + (int) $minutes;
		return sprintf( '%02d:%02d', intdiv( $m, 60 ) % 24, $m % 60 );
	}

	/**
	 * Every session and GoHighLevel appointment from $from to $to (Y-m-d,
	 * both kept), for the tutors asked for that this person may see.
	 *
	 * @return array of events: kind (session|ghl), title, student, student_id,
	 *               tutor, date, start, end, status, key, in_ghl, can_edit, url
	 */
	public static function events( $from, $to, $tutors, $viewer ) {
		$allowed = array_keys( self::tutors_for( $viewer ) );
		$tutors  = $tutors ? array_values( array_intersect( array_map( 'intval', (array) $tutors ), $allowed ) ) : $allowed;
		if ( ! $tutors ) return array();
		$want = array_fill_keys( $tutors, true );

		$out = array();
		$ids = array();   // GoHighLevel appointments the portal made, so they are not listed twice
		foreach ( BFTD_Schedule::scheduled_students() as $sid ) {
			$map = get_post_meta( $sid, BFTD_Bookings::MAP_KEY, true );
			$map = is_array( $map ) ? $map : array();
			foreach ( $map as $m ) if ( ! empty( $m['id'] ) ) $ids[ (string) $m['id'] ] = true;

			$title = (string) get_post_field( 'post_title', $sid );
			$draft = 'publish' !== get_post_status( $sid );
			$edit  = user_can( (int) $viewer, 'edit_post', $sid );
			foreach ( BFTD_Schedule::occurrences( $sid, $from, $to, 400 ) as $o ) {
				// A rescheduled session is shown where it went; where it was is
				// listed too, for the Rescheduled filter (1.203).
				if ( ! isset( $want[ (int) $o['tutor'] ] ) ) continue;
				if ( $o['date'] < $from || $o['date'] > $to ) continue;
				$out[] = array(
					'kind'       => 'session',
					'title'      => $title,
					'student'    => $title,
					'student_id' => (int) $sid,
					'tutor'      => (int) $o['tutor'],
					'date'       => $o['date'],
					'start'      => $o['time'],
					'end'        => self::plus( $o['time'], $o['minutes'] ),
					'status'     => $o['status'],
					'type'       => BFTD_Schedule::clean_type( $o['type'] ?? '' ),
					'type_label' => BFTD_Schedule::type_label( $o['type'] ?? '' ),
					'key'        => $o['key'],
					'moved_to'   => 'moved' === $o['status'] && ! empty( $o['moved_to_date'] )
						? BFTD_Time::day( $o['moved_to_date'] ) . ( ! empty( $o['moved_to_time'] ) ? ', ' . BFTD_Schedule::pretty_time( $o['moved_to_time'] ) : '' ) : '',
					'draft'      => $draft,
					'in_ghl'     => isset( $map[ $o['key'] ] ),
					// Their own sessions are a tutor's to change (Karl, 1.193).
					'can_edit'   => ( $edit || (int) $o['tutor'] === (int) $viewer ) && 'booked' === $o['status'],
					'url'        => (string) get_edit_post_link( $sid, 'raw' ),
				);
			}
		}

		foreach ( self::ghl_events( $tutors, $from, $to, $viewer ) as $e ) {
			if ( isset( $ids[ $e['id'] ] ) ) continue;
			$out[] = $e;
		}

		usort( $out, function ( $a, $b ) { return strcmp( $a['date'] . $a['start'], $b['date'] . $b['start'] ); } );
		return $out;
	}

	/**
	 * Appointments on the tutors' linked GoHighLevel calendars, in our wall
	 * time. Cancelled ones are left out. Read only.
	 */
	public static function ghl_events( $tutors, $from, $to, $viewer = 0 ) {
		if ( ! class_exists( 'BFTD_GHL' ) || ! BFTD_GHL::configured() ) return array();
		$out = array();
		foreach ( $tutors as $uid ) {
			$cal = BFTD_Calendars::calendar_for( $uid );
			if ( '' === $cal ) continue;
			$ck   = self::CACHE . md5( $cal . $from . $to . (int) get_option( self::CACHE . 'v', 0 ) );
			$list = get_transient( $ck );
			if ( ! is_array( $list ) ) {
				$start = ( strtotime( $from . ' 00:00:00 UTC' ) - 86400 ) * 1000;
				$end   = ( strtotime( $to . ' 23:59:59 UTC' ) + 86400 ) * 1000;
				list( , $data, $err ) = BFTD_GHL::request( 'GET', '/calendars/events', array(
					'locationId' => BFTD_GHL::location(), 'calendarId' => $cal, 'startTime' => $start, 'endTime' => $end,
				), null, BFTD_Bookings::VERSION );
				if ( '' !== $err ) continue;
				$list = (array) ( $data['events'] ?? array() );
				set_transient( $ck, $list, 5 * MINUTE_IN_SECONDS );
			}
			foreach ( $list as $e ) {
				if ( empty( $e['id'] ) || ! empty( $e['deleted'] ) ) continue;
				$status = strtolower( (string) ( $e['appointmentStatus'] ?? '' ) );
				if ( 'cancelled' === $status ) continue;
				$s = self::wall( (string) ( $e['startTime'] ?? '' ) );
				$f = self::wall( (string) ( $e['endTime'] ?? '' ) );
				if ( ! $s || $s['date'] < $from || $s['date'] > $to ) continue;
				$out[] = array(
					'kind'       => 'ghl',
					'id'         => (string) $e['id'],
					'title'      => sanitize_text_field( (string) ( $e['title'] ?? 'Appointment' ) ),
					'student'    => '',
					'student_id' => 0,
					'tutor'      => (int) $uid,
					'date'       => $s['date'],
					'start'      => $s['time'],
					'end'        => $f ? $f['time'] : $s['time'],
					'status'     => '' !== $status ? $status : 'booked',
					'key'        => '',
					'draft'      => false,
					'in_ghl'     => true,
					// A manager, or the tutor whose calendar it is (1.193).
					'can_edit'   => 'cancelled' !== $status && ( BFTD_Roles::can_manage( (int) $viewer ) || (int) $uid === (int) $viewer ),
					'url'        => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Move or cancel an appointment made in GoHighLevel itself (1.193).
	 * Only a manager, or the tutor whose linked calendar it is on, and never
	 * one the portal made: those are changed on the student's schedule, so
	 * the two do not disagree. A move keeps its length and follows
	 * GoHighLevel's availability.
	 */
	public static function ajax_ghl() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$viewer = get_current_user_id();
		if ( ! BFTD_Roles::is_staff( $viewer ) || ! BFTD_GHL::configured() ) wp_send_json_error( array( 'message' => 'Not for this account.' ), 403 );
		$id   = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$what = sanitize_key( wp_unslash( $_POST['what'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $id ) || ! in_array( $what, array( 'move', 'cancel', 'delete' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Nothing to change there.' ), 400 );
		}
		$r = self::change_ghl( $id, $what, sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['time'] ?? '' ) ), $viewer );
		if ( is_wp_error( $r ) ) wp_send_json_error( array( 'message' => $r->get_error_message() ), 409 );
		wp_send_json_success();
	}

	/** @return true|WP_Error */
	public static function change_ghl( $id, $what, $date, $time, $viewer ) {
		list( , $data, $err ) = BFTD_GHL::request( 'GET', '/calendars/events/appointments/' . rawurlencode( $id ), array(), null, BFTD_Bookings::VERSION );
		if ( '' !== $err ) return new WP_Error( 'bftd_ghl', 'GoHighLevel did not find that appointment: ' . $err );
		$ev    = (array) ( $data['appointment'] ?? $data['event'] ?? $data );
		$cal   = (string) ( $ev['calendarId'] ?? '' );
		$links = BFTD_Calendars::links();
		$owner = (int) ( $links[ $cal ] ?? 0 );
		if ( ! $owner ) return new WP_Error( 'bftd_cal', 'That appointment is not on a tutor\'s linked calendar.' );
		if ( ! BFTD_Roles::can_manage( $viewer ) && $owner !== (int) $viewer ) return new WP_Error( 'bftd_mine', 'Only a manager, or the tutor whose calendar it is on, can change it.' );
		foreach ( BFTD_Schedule::scheduled_students() as $sid ) {
			$map = get_post_meta( $sid, BFTD_Bookings::MAP_KEY, true );
			foreach ( is_array( $map ) ? $map : array() as $m ) {
				if ( ( $m['id'] ?? '' ) === $id ) return new WP_Error( 'bftd_ours', 'That one is on a student\'s schedule: change it there, or as a session here.' );
			}
		}
		if ( 'delete' === $what ) {
			// Deleted outright, telling nobody (1.201).
			list( $code, , $err ) = BFTD_GHL::request( 'DELETE', '/calendars/events/' . rawurlencode( $id ), array(), null, BFTD_Bookings::VERSION );
			if ( '' !== $err && 404 !== (int) $code ) return new WP_Error( 'bftd_ghl', 'GoHighLevel did not delete it: ' . $err );
			update_option( self::CACHE . 'v', (int) get_option( self::CACHE . 'v', 0 ) + 1, false );
			BFTD_Audit::log( 'crm_appointment_changed', array( 'summary' => 'GoHighLevel appointment "' . sanitize_text_field( (string) ( $ev['title'] ?? '' ) ) . '" was deleted from the calendar, with nobody told.' ) );
			return true;
		}
		if ( 'cancel' === $what ) {
			$body = array( 'appointmentStatus' => 'cancelled' );
			$say  = 'cancelled';
		} else {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) return new WP_Error( 'bftd_when', 'Pick a date and a time.' );
			$len  = max( 15, (int) round( ( strtotime( (string) ( $ev['endTime'] ?? '' ) ) - strtotime( (string) ( $ev['startTime'] ?? '' ) ) ) / 60 ) );
			$body = array(
				'calendarId'               => $cal,
				'startTime'                => BFTD_Bookings::iso( $date, $time ),
				'endTime'                  => BFTD_Bookings::iso( $date, $time, $len ),
				'ignoreDateRange'          => false,
				'ignoreFreeSlotValidation' => false,
				'toNotify'                 => true,
			);
			$say = 'moved to ' . $date . ' ' . $time;
		}
		list( , , $err ) = BFTD_GHL::request( 'PUT', '/calendars/events/appointments/' . rawurlencode( $id ), array(), $body, BFTD_Bookings::VERSION );
		if ( '' !== $err ) return new WP_Error( 'bftd_ghl', 'GoHighLevel did not take it: ' . $err );
		update_option( self::CACHE . 'v', (int) get_option( self::CACHE . 'v', 0 ) + 1, false );
		BFTD_Audit::log( 'crm_appointment_changed', array( 'summary' => 'GoHighLevel appointment "' . sanitize_text_field( (string) ( $ev['title'] ?? '' ) ) . '" was ' . $say . ' from the calendar.' ) );
		return true;
	}

	/** An ISO time from GoHighLevel as our date and time of day. */
	public static function wall( $iso ) {
		$ts = strtotime( $iso );
		if ( ! $ts ) return null;
		$off = BFTD_Bookings::offset_on( gmdate( 'Y-m-d', $ts ) );
		$t   = $ts + 60 * $off;
		return array( 'date' => gmdate( 'Y-m-d', $t ), 'time' => gmdate( 'H:i', $t ) );
	}

	public static function ajax_events() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		if ( ! BFTD_Roles::is_staff() ) wp_send_json_error( array( 'message' => 'Not for this account.' ), 403 );
		$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
		$to   = sanitize_text_field( wp_unslash( $_POST['to'] ?? '' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
			wp_send_json_error( array( 'message' => 'Those dates are not a range.' ), 400 );
		}
		if ( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS > self::MAX_DAYS ) {
			wp_send_json_error( array( 'message' => 'That is more than six weeks at once.' ), 400 );
		}
		$tutors = isset( $_POST['tutors'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['tutors'] ) ) : array();
		wp_send_json_success( array(
			'events' => self::events( $from, $to, $tutors, get_current_user_id() ),
			'closed' => self::closed( $from, $to ),
		) );
	}

	/**
	 * Dates in a range that nothing can be booked on, with why: Sundays,
	 * statutory holidays where the business is, our school breaks (1.203).
	 * date => reason. Greyed out on the calendar.
	 */
	public static function closed( $from, $to ) {
		$out = array();
		for ( $d = $from; $d <= $to; $d = BFTD_Time::add_days( $d, 1 ) ) {
			$why = BFTD_Schedule::closed_on( $d );
			if ( '' !== $why ) $out[ $d ] = $why;
		}
		return $out;
	}

	public static function render() {
		if ( ! BFTD_Roles::is_staff() ) return;
		$viewer = get_current_user_id();
		$tutors = self::tutors_for( $viewer );
		$manage = BFTD_Roles::can_manage( $viewer );
		$config = array(
			'ajax'   => admin_url( 'admin-ajax.php' ),
			'nonce'  => wp_create_nonce( 'bftd_nonce' ),
			'today'  => BFTD_Time::today(),
			'tutors' => $tutors,
			'manage' => $manage ? 1 : 0,
			'me'     => (int) $viewer,
			// Each tutor's colour, numbered in the order of the side panel, so a
			// booking's border and the swatch beside the name always match (1.206.1).
			// A colour set on the profile wins (1.209.1).
			'colours' => self::colour_map( array_keys( $tutors ) ),
		);
		$students = self::bookable( $viewer );
		?>
		<div class="wrap bftd-wrap bftd-calv">
			<h1 class="wp-heading-inline"><?php echo $manage ? 'Calendar' : 'My schedule'; ?></h1>
			<?php if ( class_exists( 'BFTD_Send_Window' ) ) BFTD_Send_Window::banner_shell(); ?>
			<hr class="wp-header-end">
			<div class="bftd-calv-bar">
				<button type="button" class="button bftd-calv-today">Today</button>
				<span class="bftd-calv-nav">
					<button type="button" class="button bftd-calv-prev" aria-label="Earlier">&lsaquo;</button>
					<strong class="bftd-calv-label" aria-live="polite"></strong>
					<button type="button" class="button bftd-calv-next" aria-label="Later">&rsaquo;</button>
				</span>
				<label class="screen-reader-text" for="bftd-calv-view">View</label>
				<select id="bftd-calv-view" class="bftd-calv-view">
					<option value="day">Day</option>
					<option value="week" selected>Week</option>
					<option value="month">Month</option>
				</select>
				<label class="bftd-calv-ghl" title="Bookings sent to the CRM, and appointments made in it"><input type="checkbox" class="bftd-calv-showghl" checked> Scheduled</label>
				<label class="bftd-calv-ghl"><input type="checkbox" class="bftd-calv-showdraft" checked> Drafts</label>
				<label class="bftd-calv-ghl" title="Bookings before today, taught or not"><input type="checkbox" class="bftd-calv-showheld" checked> Past</label>
				<label class="bftd-calv-ghl"><input type="checkbox" class="bftd-calv-showcancel"> Cancelled</label>
				<label class="bftd-calv-ghl"><input type="checkbox" class="bftd-calv-showmoved"> Rescheduled</label>
			</div>
			<div class="bftd-calv-body">
				<div class="bftd-calv-grid" role="region" aria-label="Sessions"></div>
				<aside class="bftd-calv-side" aria-label="Bookings and tutors">
					<?php if ( $students ) : ?>
						<button type="button" class="button button-primary bftd-calv-addbtn" aria-expanded="false" aria-controls="bftd-calv-add">Add a booking</button>
						<?php // In the panel, in the page's flow: it covers nothing (1.203). ?>
						<form id="bftd-calv-add" class="bftd-calv-add" hidden>
							<h2>New booking</h2>
							<label for="bftd-calv-add-student">Student</label>
							<select id="bftd-calv-add-student" name="student_id" required>
								<option value="">Choose a student</option>
								<?php foreach ( $students as $sid => $name ) : ?>
									<option value="<?php echo (int) $sid; ?>"><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<label for="bftd-calv-add-type">Type</label>
							<select id="bftd-calv-add-type" name="type">
								<?php foreach ( BFTD_Schedule::types() as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>"<?php selected( 'tutoring', $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<label for="bftd-calv-add-date">Date</label>
							<input type="date" id="bftd-calv-add-date" name="date" required>
							<label for="bftd-calv-add-time">Time</label>
							<input type="time" id="bftd-calv-add-time" name="time" step="300" required>
							<label for="bftd-calv-add-minutes">Minutes</label>
							<input type="number" id="bftd-calv-add-minutes" name="minutes" value="50" min="15" step="5">
							<label for="bftd-calv-add-repeat">Repeats</label>
							<select id="bftd-calv-add-repeat" name="repeat">
								<option value="once" selected>One time</option>
								<option value="weekly">Every week</option>
							</select>
							<div class="bftd-calv-add-until" hidden>
								<label for="bftd-calv-add-ends">Ends (optional)</label>
								<input type="date" id="bftd-calv-add-ends" name="until">
							</div>
							<?php if ( $manage ) : ?>
								<label for="bftd-calv-add-tutor">Tutor</label>
								<select id="bftd-calv-add-tutor" name="tutor">
									<option value="0">The student's assigned tutor</option>
									<?php foreach ( $tutors as $uid => $name ) : ?>
										<option value="<?php echo (int) $uid; ?>"><?php echo esc_html( $name ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
							<p class="bftd-calv-said" role="alert"></p>
							<p class="bftd-calv-add-do">
								<button type="submit" class="button button-primary">Book it</button>
								<button type="button" class="button-link bftd-calv-add-close">Close</button>
							</p>
							<p class="description">Families are told once the change is confirmed in the banner above.</p>
						</form>
					<?php endif; ?>
					<?php if ( $manage ) : ?>
						<h2>Tutors</h2>
						<p><button type="button" class="button-link bftd-calv-all">All</button> &middot; <button type="button" class="button-link bftd-calv-none">None</button></p>
						<?php foreach ( $tutors as $uid => $name ) : ?>
							<label class="bftd-calv-tutor"><input type="checkbox" value="<?php echo (int) $uid; ?>" checked>
								<span class="bftd-calv-swatch" data-t="<?php echo (int) ( $config['colours'][ (int) $uid ] ?? 0 ); ?>"></span>
								<?php echo esc_html( $name ); ?></label>
						<?php endforeach; ?>
					<?php endif; ?>
				</aside>
			</div>
			<p class="description bftd-calv-key">
				<span class="bftd-calv-k is-booked">Booked</span>
				<span class="bftd-calv-k is-draft">Draft</span>
				<span class="bftd-calv-k is-held">Taught</span>
				<span class="bftd-calv-k is-cancelled">Cancelled</span>
				<span class="bftd-calv-k is-moved">Rescheduled</span>
				<span class="bftd-calv-k is-closed">Closed: Sundays, statutory holidays, school breaks</span>
				<span class="bftd-calv-k is-ghl">Made in CRM</span>
			</p>
			<div class="bftd-calv-veil" hidden></div>
			<div class="bftd-calv-pop" role="dialog" aria-modal="true" aria-labelledby="bftd-calv-pop-title" tabindex="-1" hidden></div>
		</div>
		<script>window.BFTD_CAL = <?php echo wp_json_encode( $config ); ?>;</script>
		<?php
	}
}
