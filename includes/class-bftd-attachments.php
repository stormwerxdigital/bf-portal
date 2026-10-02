<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Files on messages: a screenshot pasted in, a photo of a page, a school
 * report a parent wants a tutor to see.
 *
 * These are children's work samples and school documents, so they are not
 * served from a guessable uploads path. Every attachment is stored with its
 * comment, and the front end links to a short-lived signed URL that checks
 * the viewer's access before streaming the file. A family cannot reach another
 * family's file by editing a URL, and a stale link stops working.
 */
class BFTD_Attachments {

	const MAX_FILES = 5;
	const META_KEY  = 'bftd_attachments';
	const TTL       = 900; // signed links live 15 minutes

	public static function allowed_types() {
		return array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'gif'          => 'image/gif',
			'webp'         => 'image/webp',
			'heic'         => 'image/heic',
			'pdf'          => 'application/pdf',
			'txt'          => 'text/plain',
			'doc'          => 'application/msword',
			'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ) );
	}

	/**
	 * Move an uploaded batch into the media library and record the ids on the
	 * comment. Returns the attachment ids that actually landed.
	 */
	public static function attach_to_comment( $comment_id, $files ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$comment = get_comment( $comment_id );
		if ( ! $comment ) return array();

		$ids   = array();
		$count = 0;

		foreach ( (array) $files as $file ) {
			if ( $count >= self::MAX_FILES ) break;
			if ( empty( $file['name'] ) || ! empty( $file['error'] ) ) continue;

			$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_types() );
			if ( empty( $check['ext'] ) || empty( $check['type'] ) ) continue;

			$moved = wp_handle_upload( $file, array(
				'test_form' => false,
				'mimes'     => self::allowed_types(),
			) );
			if ( ! empty( $moved['error'] ) || empty( $moved['file'] ) ) continue;

			$attach_id = wp_insert_attachment( array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_file_name( pathinfo( $moved['file'], PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_parent'    => (int) $comment->comment_post_ID,
			), $moved['file'] );

			if ( ! $attach_id ) continue;

			wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $moved['file'] ) );
			update_post_meta( $attach_id, '_bftd_comment_id', (int) $comment_id );

			$ids[] = (int) $attach_id;
			$count++;
		}

		if ( $ids ) update_comment_meta( $comment_id, self::META_KEY, $ids );
		return $ids;
	}

	public static function for_comment( $comment_id ) {
		$ids = get_comment_meta( $comment_id, self::META_KEY, true );
		$ids = is_array( $ids ) ? array_map( 'absint', $ids ) : array();

		$out = array();
		foreach ( $ids as $id ) {
			$file = get_attached_file( $id );
			if ( ! $file || ! file_exists( $file ) ) continue;
			$out[] = array(
				'id'    => $id,
				'name'  => basename( $file ),
				'size'  => size_format( filesize( $file ), 0 ),
				'mime'  => get_post_mime_type( $id ),
				'image' => (bool) wp_attachment_is_image( $id ),
				'url'   => self::signed_url( $id ),
				'thumb' => wp_attachment_is_image( $id ) ? self::signed_url( $id, 'thumbnail' ) : '',
			);
		}
		return $out;
	}

	private static function signature( $attach_id, $expires, $user_id, $size ) {
		return hash_hmac( 'sha256', $attach_id . '|' . $expires . '|' . $user_id . '|' . $size, wp_salt( 'auth' ) );
	}

	public static function signed_url( $attach_id, $size = 'full' ) {
		$user_id = get_current_user_id();
		$expires = time() + self::TTL;
		return add_query_arg( array(
			'bftd_file' => (int) $attach_id,
			'size'      => $size,
			'u'         => $user_id,
			'e'         => $expires,
			'sig'       => self::signature( $attach_id, $expires, $user_id, $size ),
		), home_url( '/' ) );
	}

	/**
	 * Serve a file if, and only if, the link is intact, unexpired, being used
	 * by the person it was issued to, and that person still has access to the
	 * conversation it belongs to. Access is re-checked at download time, not
	 * trusted from when the link was made.
	 */
	public static function maybe_serve() {
		if ( empty( $_GET['bftd_file'] ) || empty( $_GET['sig'] ) ) return;

		$id      = (int) $_GET['bftd_file'];
		$expires = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
		$user    = isset( $_GET['u'] ) ? (int) $_GET['u'] : 0;
		$size    = isset( $_GET['size'] ) ? sanitize_key( wp_unslash( $_GET['size'] ) ) : 'full';
		$sig     = sanitize_text_field( wp_unslash( $_GET['sig'] ) );

		if ( ! hash_equals( self::signature( $id, $expires, $user, $size ), $sig ) ) wp_die( 'That link is not valid.', 403 );
		if ( $expires < time() ) wp_die( 'That link has expired. Open it again from the portal.', 403 );
		if ( get_current_user_id() !== $user ) wp_die( 'That link was issued to someone else.', 403 );

		$comment_id = (int) get_post_meta( $id, '_bftd_comment_id', true );
		$comment    = $comment_id ? get_comment( $comment_id ) : null;
		if ( ! $comment || ! BFTD_Threads::can_access( $comment->comment_post_ID, $user ) ) {
			wp_die( 'You do not have access to that file.', 403 );
		}

		$path = get_attached_file( $id );
		if ( 'thumbnail' === $size ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( ! empty( $meta['sizes']['thumbnail']['file'] ) ) {
				$path = dirname( $path ) . '/' . $meta['sizes']['thumbnail']['file'];
			}
		}
		if ( ! $path || ! file_exists( $path ) ) wp_die( 'That file is no longer here.', 404 );

		header( 'Content-Type: ' . get_post_mime_type( $id ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=600' );
		readfile( $path );
		exit;
	}
}
