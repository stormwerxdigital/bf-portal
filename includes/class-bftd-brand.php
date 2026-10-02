<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Makes wp-admin look like Brilliant Futures.
 *
 * A tutor spends their whole working day in here, and a parent who does land
 * on a WordPress screen should not feel like they took a wrong turn into
 * somebody's server. The sidebar, the toolbar, the buttons, the login screen
 * and the footer all take the same purple and sage the portal uses.
 *
 * This is a skin, not a rewrite. Nothing here changes what a screen does or
 * who can reach it; every rule is a colour, a typeface or a spacing value, so
 * a WordPress update can never break a feature by moving something we styled.
 * The core colour-scheme picker is removed for everyone, because a user
 * switching to "Midnight" halfway through would just fight this.
 */
class BFTD_Brand {

	const OPTION_KEY = 'bftd_brand';

	public static function defaults() {
		return array(
			'purple'    => '#70567F',
			'sage'      => '#A2A55A',
			'logo_id'   => 0,
			'login_logo'=> 0,
			'login_msg' => '',
			'admin_skin'=> 1,
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function init() {
		add_action( 'admin_head', array( __CLASS__, 'admin_vars' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'toolbar_brand' ), 1 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer' ) );
		add_filter( 'update_footer', '__return_empty_string', 11 );

		add_action( 'login_enqueue_scripts', array( __CLASS__, 'login_styles' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'login_url' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'login_text' ) );
		add_filter( 'login_message', array( __CLASS__, 'login_message' ) );
	}

	/**
	 * The palette is written once as custom properties on :root, and the
	 * stylesheet reads them — so changing the brand colour in settings
	 * recolours the whole admin without a rebuild or a second stylesheet.
	 */
	public static function admin_vars() {
		$b = self::get();
		if ( empty( $b['admin_skin'] ) ) return;

		$purple = sanitize_hex_color( $b['purple'] ) ? $b['purple'] : '#70567F';
		$sage   = sanitize_hex_color( $b['sage'] ) ? $b['sage'] : '#A2A55A';
		?>
		<style id="bftd-brand-vars">
			:root{
				--bftd-purple:<?php echo esc_html( $purple ); ?>;
				--bftd-sage:<?php echo esc_html( $sage ); ?>;
			}
		</style>
		<?php
	}

	/**
	 * The URL of an uploaded logo.
	 *
	 * Falls back to the full-size file when the named size does not exist.
	 * WordPress only generates the intermediate sizes for raster images it can
	 * resize, so an SVG — which is what most people upload for a logo — has no
	 * "medium" and would otherwise resolve to nothing at all.
	 *
	 * The login screen falls back to the portal logo when it has none of its
	 * own. One logo is the common case; a separate login mark is the exception,
	 * and setting one should be a choice rather than a second step everybody
	 * has to remember.
	 */
	public static function logo_url( $key = 'logo_id' ) {
		$b  = self::get();
		$id = empty( $b[ $key ] ) ? 0 : (int) $b[ $key ];

		if ( ! $id && 'login_logo' === $key ) {
			$id = empty( $b['logo_id'] ) ? 0 : (int) $b['logo_id'];
		}
		if ( ! $id ) return '';

		$url = wp_get_attachment_image_url( $id, 'medium' );
		if ( ! $url ) $url = wp_get_attachment_url( $id );

		return $url ? $url : '';
	}

	/** Replaces the WordPress mark in the toolbar with the practice name. */
	public static function toolbar_brand( $bar ) {
		if ( ! is_user_logged_in() ) return;
		$bar->remove_node( 'wp-logo' );
		$bar->add_node( array(
			'id'    => 'bftd-brand',
			'title' => '<span class="bftd-tb-mark">Brilliant Futures</span>',
			'href'  => admin_url( 'admin.php?page=' . BFTD_Admin::MENU_SLUG ),
			'meta'  => array( 'title' => 'Brilliant Futures dashboard' ),
		) );
	}

	public static function footer( $text ) {
		return 'Brilliant Futures Tutoring &middot; dashboard by <a href="https://stormwerxdigital.com" target="_blank" rel="noopener">Stormwerx Digital</a>';
	}

	/* ------------------------------------------------------------------ */
	/* Login screen                                                        */
	/* ------------------------------------------------------------------ */

	public static function login_styles() {
		$b      = self::get();
		$logo   = self::logo_url( 'login_logo' );
		$purple = sanitize_hex_color( $b['purple'] ) ? $b['purple'] : '#70567F';
		$sage   = sanitize_hex_color( $b['sage'] ) ? $b['sage'] : '#A2A55A';
		?>
		<style>
			body.login{background:#F9F4F2;font-family:'Source Sans 3',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
			body.login #login{padding-top:6%}
			<?php if ( $logo ) : ?>
			/* Sized for a round badge as readily as a wide wordmark: contain
			   never crops, and the taller box stops a circular mark shrinking
			   to a dot inside a letterbox. */
			body.login #login h1 a{background-image:url('<?php echo esc_url( $logo ); ?>');background-size:contain;background-repeat:no-repeat;background-position:center;width:100%;max-width:260px;height:120px;margin-bottom:14px}
			<?php else : ?>
			body.login #login h1 a{background-image:none;text-indent:0;width:auto;height:auto;line-height:1.2;
				font-family:'Raleway','Trebuchet MS',sans-serif;font-weight:800;font-size:19px;letter-spacing:.13em;
				text-transform:uppercase;color:<?php echo esc_html( $purple ); ?>;overflow:visible}
			<?php endif; ?>
			body.login form{border:1px solid #E2D9E6;border-radius:8px;box-shadow:0 10px 30px -18px rgba(36,30,42,.35)}
			body.login label{color:#39323F}
			body.login input[type=text],body.login input[type=password]{border-color:#E2D9E6;border-radius:4px}
			body.login input[type=text]:focus,body.login input[type=password]:focus{border-color:<?php echo esc_html( $purple ); ?>;box-shadow:0 0 0 1px <?php echo esc_html( $purple ); ?>}
			body.login .button-primary{background:<?php echo esc_html( $sage ); ?>!important;border-color:<?php echo esc_html( $sage ); ?>!important;color:#22260B!important;
				border-radius:3px!important;text-shadow:none!important;box-shadow:none!important;font-weight:600}
			body.login .button-primary:hover{background:#5A6B2C!important;border-color:#5A6B2C!important;color:#fff!important}
			body.login #nav a,body.login #backtoblog a{color:#5F5769}
			body.login #nav a:hover,body.login #backtoblog a:hover{color:<?php echo esc_html( $purple ); ?>}
			body.login .privacy-policy-page-link{display:none}
			.bftd-login-note{margin:0 0 20px;padding:16px 20px;background:#fff;border-left:4px solid <?php echo esc_html( $purple ); ?>;
				border-radius:4px;font-size:14px;line-height:1.55;color:#39323F}
		</style>
		<?php
	}

	public static function login_url( $url ) { return home_url( '/' ); }

	public static function login_text( $text ) { return 'Brilliant Futures Tutoring'; }

	/**
	 * The note is stored as plain text — HTML is stripped at save — so it is
	 * safe to escape and run through wpautop for paragraph breaks.
	 */
	public static function login_message( $message ) {
		$b = self::get();
		if ( '' === trim( (string) $b['login_msg'] ) ) return $message;
		return '<div class="bftd-login-note">' . wpautop( esc_html( $b['login_msg'] ) ) . '</div>' . $message;
	}
}
