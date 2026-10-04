<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Accessibility mode, one person at a time.
 *
 * A setting on the staff member's own account, for poor eyesight, double
 * vision and anyone who finds the normal screens hard to read. When it is on,
 * every wp-admin screen they open, the family portal as they see it, and every
 * report they open (real, preview or sample) are drawn with
 * assets/css/bftd-a11y.css on top of the normal styles. Nobody else's screens
 * change, and nothing about a report changes for the family who receives it.
 *
 * WHO. Staff only. Clients do not get the switch, and a client account that
 * somehow has the setting stored is still drawn normally, because is_on() asks
 * about the role as well as the setting.
 *
 * HOW IT IS SWITCHED. A toggle on the admin bar, on every screen that has one,
 * and a checkbox on the profile. Both write the same user meta, so they cannot
 * disagree. An administrator can also set it for somebody else from their
 * profile, which is how it gets turned on for a tutor who cannot read the
 * screen well enough to find the switch.
 *
 * WHAT IT HOOKS. A class on <body> (bftd-a11y) and the stylesheet. Every rule
 * in the stylesheet is behind that class and inside @media screen, so printing
 * a report while the mode is on prints the report the family gets.
 */
class BFTD_Accessibility {

	const META   = '_bftd_a11y';
	const ACTION = 'bftd_a11y';
	const BODY   = 'bftd-a11y';

	public static function init() {
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );

