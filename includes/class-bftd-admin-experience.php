<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Strips wp-admin down to what each person actually needs.
 *
 * Families work in the portal, on the front of the site, and should never see
 * a WordPress screen. Tutors do work in here, so they get a real admin, just
 * without the parts of WordPress that have nothing to do with teaching.
 *
 * The one thing this deliberately does not do is force traffic through a
 * single entry point. Login, password reset and the public site are left
 * exactly as WordPress ships them.
 */
class BFTD_Admin_Experience {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'strip_menu_for_clients' ), 999 );
		add_action( 'admin_menu', array( __CLASS__, 'trim_menu_for_tutors' ), 999 );
		add_action( 'admin_menu', array( __CLASS__, 'apply_own_menu_prefs' ), 999 );
		// Late, so every other menu change has already happened, but still
		// inside admin_menu — WordPress computes the "no privileges" map
		// immediately afterwards, and that map is the whole problem.
		add_action( 'admin_menu', array( __CLASS__, 'unblock_add_new' ), 9999 );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'replace_widgets' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'send_clients_to_portal' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'trim_admin_bar' ), 999 );
		add_action( 'admin_head', array( __CLASS__, 'hide_chrome' ) );

		// Profile and Edit User cleanup.
		add_action( 'admin_init', array( __CLASS__, 'remove_colour_picker' ) );
		add_action( 'admin_head-profile.php', array( __CLASS__, 'hide_toolbar_row' ) );
		add_action( 'admin_head-user-edit.php', array( __CLASS__, 'hide_toolbar_row' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'restrict_app_passwords' ), 10, 2 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_prefs' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_prefs' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_prefs' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_prefs' ) );

		add_filter( 'gettext', array( __CLASS__, 'upload_wording' ), 10, 3 );
	}

	/**
	 * The media window's own wording, which does not mention pasting.
	 *
	 * A tutor's most common attachment is a screenshot, and that window is
	 * the one screen entirely about adding a file. It offers a drop zone and
	 * a file picker and says nothing about the clipboard, so people who could
	 * paste never find out they can.
	 *
	 * Done as a translation rather than with JavaScript because the string is
	 * printed into a media template, and a line rewritten after the fact
	 * flashes the old one first.
	 */
	public static function upload_wording( $translated, $original, $domain ) {
		if ( 'default' !== $domain ) return $translated;

		$ours = array(
			'Drop files to upload'          => 'Drop files here, or copy and paste images',
			'Drop files anywhere to upload' => 'Drop files anywhere, or copy and paste images',
		);
		return isset( $ours[ $original ] ) ? $ours[ $original ] : $translated;
	}

	private static function is_family() {
		return is_user_logged_in() && ! BFTD_Roles::is_staff();
	}

	/** A caregiver who somehow reaches wp-admin sees nothing but a way out. */
	public static function strip_menu_for_clients() {
		if ( ! self::is_family() ) return;
		global $menu;
		if ( ! is_array( $menu ) ) return;
		foreach ( $menu as $item ) {
			if ( ! empty( $item[2] ) ) remove_menu_page( $item[2] );
		}
	}

	/**
	 * Tutors keep everything they need and lose the rest. Posts, Comments and
	 * Tools are WordPress furniture that has nothing to do with a lesson;
	 * Media and Users stay, because tutors upload work samples and set up
	 * family accounts.
	 */
	public static function trim_menu_for_tutors() {
		if ( ! BFTD_Roles::is_staff() ) return;
		if ( current_user_can( 'manage_options' ) ) return; // administrators keep everything

		global $submenu;
		foreach ( array( 'edit.php', 'edit-comments.php', 'tools.php', 'themes.php', 'plugins.php', 'options-general.php' ) as $slug ) {
			remove_menu_page( $slug );
			// remove_menu_page() only touches $menu. Leaving the submenu
			// behind is what lets a hidden menu still block a screen through
			// the no-privileges map — see unblock_add_new().
			unset( $submenu[ $slug ] );
		}
	}

	/** Anyone's own decluttering preferences, from their profile. */
	public static function apply_own_menu_prefs() {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! BFTD_Roles::is_staff( $user_id ) ) return;

		if ( get_user_meta( $user_id, '_bftd_hide_media', true ) ) remove_menu_page( 'upload.php' );
		if ( get_user_meta( $user_id, '_bftd_hide_pages', true ) ) remove_menu_page( 'edit.php?post_type=page' );
		if ( get_user_meta( $user_id, '_bftd_hide_posts', true ) ) remove_menu_page( 'edit.php' );
	}

	/**
	 * Lets staff reach the Add New screen for our post types.
	 *
	 * This is not a permission problem, and no amount of granting fixes it.
	 * After admin_menu runs, WordPress walks every registered submenu and,
	 * for each one the current user cannot use, records it in
	 * $_wp_submenu_nopriv keyed by parent and slug. user_can_access_admin_page()
	 * then scans that map for the *current filename* under any parent at all:
	 *
	 *     foreach ( array_keys( $_wp_submenu_nopriv ) as $key )
	 *         if ( isset( $_wp_submenu_nopriv[ $key ][ $pagenow ] ) ) return false;
	 *
	 * Core's Posts menu registers "Add New" with the slug "post-new.php" and
	 * the capability edit_posts. Our staff roles deliberately do not have
	 * edit_posts — they have no business writing blog posts — so that entry is
	 * blacklisted, and because the scan matches on filename rather than on
	 * post type, post-new.php becomes unreachable for EVERY post type,
	 * including ours. The symptom is "Sorry, you are not allowed to access
	 * this page" on a screen the person has every capability for.
	 *
	 * List screens are unaffected, because edit.php?post_type=... resolves to
	 * a real parent and takes a different branch of the same function. That is
	 * why the Students list renders and Add Student does not.
	 *
	 * The fix is to take core's entry out of the submenu before that map is
	 * built, so nothing blacklists the filename. Only for staff who lack
	 * edit_posts: an administrator keeps their Posts menu exactly as it is.
	 *
	 * Granting edit_posts would also work and is what most plugins do. It is
	 * the wrong trade here — it hands a tutor the ability to write and publish
	 * on the website itself, to fix a menu bookkeeping problem.
	 */
	public static function unblock_add_new() {
		if ( ! BFTD_Roles::is_staff() ) return;
		if ( current_user_can( 'edit_posts' ) ) return; // administrators: nothing to do

		global $submenu;

		// The Posts menu is already hidden for our staff; drop its submenu
		// too, so "post-new.php" is never evaluated and never blacklisted.
		unset( $submenu['edit.php'] );

		// Pages registers "post-new.php?post_type=page", a different slug, so
		// it cannot poison ours. Removed anyway for anyone who cannot use it,
		// to keep the map honest rather than relying on that distinction.
		if ( ! current_user_can( 'edit_pages' ) ) {
			unset( $submenu['edit.php?post_type=page'] );
		}
	}

	/**
	 * The WordPress "At a Glance" widgets are noise here. One widget replaces
	 * them: what is waiting on this tutor right now, with a way straight to it.
	 */
	public static function replace_widgets() {
		global $wp_meta_boxes;

		if ( self::is_family() ) {
			foreach ( array( 'normal', 'side', 'column3', 'column4' ) as $ctx ) {
				if ( ! empty( $wp_meta_boxes['dashboard'][ $ctx ] ) ) unset( $wp_meta_boxes['dashboard'][ $ctx ] );
			}
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
			return;
		}

		if ( ! BFTD_Roles::is_staff() ) return;

		foreach ( array( 'dashboard_primary', 'dashboard_quick_press', 'dashboard_incoming_links', 'dashboard_plugins', 'dashboard_recent_drafts', 'dashboard_activity' ) as $id ) {
			remove_meta_box( $id, 'dashboard', 'normal' );
			remove_meta_box( $id, 'dashboard', 'side' );
		}

		wp_add_dashboard_widget( 'bftd_today', 'Brilliant Futures', array( __CLASS__, 'render_widget' ) );
	}

	public static function render_widget() {
		$waiting = BFTD_Admin::waiting_count();
		$students = BFTD_Access::visible_student_ids( get_current_user_id() );
		?>
		<div class="bftd-widget">
			<p class="bftd-widget-line">
				<b><?php echo esc_html( number_format_i18n( count( $students ) ) ); ?></b> <?php echo esc_html( _n( 'student', 'students', count( $students ), 'bftd' ) ); ?>
				<?php if ( $waiting ) : ?>
					&middot; <b class="bftd-widget-warn"><?php echo esc_html( number_format_i18n( $waiting ) ); ?></b> waiting on a reply
				<?php else : ?>
					&middot; nothing waiting on a reply
				<?php endif; ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::MENU_SLUG ) ); ?>">Open Today</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::INBOX_SLUG ) ); ?>">Conversations</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Send a caregiver out of wp-admin and into their portal. Profile,
	 * media upload and AJAX are left reachable, because a parent changing
	 * their own password or a file upload in a message both need them.
	 */
	public static function send_clients_to_portal() {
		if ( ! self::is_family() ) return;
		if ( wp_doing_ajax() ) return;

		global $pagenow;
		$allowed = array( 'profile.php', 'admin-ajax.php', 'async-upload.php', 'media-upload.php' );
		if ( in_array( $pagenow, $allowed, true ) ) return;

		wp_safe_redirect( BFTD_Dashboard::url() );
		exit;
	}

	public static function trim_admin_bar( $bar ) {
		foreach ( array( 'about', 'wporg', 'documentation', 'support-forums', 'feedback', 'customize', 'comments', 'new-content', 'updates', 'search' ) as $id ) {
			$bar->remove_node( $id );
		}
		if ( ! self::is_family() ) return;
		$bar->remove_node( 'site-name' );
	}

	public static function hide_chrome() {
		if ( ! self::is_family() ) return;
		echo '<style>#screen-meta-links,#screen-meta,#wpfooter{display:none!important}</style>';
	}

	/**
	 * The core colour scheme picker goes for everyone. The admin is branded
	 * and fixed; a user switching schemes would only fight it.
	 */
	public static function remove_colour_picker() {
		remove_all_actions( 'admin_color_scheme_picker' );
	}

	/** No core hook exists for this one row, so it is hidden with a rule. */
	public static function hide_toolbar_row() {
		echo '<style>tr.user-admin-bar-front-wrap{display:none!important}</style>';
	}

	/**
	 * Application passwords stay administrator only. Using core's own filter
	 * disables the capability rather than just hiding the row.
	 */
	public static function restrict_app_passwords( $available, $user ) {
		if ( ! $available ) return $available;
		return ( $user instanceof WP_User ) ? user_can( $user, 'manage_options' ) : $available;
	}

	/* ------------------------------------------------------------------ */
	/* Profile                                                             */
	/* ------------------------------------------------------------------ */

	public static function render_prefs( $user ) {
		if ( ! BFTD_Roles::is_staff( $user->ID ) ) return;
		if ( get_current_user_id() !== (int) $user->ID && ! current_user_can( 'manage_options' ) ) return;

		$rows = array(
			'_bftd_hide_posts' => array( 'Posts', 'Hide the Posts menu' ),
			'_bftd_hide_pages' => array( 'Pages', 'Hide the Pages menu' ),
			'_bftd_hide_media' => array( 'Media', 'Hide the Media menu' ),
		);
		$digest = get_user_meta( $user->ID, '_bftd_digest', true );
		?>
		<h2>Brilliant Futures dashboard</h2>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $key => $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( (bool) get_user_meta( $user->ID, $key, true ) ); ?>> <?php echo esc_html( $row[1] ); ?></label>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row">Email about new messages</th>
				<td>
					<select name="_bftd_digest">
						<option value="" <?php selected( $digest, '' ); ?>>Every message, as it arrives</option>
						<option value="daily" <?php selected( $digest, 'daily' ); ?>>Once a day</option>
						<option value="off" <?php selected( $digest, 'off' ); ?>>Never, I will check the dashboard</option>
					</select>
					<p class="description">This only affects email. Whatever you choose, the count on the menu and the Conversations screen stay accurate.</p>
				</td>
			</tr>
		</table>
		<p class="description">Hiding a menu is only tidying. Nothing is deleted, no permission changes, and direct links keep working.</p>
		<?php
	}

	public static function save_prefs( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) return;
		if ( ! BFTD_Roles::is_staff( $user_id ) ) return;

		foreach ( array( '_bftd_hide_posts', '_bftd_hide_pages', '_bftd_hide_media' ) as $key ) {
			update_user_meta( $user_id, $key, empty( $_POST[ $key ] ) ? 0 : 1 );
		}

		$digest = isset( $_POST['_bftd_digest'] ) ? sanitize_key( wp_unslash( $_POST['_bftd_digest'] ) ) : '';
		if ( ! in_array( $digest, array( '', 'daily', 'off' ), true ) ) $digest = '';
		update_user_meta( $user_id, '_bftd_digest', $digest );
	}
}
