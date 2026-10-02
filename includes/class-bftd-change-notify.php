<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The review bar.
 *
 * When a report changes, this notices, and shows the tutor a small bar on the
 * edit screen: here is what changed, here is the email that would go out, edit
 * it if you like, send it or dismiss it. Nothing on this path ever emails a
 * family on its own.
 *
 * That is a product decision as much as a technical one. A family hearing from
 * Brilliant Futures should feel like their tutor wrote to them, because their
 * tutor did. Automatic mail is reserved for the handful of true system events
 * in BFTD_Emails where a rule is set to "always".
 */
class BFTD_Change_Notify {

	public static function types() {
		return array(
			'report' => array(
				'label' => 'Report content',
				'email' => 'client_report_published',
			),
			'lesson' => array(
				'label' => 'Session notes',
				'email' => 'client_lesson_recorded',
			),
			'items' => array(
				'label' => 'Priority and review items',
				'email' => 'client_items_updated',
			),
			'messages' => array(
				'label' => 'Messages you have sent',
				'email' => 'client_new_message',
			),
		);
	}

	public static function init() {
		add_action( 'edit_form_after_title', array( __CLASS__, 'render_bar' ) );
		add_action( 'wp_ajax_bftd_change_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_bftd_change_send', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_bftd_change_dismiss', array( __CLASS__, 'ajax_dismiss' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
	}

	/**
	 * Publishing is the moment a family may hear about a record.
	 *
	 * Everything held back while the record was a draft is still flagged, so
	 * the review bar is waiting on the next edit screen. The one thing that
	 * has to happen here is the caregiver's welcome, because a caregiver
	 * linked to a draft student was deliberately not told their portal was
	 * ready, and there is no later save that would tell them.
	 */
	public static function on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) return;
		if ( ! $post || BFTD_CPT::STUDENT !== $post->post_type ) return;

