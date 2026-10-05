<?php
/**
 * Plugin Name: Brilliant Futures Dashboard
 * Update URI: https://github.com/stormwerxdigital/bf-portal
 * Primary Branch: main
 * Plugin URI:  https://bftutoring.com
 * Description: The parent portal and the tutor back end for Brilliant Futures Tutoring. Reading diagnostics, living progress reports, lesson records, per-section conversations, a full activity log, and customisable email with send rules.
 * Version:     1.136.0
 * Author:      Stormwerx Digital
 * Author URI:  https://stormwerxdigital.com
 * Text Domain: bftd
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BFTD_VERSION', '1.136.0' );
define( 'BFTD_PATH', plugin_dir_path( __FILE__ ) );
define( 'BFTD_URL', plugin_dir_url( __FILE__ ) );

foreach ( array(
	'time', 'schema', 'roles', 'cpt', 'access', 'audit', 'access-log',
	'emails', 'notices', 'attachments', 'threads', 'fields', 'library', 'skills', 'activities', 'library-view', 'items',
	'schedule', 'change-notify', 'autosave', 'crm', 'prefill', 'derived', 'charts', 'sample', 'report-view', 'preview', 'metaboxes', 'brand', 'admin-experience',
	'pay-period', 'stat-holidays', 'pay', 'stat-pay', 'pay-profile', 'accessibility',
	'admin', 'students', 'sessions', 'oversight', 'timecards', 'settings', 'dashboard', 'ajax',
) as $file ) {
	require_once BFTD_PATH . 'includes/class-bftd-' . $file . '.php';
}

register_activation_hook( __FILE__, array( 'BFTD_Roles', 'activate' ) );
register_activation_hook( __FILE__, array( 'BFTD_Audit', 'install' ) );
register_activation_hook( __FILE__, 'bftd_create_portal_page' );

/**
 * Make the page the portal lives on, once, at activation. A normal WordPress
 * page holding the shortcode — the theme, the header and the rest of the site
 * are untouched, and nothing about the login or password reset routes changes.
 */
function bftd_create_portal_page() {
	if ( get_option( BFTD_Dashboard::OPTION_PAGE ) ) return;

	$existing = get_page_by_path( 'portal' );
	if ( $existing ) {
		update_option( BFTD_Dashboard::OPTION_PAGE, $existing->ID );
		return;
	}

	$id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Portal',
		'post_name'    => 'portal',
		'post_content' => '[' . BFTD_Dashboard::SHORTCODE . ']',
	) );
	if ( $id && ! is_wp_error( $id ) ) update_option( BFTD_Dashboard::OPTION_PAGE, $id );
}

final class BFTD_Plugin {

