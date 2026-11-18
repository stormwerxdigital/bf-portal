<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Schedule changes, held until confirmed, and sent in hours (Karl, 1.200).
 *
 * A tutor working on a schedule makes many changes; a family should hear
 * about the result once, at a sensible hour, not about every step. So a
 * change to a student's schedule (saving the student, a session moved or
 * cancelled, who is reminded, the student moved to the trash) no longer goes
 * to GoHighLevel. The student is marked as having changes not sent, and a
 * banner across the top of the portal's screens says so, until the person
 * confirms.
 *
 * Confirming between 8:00 am and 6:00 pm, business time, sends at once; out
 * of hours it is queued and sent at 8:00 am. A manager, senior manager or
 * administrator may send at any time. Whatever is sent is only the final
 * state: the mirror (BFTD_Bookings::sync_student) compares what GoHighLevel
 * has with what the schedule says now, so ten changes to one session are one
 * change, and a session moved and moved back is none.
 *
 * The hourly run sends only in hours, and never a student with changes not
 * confirmed: it keeps the sixty days ahead filled for students whose
 * schedule has not changed, and flags any that has.
 */
class BFTD_Send_Window {

	/** student id => array( state: editing|queued, by: user ids, since, send_at, confirmed_by ) */
	const PENDING = 'bftd_ghl_pending';

	/** Per student: the schedule as it was when last sent. */
	const SENT_KEY = '_bftd_ghl_sent_hash';

	const EVENT = 'bftd_ghl_send_queued';

