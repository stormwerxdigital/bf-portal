<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * See a client's portal exactly as they do, read only (Karl, 1.150).
 *
 * A "View as client" link on the Users screen, for administrators and senior
 * managers, opens the portal as that client. For that one page load the
 * current user IS the client, so every access check, every list of students,
 * every section and every word is what they get, drawn by the same code.
 *
 * Nothing can be done as them:
 *
 *   - Only the page request is switched. The browser is still signed in as
 *     the staff member, so anything the page sends back (a message, a ticked
 *     item, a read receipt) arrives as the staff member carrying a nonce that
 *     was made for the client. WordPress refuses it. The script also stops
 *     those requests before they leave, so the screen says why.
 *   - Opening the page writes nothing as the client: the portal records no
 *     view, login or read state when a page is simply drawn.
 *   - Only a client can be viewed, never staff, and only by somebody holding
 *     ADMIN_CAP (senior manager and administrator), checked on every page.
 *
 * The staff member's own family view (/portal/?family=...) is a different
 * thing and is left as it was: they stay themselves there and can answer.
 */
class BFTD_View_As {

	const ARG   = 'bftd_view_as';
	const NONCE = 'bftd_view_as_nonce';

	/** The client being viewed on this request, or 0. */
	private static $client = 0;
	/** The staff member doing the viewing. */
	private static $viewer = 0;

	public static function init() {
		add_filter( 'user_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
		// After the query, before the admin bar and the scripts are set up.
		add_action( 'wp', array( __CLASS__, 'maybe_switch' ), 0 );
	}

	/** May the signed-in person view a client this way? */
	public static function may( $viewer_id = 0 ) {
		$viewer_id = $viewer_id ? (int) $viewer_id : get_current_user_id();
		return $viewer_id && user_can( $viewer_id, BFTD_Roles::ADMIN_CAP );
	}

	/** The portal, opened as this client. */
	public static function url( $client_id ) {
		$client_id = (int) $client_id;
		return add_query_arg( array(
			self::ARG   => $client_id,
			self::NONCE => wp_create_nonce( self::ARG . '_' . $client_id ),
		), BFTD_Dashboard::url() );
	}

	public static function row_action( $actions, $user ) {
		if ( ! self::may() || ! BFTD_Roles::is_client( $user ) ) return $actions;
		$actions['bftd_view_as'] = '<a href="' . esc_url( self::url( $user->ID ) ) . '" target="_blank" rel="noopener">View as client</a>';
		return $actions;
	}

	public static function active() {
		return self::$client > 0;
	}

	public static function client_id() {
		return self::$client;
	}

	/** The arguments every portal link carries while viewing, so the view holds as you move about. */
	public static function link_args() {
		if ( ! self::active() ) return array();
		return array( self::ARG => self::$client, self::NONCE => self::$link_nonce );
	}

	/** Made while the staff member was still current: the next page checks it against them. */
	private static $link_nonce = '';

	public static function maybe_switch() {
		if ( empty( $_GET[ self::ARG ] ) ) return;
		$client = (int) $_GET[ self::ARG ];
		$nonce  = isset( $_GET[ self::NONCE ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::NONCE ] ) ) : '';
		$viewer = get_current_user_id();

		// Every condition, every page: the right person, a link they were
		// given, a portal page, and a client on the other end.
		if ( ! $viewer || ! self::may( $viewer ) ) return;
		if ( ! wp_verify_nonce( $nonce, self::ARG . '_' . $client ) ) return;
		$page = BFTD_Dashboard::page_id();
		if ( $page && get_queried_object_id() !== $page ) return;
		$user = get_userdata( $client );
		if ( ! $user || ! BFTD_Roles::is_client( $user ) ) return;

		self::$viewer     = $viewer;
		self::$link_nonce = wp_create_nonce( self::ARG . '_' . $client );
		wp_set_current_user( $client );
		self::$client = $client;

		// The client's own screen, without the staff member's toolbar on it.
		add_filter( 'show_admin_bar', '__return_false', 999 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'read_only_script' ), 99 );
		add_action( 'wp_body_open', array( __CLASS__, 'bar' ) );
		add_action( 'wp_footer', array( __CLASS__, 'bar_fallback' ), 1 );
	}

	/**
	 * Tells the portal script it is read only, and stops anything it would
	 * send. WordPress would refuse it anyway; this says so on the screen.
	 */
	public static function read_only_script() {
		wp_add_inline_script( 'bftd-portal', '(function(){'
			. 'if(!window.BFTD)return;BFTD.read_only=1;'
			. 'var f=window.fetch;window.fetch=function(u,o){'
			. 'if(o&&String(o.method||"").toUpperCase()==="POST"&&String(u).indexOf("admin-ajax.php")!==-1){'
			. 'window.alert("This is a read-only view. Nothing is posted, ticked or saved as this client.");'
			. 'return Promise.resolve(new Response(JSON.stringify({success:false,data:{message:"Read-only view."}}),{headers:{"Content-Type":"application/json"}}));}'
			. 'return f.apply(this,arguments);};})();', 'before' );
	}

	private static $bar_done = false;

	/** Who is being viewed, that it is read only, and the way back. */
	public static function bar() {
		if ( self::$bar_done || ! self::active() ) return;
		self::$bar_done = true;
		$client = get_userdata( self::$client );
		$name   = $client ? $client->display_name : 'this client';
		?>
		<div class="bftd-viewas-bar" role="status" style="position:sticky;top:0;z-index:99999;background:#3B2F47;color:#fff;font:600 14px/1.4 system-ui,sans-serif;padding:10px 16px;display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:space-between">
			<span>Viewing as <?php echo esc_html( $name ); ?> (client). Read only: nothing you do here is posted, ticked or saved as them.</span>
			<a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" style="color:#fff;text-decoration:underline">Back to Users</a>
		</div>
		<?php
	}

	/** A theme without wp_body_open still gets the bar, fixed at the top. */
	public static function bar_fallback() {
		if ( self::$bar_done ) return;
		echo '<div style="position:fixed;top:0;left:0;right:0;z-index:99999">';
		self::bar();
		echo '</div>';
	}
}