		foreach ( BFTD_CPT::client_ids( $post->ID ) as $uid ) {
			if ( get_user_meta( $uid, self::welcomed_key( $post->ID ), true ) ) continue;
			BFTD_MetaBoxes::send_welcome( $uid, $post->ID );
		}
	}

	/** One flag per caregiver per student, so nobody is welcomed twice. */
	public static function welcomed_key( $student_id ) {
		return '_bftd_welcomed_' . (int) $student_id;
	}

	private static function pending_key( $type ) { return '_bftd_pending_' . $type; }

	/**
	 * Is this record live for the family yet?
	 *
	 * A draft is a tutor thinking out loud. Nothing about it reaches a
	 * caregiver: not an email, not a portal notice, not the review bar
	 * offering to send one. The work accumulates quietly and the offer to
	 * tell the family appears the moment the record is published.
	 *
	 * The check walks up to the student where the record is a lesson or a
	 * report, because a published lesson that hangs off an unpublished
	 * student is not visible either.
	 */
	public static function is_published( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id ) return false;
		if ( 'publish' !== get_post_status( $post_id ) ) return false;

		$student_id = BFTD_CPT::student_id( $post_id );
		if ( $student_id && $student_id !== $post_id && 'publish' !== get_post_status( $student_id ) ) {
			return false;
		}
		return true;
	}

	/** Flag a change for review. Called after a save that actually changed something. */
	public static function flag( $post_id, $type, $summary ) {
		if ( ! isset( self::types()[ $type ] ) ) return;
		$pending = get_post_meta( $post_id, self::pending_key( $type ), true );
		$pending = is_array( $pending ) ? $pending : array();
		$pending[] = array( 'summary' => wp_strip_all_tags( $summary ), 'at' => BFTD_Time::mysql() );
		if ( count( $pending ) > 20 ) $pending = array_slice( $pending, -20 );
		update_post_meta( $post_id, self::pending_key( $type ), $pending );

		BFTD_Audit::log( 'change_flagged', array(
			'post_id' => $post_id,
			'summary' => self::types()[ $type ]['label'] . ' changed: ' . wp_strip_all_tags( $summary ),
		) );
	}

	public static function queue_message( $post_id, $excerpt ) {
		self::flag( $post_id, 'messages', $excerpt );
	}

	public static function pending( $post_id ) {
		$out = array();
		foreach ( self::types() as $key => $cfg ) {
			$rows = get_post_meta( $post_id, self::pending_key( $key ), true );
			if ( is_array( $rows ) && $rows ) {
				$cfg['rows']  = $rows;
				$cfg['count'] = count( $rows );
				$out[ $key ]  = $cfg;
			}
		}
		return $out;
	}

	private static function clear( $post_id, $type ) {
		delete_post_meta( $post_id, self::pending_key( $type ) );
	}

	private static function guard( $post_id ) {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$post_id = (int) $post_id;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'You cannot send from this record.' ), 403 );
		}
		// The bar is hidden on a draft, but the request can still arrive from
		// a tab that was open when the record was unpublished.
		if ( ! self::is_published( $post_id ) ) {
			wp_send_json_error( array(
				'message' => 'This is still a draft. Publish it first and nothing goes out until you do.',
			), 409 );
		}
		return $post_id;
	}

	private static function recipients( $student_id ) {
		$out = array();
		foreach ( BFTD_CPT::client_ids( $student_id ) as $uid ) {
			$u = get_userdata( $uid );
			if ( $u && is_email( $u->user_email ) ) $out[ $uid ] = $u;
		}
		return $out;
	}

	private static function tags_for( $student_id, $user, $summary ) {
		$report = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		return array(
			'first_name'      => $user->first_name ? $user->first_name : $user->display_name,
			'student_name'    => get_the_title( $student_id ),
			'tutor_name'      => wp_get_current_user()->display_name,
			'dashboard_url'   => BFTD_Dashboard::url( $student_id ),
			'changes_summary' => $summary,
			'item_summary'    => $summary,
			'session_summary' => $summary,
			'report_name'     => $report ? get_the_title( $report ) : '',
			'report_type'     => 'progress report',
			'section_label'   => '',
			'message_excerpt' => $summary,
			'item_count'      => '',
		);
	}

	public static function render_bar( $post ) {
		if ( ! $post || ! in_array( $post->post_type, array( BFTD_CPT::STUDENT, BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS, BFTD_CPT::SESSION ), true ) ) return;
		if ( ! current_user_can( 'edit_post', $post->ID ) ) return;

		$pending = self::pending( $post->ID );
		if ( ! $pending ) return;

		// Never a send on a draft. The changes stay flagged and the offer
		// appears once the record is published, so nothing is lost by
		// drafting, but the tutor is told that is what is happening rather
		// than left wondering where the bar went.
		if ( ! self::is_published( $post->ID ) ) {
			$n = 0;
			foreach ( $pending as $cfg ) $n += $cfg['count'];
			?>
			<div class="bftd-change-bar is-held">
				<p><strong>Nothing has gone to the family.</strong>
				<?php echo esc_html(
					1 === $n
						? 'One change is saved here and waiting.'
						: $n . ' changes are saved here and waiting.'
				); ?>
				This is still a draft, so no email and no portal notice goes out.
				Publish it and the offer to write to the family appears here.</p>
			</div>
			<?php
			return;
		}

		$student_id = BFTD_CPT::student_id( $post->ID );
		$people     = self::recipients( $student_id );
		?>
		<div class="bftd-change-bar" data-post="<?php echo (int) $post->ID; ?>">
			<div class="bftd-change-head">
				<strong>Since you last told the family</strong>
				<span class="description"><?php echo $people
					? esc_html( count( $people ) ) . ' ' . esc_html( _n( 'person', 'people', count( $people ), 'bftd' ) ) . ' would get this'
					: 'Nobody is linked to this student yet, so there is no one to tell'; ?></span>
			</div>
			<div class="bftd-change-pills">
				<?php foreach ( $pending as $key => $cfg ) : ?>
					<div class="bftd-change-pill" data-type="<?php echo esc_attr( $key ); ?>">
						<span class="bftd-change-label"><?php echo esc_html( $cfg['label'] ); ?></span>
						<span class="bftd-change-n"><?php echo (int) $cfg['count']; ?></span>
						<button type="button" class="button button-primary bftd-change-review" <?php disabled( ! $people ); ?>>Review and send</button>
						<button type="button" class="button-link bftd-change-dismiss">Dismiss</button>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="bftd-modal" id="bftd-change-modal" hidden>
			<div class="bftd-modal-box" role="dialog" aria-modal="true" aria-labelledby="bftd-modal-title">
				<h2 id="bftd-modal-title">Send this to the family</h2>
				<p class="description">The wording comes from your template. Edit it here and only this send changes; edit it under Emails and every future one does.</p>
				<label>To</label><div class="bftd-modal-to"></div>
				<label for="bftd-modal-subject">Subject</label>
				<input type="text" id="bftd-modal-subject" class="large-text">
				<label for="bftd-modal-preview">Preview text</label>
				<input type="text" id="bftd-modal-preview" class="large-text">
				<label for="bftd-modal-message">Message</label>
				<textarea id="bftd-modal-message" rows="12" class="large-text"></textarea>
				<div class="bftd-modal-foot">
					<button type="button" class="button button-primary bftd-modal-send">Send it</button>
					<button type="button" class="button bftd-modal-cancel">Not now</button>
					<span class="bftd-modal-status" role="status"></span>
				</div>
			</div>
		</div>
		<?php
	}

	public static function ajax_preview() {
		$post_id = self::guard( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$types   = self::types();
		if ( ! isset( $types[ $type ] ) ) wp_send_json_error( array( 'message' => 'Unknown change type.' ), 400 );

		$pending = self::pending( $post_id );
		if ( ! isset( $pending[ $type ] ) ) wp_send_json_error( array( 'message' => 'Nothing pending there any more.' ), 400 );

		$student_id = BFTD_CPT::student_id( $post_id );
		$people     = self::recipients( $student_id );
		if ( ! $people ) wp_send_json_error( array( 'message' => 'No caregiver is linked to this student yet.' ), 400 );

		$summary = self::summary_text( $pending[ $type ]['rows'] );
		$first   = reset( $people );
		$tpl     = BFTD_Emails::get_template( $types[ $type ]['email'] );
		$tags    = self::tags_for( $student_id, $first, $summary );

		wp_send_json_success( array(
			'to'      => implode( ', ', array_map( function ( $u ) { return $u->display_name . ' <' . $u->user_email . '>'; }, $people ) ),
			'subject' => BFTD_Emails::apply_tags( $tpl['subject'], $tags ),
			'preview' => BFTD_Emails::apply_tags( $tpl['preview'], $tags ),
			'message' => BFTD_Emails::apply_tags( $tpl['message'], $tags ),
		) );
	}

	private static function summary_text( $rows ) {
		$lines = array();
		foreach ( (array) $rows as $r ) {
			$lines[] = '- ' . $r['summary'];
		}
		return implode( "\n", array_slice( $lines, 0, 12 ) );
	}

	public static function ajax_send() {
		$post_id = self::guard( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( self::types()[ $type ] ) ) wp_send_json_error( array( 'message' => 'Unknown change type.' ), 400 );

		$subject = sanitize_text_field( wp_unslash( isset( $_POST['subject'] ) ? $_POST['subject'] : '' ) );
		$preview = sanitize_text_field( wp_unslash( isset( $_POST['preview'] ) ? $_POST['preview'] : '' ) );
		$message = sanitize_textarea_field( wp_unslash( isset( $_POST['message'] ) ? $_POST['message'] : '' ) );
		if ( '' === trim( $subject ) || '' === trim( $message ) ) {
			wp_send_json_error( array( 'message' => 'It needs a subject and a message.' ), 400 );
		}

		$student_id = BFTD_CPT::student_id( $post_id );
		$sent = 0;
		foreach ( self::recipients( $student_id ) as $uid => $user ) {
			$personal = str_replace(
				array( '{{first_name}}', '{{student_name}}' ),
				array( $user->first_name ? $user->first_name : $user->display_name, get_the_title( $student_id ) ),
				$message
			);
			if ( BFTD_Emails::send_raw( $user->user_email, $subject, $preview, $personal, array( 'post_id' => $post_id, 'student_id' => $student_id ) ) ) {
				$sent++;
				BFTD_Notices::add( $uid, 'report', $student_id, $post_id, '', $subject, $preview );
			}
		}

		self::clear( $post_id, $type );
		wp_send_json_success( array( 'message' => $sent ? 'Sent to ' . $sent . '.' : 'Nothing went out. Check the activity log for why.' ) );
	}

	public static function ajax_dismiss() {
		$post_id = self::guard( isset( $_POST['post_id'] ) ? $_POST['post_id'] : 0 );
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! isset( self::types()[ $type ] ) ) wp_send_json_error( array( 'message' => 'Unknown change type.' ), 400 );

		self::clear( $post_id, $type );
		BFTD_Audit::log( 'change_dismissed', array(
			'post_id' => $post_id,
			'summary' => self::types()[ $type ]['label'] . ' change was dismissed without emailing the family.',
		) );
		wp_send_json_success( array( 'message' => 'Dismissed.' ) );
	}
}
