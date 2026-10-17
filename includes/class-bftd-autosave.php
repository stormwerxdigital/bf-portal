<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Autosave, the way Karl set it out (1.148).
 *
 * WordPress autosaves the post title and content and nothing else. Every
 * field on these screens lives in a meta box, so core's autosave has never
 * protected a single word of a report. This does.
 *
 * NOT PUBLISHED YET (a draft, pending, private, or a brand new record): the
 * record itself is saved, silently, every time something changes. No message,
 * no revision, nothing offered back. Only a failure is ever said out loud.
 *
 * PUBLISHED: a family may be reading it, so the live record is never touched
 * by a machine. Instead there is ONE draft beside it, whoever is typing,
 * autosaved the same way. Reopening the record shows that draft in the form,
 * so nothing typed is ever hidden behind a choice; pressing Update is what
 * takes it live. The only message is "Draft saved, update to take live".
 *
 * Showing the draft in the form is done by running the ordinary save against
 * the draft with every write held in memory instead of the database (see
 * overlay()), so the screen is drawn by the same code from the same values it
 * would have after an Update, for every kind of field, with nothing sent,
 * logged or published on the way.
 */
class BFTD_Autosave {

	const KEY = '_bftd_autosave';

	/**
	 * Everything this autosave keeps: the records in the portal, plus the
	 * skills and activities libraries. One copy per record, whoever is
	 * typing: WordPress's own autosave keeps one per person, and it is
	 * switched off on all of these screens.
	 */
	public static function types() {
		$types = BFTD_Roles::post_types();
		if ( class_exists( 'BFTD_Skills' ) )     $types[] = BFTD_Skills::POST_TYPE;
		if ( class_exists( 'BFTD_Activities' ) ) $types[] = BFTD_Activities::POST_TYPE;
		return $types;
	}

	/** A library record saves through its own handler, not the portal's fields. */
	private static function is_library( $post ) {
		return ( class_exists( 'BFTD_Skills' ) && BFTD_Skills::POST_TYPE === $post->post_type )
			|| ( class_exists( 'BFTD_Activities' ) && BFTD_Activities::POST_TYPE === $post->post_type );
	}

	/**
	 * Save a library record's own fields, as its edit screen does. The nonce
	 * the handler checks is written fresh, because a snapshot being put back
	 * may be older than the one the page carried.
	 */
	private static function save_library( $post ) {
		if ( BFTD_Skills::POST_TYPE === $post->post_type ) {
			$_POST['bftd_skill_nonce'] = wp_create_nonce( 'bftd_skill_' . $post->ID );
			BFTD_Skills::save( $post->ID, $post );
		} else {
			$_POST['bftd_activity_nonce'] = wp_create_nonce( 'bftd_activity_' . $post->ID );
			BFTD_Activities::save( $post->ID, $post );
		}
	}

	/** Title and description from the form, written only where they changed. */
	private static function save_title_content( $post, $fields ) {
		$update = array();
		if ( isset( $fields['post_title'] ) ) {
			$title = sanitize_text_field( $fields['post_title'] );
			if ( '' !== $title && $title !== $post->post_title ) $update['post_title'] = $title;
		}
		if ( isset( $fields['content'] ) && self::is_library( $post ) ) {
			$content = wp_kses_post( $fields['content'] );
			if ( $content !== $post->post_content ) $update['post_content'] = $content;
		}
		if ( $update ) wp_update_post( array( 'ID' => $post->ID ) + $update );
	}