	public static function init() {
		BFTD_Roles::init();
		BFTD_CPT::init();
		BFTD_Access::init();
		BFTD_Audit::init();
		BFTD_Access_Log::init();
		BFTD_Emails::init();
		BFTD_Notices::init();
		BFTD_Attachments::init();
		BFTD_Threads::init();
		BFTD_Change_Notify::init();
		BFTD_Autosave::init();
		BFTD_MetaBoxes::init();
		BFTD_Skills::init();
		BFTD_Activities::init();
		BFTD_Library_View::init();
		BFTD_Items::init();
		BFTD_Schedule::init();
		BFTD_CRM::init();
		BFTD_Preview::init();
		BFTD_Brand::init();
		BFTD_Admin_Experience::init();
		BFTD_Accessibility::init();
		BFTD_Admin::init();
		BFTD_Students::init();
		BFTD_Sessions::init();
		BFTD_Oversight::init();
		BFTD_Pay::init();
		BFTD_Pay_Profile::init();
		BFTD_Timecards::init();
		BFTD_Settings::init();
		BFTD_Dashboard::init();
		BFTD_Ajax::init();

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	public static function admin_assets( $hook ) {
		// One font list, shared with the report and the preview. When the back
		// end kept its own, the editor lost the alphabet face the report used,
		// so the letters a tutor typed were not the letters a parent saw.
		wp_enqueue_style( 'bftd-fonts', BFTD_Report_View::fonts_url(), array(), null );
		wp_enqueue_style( 'bftd-admin', BFTD_URL . 'assets/css/bftd-admin.css', array( 'bftd-fonts' ), BFTD_VERSION );

		wp_enqueue_media();
		// The search rule every picker and library list uses, on its own so
		// the same file can be tested outside a browser.
		wp_enqueue_script( 'bftd-match', BFTD_URL . 'assets/js/bftd-match.js', array(), BFTD_VERSION, true );
		wp_enqueue_script( 'bftd-admin', BFTD_URL . 'assets/js/bftd-admin.js', array( 'jquery', 'jquery-ui-sortable', 'bftd-match' ), BFTD_VERSION, true );

		// The Skills and Activities lists suggest records as somebody types in
		// their search box, from the same index their search runs on.
		if ( 'edit.php' === $hook ) {
			$type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
			$lib  = array( 'bftd_skill' => 'BFTD_Skills', 'bftd_activity' => 'BFTD_Activities' );
			if ( isset( $lib[ $type ] ) && class_exists( $lib[ $type ] ) ) {
				$tracks = call_user_func( array( $lib[ $type ], 'tracks' ) );
				$rows   = array();
				foreach ( call_user_func( array( $lib[ $type ], 'list_index' ) ) as $id => $r ) {
					$rows[] = array(
						'id'    => $id,
						'name'  => $r['name'],
						'num'   => $r['num'],
						'track' => $tracks[ $r['track'] ] ?? '',
						// The editor for those who may edit it, the read-only page
						// for a tutor, who may not.
						'url'   => BFTD_Library_View::link_for( $id ),
					);
				}
				wp_add_inline_script( 'bftd-admin', 'window.BFTD_LIBRARY = ' . wp_json_encode( $rows ) . ';', 'before' );
			}
		}
		// Whether this screen autosaves, and which of the two things that
		// means. It is the server that decides, not the screen: a tab left
		// open while somebody else published must stop writing the record and
		// start keeping a snapshot instead, and it only finds that out by
		// being told.
		$post = get_post();
		// Every record the autosave keeps, which includes the skills and
		// activities libraries as well as the portal's own records.
		$ours = ( $post && class_exists( 'BFTD_Autosave' ) && in_array( $post->post_type, BFTD_Autosave::types(), true ) );

		// The scripts wp.editor.initialize needs, on our screens only. A rich
		// note inside a repeatable row is built on the click that opens it,
		// and without these already on the page that click has nothing to
		// build with. Loading them everywhere would put the whole editor
		// bundle on screens that have no editor.
		if ( $ours && function_exists( 'wp_enqueue_editor' ) ) wp_enqueue_editor();
		$draft = ( $ours && class_exists( 'BFTD_Autosave' ) ) ? BFTD_Autosave::is_draft( $post ) : false;

		wp_localize_script( 'bftd-admin', 'BFTD', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'bftd_nonce' ),
			'post_id'  => $post ? (int) $post->ID : 0,
			'autosave' => $ours ? 1 : 0,
			'draft'    => $draft ? 1 : 0,
			'modified' => $post ? $post->post_modified_gmt : '',
			// What each track is called, so the activity list can head its two
			// groups without the names being written out a second time in a
			// script where nobody would think to look for them.
			'tracks'   => class_exists( 'BFTD_Activities' ) ? BFTD_Activities::tracks() : array(),
			'groups'   => class_exists( 'BFTD_Skills' ) ? BFTD_Skills::groups() : array(),
			// Accessibility mode, for the editors the script builds itself.
			'a11y'     => class_exists( 'BFTD_Accessibility' ) ? BFTD_Accessibility::script_config() : array( 'on' => 0 ),
		) );
	}
}
add_action( 'plugins_loaded', array( 'BFTD_Plugin', 'init' ) );
