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

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_bftd_calendar_events', array( __CLASS__, 'ajax_events' ) );
		add_action( 'wp_ajax_bftd_calendar_ghl', array( __CLASS__, 'ajax_ghl' ) );
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
				if ( 'moved' === $o['status'] ) continue;   // shown on the date it moved to
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
					'key'        => $o['key'],
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
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $id ) || ! in_array( $what, array( 'move', 'cancel' ), true ) ) {
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
		wp_send_json_success( array( 'events' => self::events( $from, $to, $tutors, get_current_user_id() ) ) );
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
		);
		?>
		<div class="wrap bftd-wrap bftd-calv">
			<h1 class="wp-heading-inline"><?php echo $manage ? 'Calendar' : 'My schedule'; ?></h1>
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
				<label class="bftd-calv-ghl"><input type="checkbox" class="bftd-calv-showghl" checked> GoHighLevel bookings</label>
				<label class="bftd-calv-ghl"><input type="checkbox" class="bftd-calv-showcancel"> Cancelled</label>
			</div>
			<div class="bftd-calv-body">
				<div class="bftd-calv-grid" role="region" aria-label="Sessions"></div>
				<?php if ( $manage ) : ?>
					<aside class="bftd-calv-side" aria-label="Tutors">
						<h2>Tutors</h2>
						<p><button type="button" class="button-link bftd-calv-all">All</button> &middot; <button type="button" class="button-link bftd-calv-none">None</button></p>
						<?php $i = 0; foreach ( $tutors as $uid => $name ) : ?>
							<label class="bftd-calv-tutor"><input type="checkbox" value="<?php echo (int) $uid; ?>" checked>
								<span class="bftd-calv-swatch" data-i="<?php echo (int) ( $i++ % 8 ); ?>"></span><?php echo esc_html( $name ); ?></label>
						<?php endforeach; ?>
					</aside>
				<?php endif; ?>
			</div>
			<p class="description bftd-calv-key">
				<span class="bftd-calv-k is-booked">Booked</span>
				<span class="bftd-calv-k is-held">Taught</span>
				<span class="bftd-calv-k is-cancelled">Cancelled</span>
				<span class="bftd-calv-k is-ghl">Made in GoHighLevel, change it there</span>
			</p>
			<div class="bftd-calv-pop" role="dialog" aria-modal="false" aria-labelledby="bftd-calv-pop-title" hidden></div>
		</div>
		<script>window.BFTD_CAL = <?php echo wp_json_encode( $config ); ?>;</script>
		<?php
	}
}
