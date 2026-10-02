<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Autosave, for drafts only.
 *
 * A reading diagnostic is six sections of writing. A tutor who loses forty
 * minutes of it to a crashed browser, an expired session or a stray back
 * button has lost an evening, and the thing they will remember about this
 * software is that it ate their work.
 *
 * WordPress autosaves the post title and content and nothing else. Every
 * field on these screens lives in a meta box, so core's autosave has never
 * protected a single word of a report.
 *
 * IT BEHAVES DIFFERENTLY ON A DRAFT AND ON A PUBLISHED REPORT, AND THE
 * DIFFERENCE IS THE POINT.
 *
 * On a DRAFT it saves the record, properly, the way pressing Update does.
 * Nobody outside the practice can open a draft, so there is nothing to
 * protect a family from, and a tutor who typed a figure and saw it vanish
 * would rightly call that broken. Only the activity log is held back, because
 * one entry every twenty-five seconds would bury the entries people made.
 *
 * On a PUBLISHED report it writes nothing. A family may be reading it, and
 * pushing a half finished sentence onto their screen because somebody left a
 * tab open is not a decision anybody made. So the form is kept as a snapshot
 * beside the record until a person asks for it back, and asking for it back
 * is an ordinary save with an ordinary entry in the log.
 *
 * In both cases a preview shows what the tutor is working on: on a draft
 * because it was really saved, and on a published report because the preview
 * is offered the snapshot.
 */
class BFTD_Autosave {

	const KEY = '_bftd_autosave';

	public static function init() {
		add_action( 'wp_ajax_bftd_autosave', array( __CLASS__, 'ajax_save' ) );
		add_action( 'wp_ajax_bftd_autosave_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_bftd_autosave_discard', array( __CLASS__, 'ajax_discard' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_bar' ) );

		// A save by a person is the snapshot's whole purpose served, so it
		// goes. Late, so it runs after the fields it was protecting are in.
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
		if ( ! $post || ! in_array( $post->post_type, BFTD_Roles::post_types(), true ) ) return;
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
		if ( ! in_array( $post->post_type, BFTD_Roles::post_types(), true ) ) return false;
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
		if ( empty( $_POST['bftd_nonce'] ) ) return;   // a save from our own form
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
		if ( ! $post || ! in_array( $post->post_type, BFTD_Roles::post_types(), true ) ) {
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

			BFTD_Audit::$quiet = true;
			BFTD_MetaBoxes::save_fields( $post->ID, $post );
			BFTD_Audit::$quiet = false;

			// The title is part of the form, and a report named on the screen
			// but not in the record is a report nobody can find.
			if ( isset( $_POST['post_title'] ) ) {
				$title = sanitize_text_field( wp_unslash( $_POST['post_title'] ) );
				if ( '' !== $title && $title !== $post->post_title ) {
					wp_update_post( array( 'ID' => $post->ID, 'post_title' => $title ) );
				}
			}

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
			) );
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
		foreach ( array( 'bftd', 'bftd_rows', 'bftd_sched', 'bftd_visible', 'bftd_items' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) $fields[ $k ] = wp_unslash( $_POST[ $k ] );
		}
		foreach ( array( 'post_title', 'bftd_subtitle', 'bftd_student' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) $fields[ $k ] = wp_unslash( $_POST[ $k ] );
		}
		if ( ! $fields ) wp_send_json_error( array( 'message' => 'Nothing to keep.' ), 400 );

		// Stored raw and sanitised on the way out, because a snapshot is the
		// form as it stood rather than a version of the record. Nothing here
		// is ever rendered; restoring runs it through the ordinary save.
		update_post_meta( $post->ID, self::KEY, array(
			'at'     => time(),
			'by'     => get_current_user_id(),
			'fields' => $fields,
		) );

		wp_send_json_success( array(
			'at'   => BFTD_Time::format( 'g:i a', time() ),
			'mode' => 'snapshot',
			'kept' => count( $fields ),
		) );
	}

	/**
	 * Put the snapshot back, as an ordinary save.
	 *
	 * Through the same method the form uses, so everything that normally
	 * happens on a save happens here: the same sanitising, the same activity
	 * log entry, the same flag on the review bar. A restore that wrote meta
	 * directly would be a second way into the database and the one nobody
	 * checks when a field's rules change.
	 */
	public static function ajax_restore() {
		$post = self::guard();
		$snap = self::snapshot( $post->ID );
		if ( ! $snap ) wp_send_json_error( array( 'message' => 'There is nothing to put back.' ), 404 );

		foreach ( $snap['fields'] as $k => $v ) {
			$_POST[ $k ] = wp_slash( $v );   // the save path unslashes, as WordPress does
		}
		BFTD_MetaBoxes::save_fields( $post->ID, $post );

		if ( isset( $snap['fields']['post_title'] ) ) {
			$title = sanitize_text_field( $snap['fields']['post_title'] );
			if ( '' !== $title && $title !== $post->post_title ) {
				wp_update_post( array( 'ID' => $post->ID, 'post_title' => $title ) );
			}
		}

		BFTD_Audit::log( 'autosave_restored', array(
			'post_id' => $post->ID,
			'summary' => 'Unsaved changes from ' . BFTD_Time::format( 'j M Y, g:i a', (int) $snap['at'] ) . ' were put back.',
		) );

		self::clear( $post->ID );
		wp_send_json_success();
	}

	public static function ajax_discard() {
		$post = self::guard();
		self::clear( $post->ID );
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ */
	/* The bar                                                             */
	/* ------------------------------------------------------------------ */

	public static function render_bar( $post ) {
		if ( ! $post || ! self::is_draft( $post ) ) return;
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

		$snap = self::unsaved( $post );
		if ( ! $snap ) return;

		$who  = get_userdata( (int) $snap['by'] );
		$mine = (int) $snap['by'] === get_current_user_id();
		?>
		<div class="bftd-restore" data-post="<?php echo (int) $post->ID; ?>">
			<p>
				<strong>There are changes here that were never saved.</strong>
				<?php echo esc_html( sprintf(
					'%s at %s, and %s.',
					$mine ? 'You were editing this' : ( $who ? $who->display_name . ' was editing this' : 'Somebody was editing this' ),
					BFTD_Time::format( 'g:i a on j F', (int) $snap['at'] ),
					$mine ? 'the page closed before you saved' : 'their page closed before they saved'
				) ); ?>
				Putting them back replaces what is on this screen now.
			</p>
			<p class="bftd-restore-do">
				<button type="button" class="button button-primary bftd-restore-yes">Put them back</button>
				<button type="button" class="button-link bftd-restore-no">Discard them</button>
			</p>
		</div>
		<?php
	}
}