	/**
	 * Turn an auto-draft into a draft. The placeholder title WordPress gives an
	 * auto-draft goes with it, as it does when Save Draft is pressed with the
	 * title left empty.
	 */
	public static function promote_auto_draft( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'auto-draft' !== $post->post_status ) return false;
		$update = array( 'ID' => $post->ID, 'post_status' => 'draft' );
		if ( 'Auto Draft' === $post->post_title ) $update['post_title'] = '';
		wp_update_post( $update );
		return true;
	}

	/**
	 * Is this a field the autosave carries? Mirrors payload() in bftd-admin.js.
	 *
	 * Everything the screens name bftd..., plus the title and a library
	 * record's description. It used to be a list, and the list missed the
	 * people pickers (caregivers, tutors, "also shared with"), the subheading's
	 * nonce and the completed skills: an autosave without them read as
	 * "everybody removed" and wiped those lists on every draft.
	 */
	public static function kept_key( $k ) {
		if ( in_array( $k, array( 'post_title', 'content' ), true ) ) return true;
		return 1 === preg_match( '/^bftd/', $k ) && ! in_array( $k, array( 'bftd_nonce', 'bftd_sent' ), true );
	}

	public static function init() {
		add_action( 'wp_ajax_bftd_autosave', array( __CLASS__, 'ajax_save' ) );

		// A published record with a draft opens showing the draft. Early in
		// the edit screen, before the title or any panel is drawn.
		add_action( 'add_meta_boxes', array( __CLASS__, 'open_with_draft' ), 0, 2 );

		// A save by a person is the draft's whole purpose served, so it goes.
		// Late, so it runs after the fields it was protecting are in.
		add_action( 'save_post', array( __CLASS__, 'clear_on_save' ), 99, 2 );

		// And core's own autosave is switched off on these screens.
		//
		// It saves the title and the content, neither of which a report or a
		// lesson keeps anything in, so it protects nothing here. What it does
		// do is write: on a draft owned by the person editing it, core saves
		// the post itself rather than a revision, which moves
		// post_modified_gmt. The next save from this file then compared the
		// timestamp it drew the screen with against a newer one, concluded
		// somebody else had been in, and refused to write — on a record
		// nobody else had touched. The tutor saw "Somebody else has saved
		// this" and, from then on, nothing saved at all.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'silence_core_autosave' ), 99 );
	}

	public static function silence_core_autosave() {
		$post = get_post();
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) return;
		wp_dequeue_script( 'autosave' );
	}

	/* ------------------------------------------------------------------ */
	/* What counts as a draft                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Is this a record a machine may safely write a snapshot for?
	 *
	 * Anything not published. A pending or private report is still a report
	 * nobody outside the practice is reading, and an auto-draft is a screen
	 * somebody has only just opened.
	 */
	public static function is_draft( $post ) {
		if ( is_numeric( $post ) ) $post = get_post( $post );
		if ( ! $post ) return false;
		if ( ! in_array( $post->post_type, self::types(), true ) ) return false;
		return ! in_array( $post->post_status, array( 'publish', 'future' ), true );
	}

	/* ------------------------------------------------------------------ */
	/* The snapshot                                                        */
	/* ------------------------------------------------------------------ */

	/** What the form was carrying, and when. */
	public static function snapshot( $post_id ) {
		$snap = get_post_meta( (int) $post_id, self::KEY, true );
		return ( is_array( $snap ) && ! empty( $snap['fields'] ) ) ? $snap : null;
	}

	public static function clear( $post_id ) {
		delete_post_meta( (int) $post_id, self::KEY );
	}

	public static function clear_on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		// A save from our own form, or from a library record's.
		if ( empty( $_POST['bftd_nonce'] ) && empty( $_POST['bftd_skill_nonce'] ) && empty( $_POST['bftd_activity_nonce'] ) ) return;
		self::clear( $post_id );
	}

	/**
	 * Is there something here a person has not seen?
	 *
	 * Only if the snapshot is newer than the record itself. One left behind
	 * by a save that went through is not unsaved work, it is the same work,
	 * and offering it back would teach people to dismiss the bar without
	 * reading it.
	 */
	public static function unsaved( $post ) {
		$snap = self::snapshot( $post->ID );
		if ( ! $snap ) return null;
		$saved = strtotime( $post->post_modified_gmt . ' UTC' );
		$at    = isset( $snap['at'] ) ? (int) $snap['at'] : 0;
		return ( $at > $saved + 2 ) ? $snap : null;   // two seconds for the clock
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	private static function guard() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'You cannot edit this record.' ), 403 );
		}
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) {
			wp_send_json_error( array( 'message' => 'That is not a record this can keep.' ), 400 );
		}
		return $post;
	}

	/**
	 * Was the last write to this record somebody else's?
	 *
	 * A moved timestamp on its own is not a conflict. The same person in a
	 * second tab, or a background save of their own, moves it too, and
	 * refusing to write then loses the work this file exists to protect
	 * while blaming a colleague who was never there.
	 *
	 * WordPress records who wrote last in _edit_last. Where it says somebody
	 * else, the message is true and the refusal is right. Where it says this
	 * person, or says nothing, the write goes ahead: the worst case is a
	 * person overwriting their own earlier tab, which is what pressing Update
	 * in that tab would have done anyway.
	 */
	private static function someone_else_wrote( $post ) {
		$last = (int) get_post_meta( $post->ID, '_edit_last', true );
		if ( ! $last ) return false;
		return $last !== (int) get_current_user_id();
	}

	public static function ajax_save() {
		$post = self::guard();

		// A draft is saved for real. Somebody else may have saved it since
		// this tab drew the screen, though, and writing over their work
		// without a word is the one thing worse than not saving at all.
		if ( self::is_draft( $post ) ) {
			$seen = isset( $_POST['modified'] ) ? sanitize_text_field( wp_unslash( $_POST['modified'] ) ) : '';
			if ( $seen && $seen !== $post->post_modified_gmt && self::someone_else_wrote( $post ) ) {
				wp_send_json_error( array(
					'message'  => 'Somebody else has saved this since you opened it, so nothing was written. Reload to see their version.',
					'conflict' => true,
				), 409 );
			}

			// A request PHP cut short carries a form with its tail missing, and
			// writing it would empty every field past the cut. Checked here as
			// well as inside save_fields, because this path has to answer the
			// screen: telling somebody their draft was saved when it was
			// refused is worse than the refusal.
			if ( ! BFTD_MetaBoxes::posted_is_complete() ) {
				BFTD_MetaBoxes::log_refused_save( $post->ID );
				wp_send_json_error( array(
					'message'  => 'That was too much for one save, so nothing was written. '
						. 'The server stopped reading the form part way through. '
						. 'Nothing has been lost, but this record needs its max_input_vars limit raised before it can save again.',
					'conflict' => true,
				), 413 );
			}

			// Silently: no activity log entry, no revision, and no "Saved"
			// message waiting on the next screen. Only a person's save says so.
			BFTD_Audit::$quiet = true;
			add_filter( 'wp_revisions_to_keep', '__return_zero', 999 );
			if ( self::is_library( $post ) ) {
				self::save_library( $post );
			} else {
				BFTD_MetaBoxes::save_fields( $post->ID, $post );
			}
			BFTD_Audit::$quiet = false;

			// The title is part of the form, and a report named on the screen
			// but not in the record is a report nobody can find. A library
			// record's description is its content, so that goes too.
			self::save_title_content( $post, wp_unslash( $_POST ) );
			remove_filter( 'wp_revisions_to_keep', '__return_zero', 999 );
			delete_transient( 'bftd_saved_' . get_current_user_id() );

			// The one thing a silent save does say: something typed could not
			// be kept (a session slot that clashes or overfills a tutor's day).
			// Said now, on the screen being typed on, rather than left for the
			// next page load to announce after the row has quietly gone.
			$warn_key = 'bftd_sched_warn_' . get_current_user_id();
			$warnings = get_transient( $warn_key );
			delete_transient( $warn_key );

			// A brand new record is an "auto-draft" until somebody presses Save
			// Draft: it is left off every list, and WordPress deletes it after
			// a week. Autosaving the fields of one and saying "Draft saved"
			// left a tutor with a diagnostic nobody could find again, and then
			// none at all. So the first autosave makes it a real draft, which
			// is what Save Draft would have done.
			self::promote_auto_draft( $post->ID );

			$fresh = get_post( $post->ID );

			// The header pills describe the record, and the record has just
			// changed. They are worked out here rather than in the browser so
			// there is one answer to "will the family see this section", not a
			// second one guessing at it from the form.
			$pills = array();
			foreach ( BFTD_Schema::sections_for_post_type( $post->post_type ) as $sid => $unused ) {
				$pills[ $sid ] = BFTD_Fields::pill_state( $post->ID, $sid );
			}

			wp_send_json_success( array(
				'at'       => BFTD_Time::format( 'g:i a', time() ),
				'mode'     => 'draft',
				'modified' => $fresh ? $fresh->post_modified_gmt : '',
				'pills'    => $pills,
				'warnings' => $warnings ? array_values( (array) $warnings ) : array(),
			) );
		}

		// A published record updated since this tab drew it: this tab's draft
		// is out of date. Written anyway it would sit newer than the Update and
		// reopen over it, which is how an autosave still in flight when Update
		// was pressed could bring old words back.
		$seen = isset( $_POST['modified'] ) ? sanitize_text_field( wp_unslash( $_POST['modified'] ) ) : '';
		if ( $seen && $seen !== $post->post_modified_gmt ) {
			wp_send_json_error( array(
				'message'  => 'This was updated since you opened it. Reload to keep working on the latest version.',
				'conflict' => true,
			), 409 );
		}

		if ( ! BFTD_MetaBoxes::posted_is_complete() ) {
			BFTD_MetaBoxes::log_refused_save( $post->ID );
			wp_send_json_error( array(
				'message'  => 'That was too much for one save, so no copy was kept. '
					. 'The server stopped reading the form part way through, and half a form is not worth keeping.',
				'conflict' => true,
			), 413 );
		}

		$fields = array();
		foreach ( array_keys( $_POST ) as $k ) {
			if ( self::kept_key( (string) $k ) ) $fields[ $k ] = wp_unslash( $_POST[ $k ] );
		}
		// Nonces are not part of what was typed.
		unset( $fields['bftd_skill_nonce'], $fields['bftd_activity_nonce'] );
		if ( ! $fields ) wp_send_json_error( array( 'message' => 'Nothing to keep.' ), 400 );

		// One draft per record, whoever is typing: each autosave replaces it.
		// Stored raw and sanitised on the way out, because a snapshot is the
		// form as it stood rather than a version of the record. Nothing here
		// is ever rendered; drawing the draft runs it through the ordinary save.
		update_post_meta( $post->ID, self::KEY, array(
			'at'     => time(),
			'by'     => get_current_user_id(),
			'fields' => $fields,
		) );

		wp_send_json_success( array(
			'at'      => BFTD_Time::format( 'g:i a', time() ),
			'mode'    => 'snapshot',
			'kept'    => count( $fields ),
			'message' => self::DRAFT_LINE,
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Opening a published record that has a draft                          */
	/* ------------------------------------------------------------------ */

	/** Writes held in memory while the draft is drawn: [object id][meta key] => values. */
	private static $store     = null;
	private static $capturing = false;
	private static $bypass    = false;
	private static $hooked    = false;

	/** True while the draft is being worked out, so nothing leaves the building. */
	public static $dry = false;

	/** Is this request drawing a published record from its draft? */
	public static function showing_draft() {
		return null !== self::$store;
	}

	public static function open_with_draft( $post_type, $post = null ) {
		if ( ! $post instanceof WP_Post ) return;
		if ( ! in_array( $post->post_type, self::types(), true ) ) return;
		self::forget_core_autosaves( $post->ID );
		if ( self::is_draft( $post ) ) return;
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return;
		self::overlay( $post );
	}

	/**
	 * Run the ordinary save against the draft, with every write held in
	 * memory, so the edit screen that follows reads the draft's values.
	 *
	 * Nothing leaves memory: meta writes are captured, posts cannot be
	 * created or updated, mail is not sent, notices and the activity log are
	 * held back. After the save, reads of anything it wrote are answered from
	 * memory for the rest of this request; everything else, including the
	 * edit lock WordPress writes next, goes to the database as normal.
	 */
	public static function overlay( $post ) {
		$snap = self::unsaved( $post );
		if ( ! $snap ) return false;

		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_filter( 'get_post_metadata',    array( __CLASS__, 'meta_get' ),    0, 4 );
			add_filter( 'update_post_metadata', array( __CLASS__, 'meta_update' ), 0, 5 );
			add_filter( 'add_post_metadata',    array( __CLASS__, 'meta_add' ),    0, 5 );
			add_filter( 'delete_post_metadata', array( __CLASS__, 'meta_delete' ), 0, 5 );
		}
		self::$store = array();
		self::$capturing = true;
		self::$dry = true;

		$hold_posts = function () { return true; };   // wp_insert_post / wp_update_post do nothing
		$hold_mail  = function () { return false; };  // wp_mail sends nothing
		add_filter( 'wp_insert_post_empty_content', $hold_posts, 999 );
		add_filter( 'pre_wp_mail', $hold_mail, 999 );

		$was_post  = $_POST;
		$was_quiet = BFTD_Audit::$quiet;
		BFTD_Audit::$quiet = true;
		foreach ( $snap['fields'] as $k => $v ) {
			$_POST[ $k ] = wp_slash( $v );   // the save path unslashes, as WordPress does
		}
		unset( $_POST['bftd_sent'] );

		if ( self::is_library( $post ) ) {
			self::save_library( $post );
		} else {
			BFTD_MetaBoxes::save_fields( $post->ID, $post );
		}

		$_POST = $was_post;
		BFTD_Audit::$quiet = $was_quiet;
		remove_filter( 'wp_insert_post_empty_content', $hold_posts, 999 );
		remove_filter( 'pre_wp_mail', $hold_mail, 999 );
		self::$capturing = false;
		self::$dry = false;

		// The "saved" and warning messages a save leaves for the next screen
		// belong to a person's save, not to drawing a draft.
		foreach ( array( 'bftd_saved_', 'bftd_sched_warn_', 'bftd_held_', 'bftd_dupe_' ) as $t ) {
			delete_transient( $t . get_current_user_id() );
		}

		// And the title, which the screen reads off the post itself.
		if ( isset( $snap['fields']['post_title'] ) ) {
			$title = sanitize_text_field( $snap['fields']['post_title'] );
			if ( '' !== $title ) $post->post_title = $title;
		}
		return true;
	}

	private static function load( $id, $key ) {
		if ( isset( self::$store[ $id ][ $key ] ) ) return;
		self::$bypass = true;
		$vals = get_post_meta( $id, $key, false );
		self::$bypass = false;
		self::$store[ $id ][ $key ] = is_array( $vals ) ? array_values( $vals ) : array();
	}

	public static function meta_get( $check, $id, $key, $single ) {
		if ( self::$bypass || null === self::$store || '' === (string) $key ) return $check;
		if ( ! isset( self::$store[ $id ][ $key ] ) ) return $check;
		$vals = self::$store[ $id ][ $key ];
		if ( $single ) return $vals ? array( $vals[0] ) : array( '' );
		return $vals;
	}

	public static function meta_update( $check, $id, $key, $value, $prev = '' ) {
		if ( ! self::$capturing ) return $check;
		self::load( $id, $key );
		if ( '' !== $prev && null !== $prev ) {
			foreach ( self::$store[ $id ][ $key ] as $i => $v ) {
				if ( $v == $prev ) self::$store[ $id ][ $key ][ $i ] = $value;
			}
		} else {
			self::$store[ $id ][ $key ] = array( $value );
		}
		return true;
	}

	public static function meta_add( $check, $id, $key, $value, $unique = false ) {
		if ( ! self::$capturing ) return $check;
		self::load( $id, $key );
		if ( $unique && self::$store[ $id ][ $key ] ) return false;
		self::$store[ $id ][ $key ][] = $value;
		return true;
	}

	public static function meta_delete( $check, $id, $key, $value = '', $all = false ) {
		if ( ! self::$capturing ) return $check;
		self::load( $id, $key );
		if ( '' === $value || null === $value || false === $value ) {
			self::$store[ $id ][ $key ] = array();
		} else {
			self::$store[ $id ][ $key ] = array_values( array_filter(
				self::$store[ $id ][ $key ],
				function ( $v ) use ( $value ) { return $v != $value; }
			) );
		}
		return true;
	}

	/**
	 * WordPress's own per-person autosaves, left over from before core's
	 * autosave was switched off here. They are copies nobody can see or use,
	 * and "no draft revisions" means none, so they go.
	 */
	private static function forget_core_autosaves( $post_id ) {
		global $wpdb;
		$old = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' AND post_name LIKE %s",
			(int) $post_id,
			'%' . $wpdb->esc_like( '-autosave-v' ) . '%'
		) );
		foreach ( (array) $old as $rid ) wp_delete_post_revision( (int) $rid );
	}

	/* ------------------------------------------------------------------ */
	/* The one message                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * The only thing autosave ever says, and only on a published record: shown
	 * beside the Update button when the screen opens on a draft and after each
	 * autosave of one (bftd-admin.js).
	 */
	const DRAFT_LINE = 'Draft saved, update to take live';
}