		// After BFTD_Admin_Experience trims the bar at 999, so it stays.
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 1000 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'toggle' ) );

		// The editor's own text lives in a frame the stylesheet cannot reach.
		add_filter( 'tiny_mce_before_init', array( __CLASS__, 'editor' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'profile_row' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_row' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The one question                                                     */
	/* ------------------------------------------------------------------ */

	/** Has this person switched it on? Staff only, whatever is stored. */
	public static function is_on( $user_id = null ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) return false;
		if ( ! BFTD_Roles::is_staff( $user_id ) ) return false;
		return '1' === (string) get_user_meta( $user_id, self::META, true );
	}

	public static function set( $user_id, $on ) {
		update_user_meta( (int) $user_id, self::META, $on ? '1' : '0' );
	}

	/* ------------------------------------------------------------------ */
	/* Applying it                                                          */
	/* ------------------------------------------------------------------ */

	public static function admin_body_class( $classes ) {
		return self::is_on() ? $classes . ' ' . self::BODY . ' ' : $classes;
	}

	public static function body_class( $classes ) {
		if ( self::is_on() ) $classes[] = self::BODY;
		return $classes;
	}

	/**
	 * The stylesheet and its typeface. Loaded only for somebody who has the
	 * mode on, so nobody else downloads a byte of it.
	 */
	public static function enqueue() {
		if ( ! self::is_on() ) return;
		wp_enqueue_style( 'bftd-a11y-font', self::font_url(), array(), null );
		// Listed after every other plugin stylesheet that may be on the page,
		// so its rules win the ties they are meant to win.
		$after = array_values( array_filter( array( 'bftd-admin', 'bftd-portal', 'bftd-report' ), 'wp_style_is' ) );
		wp_enqueue_style( 'bftd-a11y', self::css_url(), array_merge( array( 'bftd-a11y-font' ), $after ), BFTD_VERSION );
		wp_add_inline_style( 'bftd-a11y', self::sizes_css() );
	}

	/**
	 * Atkinson Hyperlegible, drawn by the Braille Institute for low vision
	 * readers: letters that are easy to confuse (I, l, 1; O, 0; b, d) are
	 * made different from each other on purpose.
	 */
	public static function font_url() {
		return 'https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&display=swap';
	}

	public static function css_url() {
		return BFTD_URL . 'assets/css/bftd-a11y.css';
	}

	/**
	 * For a page drawn outside WordPress's own head, which is the report
	 * preview: the tags to put in its head, and the class for its body.
	 */
	public static function head_tags() {
		if ( ! self::is_on() ) return '';
		return '<link rel="stylesheet" href="' . esc_url( self::font_url() ) . '">'
			. '<link rel="stylesheet" href="' . esc_url( self::css_url() . '?v=' . BFTD_VERSION ) . '">'
			. '<style id="bftd-a11y-sizes">' . self::sizes_css() . '</style>';
	}

	/* ------------------------------------------------------------------ */
	/* Larger text, worked out from the stylesheets themselves              */
	/* ------------------------------------------------------------------ */

	/** The stylesheets whose type sizes the mode enlarges. */
	public static function sources() {
		return array( 'bftd-admin.css', 'bftd-portal.css', 'bftd-report.css' );
	}

	/**
	 * The size a piece of text is drawn at in accessibility mode, for the
	 * size it is drawn at normally.
	 *
	 * Nothing comes out under 16.5px, the smallest a low vision reader
	 * should be asked to read. Text grows by about a fifth, up to 4.5px, so
	 * the order of sizes (heading, body, label) is kept and a big page
	 * heading does not push everything else off the screen. Bigger always
	 * stays bigger.
	 */
	public static function scale( $px ) {
		$px = (float) $px;
		if ( $px <= 0 ) return $px;
		return max( 16.5, round( ( $px + min( $px * 0.22, 4.5 ) ) * 2 ) / 2 );
	}

	/**
	 * Every type size in the plugin's stylesheets, enlarged, as CSS.
	 *
	 * DERIVED, NOT WRITTEN OUT. The three stylesheets set some five hundred
	 * sizes between them. A hand-kept list of overrides would be wrong the
	 * first time somebody added a label, so the overrides are read off the
	 * stylesheets: every rule that sets a px font size (or a font shorthand
	 * with one) is repeated behind body.bftd-a11y with the size from scale(),
	 * and a px line height with it so the bigger text is not clipped. The
	 * at-rules a rule sits in are kept, so a size that only applies on a
	 * phone still only applies on a phone.
	 *
	 * Inside @media screen, so printing is untouched. Cached per version and
	 * per file date, so it is worked out once, not per page.
	 */
	public static function sizes_css() {
		$stamp = BFTD_VERSION;
		foreach ( self::sources() as $f ) $stamp .= '|' . @filemtime( BFTD_PATH . 'assets/css/' . $f );
		$key   = 'bftd_a11y_sizes_' . md5( $stamp );
		$cache = get_transient( $key );
		if ( is_string( $cache ) && '' !== $cache ) return $cache;

		$out = '';
		foreach ( self::sources() as $f ) {
			$css = (string) @file_get_contents( BFTD_PATH . 'assets/css/' . $f );
			$out .= self::enlarge( $css );
		}
		$out = '@media screen{' . $out . '}';
		set_transient( $key, $out, WEEK_IN_SECONDS );
		return $out;
	}

	/** The enlarged rules for one stylesheet. Public so it can be tested. */
	public static function enlarge( $css ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		return self::walk( $css );
	}

	/**
	 * Read a block of CSS rule by rule, keeping nested at-rules.
	 *
	 * Hand-written stylesheets with no strings containing braces, which is
	 * what these are; a brace inside a content string would be the one thing
	 * to trip it, and none of the three has one (a test checks).
	 */
	private static function walk( $css ) {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) break;
			$head = trim( substr( $css, $i, $open - $i ) );
			// Find the matching close brace.
			$depth = 1; $j = $open + 1;
			while ( $j < $len && $depth ) {
				if ( '{' === $css[ $j ] ) $depth++;
				elseif ( '}' === $css[ $j ] ) $depth--;
				$j++;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			$i    = $j;

			// A statement at-rule (@import, @charset) before a block ends at ';'.
			while ( '' !== $head && '@' === $head[0] && false !== strpos( $head, ';' ) ) {
				$head = trim( substr( $head, strpos( $head, ';' ) + 1 ) );
			}
			if ( '' === $head ) continue;

			if ( '@' === $head[0] ) {
				if ( ! preg_match( '/^@(media|container|supports|layer)\b/i', $head ) ) continue;   // keyframes, font-face, page
				if ( preg_match( '/^@media\s+print\b/i', $head ) ) continue;
				$inner = self::walk( $body );
				if ( '' !== $inner ) $out .= $head . '{' . $inner . '}';
				continue;
			}

			$decl = self::sized( $body );
			if ( '' === $decl ) continue;
			$sel = self::scope( $head );
			if ( '' !== $sel ) $out .= $sel . '{' . $decl . '}';
		}
		return $out;
	}

	/** The enlarged size (and px line height) a rule body sets, or ''. */
	private static function sized( $body ) {
		$size = null; $lh = null;
		foreach ( explode( ';', $body ) as $d ) {
			$d = trim( $d );
			if ( preg_match( '/^font-size\s*:\s*([\d.]+)px/i', $d, $m ) ) {
				$size = (float) $m[1];
			} elseif ( preg_match( '/^font\s*:([^;]*?)\b([\d.]+)px(?:\s*\/\s*([\d.]+)(px)?)?/i', $d, $m ) ) {
				$size = (float) $m[2];
				if ( ! empty( $m[4] ) ) $lh = (float) $m[3];
			} elseif ( preg_match( '/^line-height\s*:\s*([\d.]+)px/i', $d, $m ) ) {
				$lh = (float) $m[1];
			}
		}
		if ( null === $size ) return '';
		$new = self::scale( $size );
		$out = 'font-size:' . $new . 'px';
		if ( null !== $lh ) $out .= ';line-height:' . round( $lh * $new / $size, 1 ) . 'px';
		return $out;
	}

	/**
	 * Put each selector behind the mode's body class, so the enlarged size
	 * outweighs the original and applies to nobody else.
	 */
	private static function scope( $selectors ) {
		$out = array();
		foreach ( self::split( $selectors ) as $sel ) {
			$sel = trim( preg_replace( '/\s+/', ' ', $sel ) );
			if ( '' === $sel ) continue;
			if ( preg_match( '/^(:root|html)\b(.*)$/', $sel, $m ) ) {
				// html.wp-toolbar and :root[x] are states of the root itself,
				// which the body cannot stand in for; html .x is a descendant.
				if ( '' !== $m[2] && ! ctype_space( $m[2][0] ) ) continue;
				$rest = trim( $m[2] );
				if ( '' === $rest ) $out[] = 'body.' . self::BODY;
				elseif ( preg_match( '/^body\b(.*)$/', $rest, $b ) ) $out[] = 'body.' . self::BODY . $b[1];
				else $out[] = 'body.' . self::BODY . ' ' . $rest;
				continue;
			}
			if ( preg_match( '/^body\b(.*)$/', $sel, $m ) ) {
				$out[] = 'body.' . self::BODY . $m[1];
				continue;
			}
			$out[] = 'body.' . self::BODY . ' ' . $sel;
		}
		return implode( ',', $out );
	}

	/** A selector list split on its own commas, not those inside :is() or :not(). */
	private static function split( $selectors ) {
		$out = array(); $cur = ''; $depth = 0;
		foreach ( str_split( (string) $selectors ) as $c ) {
			if ( '(' === $c ) $depth++;
			elseif ( ')' === $c ) $depth--;
			if ( ',' === $c && 0 === $depth ) { $out[] = $cur; $cur = ''; continue; }
			$cur .= $c;
		}
		$out[] = $cur;
		return $out;
	}


	public static function body_attr() {
		return self::is_on() ? ' class="' . self::BODY . '"' : '';
	}

	/**
	 * The text inside a visual editor. TinyMCE draws it in a frame of its own,
	 * so it is told directly: the same typeface, size and contrast as the
	 * rest of the screen. What is typed is stored exactly as before.
	 */
	public static function editor( $init ) {
		if ( ! self::is_on() ) return $init;
		$init['content_style'] = ( isset( $init['content_style'] ) ? $init['content_style'] . ' ' : '' ) . self::editor_css();
		$init['content_css']   = ( ! empty( $init['content_css'] ) ? $init['content_css'] . ',' : '' ) . self::font_url();
		return $init;
	}

	/**
	 * The editor's text style, for the editors drawn with the page (above)
	 * and the ones bftd-admin.js builds when a row's notes are opened, which
	 * WordPress's filter never sees. One string, so the two cannot differ.
	 *
	 * No double quotes anywhere in it: WordPress prints this setting inside a
	 * double-quoted JavaScript string without escaping it, and one stray
	 * quote stops every editor on the screen from loading.
	 */
	public static function editor_css() {
		return "body.mce-content-body{font-family:'Atkinson Hyperlegible',Verdana,sans-serif;font-size:19px;line-height:1.6;color:#111;background:#fff}"
			. 'body.mce-content-body a{color:#3D2049;text-decoration:underline}'
			. 'body.mce-content-body *{font-style:normal}';
	}

	/** What bftd-admin.js needs to style an editor it builds. Empty when off. */
	public static function script_config() {
		if ( ! self::is_on() ) return array( 'on' => 0 );
		return array( 'on' => 1, 'editor_style' => self::editor_css(), 'editor_font' => self::font_url() );
	}

	/* ------------------------------------------------------------------ */
	/* Switching it                                                         */
	/* ------------------------------------------------------------------ */

	/** The address of the page being drawn, to come back to. */
	public static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

	/** The link that turns it the other way, back to where you were. */
	public static function toggle_url( $back = null ) {
		if ( null === $back ) $back = self::current_url();
		$args = array(
			'action' => self::ACTION,
			'to'     => self::is_on() ? 0 : 1,
		);
		if ( $back ) $args['back'] = rawurlencode( $back );
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), self::ACTION );
	}

	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() || ! BFTD_Roles::is_staff() ) return;
		$on   = self::is_on();
		$bar->add_node( array(
			'id'     => 'bftd-a11y',
			'parent' => 'top-secondary',
			'title'  => '<span class="ab-icon dashicons dashicons-universal-access-alt" aria-hidden="true"></span>'
				. '<span class="ab-label">' . ( $on ? 'Accessibility mode: on' : 'Accessibility mode' ) . '</span>',
			'href'   => self::toggle_url(),
			'meta'   => array(
				'title' => $on ? 'Turn accessibility mode off' : 'Turn accessibility mode on: larger, clearer text and stronger contrast, for you only',
				'class' => $on ? 'bftd-a11y-on' : 'bftd-a11y-off',
			),
		) );
	}

	public static function toggle() {
		if ( ! is_user_logged_in() || ! BFTD_Roles::is_staff() ) wp_die( 'Accessibility mode is for staff accounts.', 403 );
		check_admin_referer( self::ACTION );
		self::set( get_current_user_id(), ! empty( $_GET['to'] ) );

		$back = isset( $_GET['back'] ) ? esc_url_raw( wp_unslash( $_GET['back'] ) ) : '';
		$to   = wp_validate_redirect( $back, admin_url() );
		wp_safe_redirect( $to );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* The profile                                                          */
	/* ------------------------------------------------------------------ */

	public static function profile_row( $user ) {
		if ( ! BFTD_Roles::is_staff( $user->ID ) ) return;
		if ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) return;
		$mine = get_current_user_id() === (int) $user->ID;
		?>
		<h2 id="bftd-a11y">Accessibility</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Accessibility mode</th>
				<td>
					<input type="hidden" name="bftd_a11y_shown" value="1">
					<label><input type="checkbox" name="bftd_a11y" value="1" <?php checked( self::is_on( $user->ID ) ); ?>>
						<?php echo $mine ? 'Use accessibility mode' : 'Use accessibility mode for this person'; ?></label>
					<p class="description">Larger, clearer text, stronger contrast, underlined links, bigger buttons and a clear outline on whatever has focus. It is for <?php echo $mine ? 'you' : 'this person'; ?> only: on every admin screen, the portal and every report <?php echo $mine ? 'you open' : 'they open'; ?>. Nobody else's screens change, and printing a report prints it as usual. It can also be switched from the top bar.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( empty( $_POST['bftd_a11y_shown'] ) ) return;   // the row was not on the form
		if ( ! current_user_can( 'edit_user', $user_id ) ) return;
		if ( ! BFTD_Roles::is_staff( $user_id ) ) return;
		self::set( $user_id, ! empty( $_POST['bftd_a11y'] ) );
	}
}