	/** Hours, business time, when families may be told: 8:00 am to 6:00 pm. */
	public static function hours() {
		return array( (int) apply_filters( 'bftd_send_from', 8 ), (int) apply_filters( 'bftd_send_until', 18 ) );
	}

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 100, 2 );
		add_action( 'bftd_schedule_changed', array( __CLASS__, 'note_change' ) );
		add_action( 'trashed_post', array( __CLASS__, 'note_change' ) );
		add_action( BFTD_GHL::CRON, array( __CLASS__, 'hourly' ) );
		add_action( self::EVENT, array( __CLASS__, 'run_queue' ) );
		add_action( 'admin_post_bftd_send_changes', array( __CLASS__, 'confirm_request' ) );
		add_action( 'wp_ajax_bftd_pending_banner', array( __CLASS__, 'ajax_banner' ) );
		add_action( 'admin_footer', array( __CLASS__, 'banner_shell' ) );
		add_action( 'admin_notices', array( __CLASS__, 'flash' ) );
	}

	/** What a confirm did, once, after the page it came back to. */
	public static function flash() {
		$k = 'bftd_sw_flash_' . get_current_user_id();
		$m = get_transient( $k );
		if ( ! $m ) return;
		delete_transient( $k );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $m ) . '</p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* The clock                                                           */
	/* ------------------------------------------------------------------ */

	/** Now, as a wall clock in business time (a timestamp read as if UTC). */
	public static function wall( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		return $now + 60 * BFTD_Bookings::offset_on( gmdate( 'Y-m-d', $now ) );
	}

	/** Is it within the hours families may be told? */
	public static function in_hours( $now = null ) {
		list( $from, $until ) = self::hours();
		$h = (int) gmdate( 'G', self::wall( $now ) );
		return $h >= $from && $h < $until;
	}

	/** When the hours next open, as a real timestamp: today at 8:00 am, or tomorrow's. */
	public static function next_open( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		list( $from ) = self::hours();
		$wall = self::wall( $now );
		$day  = gmdate( 'Y-m-d', $wall );
		if ( (int) gmdate( 'G', $wall ) >= $from ) $day = gmdate( 'Y-m-d', $wall + DAY_IN_SECONDS );
		$open_wall = strtotime( $day . ' ' . sprintf( '%02d:00:00', $from ) . ' UTC' );
		return $open_wall - 60 * BFTD_Bookings::offset_on( $day );
	}

	/* ------------------------------------------------------------------ */
	/* What has changed                                                    */
	/* ------------------------------------------------------------------ */

	public static function pending() {
		$p = get_option( self::PENDING, array() );
		return is_array( $p ) ? $p : array();
	}

	private static function save_pending( $p ) {
		update_option( self::PENDING, $p, false );
	}

	/** Everything that decides what GoHighLevel should hold for a student. */
	public static function inputs( $sid ) {
		return md5( maybe_serialize( array(
			BFTD_Schedule::rules( $sid ),
			BFTD_Schedule::exceptions( $sid ),
			BFTD_Bookings::prefs( $sid ),
			(string) BFTD_Fields::get( $sid, 'student', 'lessons_bank' ),
			(string) BFTD_Fields::get( $sid, 'student', 'join_url' ),
			(string) get_post_meta( $sid, BFTD_Schedule::OFFSET_KEY, true ),
			(string) get_post_status( $sid ),
			(string) get_post_field( 'post_title', $sid ),
			BFTD_CPT::staff_ids( $sid ),
		) ) );
	}

	/** Has everything about this student already gone to GoHighLevel? */
	public static function in_step( $sid ) {
		return self::inputs( $sid ) === (string) get_post_meta( $sid, self::SENT_KEY, true );
	}

	/**
	 * Say this student's current state has been sent. For a quiet delete
	 * (1.201), which reaches GoHighLevel itself, so it is not held.
	 */
	public static function mark_sent( $sid ) {
		update_post_meta( $sid, self::SENT_KEY, self::inputs( $sid ) );
	}

	/** Does GoHighLevel have, or should it have, anything for this student? */
	public static function in_play( $sid ) {
		$map = get_post_meta( $sid, BFTD_Bookings::MAP_KEY, true );
		return ! empty( BFTD_Bookings::prefs( $sid )['notify'] ) || ( is_array( $map ) && $map );
	}

	/**
	 * A student's schedule may have changed: if it has, and GoHighLevel is
	 * involved, mark it as not sent. A change to one already confirmed puts it
	 * back to not sent, to be confirmed again.
	 */
	public static function note_change( $sid ) {
		$sid = (int) $sid;
		if ( ! $sid || BFTD_CPT::STUDENT !== get_post_type( $sid ) || ! BFTD_GHL::configured() ) return;
		if ( ! self::in_play( $sid ) ) return;
		if ( self::inputs( $sid ) === (string) get_post_meta( $sid, self::SENT_KEY, true ) ) return;
		$p   = self::pending();
		$me  = get_current_user_id();
		$row = $p[ $sid ] ?? array( 'by' => array(), 'since' => time() );
		$row['state']   = 'editing';
		$row['send_at'] = 0;
		if ( $me && ! in_array( $me, $row['by'], true ) ) $row['by'][] = $me;
		$p[ $sid ] = $row;
		self::save_pending( $p );
	}

	public static function on_save( $sid, $post ) {
		if ( ! $post || BFTD_CPT::STUDENT !== $post->post_type ) return;
		if ( wp_is_post_revision( $sid ) || wp_is_post_autosave( $sid ) || 'auto-draft' === $post->post_status ) return;
		self::note_change( $sid );
	}

	/** The students this person's banner is about: their own changes, or every one for a manager. */
	public static function mine( $uid ) {
		$all = self::pending();
		if ( BFTD_Roles::can_manage( $uid ) ) return $all;
		return array_filter( $all, function ( $row ) use ( $uid ) { return in_array( (int) $uid, array_map( 'intval', $row['by'] ), true ); } );
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	/** Send these students' schedules to GoHighLevel now. */
	public static function send_now( $sids, $by = 0 ) {
		$p    = self::pending();
		$sent = array();
		foreach ( $sids as $sid ) {
			$sid   = (int) $sid;
			$state = BFTD_Bookings::sync_student( $sid );
			update_post_meta( $sid, self::SENT_KEY, self::inputs( $sid ) );
			unset( $p[ $sid ] );
			$sent[] = (string) get_post_field( 'post_title', $sid ) . ( '' !== $state['error'] ? ' (' . $state['error'] . ')' : '' );
		}
		self::save_pending( $p );
		if ( $sent ) {
			BFTD_Audit::log( 'schedule_sent', array( 'summary' => 'Schedule changes sent to GoHighLevel' . ( $by ? ' by ' . self::name( $by ) : '' ) . ': ' . implode( '; ', $sent ) . '.' ) );
		}
		return $sent;
	}

	/**
	 * Done editing: send now if it is within hours, or a manager says so;
	 * otherwise queue for when the hours open.
	 *
	 * @return array( sent|queued, when, count )
	 */
	public static function confirm( $uid, $override = false, $now = null ) {
		$mine = self::mine( $uid );
		if ( ! $mine ) return array( 'none', 0, 0 );
		$override = $override && BFTD_Roles::can_manage( $uid );
		if ( $override || self::in_hours( $now ) ) {
			self::send_now( array_keys( $mine ), $uid );
			return array( 'sent', 0, count( $mine ) );
		}
		$at = self::next_open( $now );
		$p  = self::pending();
		foreach ( array_keys( $mine ) as $sid ) {
			$p[ $sid ]['state']        = 'queued';
			$p[ $sid ]['send_at']      = $at;
			$p[ $sid ]['confirmed_by'] = (int) $uid;
		}
		self::save_pending( $p );
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::EVENT, array( $at ) ) ) wp_schedule_single_event( $at, self::EVENT, array( $at ) );
		BFTD_Audit::log( 'schedule_queued', array( 'summary' => 'Schedule changes for ' . count( $mine ) . ' ' . ( 1 === count( $mine ) ? 'student' : 'students' )
			. ' confirmed by ' . self::name( $uid ) . ', to be sent at ' . self::when( $at, $now ) . ', inside the hours families are told.' ) );
		return array( 'queued', $at, count( $mine ) );
	}

	/** The queue, at the time it was set for (and from the hourly run, in case that was missed). */
	public static function run_queue( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! self::in_hours( $now ) ) return array();
		$due = array();
		foreach ( self::pending() as $sid => $row ) {
			if ( 'queued' === $row['state'] && (int) $row['send_at'] <= $now ) $due[] = (int) $sid;
		}
		return $due ? self::send_now( $due, 0 ) : array();
	}

	/**
	 * Every hour, in hours only: the queue, then the sixty days ahead kept
	 * filled for students whose schedule has not changed. One that has
	 * changed without anybody saying so is flagged, not sent.
	 */
	public static function hourly( $now = null ) {
		if ( ! BFTD_GHL::configured() || ! self::in_hours( $now ) ) return;
		self::run_queue( $now );
		$pending = self::pending();
		$ids = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => BFTD_Schedule::RULES_KEY, 'compare' => 'EXISTS' ) ),
		) );
		foreach ( $ids as $sid ) {
			if ( isset( $pending[ $sid ] ) || ! self::in_play( $sid ) ) continue;
			if ( self::inputs( $sid ) !== (string) get_post_meta( $sid, self::SENT_KEY, true ) ) { self::note_change( $sid ); continue; }
			BFTD_Bookings::sync_student( $sid );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The banner                                                          */
	/* ------------------------------------------------------------------ */

	private static function name( $uid ) {
		$u = get_userdata( (int) $uid );
		return $u ? $u->display_name : 'someone';
	}

	/** "8:00 am today", "8:00 am tomorrow", or the day. */
	public static function when( $ts, $now = null ) {
		$wall  = $ts + 60 * BFTD_Bookings::offset_on( gmdate( 'Y-m-d', $ts ) );
		$today = gmdate( 'Y-m-d', self::wall( $now ) );
		$day   = gmdate( 'Y-m-d', $wall );
		$time  = ltrim( gmdate( 'g:i a', $wall ), '0' );
		if ( $day === $today ) return $time . ' today';
		if ( $day === gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) + DAY_IN_SECONDS ) ) return $time . ' tomorrow';
		return $time . ' on ' . gmdate( 'D j M', $wall );
	}

	/** The banner's inside, for this person. Empty when nothing is waiting on them. */
	public static function banner_html( $uid, $now = null ) {
		$mine = self::mine( $uid );
		if ( ! $mine ) return '';
		$editing = array_filter( $mine, function ( $r ) { return 'editing' === $r['state']; } );
		$queued  = array_filter( $mine, function ( $r ) { return 'queued' === $r['state']; } );
		$manager = BFTD_Roles::can_manage( $uid );
		$open    = self::in_hours( $now );
		$names   = function ( $rows ) {
			$out = array();
			foreach ( array_keys( $rows ) as $sid ) {
				$out[] = '<a href="' . esc_url( (string) get_edit_post_link( $sid ) ) . '">' . esc_html( (string) get_post_field( 'post_title', $sid ) ) . '</a>';
			}
			return implode( ', ', $out );
		};
		$button = function ( $label, $override, $primary ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
				. '<input type="hidden" name="action" value="bftd_send_changes">'
				. wp_nonce_field( 'bftd_send_changes', '_wpnonce', true, false )
				. ( $override ? '<input type="hidden" name="override" value="1">' : '' )
				. '<button class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		};
		list( $from, $until ) = self::hours();
		$html = '';
		if ( $editing ) {
			$n     = count( $editing );
			$html .= '<p><strong>' . esc_html( $n . ' ' . ( 1 === $n ? 'student has' : 'students have' ) . ' schedule changes not sent:' ) . '</strong> ' . $names( $editing )
				. '. Families have not been told. Make all your changes, then confirm once.</p><div class="bftd-sw-do">';
			if ( $open ) {
				$html .= $button( 'Confirm and send now', false, true );
			} else {
				$html .= $button( 'Confirm, send at ' . self::when( self::next_open( $now ), $now ), false, true );
				if ( $manager ) $html .= $button( 'Send now anyway', true, false );
			}
			$html .= '</div>';
		}
		if ( $queued ) {
			$n     = count( $queued );
			$at    = min( array_map( function ( $r ) { return (int) $r['send_at']; }, $queued ) );
			$html .= '<p><strong>' . esc_html( 'Confirmed: the changes for ' . $n . ' ' . ( 1 === $n ? 'student' : 'students' ) . ' will be sent at ' . self::when( $at, $now ) . '.' ) . '</strong> ' . $names( $queued )
				. '. Families are only told between ' . esc_html( ltrim( gmdate( 'g:i a', $from * 3600 ), '0' ) . ' and ' . ltrim( gmdate( 'g:i a', $until * 3600 ), '0' ) ) . '.</p>';
			if ( $manager && ! $editing ) $html .= '<div class="bftd-sw-do">' . $button( 'Send now anyway', true, false ) . '</div>';
		}
		return $html;
	}

	/** Drawn on every portal screen for staff, filled when there is something to say. */
	public static function banner_shell() {
		if ( ! BFTD_Roles::is_staff() || ! BFTD_GHL::configured() ) return;
		$html = self::banner_html( get_current_user_id() );
		echo '<div class="bftd-sw" role="region" aria-label="Schedule changes not sent"' . ( '' === $html ? ' hidden' : '' ) . '>' . $html . '</div>';
		?>
		<script>
		( function () {
			var box = document.querySelector( '.bftd-sw' );
			if ( ! box ) return;
			document.addEventListener( 'bftd:schedule-changed', function () {
				var body = new FormData();
				body.append( 'action', 'bftd_pending_banner' );
				body.append( 'nonce', <?php echo wp_json_encode( wp_create_nonce( 'bftd_nonce' ) ); ?> );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( res ) { if ( ! res || ! res.success ) return; box.innerHTML = res.data.html; box.hidden = '' === res.data.html; } );
			} );
		}() );
		</script>
		<?php
	}

	public static function ajax_banner() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		if ( ! BFTD_Roles::is_staff() ) wp_send_json_error( null, 403 );
		wp_send_json_success( array( 'html' => self::banner_html( get_current_user_id() ) ) );
	}

	public static function confirm_request() {
		check_admin_referer( 'bftd_send_changes' );
		$uid = get_current_user_id();
		if ( ! BFTD_Roles::is_staff( $uid ) ) wp_die( 'Not for this account.', 403 );
		list( $what, $at, $n ) = self::confirm( $uid, ! empty( $_POST['override'] ) );
		$msg = 'sent' === $what ? 'Sent to GoHighLevel: ' . $n . ' ' . ( 1 === $n ? 'student' : 'students' ) . '.'
			: ( 'queued' === $what ? 'Confirmed. ' . $n . ' ' . ( 1 === $n ? 'student\'s changes' : 'students\' changes' ) . ' will be sent at ' . self::when( $at ) . '.' : 'Nothing was waiting to be sent.' );
		set_transient( 'bftd_sw_flash_' . $uid, $msg, 60 );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . BFTD_Admin::MENU_SLUG ) );
		exit;
	}
}
