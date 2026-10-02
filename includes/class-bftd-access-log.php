<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sign-in tracking.
 *
 * Records the moment of every successful login as user meta (a plain
 * timestamp, so it sorts numerically without parsing), keeps a short rolling
 * history per account, adds the sortable "Last Login" column to the Users
 * list, and writes each event to the activity log so a sign in sits in the
 * same timeline as everything else that account did.
 *
 * Failed attempts are recorded too, without the password, so a family
 * reporting "I could not get in" can be answered from the record rather than
 * from memory.
 */
class BFTD_Access_Log {

	const LAST_LOGIN  = '_bftd_last_login';
	const LOGIN_COUNT = '_bftd_login_count';
	const HISTORY     = '_bftd_login_history';
	const HISTORY_MAX = 25;

	public static function init() {
		add_action( 'wp_login', array( __CLASS__, 'record_login' ), 10, 2 );
		add_action( 'wp_logout', array( __CLASS__, 'record_logout' ) );
		add_action( 'wp_login_failed', array( __CLASS__, 'record_failure' ) );
		add_action( 'admin_init', array( __CLASS__, 'backfill_current_session' ) );

		add_filter( 'manage_users_columns', array( __CLASS__, 'add_columns' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'render_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'sort_query' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'profile_panel' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_panel' ) );
	}

	private static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 ) : '';
	}

	private static function agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) return '';
		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 190 );
	}

	public static function record_login( $user_login, $user ) {
		if ( empty( $user->ID ) ) return;
		$now = time();

		update_user_meta( $user->ID, self::LAST_LOGIN, $now );
		update_user_meta( $user->ID, self::LOGIN_COUNT, (int) get_user_meta( $user->ID, self::LOGIN_COUNT, true ) + 1 );

		$history = get_user_meta( $user->ID, self::HISTORY, true );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, array( 'at' => $now, 'ip' => self::ip(), 'ua' => self::agent() ) );
		update_user_meta( $user->ID, self::HISTORY, array_slice( $history, 0, self::HISTORY_MAX ) );

		if ( class_exists( 'BFTD_Audit' ) ) {
			BFTD_Audit::log( 'user_login', array(
				'actor_id' => $user->ID,
				'summary'  => self::role_label( $user->ID ) . ' signed in.',
			) );
		}
	}

	public static function record_logout( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! class_exists( 'BFTD_Audit' ) ) return;
		BFTD_Audit::log( 'user_logout', array( 'actor_id' => $user_id, 'summary' => 'Signed out.' ) );
	}

	/**
	 * A failed attempt is logged against the account when the username or
	 * email matches a real one, and anonymously otherwise — so a typo in
	 * someone's own address never creates a record implying an account exists
	 * that does not.
	 */
	public static function record_failure( $username ) {
		if ( ! class_exists( 'BFTD_Audit' ) ) return;
		$user = get_user_by( 'login', $username );
		if ( ! $user ) $user = get_user_by( 'email', $username );
		BFTD_Audit::log( 'login_failed', array(
			'actor_id' => $user ? $user->ID : 0,
			'summary'  => $user ? 'A sign in attempt for this account did not succeed.' : 'A sign in attempt used an address with no account.',
		) );
	}

	/**
	 * Accounts whose session began before this tracking existed would show
	 * "Never" forever despite being in active use. Write once, only when no
	 * real timestamp exists, so a genuine login time is never overwritten by
	 * a "was just browsing" one.
	 */
	public static function backfill_current_session() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) return;
		if ( get_user_meta( $user_id, self::LAST_LOGIN, true ) ) return;
		update_user_meta( $user_id, self::LAST_LOGIN, time() );
	}

	private static function role_label( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) return 'User';
		return BFTD_Roles::role_name( $user_id );
	}

	public static function add_columns( $columns ) {
		$columns['bftd_last_login'] = 'Last Login';
		$columns['bftd_students']   = 'Students';
		return $columns;
	}

	public static function render_column( $value, $column, $user_id ) {
		if ( 'bftd_last_login' === $column ) {
			$ts = (int) get_user_meta( $user_id, self::LAST_LOGIN, true );
			if ( ! $ts ) return '<span style="color:#a7aaad;">Never</span>';
			$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$count = (int) get_user_meta( $user_id, self::LOGIN_COUNT, true );
			$out   = esc_html( BFTD_Time::format( $fmt, $ts ) );
			$out  .= '<br><span style="color:#787c82;font-size:12px;">' . esc_html( human_time_diff( $ts, time() ) ) . ' ago';
			if ( $count ) $out .= ' &middot; ' . esc_html( number_format_i18n( $count ) ) . ' sign ins';
			$out .= '</span>';
			return $out;
		}

		if ( 'bftd_students' === $column ) {
			if ( ! class_exists( 'BFTD_CPT' ) ) return $value;
			$titles = array();
			foreach ( BFTD_CPT::students_for_client( $user_id ) as $sid ) {
				$titles[] = '<a href="' . esc_url( (string) get_edit_post_link( $sid ) ) . '">' . esc_html( get_the_title( $sid ) ) . '</a>';
			}
			if ( ! $titles && BFTD_Roles::is_staff( $user_id ) ) {
				return '<span style="color:#787c82;">' . esc_html( self::role_label( $user_id ) ) . '</span>';
			}
			return $titles ? implode( ', ', $titles ) : '<span style="color:#a7aaad;">&mdash;</span>';
		}

		return $value;
	}

	public static function sortable( $columns ) {
		$columns['bftd_last_login'] = 'bftd_last_login';
		return $columns;
	}

	/**
	 * meta_key plus orderby=meta_value_num does an INNER JOIN, which silently
	 * drops every account that has never logged in. The OR'd meta_query forces
	 * a LEFT JOIN so "Never" accounts still appear, sorted to one end.
	 */
	public static function sort_query( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_User_Query ) return;
		if ( 'bftd_last_login' !== $query->get( 'orderby' ) ) return;
		$query->set( 'meta_query', array(
			'relation' => 'OR',
			array( 'key' => self::LAST_LOGIN, 'compare' => 'NOT EXISTS' ),
			array( 'key' => self::LAST_LOGIN, 'compare' => 'EXISTS' ),
		) );
		$query->set( 'orderby', 'meta_value_num' );
	}

	public static function profile_panel( $user ) {
		if ( ! BFTD_Roles::is_staff() ) return;
		$history = get_user_meta( $user->ID, self::HISTORY, true );
		$history = is_array( $history ) ? $history : array();
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2>Recent sign ins</h2>
		<?php if ( ! $history ) : ?>
			<p class="description">No sign ins recorded for this account yet.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:640px;">
				<thead><tr><th style="width:220px;">When</th><th style="width:140px;">From</th><th>Device</th></tr></thead>
				<tbody>
				<?php foreach ( $history as $row ) : ?>
					<tr>
						<td><?php echo esc_html( BFTD_Time::format( $fmt, (int) $row['at'] ) ); ?> <span style="color:#787c82"><?php echo esc_html( BFTD_Time::abbreviation( (int) $row['at'] ) ); ?></span></td>
						<td><?php echo esc_html( $row['ip'] ? $row['ip'] : '—' ); ?></td>
						<td style="font-size:12px;color:#50575e;"><?php echo esc_html( $row['ua'] ? $row['ua'] : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
