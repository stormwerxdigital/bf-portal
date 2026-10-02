<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Conversations.
 *
 * One thread per section of a report, plus free-standing threads on a student.
 * Stored as WordPress comments with our own comment_type, so moderation,
 * ordering and pagination all come free, and so the record of what was said
 * survives independently of the report meta it hangs beside.
 *
 * A thread's root comment carries the section id in comment meta; replies are
 * children of it. The same registry the front end renders from decides which
 * sections may carry a thread, so a thread can never point at a section that
 * does not exist.
 *
 * Everything a family or a tutor writes is stored filtered and rendered
 * filtered — wp_kses_post on the way in and again on the way out — because a
 * stored value is not automatically safe just because it was clean when it
 * was written.
 */
class BFTD_Threads {

	const TYPE        = 'bftd_thread';
	const SECTION_KEY = 'bftd_section';
	const STUDENT_KEY = 'bftd_student';
	const ATTACH_KEY  = 'bftd_attachments';
	const READ_KEY    = '_bftd_thread_read'; // usermeta: thread_id => timestamp

	public static function init() {
		// Our comments are internal, never part of a public comment feed or count.
		add_filter( 'comments_clauses', array( __CLASS__, 'hide_from_public_queries' ), 10, 2 );
		add_filter( 'the_comments', array( __CLASS__, 'strip_from_admin_list' ), 10, 2 );
		add_filter( 'wp_count_comments', array( __CLASS__, 'fix_counts' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Access                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Can this user read and post in conversations about this record?
	 * Staff: yes, on anything they can view. Family: only when their account
	 * is linked to that student.
	 */
	public static function can_access( $post_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id || ! $post_id ) return false;

		$student_id = BFTD_CPT::student_id( $post_id );
		if ( ! $student_id ) return false;

		if ( BFTD_Roles::is_staff( $user_id ) ) {
			return BFTD_Access::can_staff_view( $student_id, $user_id );
		}
		return in_array( (int) $user_id, BFTD_CPT::client_ids( $student_id ), true );
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	/** The root comment of the thread on one section of one record, if any. */
	public static function find_thread( $post_id, $section_id ) {
		$found = get_comments( array(
			'post_id'    => $post_id,
			'type'       => self::TYPE,
			'parent'     => 0,
			'status'     => 'approve',
			'number'     => 1,
			'meta_query' => array( array( 'key' => self::SECTION_KEY, 'value' => $section_id ) ),
		) );
		return $found ? $found[0] : null;
	}

	public static function get_thread( $thread_id ) {
		$root = get_comment( $thread_id );
		if ( ! $root || self::TYPE !== $root->comment_type ) return null;
		return $root;
	}

	/** Root plus replies, oldest first — the shape the front end renders. */
	public static function messages( $thread_id ) {
		$root = self::get_thread( $thread_id );
		if ( ! $root ) return array();
		$replies = get_comments( array(
			'parent'  => $thread_id,
			'type'    => self::TYPE,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		) );
		return array_merge( array( $root ), $replies );
	}

	/** Every thread on a student, across all of their records. */
	public static function threads_for_student( $student_id, $args = array() ) {
		$post_ids = self::record_ids( $student_id );
		if ( ! $post_ids ) return array();

		return get_comments( wp_parse_args( $args, array(
			'post__in' => $post_ids,
			'type'     => self::TYPE,
			'parent'   => 0,
			'status'   => 'approve',
			'orderby'  => 'comment_date_gmt',
			'order'    => 'DESC',
		) ) );
	}

	/** The student record plus every report, lesson and resource under it. */
	public static function record_ids( $student_id ) {
		$ids = array( (int) $student_id );
		$children = get_posts( array(
			'post_type'      => array( BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS, BFTD_CPT::SESSION ),
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => BFTD_CPT::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );
		return array_values( array_unique( array_merge( $ids, array_map( 'absint', $children ) ) ) );
	}

	public static function last_message( $thread_id ) {
		$msgs = self::messages( $thread_id );
		return $msgs ? end( $msgs ) : null;
	}

	public static function reply_count( $thread_id ) {
		return count( self::messages( $thread_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Unread                                                              */
	/* ------------------------------------------------------------------ */

	public static function mark_read( $thread_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) return;
		$map = get_user_meta( $user_id, self::READ_KEY, true );
		$map = is_array( $map ) ? $map : array();
		$map[ (int) $thread_id ] = time();
		update_user_meta( $user_id, self::READ_KEY, $map );
	}

	public static function is_unread( $thread_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) return false;

		$last = self::last_message( $thread_id );
		if ( ! $last ) return false;
		// Your own message is never unread to you. A "something happened"
		// signal built only on unread shows nothing to the person who just
		// acted, which reads as broken.
		if ( (int) $last->user_id === $user_id ) return false;

		$map  = get_user_meta( $user_id, self::READ_KEY, true );
		$seen = ( is_array( $map ) && isset( $map[ (int) $thread_id ] ) ) ? (int) $map[ (int) $thread_id ] : 0;
		return strtotime( $last->comment_date_gmt . ' UTC' ) > $seen;
	}

	public static function unread_count_for_user( $student_id, $user_id = 0 ) {
		$n = 0;
		foreach ( self::threads_for_student( $student_id ) as $t ) {
			if ( self::is_unread( $t->comment_ID, $user_id ) ) $n++;
		}
		return $n;
	}

	/** Threads on a student whose most recent message came from the family. */
	public static function staff_unread_count( $student_id ) {
		$n = 0;
		foreach ( self::threads_for_student( $student_id ) as $t ) {
			$last = self::last_message( $t->comment_ID );
			if ( ! $last ) continue;
			if ( ! $last->user_id ) continue;
			if ( ! BFTD_Roles::is_staff( $last->user_id ) ) $n++;
		}
		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Post a message. Creates the thread if this is the first message on that
	 * section. Returns the comment ID, or a WP_Error.
	 */
	public static function post_message( $post_id, $section_id, $content, $attachments = array(), $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! self::can_access( $post_id, $user_id ) ) {
			return new WP_Error( 'bftd_forbidden', 'You do not have access to this conversation.' );
		}

		$content = wp_kses_post( $content );
		if ( '' === trim( wp_strip_all_tags( $content ) ) && ! $attachments ) {
			return new WP_Error( 'bftd_empty', 'Write something, or attach a file.' );
		}

		$section_id = sanitize_key( str_replace( '_', '-', $section_id ) );
		if ( ! self::valid_section( $section_id ) ) {
			return new WP_Error( 'bftd_bad_section', 'That section does not exist.' );
		}

		$user       = get_userdata( $user_id );
		$student_id = BFTD_CPT::student_id( $post_id );
		$root       = $section_id ? self::find_thread( $post_id, $section_id ) : null;
		$is_new     = ! $root;

		$comment_id = wp_insert_comment( array(
			'comment_post_ID'      => (int) $post_id,
			'comment_parent'       => $root ? (int) $root->comment_ID : 0,
			'comment_content'      => $content,
			'comment_type'         => self::TYPE,
			'comment_approved'     => 1,
			'user_id'              => $user_id,
			'comment_author'       => $user ? $user->display_name : '',
			'comment_author_email' => $user ? $user->user_email : '',
		) );

		if ( ! $comment_id ) return new WP_Error( 'bftd_insert_failed', 'That did not save. Try again.' );

		if ( $is_new ) {
			add_comment_meta( $comment_id, self::SECTION_KEY, $section_id );
			add_comment_meta( $comment_id, self::STUDENT_KEY, $student_id );
		}

		$ids = array();
		if ( $attachments ) {
			$ids = BFTD_Attachments::attach_to_comment( $comment_id, $attachments );
		}

		$thread_id = $root ? (int) $root->comment_ID : (int) $comment_id;
		self::mark_read( $thread_id, $user_id );

		BFTD_Audit::log( $is_new ? 'thread_started' : 'message_posted', array(
			'post_id'    => $post_id,
			'student_id' => $student_id,
			'section_id' => $section_id,
			'summary'    => ( $user ? $user->display_name : 'Someone' ) . ' posted under ' . BFTD_Schema::label( $section_id ) . ': "' . wp_trim_words( wp_strip_all_tags( $content ), 20 ) . '"',
		) );

		if ( $ids ) {
			BFTD_Audit::log( 'attachment_added', array(
				'post_id'    => $post_id,
				'student_id' => $student_id,
				'section_id' => $section_id,
				'summary'    => count( $ids ) . ' file(s) attached.',
			) );
		}

		self::notify( $thread_id, $comment_id, $post_id, $section_id, $user_id, $content );

		return $comment_id;
	}

	/**
	 * Tell the other side. A family posting always reaches their tutor,
	 * because a question sitting unanswered is the one failure this system
	 * cannot have. A tutor posting reaches the family through the send rules,
	 * which may hold it for the daily summary or for a human to send.
	 */
	private static function notify( $thread_id, $comment_id, $post_id, $section_id, $user_id, $content ) {
		$student_id = BFTD_CPT::student_id( $post_id );
		if ( ! $student_id ) return;

		$author  = get_userdata( $user_id );
		$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 40 );
		$label   = self::label_for( $section_id, $post_id );
		$student = get_the_title( $student_id );

		$base = array(
			'student_name'  => $student,
			'section_label' => $label,
			'message_excerpt' => $excerpt,
			'dashboard_url' => BFTD_Dashboard::url( $student_id, $section_id ),
			'from_name'     => $author ? $author->display_name : 'Someone',
		);

		if ( BFTD_Roles::is_staff( $user_id ) ) {
			// A reply written on a draft report tells a caregiver about a
			// page they cannot open. The note stays flagged and the offer to
			// send it appears once the record is published.
			if ( ! BFTD_Change_Notify::is_published( $post_id ) ) {
				BFTD_Change_Notify::queue_message( $post_id, $excerpt );
				return;
			}
			foreach ( BFTD_CPT::client_ids( $student_id ) as $uid ) {
				$u = get_userdata( $uid );
				if ( ! $u ) continue;
				BFTD_Notices::add( $uid, 'message', $student_id, $post_id, $section_id,
					'A reply about ' . $label, $excerpt );
				BFTD_Emails::send( 'client_new_message', $u->user_email, array_merge( $base, array(
					'first_name' => $u->first_name ? $u->first_name : $u->display_name,
					'tutor_name' => $author ? $author->display_name : '',
				) ), array( 'post_id' => $post_id, 'student_id' => $student_id, 'already_read' => false ) );
			}
			BFTD_Change_Notify::queue_message( $post_id, $excerpt );
			return;
		}

		$told = array();

		foreach ( BFTD_Access::notify_staff_ids( $student_id ) as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) continue;
			BFTD_Notices::add( $uid, 'message', $student_id, $post_id, $section_id,
				$base['from_name'] . ' wrote about ' . $label, $excerpt );
			BFTD_Emails::send( 'staff_new_message', $u->user_email, array_merge( $base, array(
				'first_name' => $u->first_name ? $u->first_name : $u->display_name,
				'tutor_name' => $u->display_name,
			) ), array( 'post_id' => $post_id, 'student_id' => $student_id ) );
			$told[] = strtolower( $u->user_email );
		}

		// The shared inbox and the named recipient, if either is set and has
		// not already had it as an assigned tutor.
		$routing = BFTD_Settings::routing();
		if ( ! empty( $routing['copy_on_new'] ) ) {
			foreach ( BFTD_Settings::copy_addresses() as $email ) {
				if ( in_array( strtolower( $email ), $told, true ) ) continue;
				BFTD_Emails::send( 'staff_new_message', $email, array_merge( $base, array(
					'first_name' => 'there',
					'tutor_name' => '',
				) ), array( 'post_id' => $post_id, 'student_id' => $student_id ) );
				$told[] = strtolower( $email );
			}
		}
	}

	public static function edit_message( $comment_id, $content, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$c = get_comment( $comment_id );
		if ( ! $c || self::TYPE !== $c->comment_type ) return new WP_Error( 'bftd_missing', 'That message is gone.' );
		if ( (int) $c->user_id !== $user_id && ! BFTD_Roles::is_staff( $user_id ) ) {
			return new WP_Error( 'bftd_forbidden', 'You can only edit your own messages.' );
		}

		$before = $c->comment_content;
		$after  = wp_kses_post( $content );
		wp_update_comment( array( 'comment_ID' => (int) $comment_id, 'comment_content' => $after ) );

		BFTD_Audit::log( 'message_edited', array(
			'post_id'    => $c->comment_post_ID,
			'section_id' => self::section_of( $comment_id ),
			'summary'    => 'A message was edited.',
			'changes'    => BFTD_Audit::diff( array( 'body' => $before ), array( 'body' => $after ), array( 'body' => 'Message' ) ),
		) );
		return true;
	}

	/**
	 * Messages are unpublished rather than removed. A conversation with a
	 * family is a record; a tutor changing their mind about a sentence should
	 * not erase that it was said.
	 */
	public static function delete_message( $comment_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		$c = get_comment( $comment_id );
		if ( ! $c || self::TYPE !== $c->comment_type ) return new WP_Error( 'bftd_missing', 'That message is gone.' );
		if ( (int) $c->user_id !== $user_id && ! BFTD_Roles::is_staff( $user_id ) ) {
			return new WP_Error( 'bftd_forbidden', 'You can only remove your own messages.' );
		}

		wp_set_comment_status( $comment_id, 'hold' );
		BFTD_Audit::log( 'message_deleted', array(
			'post_id'    => $c->comment_post_ID,
			'section_id' => self::section_of( $comment_id ),
			'summary'    => 'A message was withdrawn: "' . wp_trim_words( wp_strip_all_tags( $c->comment_content ), 20 ) . '"',
		) );
		return true;
	}

	/**
	 * A thread hangs off a schema section, one lesson, or one item. Anything
	 * else is refused, so a thread can never point at something that is not
	 * rendered anywhere and quietly become invisible.
	 */
	public static function valid_section( $section_id ) {
		if ( '' === $section_id ) return true; // a free-standing thread on the student
		if ( BFTD_Schema::section( $section_id ) ) return true;
		if ( 0 === strpos( $section_id, 'session-' ) ) return true;
		if ( 0 === strpos( $section_id, 'item-' ) ) return true;
		return false;
	}

	/**
	 * What to call a thread in the inbox and in the family's message list.
	 * Lesson and item threads are not schema sections, so they get their
	 * label from the record they belong to rather than from the registry.
	 */
	public static function label_for( $section_id, $post_id = 0 ) {
		if ( '' === (string) $section_id ) return 'General';

		if ( 0 === strpos( $section_id, 'session-' ) ) {
			$sid  = (int) substr( $section_id, 8 );
			$date = $sid ? BFTD_Fields::get( $sid, 'session', 'session_date' ) : '';
			// Counted, not read: the number is no longer stored on the
			// lesson, so a thread labelled from the old field would have
			// said "A lesson" about every one of them.
			$num  = $sid ? BFTD_CPT::lesson_number( $sid ) : 0;
			$bits = array_filter( array(
				$num ? 'Session ' . $num : 'A session',
				$date ? mysql2date( get_option( 'date_format' ), $date ) : '',
			) );
			return implode( ', ', $bits );
		}

		if ( 0 === strpos( $section_id, 'item-' ) && $post_id ) {
			$id = substr( $section_id, 5 );
			foreach ( array_keys( BFTD_Items::lists() ) as $list ) {
				foreach ( BFTD_Items::all( BFTD_CPT::student_id( $post_id ), $list ) as $item ) {
					if ( isset( $item['id'] ) && $item['id'] === $id ) {
						return wp_trim_words( $item['text'], 9 );
					}
				}
			}
			return 'An item on your list';
		}

		return BFTD_Schema::label( $section_id );
	}

	public static function section_of( $comment_id ) {
		$c = get_comment( $comment_id );
		if ( ! $c ) return '';
		$root = $c->comment_parent ? (int) $c->comment_parent : (int) $comment_id;
		return (string) get_comment_meta( $root, self::SECTION_KEY, true );
	}

	/* ------------------------------------------------------------------ */
	/* Keeping our comments out of everything else                          */
	/* ------------------------------------------------------------------ */

	public static function hide_from_public_queries( $clauses, $query ) {
		if ( is_admin() ) return $clauses;
		$type = isset( $query->query_vars['type'] ) ? $query->query_vars['type'] : '';
		if ( self::TYPE === $type ) return $clauses;
		global $wpdb;
		$clauses['where'] .= $wpdb->prepare( " AND {$wpdb->comments}.comment_type != %s", self::TYPE );
		return $clauses;
	}

	public static function strip_from_admin_list( $comments, $query ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-comments' !== $screen->id ) return $comments;
		return array_values( array_filter( $comments, function ( $c ) {
			return self::TYPE !== $c->comment_type;
		} ) );
	}

	public static function fix_counts( $counts, $post_id ) {
		return $counts; // core recounts on its own; ours are excluded by type above
	}
}
