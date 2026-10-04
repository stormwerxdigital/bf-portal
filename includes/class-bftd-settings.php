<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One Settings screen with tabs, rather than a scatter of separate pages
 * buried under the WordPress Settings menu.
 *
 * The Stormwerx plugin grew a settings page per feature, and by the end there
 * were nine of them sitting in a list under Settings with no relationship to
 * each other. Everything here lives under Brilliant Futures where the work is,
 * and every change is written to the activity log with a before and after,
 * the same as a report edit — because "who turned that off" is a question that
 * gets asked about settings more often than about content.
 */
class BFTD_Settings {

	const PAGE_SLUG    = 'bftd-settings';
	const OPTION_PORTAL = 'bftd_portal_settings';
	const OPTION_BANNER = 'bftd_banner';
	const OPTION_HELP   = 'bftd_help_bar';
	const OPTION_ROUTE  = 'bftd_routing';
	const OPTION_TEXT   = 'bftd_standing_text';

	const BANNER_META = '_bftd_dismissed_banner_rev';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 50 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function menu() {
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			'Settings',
			'Settings',
			BFTD_Roles::ADMIN_CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	private static function tabs() {
		return array(
			'brand'   => 'Look and brand',
			'portal'  => 'The portal',
			'login'   => 'Login page',
			'banner'  => 'Portal notice',
			'help'    => 'Help bar',
			'route'   => 'Where messages go',
			'reports' => 'Report wording',
			'pay'     => 'Pay rates',
			'crm'     => 'CRM connection',
			'people'  => 'People and access',
			'perms'   => 'Roles and permissions',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Stored settings                                                     */
	/* ------------------------------------------------------------------ */

	public static function portal() {
		$saved = get_option( self::OPTION_PORTAL, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array(
			'page_id'        => (int) get_option( BFTD_Dashboard::OPTION_PAGE, 0 ),
			'welcome'        => 'Everything about your child\'s reading, in one place.',
			'recording_days' => 14,
			'show_messages'  => 1,
			'show_resources' => 1,
			'show_setup'     => 1,
			'contact_line'   => 'Questions between sessions? Ask on any section, or call (778) 718-3661.',
		) );
	}

	/**
	 * The practice's own version of a piece of standing report wording.
	 *
	 * Returns '' where nobody has changed it, which is the signal the schema
	 * uses to fall back to what the plugin shipped. Storing the shipped text
	 * here on save would freeze it: a later improvement to the wording would
	 * then reach nobody.
	 */
	public static function standing_text( $key = null ) {
		$all = get_option( self::OPTION_TEXT, array() );
		$all = is_array( $all ) ? $all : array();
		if ( null === $key ) return $all;
		return isset( $all[ $key ] ) ? (string) $all[ $key ] : '';
	}

	/**
	 * Who may change the practice's wording.
	 *
	 * Senior manager and above, which is the same bar the per-report fields
	 * use. A tutor manager who could not reword one report should not be able
	 * to reword all of them from a different screen.
	 */
	public static function may_edit_text() {
		return BFTD_Roles::rank( get_current_user_id() ) >= 3;
	}

	public static function banner() {
		$saved = get_option( self::OPTION_BANNER, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array(
			'enabled'  => 0,
			'message'  => 'We are still adding to this portal, so you may see new things appear over the next few weeks.',
			'tone'     => 'purple',
			'revision' => 1,
		) );
	}

	/**
	 * Where a family's message actually lands.
	 *
	 * The assigned tutor is always told. On top of that there is a shared
	 * inbox that gets a copy of everything, and optionally one named person
	 * who gets a personal copy. The fallback matters most: a student with no
	 * tutor assigned yet still has a family who can write, and that message
	 * must not fall into a gap because the record was set up but not staffed.
	 */
	public static function routing() {
		$saved = get_option( self::OPTION_ROUTE, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array(
			'inbox'          => '',
			'designated'     => 0,
			'fallback'       => 'admins',
			'copy_on_new'    => 1,
			'copy_on_item'   => 1,
			'sound'          => 1,
		) );
	}

	/**
	 * Every address to copy on a staff-facing notification about this student,
	 * beyond the assigned tutors themselves.
	 */
	public static function copy_addresses() {
		$r   = self::routing();
		$out = array();

		if ( $r['inbox'] && is_email( $r['inbox'] ) ) $out[] = $r['inbox'];

		if ( $r['designated'] ) {
			$u = get_userdata( (int) $r['designated'] );
			if ( $u && is_email( $u->user_email ) ) $out[] = $u->user_email;
		}

		return array_values( array_unique( $out ) );
	}

	public static function help_bar() {
		$saved = get_option( self::OPTION_HELP, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), array(
			'enabled'   => 1,
			'message'   => 'Not sure what something means? Ask right on the section it is about.',
			'link_text' => 'Book a call',
			'link_url'  => 'https://bftutoring.com/contact/',
		) );
	}

	/** Where "book a call" goes, or nothing if nobody has set one. */
	public static function help_bar_link() {
		$h = self::help_bar();
		return ! empty( $h['link_url'] ) ? $h['link_url'] : '';
	}

	/* ------------------------------------------------------------------ */
	/* The portal notice                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Shown to families at the top of the portal, dismissable per person.
	 * A dismissal sticks until the wording actually changes, at which point
	 * the stored revision bumps and it comes back for everyone — so a real
	 * announcement is seen, and re-saving the same text is not.
	 */
	public static function render_banner( $user_id ) {
		$cfg = self::banner();
		if ( empty( $cfg['enabled'] ) || '' === trim( $cfg['message'] ) ) return;
		if ( (int) get_user_meta( $user_id, self::BANNER_META, true ) >= (int) $cfg['revision'] ) return;

		printf(
			'<div class="bf-banner bf-banner-%1$s" data-rev="%2$d"><p>%3$s</p><button type="button" class="bf-banner-x" aria-label="Dismiss">&times;</button></div>',
			esc_attr( $cfg['tone'] ),
			(int) $cfg['revision'],
			esc_html( $cfg['message'] )
		);
	}

	public static function dismiss_banner( $user_id ) {
		$cfg = self::banner();
		update_user_meta( $user_id, self::BANNER_META, (int) $cfg['revision'] );
	}

	public static function render_help_bar() {
		$cfg = self::help_bar();
		if ( empty( $cfg['enabled'] ) || '' === trim( $cfg['message'] ) ) return;
		?>
		<div class="bf-help">
			<p><?php echo esc_html( $cfg['message'] ); ?></p>
			<?php if ( trim( $cfg['link_text'] ) && trim( $cfg['link_url'] ) ) : ?>
				<a class="bf-btn bf-btn-ghost" href="<?php echo esc_url( $cfg['link_url'] ); ?>"><?php echo esc_html( $cfg['link_text'] ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	public static function handle_post() {
		if ( empty( $_POST['bftd_settings_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_settings_nonce'] ) ), 'bftd_save_settings' ) ) return;
		if ( ! BFTD_Roles::can_manage() ) return;

		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'brand';

		// Re-granting capabilities is an administrator's job. A manager who
		// could rewrite every role's capability set could grant themselves the
		// tier above, which is the one thing the ladder exists to prevent.
		if ( 'perms' === $tab ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				add_settings_error( 'bftd_settings', 'perms', 'Only an Administrator can repair role capabilities.', 'error' );
				return;
			}
			BFTD_Roles::repair();
			add_settings_error( 'bftd_settings', 'perms', 'Every role capability has been re-applied.', 'updated' );
			return;
		}

		switch ( $tab ) {
			case 'brand':  self::save_brand();  break;
			case 'crm': self::save_crm(); break;
			case 'portal': self::save_portal(); break;
			case 'login':  self::save_login();  break;
			case 'banner': self::save_banner(); break;
			case 'help':   self::save_help();   break;
			case 'route':  self::save_route();  break;
			case 'reports': if ( ! self::save_reports() ) return; break;
			case 'pay':    self::save_pay();    break;
		}

		add_settings_error( 'bftd_settings', 'saved', 'Saved.', 'updated' );
	}

	/* ------------------------------------------------------------------ */
	/* What the practice pays                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The rate a tutor gets when nobody has set one on them.
	 *
	 * A default and not a policy. Every tutor can have their own, on their
	 * own profile, and these two numbers are only what a new one starts on
	 * so that an unconfigured tutor's first lesson is not worth nothing.
	 */
	private static function tab_pay() {
		$session = (int) get_option( BFTD_Pay::OPT_SESSION, 0 );
		$diag   = (int) get_option( BFTD_Pay::OPT_DIAGNOSIS, 0 );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bftd_rate_session_default">Default rate per session</label></th>
				<td>
					<input type="text" inputmode="decimal" class="regular-text" id="bftd_rate_session_default"
						name="rate_session" value="<?php echo esc_attr( $session ? number_format( $session / 100, 2, '.', '' ) : '' ); ?>">
					<p class="description">Also what an approved cancelled or rescheduled session pays.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_rate_diagnostic_default">Default rate per reading diagnostic</label></th>
				<td>
					<input type="text" inputmode="decimal" class="regular-text" id="bftd_rate_diagnostic_default"
						name="rate_diagnostic" value="<?php echo esc_attr( $diag ? number_format( $diag / 100, 2, '.', '' ) : '' ); ?>">
				</td>
			</tr>
		</table>
		<h2>How long each one counts as</h2>
		<p class="description">
			Used for one calculation only: the premium owed to an employee who works a
			statutory holiday, which the Employment Standards Act sets per hour rather than
			per session. Nothing else in the plugin counts hours.
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bftd_hours_session">Hours in a session</label></th>
				<td><input type="text" inputmode="decimal" class="small-text" id="bftd_hours_session"
					name="hours_session" value="<?php echo esc_attr( number_format( BFTD_Pay::hours_for( BFTD_Pay::TAUGHT ) / 100, 2, '.', '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_hours_diagnostic">Hours in a reading diagnostic</label></th>
				<td><input type="text" inputmode="decimal" class="small-text" id="bftd_hours_diagnostic"
					name="hours_diagnostic" value="<?php echo esc_attr( number_format( BFTD_Pay::hours_for( BFTD_Pay::DIAGNOSTIC ) / 100, 2, '.', '' ) ); ?>"></td>
			</tr>
		</table>
		<p class="description">
			A rate is copied onto an entry when the session or diagnostic is published, so
			changing one here changes what is paid from now on and never what is already
			on a time card. Set a tutor's own rate on their user profile.
		</p>
		<?php
	}

	private static function save_pay() {
		foreach ( array( 'rate_session' => BFTD_Pay::OPT_SESSION, 'rate_diagnostic' => BFTD_Pay::OPT_DIAGNOSIS ) as $field => $option ) {
			if ( ! isset( $_POST[ $field ] ) ) continue;
			update_option( $option, BFTD_Pay::cents( wp_unslash( $_POST[ $field ] ) ) );
		}
		// Hours, in hundredths, through the same reader as money, because
		// "2.5" and "$2.50" are the same problem: a decimal a person typed
		// that must not be read with a float.
		foreach ( array( 'hours_session' => BFTD_Pay::OPT_HOURS_SESSION, 'hours_diagnostic' => BFTD_Pay::OPT_HOURS_DIAGNOSIS ) as $field => $option ) {
			if ( ! isset( $_POST[ $field ] ) ) continue;
			$h = BFTD_Pay::cents( wp_unslash( $_POST[ $field ] ) );
			if ( $h > 0 ) update_option( $option, $h );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The practice's report wording                                       */
	/* ------------------------------------------------------------------ */

	private static function tab_reports() {
		$items = BFTD_Schema::standing_text();
		$ours  = self::standing_text();
		$may   = self::may_edit_text();
		?>
		<p class="bftd-lede">The paragraphs that read the same on every report: what an assessment measures, and
			how the method addresses what it finds. Changing one here changes it on every report that has not been
			given its own version, including ones already written.</p>

		<?php if ( ! $may ) : ?>
			<div class="notice notice-info inline"><p>Only a Senior Manager or an Administrator can change this wording.
				It is shown here so you can read what families are told.</p></div>
		<?php endif; ?>

		<?php if ( ! $items ) : ?>
			<p>No section carries standing wording yet.</p>
			<?php return;
		endif; ?>

		<?php foreach ( $items as $key => $item ) :
			$own   = isset( $ours[ $key ] ) ? (string) $ours[ $key ] : '';
			$value = ( '' !== trim( $own ) ) ? $own : $item['shipped'];
			$id    = 'bftd_text_' . preg_replace( '/[^a-z0-9]+/i', '_', $key );
			?>
			<div class="bftd-panel bftd-text-block">
				<h3><?php echo esc_html( $item['label'] ); ?></h3>
				<?php if ( $item['heading'] ) : ?>
					<p class="description">Appears under the heading &ldquo;<?php echo esc_html( $item['heading'] ); ?>&rdquo;.</p>
				<?php endif; ?>

				<?php if ( $may ) : ?>
					<?php wp_editor( $value, $id, array(
						'textarea_name' => 'bftd_text[' . $key . ']',
						'textarea_rows' => 8,
						'media_buttons' => false,
						'teeny'         => true,
					) ); ?>
					<p class="bftd-text-state">
						<?php if ( '' !== trim( $own ) ) : ?>
							<span class="bftd-pill bftd-pill-purple">Your wording</span>
							<label><input type="checkbox" name="bftd_text_reset[<?php echo esc_attr( $key ); ?>]" value="1">
								Go back to the wording this plugin shipped with</label>
						<?php else : ?>
							<span class="bftd-pill bftd-pill-quiet">As shipped</span>
							<span class="description">Edit it above and save to make it yours.</span>
						<?php endif; ?>
					</p>
				<?php else : ?>
					<div class="bftd-locked-box"><?php echo wp_kses_post( wpautop( $value ) ); ?></div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
		<?php
	}

	private static function save_reports() {
		if ( ! self::may_edit_text() ) {
			add_settings_error( 'bftd_settings', 'text', 'Only a Senior Manager or an Administrator can change report wording.', 'error' );
			return false;
		}

		$in    = isset( $_POST['bftd_text'] ) ? (array) wp_unslash( $_POST['bftd_text'] ) : array();
		$reset = isset( $_POST['bftd_text_reset'] ) ? (array) wp_unslash( $_POST['bftd_text_reset'] ) : array();
		$items = BFTD_Schema::standing_text();
		$before = self::standing_text();
		$after  = array();
		$labels = array();

		foreach ( $items as $key => $item ) {
			$labels[ $key ] = $item['label'];

			// Asked for the shipped wording back, or left matching it: either
			// way nothing is stored, so a later improvement to the shipped
			// text still reaches this practice.
			if ( ! empty( $reset[ $key ] ) ) continue;

			$val = isset( $in[ $key ] ) ? wp_kses_post( $in[ $key ] ) : '';
			if ( ! BFTD_Fields::has_value( $val ) ) continue;
			if ( trim( $val ) === trim( $item['shipped'] ) ) continue;

			$after[ $key ] = $val;
		}

		update_option( self::OPTION_TEXT, $after );
		self::log_change( 'Report wording', $before, $after, $labels );
		return true;
	}

	private static function log_change( $label, $before, $after, $labels ) {
		$diff = BFTD_Audit::diff( $before, $after, $labels );
		if ( ! $diff ) return;
		BFTD_Audit::log( 'settings_updated', array( 'summary' => $label . ' changed.', 'changes' => $diff ) );
	}

	private static function save_brand() {
		$in     = isset( $_POST['bftd_brand'] ) ? (array) wp_unslash( $_POST['bftd_brand'] ) : array();
		$before = BFTD_Brand::get();

		$purple = isset( $in['purple'] ) ? sanitize_hex_color( $in['purple'] ) : '';
		$sage   = isset( $in['sage'] ) ? sanitize_hex_color( $in['sage'] ) : '';

		$after = array_merge( $before, array(
			'purple'     => $purple ? $purple : $before['purple'],
			'sage'       => $sage ? $sage : $before['sage'],
			'logo_id'    => isset( $in['logo_id'] ) ? absint( $in['logo_id'] ) : 0,
			'admin_skin' => empty( $in['admin_skin'] ) ? 0 : 1,
		) );
		update_option( BFTD_Brand::OPTION_KEY, $after, false );

		self::log_change( 'Look and brand', $before, $after, array(
			'purple' => 'Primary colour', 'sage' => 'Accent colour',
			'logo_id' => 'Portal logo', 'admin_skin' => 'Branded admin',
		) );
	}

	private static function save_login() {
		$in     = isset( $_POST['bftd_login'] ) ? (array) wp_unslash( $_POST['bftd_login'] ) : array();
		$before = BFTD_Brand::get();

		$after = array_merge( $before, array(
			'login_logo' => isset( $in['login_logo'] ) ? absint( $in['login_logo'] ) : 0,
			'login_msg'  => isset( $in['login_msg'] ) ? sanitize_textarea_field( $in['login_msg'] ) : '',
		) );
		update_option( BFTD_Brand::OPTION_KEY, $after, false );

		self::log_change( 'Login page', $before, $after, array(
			'login_logo' => 'Login logo', 'login_msg' => 'Login welcome note',
		) );
	}

	private static function save_portal() {
		$in     = isset( $_POST['bftd_portal'] ) ? (array) wp_unslash( $_POST['bftd_portal'] ) : array();
		$before = self::portal();

		$after = array(
			'page_id'        => isset( $in['page_id'] ) ? absint( $in['page_id'] ) : 0,
			'welcome'        => isset( $in['welcome'] ) ? sanitize_textarea_field( $in['welcome'] ) : '',
			'recording_days' => max( 0, (int) ( isset( $in['recording_days'] ) ? $in['recording_days'] : 14 ) ),
			'show_messages'  => empty( $in['show_messages'] ) ? 0 : 1,
			'show_resources' => empty( $in['show_resources'] ) ? 0 : 1,
			'show_setup'     => empty( $in['show_setup'] ) ? 0 : 1,
			'contact_line'   => isset( $in['contact_line'] ) ? sanitize_text_field( $in['contact_line'] ) : '',
		);
		update_option( self::OPTION_PORTAL, $after, false );
		if ( $after['page_id'] ) update_option( BFTD_Dashboard::OPTION_PAGE, $after['page_id'] );

		self::log_change( 'Portal settings', $before, $after, array(
			'page_id' => 'Portal page', 'welcome' => 'Welcome line',
			'recording_days' => 'Recording window, days',
			'show_messages' => 'Messages screen', 'show_resources' => 'Resources screen',
			'show_setup' => 'Setup screen', 'contact_line' => 'Contact line',
		) );
	}

	private static function save_banner() {
		$in     = isset( $_POST['bftd_banner'] ) ? (array) wp_unslash( $_POST['bftd_banner'] ) : array();
		$before = self::banner();

		$message = isset( $in['message'] ) ? sanitize_textarea_field( $in['message'] ) : '';
		$tone    = isset( $in['tone'] ) ? sanitize_key( $in['tone'] ) : 'purple';
		if ( ! in_array( $tone, array( 'purple', 'sage', 'clay' ), true ) ) $tone = 'purple';

		$after = array(
			'enabled'  => empty( $in['enabled'] ) ? 0 : 1,
			'message'  => $message,
			'tone'     => $tone,
			// Only new wording brings it back for people who dismissed it.
			'revision' => $message !== $before['message'] ? (int) $before['revision'] + 1 : (int) $before['revision'],
		);
		update_option( self::OPTION_BANNER, $after, false );

		self::log_change( 'Portal notice', $before, $after, array(
			'enabled' => 'Notice shown', 'message' => 'Notice wording', 'tone' => 'Notice colour',
		) );
	}

	private static function save_route() {
		$in     = isset( $_POST['bftd_route'] ) ? (array) wp_unslash( $_POST['bftd_route'] ) : array();
		$before = self::routing();

		$fallback = isset( $in['fallback'] ) ? sanitize_key( $in['fallback'] ) : 'admins';
		if ( ! in_array( $fallback, array( 'admins', 'inbox', 'none' ), true ) ) $fallback = 'admins';

		$after = array(
			'inbox'        => isset( $in['inbox'] ) ? sanitize_email( $in['inbox'] ) : '',
			'designated'   => isset( $in['designated'] ) ? absint( $in['designated'] ) : 0,
			'fallback'     => $fallback,
			'copy_on_new'  => empty( $in['copy_on_new'] ) ? 0 : 1,
			'copy_on_item' => empty( $in['copy_on_item'] ) ? 0 : 1,
			'sound'        => empty( $in['sound'] ) ? 0 : 1,
		);
		update_option( self::OPTION_ROUTE, $after, false );

		self::log_change( 'Message routing', $before, $after, array(
			'inbox' => 'Shared inbox', 'designated' => 'Named recipient',
			'fallback' => 'When nobody is assigned', 'copy_on_new' => 'Copy on new messages',
			'copy_on_item' => 'Copy on completed lists', 'sound' => 'Sound on a new message',
		) );
	}

	private static function save_help() {
		$in     = isset( $_POST['bftd_help'] ) ? (array) wp_unslash( $_POST['bftd_help'] ) : array();
		$before = self::help_bar();

		$after = array(
			'enabled'   => empty( $in['enabled'] ) ? 0 : 1,
			'message'   => isset( $in['message'] ) ? sanitize_text_field( $in['message'] ) : '',
			'link_text' => isset( $in['link_text'] ) ? sanitize_text_field( $in['link_text'] ) : '',
			'link_url'  => isset( $in['link_url'] ) ? esc_url_raw( $in['link_url'] ) : '',
		);
		update_option( self::OPTION_HELP, $after, false );

		self::log_change( 'Help bar', $before, $after, array(
			'enabled' => 'Help bar shown', 'message' => 'Help bar wording',
			'link_text' => 'Button label', 'link_url' => 'Button link',
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! BFTD_Roles::can_manage() ) return;

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'brand';
		$tabs = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) $tab = 'brand';
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap bftd-wrap">
			<h1>Settings</h1>
			<p class="bftd-lede">How the portal looks, what it shows, and what families see when they arrive. Every change here is recorded in the activity log with what it was before.</p>
			<?php settings_errors( 'bftd_settings' ); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
				<a class="nav-tab" href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Emails::PAGE_SLUG ) ); ?>">Emails &rarr;</a>
			</h2>

			<?php if ( 'people' === $tab ) : self::render_people(); return; endif; ?>
			<?php if ( 'perms' === $tab ) : self::render_perms(); return; endif; ?>

			<form method="post" class="bftd-settings-form">
				<?php wp_nonce_field( 'bftd_save_settings', 'bftd_settings_nonce' ); ?>
				<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>">
				<?php
				switch ( $tab ) {
					case 'crm': self::tab_crm(); break;
					case 'portal': self::tab_portal(); break;
					case 'login':  self::tab_login();  break;
					case 'banner': self::tab_banner(); break;
					case 'help':   self::tab_help();   break;
					case 'route':  self::tab_route();  break;
					case 'reports': self::tab_reports(); break;
					case 'pay':    self::tab_pay();    break;
					default:       self::tab_brand();
				}
				?>
				<p><button class="button button-primary">Save</button></p>
			</form>
		</div>
		<?php
	}

	private static function media_field( $name, $id, $label, $help ) {
		$url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<div class="bftd-media" data-target="<?php echo esc_attr( $name ); ?>">
					<input type="hidden" id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $id; ?>">
					<div class="bftd-media-prev"><?php echo $url ? '<img alt="" src="' . esc_url( $url ) . '">' : ''; ?></div>
					<button type="button" class="button bftd-media-pick">Choose image</button>
					<button type="button" class="button-link bftd-media-clear">Remove</button>
				</div>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}

	private static function tab_brand() {
		$b = BFTD_Brand::get();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bftd_purple">Primary colour</label></th>
				<td>
					<input type="color" id="bftd_purple" name="bftd_brand[purple]" value="<?php echo esc_attr( $b['purple'] ); ?>">
					<code><?php echo esc_html( $b['purple'] ); ?></code>
					<p class="description">Taken from the live bftutoring.com theme. Headers, links and the admin sidebar.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_sage">Accent colour</label></th>
				<td>
					<input type="color" id="bftd_sage" name="bftd_brand[sage]" value="<?php echo esc_attr( $b['sage'] ); ?>">
					<code><?php echo esc_html( $b['sage'] ); ?></code>
					<p class="description">Buttons and anything a family is meant to act on.</p>
				</td>
			</tr>
			<?php self::media_field( 'bftd_brand[logo_id]', (int) $b['logo_id'], 'Portal logo', 'Shown at the top of the family portal, and on the sign in screen unless you set a different one there. Leave empty to use the wordmark.' ); ?>
			<tr>
				<th scope="row">Branded admin</th>
				<td>
					<label><input type="checkbox" name="bftd_brand[admin_skin]" value="1" <?php checked( $b['admin_skin'], 1 ); ?>> Use the Brilliant Futures colours in wp-admin</label>
					<p class="description">Off gives you plain WordPress grey. Nothing about what a screen does changes either way.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function save_crm() {
		if ( ! empty( $_POST['bftd_crm_new_key'] ) ) BFTD_CRM::new_key();
	}

	/**
	 * What the CRM needs in order to create students here.
	 *
	 * The flow this serves: basics are entered in the CRM, the CRM creates
	 * the student record, and from then on the journey is managed here.
	 */
	private static function tab_crm() {
		$key = BFTD_CRM::key();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Where the CRM posts</th>
				<td>
					<input type="text" class="large-text code" readonly onfocus="this.select()"
						value="<?php echo esc_attr( BFTD_CRM::endpoint_url() ); ?>">
					<p class="description">
						A <code>POST</code> of the student's details, as JSON, with the key below in an
						<code>X-BFTD-Key</code> header. The column names from your CRM student list are
						understood as they are, including <code>From/To</code> as a single value.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Connection key</th>
				<td>
					<?php if ( $key ) : ?>
						<input type="text" class="large-text code" readonly onfocus="this.select()" value="<?php echo esc_attr( $key ); ?>">
						<p class="description">
							Anyone holding this key can create students here, so treat it as a password.
							Replacing it stops the old one working straight away, so paste the new one
							into the CRM before the next sync runs.
						</p>
						<p><button class="button" name="bftd_crm_new_key" value="1">Replace this key</button></p>
					<?php else : ?>
						<p><strong>Not set up yet.</strong> Until a key exists, the endpoint refuses everything.</p>
						<p><button class="button button-primary" name="bftd_crm_new_key" value="1">Create a key</button></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Who owns what</th>
				<td>
					<p style="margin-top:6px">The CRM enters the basics and creates the record. After that this dashboard runs the customer journey.</p>
					<p><strong>The CRM keeps these in step:</strong>
						<?php
						$labels = BFTD_MetaBoxes::crm_fields();
						$keep   = array_diff( BFTD_CRM::fields(), BFTD_CRM::seed_only() );
						$names  = array();
						foreach ( $keep as $k ) $names[] = isset( $labels[ $k ] ) ? $labels[ $k ]['label'] : $k;
						echo esc_html( implode( ', ', $names ) );
						?>.
						A family that changes its phone number tells the CRM, so there is no reason for this to be the last to know.
						Anything you have typed here is still left alone.
					</p>
					<p><strong>The CRM sets these once, when it creates the student, and never again:</strong>
						<?php
						$names = array();
						foreach ( BFTD_CRM::seed_only() as $k ) $names[] = isset( $labels[ $k ] ) ? $labels[ $k ]['label'] : $k;
						echo esc_html( implode( ', ', $names ) );
						?>.
						These are the journey. If the CRM kept writing them, a student you moved to Active
						would be dragged back to Prospect by the next sync.
					</p>
					<p>New students arrive as drafts, so somebody here decides when a prospect becomes a family with a portal.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function tab_portal() {
		$p     = self::portal();
		$pages = get_pages( array( 'sort_column' => 'post_title' ) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Clock</th>
				<td>
					<p style="margin:6px 0 4px"><strong><?php
						echo esc_html( BFTD_Time::format( 'l j F Y, g:i A' ) . ' ' . BFTD_Time::abbreviation() );
					?></strong></p>
					<p class="description">
						Every date and time in the portal, in reports, in emails and in the logs is
						<?php echo esc_html( BFTD_Time::zone()->getName() ); ?>, which is Pacific: PDT through the
						summer and PST through the winter, without anybody having to change it.
						This does not follow the WordPress timezone setting, so moving the site or its server
						cannot quietly shift a session time.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_page">Portal page</label></th>
				<td>
					<select id="bftd_page" name="bftd_portal[page_id]">
						<option value="0">Not set</option>
						<?php foreach ( $pages as $page ) : ?>
							<option value="<?php echo (int) $page->ID; ?>" <?php selected( $p['page_id'], $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $p['page_id'] ) : ?>
						<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( (string) get_permalink( $p['page_id'] ) ); ?>">View it</a>
					<?php endif; ?>
					<p class="description">The page holding the <code>[bf_portal]</code> shortcode. Activation made one called Portal; move or rename it freely and point this at it.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_welcome">Welcome line</label></th>
				<td><textarea id="bftd_welcome" class="large-text" rows="2" name="bftd_portal[welcome]"><?php echo esc_textarea( $p['welcome'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_contact">Contact line</label></th>
				<td><input type="text" id="bftd_contact" class="large-text" name="bftd_portal[contact_line]" value="<?php echo esc_attr( $p['contact_line'] ); ?>">
				<p class="description">Sits in the portal footer, on every screen.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_rec">Recordings stay available for</label></th>
				<td><input type="number" min="0" id="bftd_rec" class="small-text" name="bftd_portal[recording_days]" value="<?php echo (int) $p['recording_days']; ?>"> days
				<p class="description">The default for new students. A student record can override it. The check happens when the page renders, so a link can never outlive the window because a scheduled job did not run.</p></td>
			</tr>
			<tr>
				<th scope="row">Screens families see</th>
				<td>
					<label class="bftd-check"><input type="checkbox" name="bftd_portal[show_messages]" value="1" <?php checked( $p['show_messages'], 1 ); ?>> Messages</label>
					<label class="bftd-check"><input type="checkbox" name="bftd_portal[show_resources]" value="1" <?php checked( $p['show_resources'], 1 ); ?>> Resources</label>
					<label class="bftd-check"><input type="checkbox" name="bftd_portal[show_setup]" value="1" <?php checked( $p['show_setup'], 1 ); ?>> Setup</label>
					<p class="description">Home, the progress report and the reading diagnostic are always shown. Turning a screen off here hides the tab; nothing is deleted and conversations on it are kept.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function tab_login() {
		$b = BFTD_Brand::get();
		?>
		<table class="form-table" role="presentation">
			<?php
			$portal_logo = BFTD_Brand::logo_url( 'logo_id' );
			self::media_field(
				'bftd_login[login_logo]',
				(int) $b['login_logo'],
				'Login logo',
				$portal_logo
					? 'Only set this if the sign in screen should use a different mark from the portal. Left empty, it uses your portal logo.'
					: 'Replaces the WordPress mark above the sign in form. Left empty, it uses your portal logo, or the wordmark if you have not set one.'
			);
			?>
			<?php if ( $portal_logo && empty( $b['login_logo'] ) ) : ?>
				<tr>
					<th scope="row">Currently using</th>
					<td>
						<img src="<?php echo esc_url( $portal_logo ); ?>" alt="" style="max-width:180px;height:auto;border:1px solid var(--bftd-line);border-radius:4px;padding:8px;background:#fff;">
						<p class="description">Your portal logo, inherited. Nothing to do unless you want a different one here.</p>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"><label for="bftd_login_msg">Welcome note</label></th>
				<td>
					<textarea id="bftd_login_msg" class="large-text" rows="3" name="bftd_login[login_msg]" placeholder="Welcome back. Sign in to see reports, session notes and recordings."><?php echo esc_textarea( $b['login_msg'] ); ?></textarea>
					<p class="description">Shown in a highlighted box above the form. Leave blank for nothing.</p>
				</td>
			</tr>
		</table>
		<p class="description">The sign in form itself, password reset and every other WordPress route are left exactly as they ship. This is colour and copy only.</p>
		<?php
	}

	private static function tab_banner() {
		$c = self::banner();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Show the notice</th>
				<td><label><input type="checkbox" name="bftd_banner[enabled]" value="1" <?php checked( $c['enabled'], 1 ); ?>> Show it to families at the top of the portal</label></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_banner_msg">Wording</label></th>
				<td>
					<textarea id="bftd_banner_msg" class="large-text" rows="3" name="bftd_banner[message]"><?php echo esc_textarea( $c['message'] ); ?></textarea>
					<p class="description">Each family can dismiss it. Changing the wording brings it back for everyone who dismissed the old one; re-saving the same words, or just toggling it off and on, does not.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Colour</th>
				<td>
					<?php foreach ( array( 'purple' => 'Purple, for news', 'sage' => 'Sage, for something good', 'clay' => 'Clay, for something that needs attention' ) as $k => $label ) : ?>
						<label class="bftd-radio"><input type="radio" name="bftd_banner[tone]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $c['tone'], $k ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function tab_help() {
		$c = self::help_bar();
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Show the help bar</th>
				<td><label><input type="checkbox" name="bftd_help[enabled]" value="1" <?php checked( $c['enabled'], 1 ); ?>> Show it at the foot of every portal screen</label></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_help_msg">Wording</label></th>
				<td><input type="text" id="bftd_help_msg" class="large-text" name="bftd_help[message]" value="<?php echo esc_attr( $c['message'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_help_text">Button label</label></th>
				<td><input type="text" id="bftd_help_text" class="regular-text" name="bftd_help[link_text]" value="<?php echo esc_attr( $c['link_text'] ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_help_url">Button link</label></th>
				<td><input type="url" id="bftd_help_url" class="regular-text" name="bftd_help[link_url]" value="<?php echo esc_attr( $c['link_url'] ); ?>"></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * What each role actually holds on this site, read back from WordPress
	 * rather than from what the code meant to grant. The two can drift — a
	 * role edited by another plugin, an update that stopped half way — and
	 * when they do, the useful thing is to see it rather than to reason about
	 * what should have happened.
	 */
	private static function render_perms() {
		$audit   = BFTD_Roles::audit_capabilities();
		$broken  = false;
		foreach ( $audit as $row ) {
			if ( ! $row['exists'] || $row['missing'] ) $broken = true;
		}
		$tiers = BFTD_Roles::tiers();
		?>
			<p class="bftd-lede">Every role, and every capability it should hold, checked against what WordPress actually has stored right now.</p>

			<?php if ( $broken ) : ?>
				<div class="notice notice-warning inline"><p><strong>Something is missing.</strong> A role is short of capabilities it should have, which is what makes a screen say "you do not have permission" to someone who plainly should. Repairing re-applies every grant; it changes nothing else and is safe to run any time.</p></div>
			<?php else : ?>
				<div class="notice notice-success inline"><p>Every role holds everything it should.</p></div>
			<?php endif; ?>

			<table class="widefat striped">
				<thead><tr><th style="width:180px;">Role</th><th style="width:90px;">Rank</th><th style="width:130px;">Capabilities</th><th>Missing</th><th style="width:110px;">People</th></tr></thead>
				<tbody>
				<?php foreach ( $audit as $slug => $row ) :
					$count = count( get_users( array( 'role' => $slug, 'fields' => 'ID' ) ) );
					?>
					<tr>
						<td><strong><?php echo esc_html( isset( $tiers[ $slug ] ) ? $tiers[ $slug ]['label'] : $slug ); ?></strong>
							<div class="bftd-msg-who"><code><?php echo esc_html( $slug ); ?></code></div></td>
						<td><?php echo esc_html( (string) BFTD_Roles::role_rank( $slug ) ); ?></td>
						<td><?php echo $row['exists']
							? esc_html( ( $row['expected'] - count( $row['missing'] ) ) . ' of ' . $row['expected'] )
							: '<span class="bftd-count">role missing</span>'; ?></td>
						<td>
							<?php if ( ! $row['exists'] ) : ?>
								<span class="bftd-none">The role does not exist on this site.</span>
							<?php elseif ( ! $row['missing'] ) : ?>
								<span class="bftd-none">Nothing</span>
							<?php else : ?>
								<?php foreach ( $row['missing'] as $cap ) : ?><code class="bftd-cap-bad"><?php echo esc_html( $cap ); ?></code> <?php endforeach; ?>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $count; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Who can do what</h2>
			<p class="bftd-lede">The rule is one comparison: you may act on a role strictly below your own rank, never at or above it.</p>
			<table class="widefat striped">
				<thead><tr><th>Can they&hellip;</th>
					<th style="width:110px;">Admin</th><th style="width:110px;">Senior Mgr</th>
					<th style="width:110px;">Tutor Mgr</th><th style="width:110px;">Tutor</th></tr></thead>
				<tbody>
				<?php
				$rows = array(
					array( 'See every student',                    'manage', 'manage', 'manage', 'no' ),
					array( 'Open any report or session',            'yes',    'yes',    'yes',    'their students only' ),
					array( 'Add and edit skills and activities',    'yes',    'yes',    'yes',    'read only' ),
					array( 'Delete a report or session',            'yes',    'yes',    'yes',    'no' ),
					array( 'Assign tutors to a student',           'yes',    'yes',    'yes',    'no' ),
					array( 'Change settings and email wording',    'yes',    'yes',    'no',     'no' ),
					array( 'Read the whole activity log',          'yes',    'yes',    'no',     'no' ),
					array( 'Add or remove a Tutor Manager',        'yes',    'yes',    'no',     'no' ),
					array( 'Add or remove a Senior Manager',       'yes',    'no',     'no',     'no' ),
					array( 'Repair capabilities on this screen',   'yes',    'no',     'no',     'no' ),
					array( 'Touch plugins, themes or WordPress',   'yes',    'no',     'no',     'no' ),
				);
				foreach ( $rows as $r ) :
					echo '<tr><td>' . esc_html( $r[0] ) . '</td>';
					foreach ( array_slice( $r, 1 ) as $v ) {
						$v = ( 'manage' === $v ) ? 'yes' : $v;
						$class = ( 'yes' === $v ) ? 'bftd-yes' : ( 'no' === $v ? 'bftd-no' : 'bftd-part' );
						echo '<td><span class="' . esc_attr( $class ) . '">' . esc_html( $v ) . '</span></td>';
					}
					echo '</tr>';
				endforeach;
				?>
				</tbody>
			</table>

			<?php self::render_person_check(); ?>
			<?php self::render_report(); ?>

			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<form method="post" style="margin-top:18px;">
					<?php wp_nonce_field( 'bftd_save_settings', 'bftd_settings_nonce' ); ?>
					<input type="hidden" name="tab" value="perms">
					<button class="button button-primary">Repair capabilities</button>
					<span class="description" style="margin-left:10px;">Re-applies every grant. Safe to run at any time; nothing else changes.</span>
				</form>
			<?php else : ?>
				<p class="description" style="margin-top:18px;">Repairing capabilities is an Administrator's job.</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The whole permission state as plain text, in one box, ready to copy.
	 *
	 * When something is refusing access on a live site, the fastest way to a
	 * fix is the actual state rather than a description of it — so this is one
	 * click and a paste, not a tour of six screens.
	 */
	private static function render_report() {
		global $wp_version;

		$who   = isset( $_GET['who'] ) ? (int) $_GET['who'] : 0;
		$lines = array();

		$lines[] = 'Brilliant Futures Dashboard: permission report';
		$lines[] = 'Generated ' . BFTD_Time::format( 'Y-m-d H:i' ) . ' ' . BFTD_Time::abbreviation();
		$lines[] = '';
		$lines[] = 'Plugin ' . BFTD_VERSION . ' | caps version stored: ' . (int) get_option( 'bftd_caps_version', 0 )
			. ' | expected: ' . BFTD_Roles::CAPS_VERSION;
		$lines[] = 'WordPress ' . $wp_version . ' | PHP ' . PHP_VERSION;
		$lines[] = 'Portal page id: ' . (int) get_option( BFTD_Dashboard::OPTION_PAGE, 0 );
		$lines[] = 'Clock: ' . BFTD_Time::zone()->getName() . ' (' . BFTD_Time::abbreviation() . ')'
			. ' | WordPress site setting: ' . ( get_option( 'timezone_string' ) ? get_option( 'timezone_string' ) : 'not set, offset ' . get_option( 'gmt_offset', 0 ) );
		$lines[] = '';

		$lines[] = 'ROLES';
		foreach ( BFTD_Roles::audit_capabilities() as $slug => $row ) {
			$n = count( get_users( array( 'role' => $slug, 'fields' => 'ID' ) ) );
			$lines[] = sprintf(
				'  %-22s exists:%-3s caps:%d/%d  people:%d  missing:%s',
				$slug,
				$row['exists'] ? 'yes' : 'NO',
				$row['expected'] - count( $row['missing'] ),
				$row['expected'],
				$n,
				$row['missing'] ? implode( ' ', $row['missing'] ) : 'none'
			);
		}

		$lines[] = '';
		$lines[] = 'POST TYPES (registered this request)';
		foreach ( BFTD_Roles::post_type_plurals() as $singular => $plural ) {
			$obj = get_post_type_object( $singular );
			$lines[] = sprintf(
				'  %-18s registered:%-4s create_posts cap:%s',
				$singular,
				$obj ? 'yes' : 'NO',
				$obj && isset( $obj->cap->create_posts ) ? $obj->cap->create_posts : 'n/a'
			);
		}

		if ( $who ) {
			$user = get_userdata( $who );
			if ( $user ) {
				$lines[] = '';
				$lines[] = 'ACCOUNT: ' . $user->display_name . ' (' . $user->user_email . ')';
				$lines[] = '  roles stored: ' . implode( ', ', (array) $user->roles );
				$lines[] = '  is_staff:' . ( BFTD_Roles::is_staff( $who ) ? 'y' : 'N' )
					. '  can_manage:' . ( BFTD_Roles::can_manage( $who ) ? 'y' : 'N' )
					. '  can_manage_team:' . ( BFTD_Roles::can_manage_team( $who ) ? 'y' : 'N' )
					. '  manage_options:' . ( user_can( $who, 'manage_options' ) ? 'y' : 'N' );
				$lines[] = '  students visible: ' . count( BFTD_Access::visible_student_ids( $who ) );
				foreach ( BFTD_Roles::post_type_plurals() as $singular => $plural ) {
					$obj  = get_post_type_object( $singular );
					$ccap = $obj && isset( $obj->cap->create_posts ) ? $obj->cap->create_posts : ( 'create_' . $plural );
					$lines[] = sprintf(
						'  %-18s create:%-3s edit:%-3s edit_others:%-3s publish:%-3s',
						$singular,
						user_can( $who, $ccap ) ? 'y' : 'NO',
						user_can( $who, 'edit_' . $plural ) ? 'y' : 'NO',
						user_can( $who, 'edit_others_' . $plural ) ? 'y' : 'NO',
						user_can( $who, 'publish_' . $plural ) ? 'y' : 'NO'
					);
				}
			}
		} else {
			$lines[] = '';
			$lines[] = '(Choose a person above to include their account in this report.)';
		}

		$lines[] = '';
		$lines[] = 'ADD NEW PAGES (a type with no registered entry cannot be created)';
		global $submenu;
		$reg = array();
		foreach ( (array) $submenu as $parent => $rows ) {
			foreach ( (array) $rows as $r ) $reg[ $r[2] ] = $parent;
		}
		foreach ( BFTD_Roles::post_types() as $pt ) {
			$slug = 'post-new.php?post_type=' . $pt;
			$lines[] = sprintf( '  %-18s %s', $pt,
				isset( $reg[ $slug ] ) ? 'registered under [' . $reg[ $slug ] . ']'
					: ( in_array( $pt, BFTD_CPT::hidden_add_rows(), true ) ? 'registered, row hidden' : 'not registered, so it cannot be created' ) );
		}

		$lines[] = '';
		$lines[] = 'ADMIN MENU BLACKLIST (why a screen can refuse someone who has the capability)';
		global $_wp_submenu_nopriv, $_wp_menu_nopriv;
		$blocked = array();
		foreach ( (array) $_wp_submenu_nopriv as $parent => $rows ) {
			foreach ( (array) $rows as $slug => $x ) {
				$blocked[] = '  [' . $parent . '] ' . $slug;
				if ( 'post-new.php' === $slug ) {
					$blocked[] = '  ^^ THIS BLOCKS Add New FOR EVERY POST TYPE';
				}
			}
		}
		$lines = array_merge( $lines, $blocked ? $blocked : array( '  nothing blacklisted' ) );

		$lines[] = '';
		$lines[] = 'OTHER PLUGINS ACTIVE';
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$lines[] = '  ' . $plugin;
		}

		$text = implode( "\n", $lines );
		?>
		<h2>Send this to support</h2>
		<p class="bftd-lede">Everything about the current permission state, in one box. Copy it and send it over rather than describing what happened.</p>
		<textarea class="large-text code" id="bftd-report" rows="14" readonly onclick="this.select()"><?php echo esc_textarea( $text ); ?></textarea>
		<p>
			<button type="button" class="button" onclick="var t=document.getElementById('bftd-report');t.select();document.execCommand('copy');this.textContent='Copied';">Copy the report</button>
		</p>
		<?php
	}

	/**
	 * Resolve every gate for one named person.
	 *
	 * A role-level audit says what a role should hold; this says what a
	 * specific account actually resolves to, which is the question being asked
	 * whenever someone reports "it says I am not allowed". It reads through
	 * user_can(), the same call every screen makes, so what it reports is what
	 * WordPress will do rather than what we believe it should.
	 */
	private static function render_person_check() {
		$who = isset( $_GET['who'] ) ? (int) $_GET['who'] : 0;
		$staff = get_users( array(
			'role__in' => array( BFTD_Roles::TUTOR_ROLE, BFTD_Roles::MANAGER_ROLE, BFTD_Roles::SENIOR_ROLE, 'administrator' ),
			'orderby'  => 'display_name',
		) );
		$base = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=perms' );
		?>
		<h2>Check one person</h2>
		<p class="bftd-lede">What this exact account resolves to right now, read through the same check every screen makes.</p>

		<form method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
			<input type="hidden" name="tab" value="perms">
			<select name="who" onchange="this.form.submit()">
				<option value="0">Choose a person</option>
				<?php foreach ( $staff as $u ) : ?>
					<option value="<?php echo (int) $u->ID; ?>" <?php selected( $who, $u->ID ); ?>><?php echo esc_html( $u->display_name . ' · ' . BFTD_Roles::role_name( $u->ID ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button">Check</button>
		</form>

		<?php
		if ( ! $who ) return;
		$user = get_userdata( $who );
		if ( ! $user ) { echo '<p class="bftd-none">No such account.</p>'; return; }

		$checks = array( 'Signed-in role' => implode( ', ', (array) $user->roles ) );

		$gates = array(
			'Counts as staff'                => BFTD_Roles::is_staff( $who ),
			'Can run the practice'           => BFTD_Roles::can_manage( $who ),
			'Can add or remove managers'     => BFTD_Roles::can_manage_team( $who ),
			'Is a WordPress administrator'   => user_can( $who, 'manage_options' ),
		);

		$caps = array();
		foreach ( BFTD_Roles::post_type_plurals() as $singular => $plural ) {
			$label = ucfirst( str_replace( 'bftd_', '', $singular ) );
			$caps[ 'Create a ' . $label ]  = user_can( $who, 'create_' . $plural );
			$caps[ 'Open a ' . $label ]    = user_can( $who, 'edit_' . $plural );
			$caps[ 'Open others\' ' . $label ] = user_can( $who, 'edit_others_' . $plural );
			$caps[ 'Publish a ' . $label ] = user_can( $who, 'publish_' . $plural );
		}

		$missing = array_keys( array_filter( array_merge( $gates, $caps ), function ( $v ) { return ! $v; } ) );
		?>
		<table class="widefat striped" style="max-width:820px;margin-top:12px;">
			<tbody>
				<tr><th style="width:280px;">Account</th><td><?php echo esc_html( $user->display_name . ' · ' . $user->user_email ); ?></td></tr>
				<tr><th>Role stored on the account</th><td><code><?php echo esc_html( $checks['Signed-in role'] ); ?></code>
					<?php if ( ! array_intersect( (array) $user->roles, array_keys( BFTD_Roles::tiers() ) ) ) : ?>
						<span class="bftd-count">not one of ours</span>
					<?php endif; ?>
				</td></tr>
				<tr><th>Students they can see</th><td><?php echo (int) count( BFTD_Access::visible_student_ids( $who ) ); ?></td></tr>
				<?php foreach ( array_merge( $gates, $caps ) as $label => $ok ) : ?>
					<tr><th><?php echo esc_html( $label ); ?></th>
						<td><span class="<?php echo $ok ? 'bftd-yes' : 'bftd-part'; ?>"><?php echo $ok ? 'yes' : 'NO'; ?></span></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $missing ) : ?>
			<div class="notice notice-warning inline"><p>
				<strong>This account cannot do <?php echo esc_html( (string) count( $missing ) ); ?> of the things its role should allow.</strong>
				If the role above is one of ours, Repair capabilities below will fix it. If the role says "not one of ours", the account is on a plain WordPress role and needs the right one setting on
				<a href="<?php echo esc_url( (string) get_edit_user_link( $who ) ); ?>">their profile</a>.
			</p></div>
		<?php else : ?>
			<div class="notice notice-success inline"><p>This account resolves to everything its role should allow.</p></div>
		<?php endif; ?>
		<?php
	}

	private static function tab_route() {
		$r     = self::routing();
		$staff = get_users( array(
			'role__in' => array( BFTD_Roles::TUTOR_ROLE, BFTD_Roles::MANAGER_ROLE, BFTD_Roles::SENIOR_ROLE, 'administrator' ),
			'orderby'  => 'display_name',
		) );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="bftd_inbox">Shared inbox</label></th>
				<td>
					<input type="email" id="bftd_inbox" class="regular-text" name="bftd_route[inbox]" value="<?php echo esc_attr( $r['inbox'] ); ?>" placeholder="hello@bftutoring.com">
					<p class="description">Gets a copy of every staff notification, whoever the student is assigned to. Leave blank for none.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="bftd_designated">Named recipient</label></th>
				<td>
					<select id="bftd_designated" name="bftd_route[designated]">
						<option value="0">Nobody in particular</option>
						<?php foreach ( $staff as $u ) : ?>
							<option value="<?php echo (int) $u->ID; ?>" <?php selected( $r['designated'], $u->ID ); ?>><?php echo esc_html( $u->display_name . ' · ' . $u->user_email ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">One person who gets a personal copy on top of the shared inbox. Useful when one owner reads everything.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">When nobody is assigned</th>
				<td>
					<?php foreach ( array(
						'admins' => 'Tell every administrator',
						'inbox'  => 'Send it to the shared inbox only',
						'none'   => 'Do not email anyone, it waits in Conversations',
					) as $k => $label ) : ?>
						<label class="bftd-radio"><input type="radio" name="bftd_route[fallback]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $r['fallback'], $k ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description">A student can have a family writing before a tutor is assigned. Whatever you pick, the message still shows in Conversations with a count on the menu, so it cannot be lost.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Copy the shared inbox on</th>
				<td>
					<label class="bftd-check"><input type="checkbox" name="bftd_route[copy_on_new]" value="1" <?php checked( $r['copy_on_new'], 1 ); ?>> New messages from families</label>
					<label class="bftd-check"><input type="checkbox" name="bftd_route[copy_on_item]" value="1" <?php checked( $r['copy_on_item'], 1 ); ?>> A family finishing their list</label>
					<p class="description">The wording of each of these lives under <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Emails::PAGE_SLUG ) ); ?>">Emails</a>, along with the rule for when it is allowed to send at all.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Sound</th>
				<td>
					<label><input type="checkbox" name="bftd_route[sound]" value="1" <?php checked( $r['sound'], 1 ); ?>> Play a short sound in wp-admin when a family writes</label>
					<p class="description">Only while a staff screen is open, and only for a message that arrived after the page loaded.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Not a form. Who has access to what is edited on the student record and
	 * on each account, so this is the read-only view that answers "who can see
	 * this child's file" without opening five screens.
	 */
	private static function render_people() {
		$students = array_map( 'get_post', BFTD_Access::visible_student_ids( get_current_user_id() ) );
		?>
			<p class="bftd-lede">Who can see each student's file, and who is teaching them. Change either from the student record.</p>
			<table class="widefat striped">
				<thead><tr><th style="width:220px;">Student</th><th>Family</th><th style="width:220px;">Tutors</th><th style="width:120px;">Last seen</th></tr></thead>
				<tbody>
				<?php if ( ! $students ) : ?>
					<tr><td colspan="4">No students yet.</td></tr>
				<?php else : foreach ( $students as $s ) :
					if ( ! $s ) continue;
					$clients = BFTD_CPT::client_ids( $s->ID );
					$staff   = BFTD_CPT::staff_ids( $s->ID );
					$last    = 0;
					foreach ( $clients as $uid ) {
						$last = max( $last, (int) get_user_meta( $uid, BFTD_Access_Log::LAST_LOGIN, true ) );
					}
					?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_post_link( $s->ID ) ); ?>"><strong><?php echo esc_html( $s->post_title ); ?></strong></a></td>
						<td>
							<?php if ( ! $clients ) : ?>
								<span class="bftd-none">Nobody linked yet</span>
							<?php else : foreach ( $clients as $uid ) : $u = get_userdata( $uid ); if ( ! $u ) continue; ?>
								<span class="bftd-person"><a href="<?php echo esc_url( (string) get_edit_user_link( $uid ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a> <em><?php echo esc_html( BFTD_Roles::relationship( $uid ) ); ?></em></span>
							<?php endforeach; endif; ?>
						</td>
						<td>
							<?php if ( ! $staff ) : ?>
								<span class="bftd-none">Unassigned</span>
							<?php else : foreach ( $staff as $uid ) : $u = get_userdata( $uid ); if ( ! $u ) continue; ?>
								<span class="bftd-person"><?php echo esc_html( $u->display_name ); ?></span>
							<?php endforeach; endif; ?>
						</td>
						<td><?php echo $last ? esc_html( human_time_diff( $last ) . ' ago' ) : '<span class="bftd-none">Never</span>'; ?></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<h2>The team</h2>
			<p class="bftd-lede">Who teaches, how much they are carrying, and when they were last in. A Tutor Manager can edit any of these accounts; a Tutor can only edit families.</p>
			<table class="widefat striped">
				<thead><tr><th style="width:240px;">Person</th><th style="width:170px;">Role</th><th style="width:120px;">Students</th><th style="width:130px;">Waiting on them</th><th style="width:180px;">Last signed in</th></tr></thead>
				<tbody>
				<?php
				$team = get_users( array(
					'role__in' => array( BFTD_Roles::TUTOR_ROLE, BFTD_Roles::MANAGER_ROLE, BFTD_Roles::SENIOR_ROLE, 'administrator' ),
					'orderby'  => 'display_name',
				) );
				foreach ( $team as $u ) :
					$mine    = BFTD_Roles::can_manage( $u->ID ) ? array() : BFTD_Access::visible_student_ids( $u->ID );
					$waiting = 0;
					foreach ( $mine as $sid ) $waiting += BFTD_Threads::staff_unread_count( $sid );
					$last = (int) get_user_meta( $u->ID, BFTD_Access_Log::LAST_LOGIN, true );
					?>
					<tr>
						<td><a href="<?php echo esc_url( (string) get_edit_user_link( $u->ID ) ); ?>"><strong><?php echo esc_html( $u->display_name ); ?></strong></a>
							<div class="bftd-msg-who"><?php echo esc_html( $u->user_email ); ?></div></td>
						<td><?php echo esc_html( BFTD_Roles::role_name( $u->ID ) ); ?></td>
						<td><?php echo BFTD_Roles::can_manage( $u->ID ) ? '<span class="bftd-none">Every student</span>' : (int) count( $mine ); ?></td>
						<td><?php echo $waiting ? '<span class="bftd-count">' . (int) $waiting . '</span>' : '<span class="bftd-none">Nothing</span>'; ?></td>
						<td><?php echo $last ? esc_html( human_time_diff( $last ) . ' ago' ) : '<span class="bftd-none">Never</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p><a class="button" href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">All accounts</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Add an account</a></p>
		</div>
		<?php
	}
}
