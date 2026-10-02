<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * In-app notifications: the bell in the portal, and the counter in wp-admin.
 *
 * Separate from email on purpose. Email is the thing you might miss or might
 * not want; this is the record inside the product of what is waiting for you,
 * and it is never withheld by a send rule. Every notice carries a deep link
 * to the exact section it is about, so acting on one is a single click.
 *
 * Stored per user, capped, and pruned — a notification list is a working
 * surface, not an archive. The archive is the activity log.
 */
class BFTD_Notices {

	const META_KEY = '_bftd_notices';
	const MAX      = 60;

	public static function types() {
		return array(
			'message'   => 'New message',
			'report'    => 'Report update',
			'item'      => 'Something to do',
			'lesson'    => 'Session',
			'recording' => 'Recording',
			'system'    => 'Account',
		);
	}

	public static function init() {
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 80 );
	}

	public static function add( $user_id, $type, $student_id, $post_id, $section_id, $title, $body = '' ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) return;

		$list = get_user_meta( $user_id, self::META_KEY, true );
		$list = is_array( $list ) ? $list : array();

		array_unshift( $list, array(
			'id'      => wp_generate_password( 8, false, false ),
			'type'    => sanitize_key( $type ),
			'student' => (int) $student_id,
			'post'    => (int) $post_id,
			'section' => sanitize_key( str_replace( '_', '-', (string) $section_id ) ),
			'title'   => wp_strip_all_tags( $title ),
			'body'    => wp_trim_words( wp_strip_all_tags( $body ), 26 ),
			'at'      => time(),
			'read'    => 0,
		) );

		update_user_meta( $user_id, self::META_KEY, array_slice( $list, 0, self::MAX ) );

		BFTD_Audit::log( 'notice_created', array(
			'student_id' => $student_id,
			'post_id'    => $post_id,
			'section_id' => $section_id,
			'actor_id'   => 0,
			'summary'    => 'Notified ' . ( get_userdata( $user_id ) ? get_userdata( $user_id )->display_name : 'a user' ) . ': ' . wp_strip_all_tags( $title ),
		) );
	}

	public static function all( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$list = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $list ) ? $list : array();
	}

	public static function unread_count( $user_id = 0 ) {
		$n = 0;
		foreach ( self::all( $user_id ) as $notice ) {
			if ( empty( $notice['read'] ) ) $n++;
		}
		return $n;
	}

	public static function mark_read( $notice_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$list    = self::all( $user_id );
		foreach ( $list as $i => $n ) {
			if ( $n['id'] === $notice_id ) $list[ $i ]['read'] = time();
		}
		update_user_meta( $user_id, self::META_KEY, $list );
	}

	public static function mark_all_read( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$list    = self::all( $user_id );
		foreach ( $list as $i => $n ) {
			if ( empty( $n['read'] ) ) $list[ $i ]['read'] = time();
		}
		update_user_meta( $user_id, self::META_KEY, $list );
	}

	public static function link( $notice ) {
		if ( BFTD_Roles::is_staff() ) {
			return $notice['post'] ? (string) get_edit_post_link( $notice['post'] ) : admin_url( 'admin.php?page=' . BFTD_Admin::MENU_SLUG );
		}
		return BFTD_Dashboard::url( $notice['student'], $notice['section'] );
	}

	/** A count in the wp-admin toolbar so staff see waiting work from any screen. */
	public static function admin_bar( $bar ) {
		if ( ! is_user_logged_in() || ! BFTD_Roles::is_staff() ) return;
		$n = self::unread_count();
		$bar->add_node( array(
			'id'    => 'bftd-notices',
			'title' => '<span class="ab-icon dashicons dashicons-format-chat" style="top:2px;"></span><span class="ab-label">' . ( $n ? (int) $n : '' ) . '</span>',
			'href'  => admin_url( 'admin.php?page=' . BFTD_Admin::INBOX_SLUG ),
			'meta'  => array( 'title' => $n ? $n . ' waiting on you' : 'Nothing waiting' ),
		) );
	}
}
