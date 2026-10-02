<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every request the front end makes.
 *
 * The order in each handler is always the same: verify the nonce, then check
 * what this specific user is allowed to do with this specific record, then
 * act. A valid nonce only proves the request came from our page; it proves
 * nothing about permission, so the capability check is never skipped because
 * the nonce passed.
 */
class BFTD_Ajax {

	public static function init() {
		$map = array(
			'bftd_post_message' => 'post_message',
			'bftd_mark_read'    => 'mark_read',
			'bftd_notices'      => 'notices',
			'bftd_notice_read'  => 'notice_read',
			'bftd_dismiss_banner' => 'dismiss_banner',
			'bftd_find_family'    => 'find_family',
			'bftd_skill_words'    => 'skill_words',
		);
		foreach ( $map as $action => $method ) {
			add_action( 'wp_ajax_' . $action, array( __CLASS__, $method ) );
		}
	}

	private static function verify() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Please sign in again.' ), 401 );
		}
	}

	private static function files() {
		$out = array();
		if ( empty( $_FILES['files']['name'] ) || ! is_array( $_FILES['files']['name'] ) ) return $out;
		foreach ( array_keys( $_FILES['files']['name'] ) as $i ) {
			if ( empty( $_FILES['files']['name'][ $i ] ) ) continue;
			$out[] = array(
				'name'     => $_FILES['files']['name'][ $i ],
				'type'     => $_FILES['files']['type'][ $i ],
				'tmp_name' => $_FILES['files']['tmp_name'][ $i ],
				'error'    => $_FILES['files']['error'][ $i ],
				'size'     => $_FILES['files']['size'][ $i ],
			);
		}
		return $out;
	}

	/**
	 * What a skill says about itself, for the activity screen.
	 *
	 * Assigning a skill to an activity drops that skill's description into the
	 * activity's own editor, where it becomes the activity's words and can be
	 * rewritten. It is fetched rather than shipped with the page because the
	 * library runs to a few hundred and nobody needs all of their descriptions
	 * on screen to pick one.
	 *
	 * Readable by anyone who may write the library. A skill's description is
	 * the practice's curriculum, not a child's record, but it is not public
	 * either, so it is behind the same capability as the library itself.
	 */
	public static function skill_words() {
		self::verify();

		if ( ! BFTD_Roles::can_manage_team() ) {
			wp_send_json_error( array( 'message' => 'Only senior managers maintain the library.' ), 403 );
		}

		$skill_id = isset( $_POST['skill_id'] ) ? (int) $_POST['skill_id'] : 0;
		$name     = BFTD_Skills::label( $skill_id );
		if ( ! $skill_id || '' === $name ) {
			wp_send_json_error( array( 'message' => 'There is no skill with that id.' ), 404 );
		}

		wp_send_json_success( array(
			'id'    => $skill_id,
			'name'  => $name,
			// Paragraphs, not a wall.
			//
			// A skill's description is WordPress content, and WordPress
			// content only becomes paragraphs when something puts the tags
			// in. Nothing reads this through the_content, so nothing did:
			// a skill written as three paragraphs was handed to the editor
			// as three lines of plain text, which an HTML editor renders as
			// one run-on paragraph. Every blank line the author put in was
			// lost on the way across.
			//
			// Once through wpautop it is the same markup a reader would see
			// on the skill itself, which is what the activity is supposed to
			// be starting from. wpautop leaves content that is already
			// blocked out alone, so a description saved from the visual tab
			// is not wrapped twice.
			'words' => wp_kses_post( wpautop( BFTD_Skills::description( $skill_id ) ) ),
		) );
	}

	public static function post_message() {
		self::verify();

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$section = isset( $_POST['section'] ) ? sanitize_text_field( wp_unslash( $_POST['section'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';

		if ( ! BFTD_Threads::can_access( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'That conversation is not yours.' ), 403 );
		}

		$result = BFTD_Threads::post_message( $post_id, $section, $body, self::files() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		$comment = get_comment( $result );
		$user    = get_userdata( $comment->user_id );

		wp_send_json_success( array(
			'id'    => (int) $result,
			'who'   => $user ? $user->display_name : '',
			'when'  => 'just now',
			'html'  => wp_kses_post( wpautop( $comment->comment_content ) ),
			'files' => BFTD_Attachments::for_comment( $result ),
		) );
	}

	public static function mark_read() {
		self::verify();
		$thread_id = isset( $_POST['thread'] ) ? (int) $_POST['thread'] : 0;
		$thread    = BFTD_Threads::get_thread( $thread_id );
		if ( ! $thread || ! BFTD_Threads::can_access( $thread->comment_post_ID ) ) {
			wp_send_json_error( array( 'message' => 'Not yours.' ), 403 );
		}
		BFTD_Threads::mark_read( $thread_id );
		wp_send_json_success();
	}

	/**
	 * Type-ahead for the staff family picker: search by the adult's name,
	 * username or email address, or by the student's name.
	 *
	 * Two restrictions, and the second is the one that matters. Only staff may
	 * search at all, and a tutor only ever gets back families attached to a
	 * student they are already assigned to — so the search cannot be used to
	 * enumerate the practice's client list from an account that should not see
	 * it. Managers and above see everyone, which is the difference their tier
	 * is for.
	 */
	public static function find_family() {
		self::verify();
		if ( ! BFTD_Roles::is_staff() ) {
			wp_send_json_error( array( 'message' => 'Staff only.' ), 403 );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		$visible = BFTD_Access::visible_student_ids( get_current_user_id() );
		if ( ! $visible ) wp_send_json_success( array( 'results' => array() ) );

		// Which family accounts are reachable at all, from the students this
		// person can see. Built first so the search can only ever narrow it.
		$allowed = array();
		foreach ( $visible as $sid ) {
			foreach ( BFTD_CPT::client_ids( $sid ) as $uid ) {
				$allowed[ $uid ][] = $sid;
			}
		}
		if ( ! $allowed ) wp_send_json_success( array( 'results' => array() ) );

		$matched = array_keys( $allowed );

		if ( '' !== $term ) {
			// Two ways to find the same family: by the adult's own details, or
			// by the child's name — which is how a tutor actually thinks about
			// them. Both searches are restricted to the same allowed set, so
			// matching a student name can never surface a family this person
			// could not already reach.
			$by_person = get_users( array(
				'include'        => array_keys( $allowed ),
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name', 'user_nicename' ),
				'fields'         => 'ID',
				'number'         => 40,
			) );

			$by_student = array();
			foreach ( $visible as $sid ) {
				if ( false === stripos( get_the_title( $sid ), $term ) ) continue;
				foreach ( BFTD_CPT::client_ids( $sid ) as $uid ) {
					if ( isset( $allowed[ $uid ] ) ) $by_student[] = $uid;
				}
			}

			$matched = array_values( array_unique( array_merge( array_map( 'absint', $by_person ), $by_student ) ) );
			if ( ! $matched ) wp_send_json_success( array( 'results' => array() ) );
		}

		$args = array(
			'include' => $matched,
			'orderby' => 'display_name',
			'number'  => 20,
		);

		$results = array();
		foreach ( get_users( $args ) as $u ) {
			$students = array();
			foreach ( $allowed[ $u->ID ] as $sid ) {
				$students[] = array( 'id' => (int) $sid, 'name' => get_the_title( $sid ) );
			}
			$results[] = array(
				'id'       => (int) $u->ID,
				'name'     => $u->display_name,
				'login'    => $u->user_login,
				'email'    => $u->user_email,
				'relation' => BFTD_Roles::relationship( $u->ID ),
				'students' => $students,
				'url'      => BFTD_Dashboard::url_for_family( $u->ID ),
			);
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	public static function dismiss_banner() {
		self::verify();
		BFTD_Settings::dismiss_banner( get_current_user_id() );
		wp_send_json_success();
	}

	public static function notices() {
		self::verify();
		$out = array();
		foreach ( BFTD_Notices::all() as $n ) {
			$out[] = array(
				'id'    => $n['id'],
				'type'  => $n['type'],
				'title' => $n['title'],
				'body'  => $n['body'],
				'when'  => human_time_diff( $n['at'] ) . ' ago',
				'read'  => (bool) $n['read'],
				'url'   => BFTD_Notices::link( $n ),
			);
		}
		wp_send_json_success( array( 'notices' => $out, 'unread' => BFTD_Notices::unread_count() ) );
	}

	public static function notice_read() {
		self::verify();
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		if ( 'all' === $id ) {
			BFTD_Notices::mark_all_read();
		} else {
			BFTD_Notices::mark_read( $id );
		}
		wp_send_json_success( array( 'unread' => BFTD_Notices::unread_count() ) );
	}
}
