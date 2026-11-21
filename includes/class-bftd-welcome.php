<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sending the welcome email by hand, from the Users list (Karl, 1.204).
 *
 * Families are never emailed automatically any more, the welcome included,
 * so somebody decides when a family hears that their portal is ready. The
 * Users list says, for each family account, whether the welcome has gone,
 * and offers Send welcome email (a row action, and a bulk action) for anyone
 * linked to a published student who has not had it.
 *
 * One welcome per student, as before: a caregiver of two children gets one
 * for each. Only published students count, because a caregiver welcomed to
 * a draft would sign in to nothing.
 */
class BFTD_Welcome {

	const ACTION = 'bftd_send_welcome';
	const COLUMN = 'bftd_welcome';

	public static function init() {
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ), 20 );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_filter( 'user_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		add_filter( 'bulk_actions-users', array( __CLASS__, 'bulk_action' ) );
		add_filter( 'handle_bulk_actions-users', array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_one' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/** Published students this person can see and has not been welcomed to. */
	public static function pending( $user_id ) {
		$out = array();
		foreach ( (array) BFTD_CPT::students_for_client( $user_id ) as $sid ) {
			if ( ! get_user_meta( $user_id, BFTD_Change_Notify::welcomed_key( $sid ), true ) ) $out[] = (int) $sid;
		}
		return $out;
	}

	/** When the latest welcome went to this person, as a stored date, or ''. */
	public static function last_sent( $user_id ) {
		$last = '';
		foreach ( (array) BFTD_CPT::students_for_client( $user_id ) as $sid ) {
			$at = (string) get_user_meta( $user_id, BFTD_Change_Notify::welcomed_key( $sid ), true );
			if ( $at > $last ) $last = $at;
		}
		return $last;
	}

	/** May the person here send this person their welcome? Staff who can edit the account. */
	public static function may_send_to( $user_id ) {
		$u = get_userdata( (int) $user_id );
		return $u && BFTD_Roles::is_client( $u ) && BFTD_Roles::is_staff() && current_user_can( 'edit_user', (int) $user_id );
	}

	/**
	 * Send every welcome this person is still owed.
	 *
	 * @return array( sent, failed ) counts of students
	 */
	public static function send_to( $user_id ) {
		$sent = 0;
		$failed = 0;
		foreach ( self::pending( $user_id ) as $sid ) {
			if ( BFTD_MetaBoxes::send_welcome( $user_id, $sid ) ) $sent++; else $failed++;
		}
		if ( $sent ) {
			$u = get_userdata( $user_id );
			BFTD_Audit::log( 'welcome_sent', array( 'summary' => 'The welcome email was sent by hand to ' . ( $u ? $u->display_name : 'a family member' ) . ' (' . $sent . ' ' . ( 1 === $sent ? 'student' : 'students' ) . ').' ) );
		}
		return array( $sent, $failed );
	}

	/* ------------------------------------------------------------------ */
	/* The Users list                                                      */
	/* ------------------------------------------------------------------ */

	public static function add_column( $cols ) {
		if ( ! BFTD_Roles::is_staff() ) return $cols;
		$cols[ self::COLUMN ] = 'Welcome email';
		return $cols;
	}

	private static function link( $user_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&user=' . (int) $user_id ), self::ACTION . '_' . (int) $user_id );
	}

	public static function render_column( $out, $column, $user_id ) {
		if ( self::COLUMN !== $column ) return $out;
		$u = get_userdata( (int) $user_id );
		if ( ! $u || ! BFTD_Roles::is_client( $u ) ) return '';
		$pending = self::pending( $user_id );
		$last    = self::last_sent( $user_id );
		if ( ! $pending && '' === $last ) {
			return '<span class="description">No published student yet</span>';
		}
		$html = '' !== $last
			? 'Sent ' . esc_html( BFTD_Time::day( substr( $last, 0, 10 ) ) )
			: '<strong>Not sent</strong>';
		if ( $pending && '' !== $last ) $html .= '<br><span class="description">Not yet for ' . (int) count( $pending ) . ' more ' . ( 1 === count( $pending ) ? 'student' : 'students' ) . '</span>';
		if ( $pending && self::may_send_to( $user_id ) ) {
			$html .= '<br><a href="' . esc_url( self::link( $user_id ) ) . '">Send welcome email</a>';
		}
		return $html;
	}

	public static function row_action( $actions, $user ) {
		if ( ! $user || ! self::may_send_to( $user->ID ) || ! self::pending( $user->ID ) ) return $actions;
		$actions[ self::ACTION ] = '<a href="' . esc_url( self::link( $user->ID ) ) . '">Send welcome email</a>';
		return $actions;
	}

	public static function bulk_action( $actions ) {
		if ( BFTD_Roles::is_staff() ) $actions[ self::ACTION ] = 'Send welcome email';
		return $actions;
	}

	/** Bulk: everyone ticked who is owed a welcome and may be sent one; the rest are left alone. */
	public static function handle_bulk( $redirect, $action, $user_ids ) {
		if ( self::ACTION !== $action ) return $redirect;
		$people = 0;
		$skipped = 0;
		foreach ( (array) $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( ! self::may_send_to( $uid ) || ! self::pending( $uid ) ) { $skipped++; continue; }
			list( $sent ) = self::send_to( $uid );
			if ( $sent ) $people++; else $skipped++;
		}
		self::flash( $people, $skipped );
		return $redirect;
	}

	public static function handle_one() {
		$uid = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $uid );
		if ( ! self::may_send_to( $uid ) ) wp_die( 'You cannot send this person a welcome.', 403 );
		list( $sent, $failed ) = self::send_to( $uid );
		self::flash( $sent ? 1 : 0, $sent ? 0 : 1, $failed );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'users.php' ) );
		exit;
	}

	private static function flash( $people, $skipped, $failed = 0 ) {
		if ( $people ) {
			$msg = 'Welcome email sent to ' . $people . ' ' . ( 1 === $people ? 'person' : 'people' ) . '.';
		} else {
			$msg = 'No welcome email was sent.';
		}
		if ( $skipped ) $msg .= ' ' . $skipped . ' ' . ( 1 === $skipped ? 'was' : 'were' ) . ' left out: already welcomed, not linked to a published student, or the email did not go (see the Activity Log).';
		set_transient( 'bftd_welcome_flash_' . get_current_user_id(), array( $people ? 'success' : 'warning', $msg ), 60 );
	}

	public static function notice() {
		$k = 'bftd_welcome_flash_' . get_current_user_id();
		$m = get_transient( $k );
		if ( ! is_array( $m ) ) return;
		delete_transient( $k );
		echo '<div class="notice notice-' . esc_attr( $m[0] ) . ' is-dismissible"><p>' . esc_html( $m[1] ) . '</p></div>';
	}
}
