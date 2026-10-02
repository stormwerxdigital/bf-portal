<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Priority items and For review.
 *
 * Two lists, deliberately kept as two, with their own meta keys and their own
 * AJAX actions. They are nearly the same feature, and the temptation to write
 * one parameterised class is exactly the mistake the Stormwerx build avoided:
 * the wording, the colour and the urgency of the two lists diverge over time,
 * and a shared class means every wording change to one has to be checked
 * against the other.
 *
 * The shape of one item:
 *
 *   id            stable, so a tutor editing the wording never loses the
 *                 family's checked state
 *   text          the ask, in one line
 *   details       the longer explanation, behind a disclosure
 *   link/label    an optional thing to open
 *   section       optional: pin the item to a report section
 *   checked       the family has done it
 *   confirmed     a tutor has since agreed it is done, which retires it
 *
 * Checking and confirming are two separate steps on purpose. A parent saying
 * "done" and a tutor agreeing are different facts, and collapsing them loses
 * the one that matters when something was not actually done.
 */
class BFTD_Items {

	const PRIORITY = 'priority';
	const REVIEW   = 'review';

	public static function lists() {
		return array(
			self::PRIORITY => array(
				'meta'    => '_bftd_priority_items',
				'title'   => 'Priority items',
				'eyebrow' => 'Before your next session',
				'empty'   => 'Nothing needs your attention right now.',
				'done'    => 'All done',
				'verb'    => 'Acknowledged by',
				'tone'    => 'clay',
				'email'   => 'client_items_updated',
			),
			self::REVIEW => array(
				'meta'    => '_bftd_review_items',
				'title'   => 'For review',
				'eyebrow' => 'Your to-do list',
				'empty'   => 'Nothing waiting on you.',
				'done'    => 'All done',
				'verb'    => 'Done by',
				'tone'    => 'sage',
				'email'   => 'client_items_updated',
			),
		);
	}

	public static function config( $list ) {
		$all = self::lists();
		return isset( $all[ $list ] ) ? $all[ $list ] : null;
	}

	public static function init() {
		add_action( 'wp_ajax_bftd_toggle_item', array( __CLASS__, 'ajax_toggle' ) );
		add_action( 'wp_ajax_bftd_confirm_item', array( __CLASS__, 'ajax_confirm' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Stand-in items for the sample report, the same trick the field store
	 * uses: the sample renders through the real card rather than a drawing
	 * of one, so it cannot look different from the thing it demonstrates.
	 */
	private static $fixture = null;

	public static function use_fixture( $data ) { self::$fixture = is_array( $data ) ? $data : null; }
	public static function clear_fixture() { self::$fixture = null; }

	public static function all( $student_id, $list ) {
		$cfg = self::config( $list );
		if ( ! $cfg ) return array();

		if ( null !== self::$fixture ) {
			return isset( self::$fixture[ $list ] ) ? (array) self::$fixture[ $list ] : array();
		}

		$items = get_post_meta( $student_id, $cfg['meta'], true );
		return is_array( $items ) ? $items : array();
	}

	/** What a family sees: everything a tutor has not yet retired. */
	public static function open_items( $student_id, $list, $section_id = null ) {
		$out = array();
		foreach ( self::all( $student_id, $list ) as $item ) {
			if ( ! empty( $item['confirmed'] ) ) continue;
			if ( '' === trim( (string) $item['text'] ) ) continue;
			if ( null !== $section_id && ( isset( $item['section'] ) ? $item['section'] : '' ) !== $section_id ) continue;
			$out[] = $item;
		}
		return $out;
	}

	public static function outstanding_count( $student_id, $list, $section_id = null ) {
		$n = 0;
		foreach ( self::open_items( $student_id, $list, $section_id ) as $item ) {
			if ( empty( $item['checked'] ) ) $n++;
		}
		return $n;
	}

	private static function all_checked( $items ) {
		$any = false;
		foreach ( $items as $item ) {
			if ( ! empty( $item['confirmed'] ) ) continue;
			$any = true;
			if ( empty( $item['checked'] ) ) return false;
		}
		return $any;
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                             */
	/* ------------------------------------------------------------------ */

	public static function ajax_toggle() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );

		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
		$list       = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		$item_id    = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
		$cfg        = self::config( $list );

		if ( ! $student_id || ! $item_id || ! $cfg || ! BFTD_Threads::can_access( $student_id ) ) {
			wp_send_json_error( array( 'message' => 'That list is not yours.' ), 403 );
		}

		$items = self::all( $student_id, $list );

		// Snapshot before the toggle, so the "everything is done" email fires
		// on the click that finishes the list and never again after it.
		$was_all_done = self::all_checked( $items );

		$matched = null;
		foreach ( $items as &$item ) {
			if ( ! isset( $item['id'] ) || $item['id'] !== $item_id ) continue;
			$now = empty( $item['checked'] );
			$item['checked']    = $now;
			$item['checked_by'] = $now ? get_current_user_id() : 0;
			$item['checked_at'] = $now ? BFTD_Time::mysql() : '';
			$matched = $item;
			break;
		}
		unset( $item );

		if ( null === $matched ) wp_send_json_error( array( 'message' => 'That item is gone.' ), 404 );

		update_post_meta( $student_id, $cfg['meta'], $items );

		$checked = ! empty( $matched['checked'] );
		$who     = $checked && $matched['checked_by'] ? get_userdata( $matched['checked_by'] ) : null;
		$meta    = $who ? $cfg['verb'] . ' ' . $who->display_name . ' &middot; just now' : '';

		// Only a family checking their own item is "completed". A tutor
		// ticking one on their behalf is a different act, and confirming is
		// already logged separately.
		if ( $checked && ! BFTD_Roles::is_staff() ) {
			BFTD_Audit::log( 'item_checked', array(
				'post_id'    => $student_id,
				'student_id' => $student_id,
				'summary'    => wp_get_current_user()->display_name . ' checked off "' . $matched['text'] . '" on ' . $cfg['title'] . '.',
			) );
		}

		if ( ! $was_all_done && self::all_checked( $items ) && ! BFTD_Roles::is_staff() ) {
			self::notify_all_done( $student_id, $cfg );
		}

		wp_send_json_success( array( 'checked' => $checked, 'meta' => $meta ) );
	}

	private static function notify_all_done( $student_id, $cfg ) {
		$family = wp_get_current_user();
		$told   = array();

		foreach ( BFTD_Access::notify_staff_ids( $student_id ) as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) continue;

			BFTD_Notices::add( $uid, 'item', $student_id, $student_id, '',
				get_the_title( $student_id ) . ': ' . $cfg['title'] . ' is all done',
				$family->display_name . ' checked off the last one.' );

			BFTD_Emails::send( 'staff_item_completed', $u->user_email, array(
				'first_name'    => $u->first_name ? $u->first_name : $u->display_name,
				'tutor_name'    => $u->display_name,
				'student_name'  => get_the_title( $student_id ),
				'list_name'     => $cfg['title'],
				'dashboard_url' => admin_url( 'admin.php?page=' . BFTD_Admin::HUB_SLUG . '&student=' . (int) $student_id ),
			), array( 'student_id' => $student_id, 'post_id' => $student_id ) );
			$told[] = strtolower( $u->user_email );
		}

		if ( empty( BFTD_Settings::routing()['copy_on_item'] ) ) return;

		foreach ( BFTD_Settings::copy_addresses() as $email ) {
			if ( in_array( strtolower( $email ), $told, true ) ) continue;
			BFTD_Emails::send( 'staff_item_completed', $email, array(
				'first_name'    => 'there',
				'tutor_name'    => '',
				'student_name'  => get_the_title( $student_id ),
				'list_name'     => $cfg['title'],
				'dashboard_url' => admin_url( 'admin.php?page=' . BFTD_Admin::HUB_SLUG . '&student=' . (int) $student_id ),
			), array( 'student_id' => $student_id, 'post_id' => $student_id ) );
		}
	}

	/**
	 * Staff only. Confirming retires the item from the family's view;
	 * reopening brings it back exactly as they left it. Their checked state
	 * is never touched by either.
	 */
	public static function ajax_confirm() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );

		$student_id = isset( $_POST['student_id'] ) ? absint( $_POST['student_id'] ) : 0;
		$list       = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		$item_id    = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
		$confirm    = ! empty( $_POST['confirm'] );
		$cfg        = self::config( $list );

		if ( ! $student_id || ! $item_id || ! $cfg || ! BFTD_Roles::is_staff() || ! current_user_can( 'edit_post', $student_id ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ), 403 );
		}

		$items   = self::all( $student_id, $list );
		$matched = null;
		foreach ( $items as &$item ) {
			if ( ! isset( $item['id'] ) || $item['id'] !== $item_id ) continue;
			$item['confirmed']    = $confirm;
			$item['confirmed_by'] = $confirm ? get_current_user_id() : 0;
			$item['confirmed_at'] = $confirm ? BFTD_Time::mysql() : '';
			$matched = $item;
			break;
		}
		unset( $item );

		if ( null === $matched ) wp_send_json_error( array( 'message' => 'That item is gone.' ), 404 );

		update_post_meta( $student_id, $cfg['meta'], $items );

		BFTD_Audit::log( $confirm ? 'item_confirmed' : 'item_reopened', array(
			'post_id'    => $student_id,
			'student_id' => $student_id,
			'summary'    => wp_get_current_user()->display_name . ' ' . ( $confirm ? 'marked complete' : 'reopened' ) . ' "' . $matched['text'] . '" on ' . $cfg['title'] . '.',
		) );

		wp_send_json_success( array( 'confirmed' => $confirm ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin editor                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * The editor for one list.
	 *
	 * $owner is the student's name, passed only from a screen that is not
	 * the student's own record. It goes into the same sentence rather than a
	 * second paragraph above it, because two descriptions stacked on one box
	 * is how a box stops being read at all.
	 */
	public static function render_editor( $student_id, $list, $owner = '' ) {
		$cfg   = self::config( $list );
		$items = self::all( $student_id, $list );
		$secs  = BFTD_Schema::thread_sections();
		$note  = 'These sit across the top of the family\'s portal. An item pinned to a section only appears when they are reading that section; leave it unpinned and it shows everywhere.';
		if ( '' !== trim( (string) $owner ) ) {
			$note = 'This is ' . esc_html( $owner ) . '\'s list, the same one on their student record, so anything added here shows on every report they can see. ' . $note;
		}
		?>
		<div class="bftd-items bftd-items-<?php echo esc_attr( $cfg['tone'] ); ?>" data-list="<?php echo esc_attr( $list ); ?>" data-student="<?php echo (int) $student_id; ?>">
			<p class="description"><?php echo $note; // Composed above; the only variable in it is escaped there. ?></p>
			<div class="bftd-item-rows">
				<?php
				$i = 0;
				foreach ( $items as $item ) {
					self::render_editor_row( $list, $item, $i, $secs );
					$i++;
				}
				?>
			</div>
			<button type="button" class="button bftd-item-add">Add an item</button>
			<script type="text/template" class="bftd-item-tpl"><?php self::render_editor_row( $list, array(), '__i__', $secs ); ?></script>
		</div>
		<?php
	}

	private static function render_editor_row( $list, $item, $i, $secs ) {
		$item = wp_parse_args( $item, array(
			'id' => '', 'text' => '', 'details' => '', 'link' => '', 'link_text' => '',
			'section' => '', 'checked' => false, 'checked_by' => 0, 'checked_at' => '',
			'confirmed' => false, 'confirmed_by' => 0, 'confirmed_at' => '',
		) );
		$name = 'bftd_items[' . esc_attr( $list ) . '][' . $i . ']';
		?>
		<div class="bftd-item-row<?php echo ! empty( $item['confirmed'] ) ? ' is-confirmed' : ''; ?>" data-item="<?php echo esc_attr( $item['id'] ); ?>">
			<input type="hidden" name="<?php echo $name; ?>[id]" value="<?php echo esc_attr( $item['id'] ); ?>">
			<input type="hidden" name="<?php echo $name; ?>[checked]" value="<?php echo ! empty( $item['checked'] ) ? 1 : 0; ?>">
			<input type="hidden" name="<?php echo $name; ?>[checked_by]" value="<?php echo (int) $item['checked_by']; ?>">
			<input type="hidden" name="<?php echo $name; ?>[checked_at]" value="<?php echo esc_attr( $item['checked_at'] ); ?>">
			<input type="hidden" name="<?php echo $name; ?>[confirmed]" value="<?php echo ! empty( $item['confirmed'] ) ? 1 : 0; ?>">
			<input type="hidden" name="<?php echo $name; ?>[confirmed_by]" value="<?php echo (int) $item['confirmed_by']; ?>">
			<input type="hidden" name="<?php echo $name; ?>[confirmed_at]" value="<?php echo esc_attr( $item['confirmed_at'] ); ?>">

			<div class="bftd-item-main">
				<span class="bftd-drag dashicons dashicons-menu-alt2" aria-hidden="true"></span>
				<div class="bftd-item-fields">
					<input type="text" class="bftd-item-text" name="<?php echo $name; ?>[text]" value="<?php echo esc_attr( $item['text'] ); ?>" placeholder="What you are asking them to do">
					<textarea rows="2" name="<?php echo $name; ?>[details]" placeholder="The longer explanation, shown behind a Details link"><?php echo esc_textarea( $item['details'] ); ?></textarea>
					<div class="bftd-item-grid">
						<label>Link<input type="url" name="<?php echo $name; ?>[link]" value="<?php echo esc_attr( $item['link'] ); ?>" placeholder="https://"></label>
						<label>Link label<input type="text" name="<?php echo $name; ?>[link_text]" value="<?php echo esc_attr( $item['link_text'] ); ?>" placeholder="Open it"></label>
						<label>Pin to section
							<select name="<?php echo $name; ?>[section]">
								<option value="">Show everywhere</option>
								<?php foreach ( $secs as $sid => $sec ) : ?>
									<option value="<?php echo esc_attr( $sid ); ?>" <?php selected( $item['section'], $sid ); ?>><?php echo esc_html( BFTD_Schema::screen_label( $sec['screen'] ) . ' · ' . $sec['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</div>
					<div class="bftd-item-state">
						<?php if ( ! empty( $item['checked'] ) && $item['checked_by'] ) :
							$u = get_userdata( $item['checked_by'] ); ?>
							<span class="bftd-pill bftd-pill-sage">Checked by <?php echo esc_html( $u ? $u->display_name : 'someone' ); ?><?php echo $item['checked_at'] ? esc_html( ' · ' . mysql2date( get_option( 'date_format' ), $item['checked_at'] ) ) : ''; ?></span>
						<?php elseif ( $item['id'] ) : ?>
							<span class="bftd-pill bftd-pill-quiet">Not checked yet</span>
						<?php endif; ?>
						<?php if ( $item['id'] ) : ?>
							<button type="button" class="button-link bftd-item-confirm" data-confirm="<?php echo ! empty( $item['confirmed'] ) ? '0' : '1'; ?>">
								<?php echo ! empty( $item['confirmed'] ) ? 'Reopen' : 'Mark complete and retire it'; ?>
							</button>
						<?php endif; ?>
						<button type="button" class="button-link bftd-item-rm">Remove</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Save both lists. New rows get an id here rather than in the browser, so
	 * two tutors adding an item at the same moment cannot collide, and the
	 * id a family's checked state hangs off is never generated client side.
	 */
	public static function save( $student_id ) {
		$posted  = isset( $_POST['bftd_items'] ) ? (array) wp_unslash( $_POST['bftd_items'] ) : array();
		$changed = 0;

		foreach ( self::lists() as $list => $cfg ) {
			$before = self::all( $student_id, $list );
			$rows   = isset( $posted[ $list ] ) ? (array) $posted[ $list ] : array();
			$after  = array();

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) continue;
				$text = sanitize_text_field( isset( $row['text'] ) ? $row['text'] : '' );
				if ( '' === trim( $text ) ) continue;

				$id = isset( $row['id'] ) ? sanitize_text_field( $row['id'] ) : '';
				if ( '' === $id ) $id = 'i' . wp_generate_password( 10, false, false );

				$section = isset( $row['section'] ) ? sanitize_key( str_replace( '_', '-', $row['section'] ) ) : '';
				if ( $section && ! BFTD_Schema::section( $section ) ) $section = '';

				$after[] = array(
					'id'           => $id,
					'text'         => $text,
					'details'      => wp_kses_post( isset( $row['details'] ) ? $row['details'] : '' ),
					'link'         => esc_url_raw( isset( $row['link'] ) ? $row['link'] : '' ),
					'link_text'    => sanitize_text_field( isset( $row['link_text'] ) ? $row['link_text'] : '' ),
					'section'      => $section,
					'checked'      => ! empty( $row['checked'] ),
					'checked_by'   => absint( isset( $row['checked_by'] ) ? $row['checked_by'] : 0 ),
					'checked_at'   => sanitize_text_field( isset( $row['checked_at'] ) ? $row['checked_at'] : '' ),
					'confirmed'    => ! empty( $row['confirmed'] ),
					'confirmed_by' => absint( isset( $row['confirmed_by'] ) ? $row['confirmed_by'] : 0 ),
					'confirmed_at' => sanitize_text_field( isset( $row['confirmed_at'] ) ? $row['confirmed_at'] : '' ),
				);
			}

			update_post_meta( $student_id, $cfg['meta'], $after );

			$diff = BFTD_Audit::diff(
				array( 'items' => wp_list_pluck( $before, 'text' ) ),
				array( 'items' => wp_list_pluck( $after, 'text' ) ),
				array( 'items' => $cfg['title'] )
			);
			if ( $diff ) {
				$changed++;
				BFTD_Audit::log( 'item_edited', array(
					'post_id'    => $student_id,
					'student_id' => $student_id,
					'summary'    => $cfg['title'] . ' changed.',
					'changes'    => $diff,
				) );
				BFTD_Change_Notify::flag( $student_id, 'items', $cfg['title'] . ' updated for ' . get_the_title( $student_id ) );
			}
		}

		return $changed;
	}

	/* ------------------------------------------------------------------ */
	/* Family-facing card                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * The banner card. Collapsed by default with a count, because a list of
	 * six things is intimidating at the top of a page and a single number is
	 * not. A list with nothing outstanding still renders, quietly, so a family
	 * can see they are on top of it rather than wondering where it went.
	 */
	public static function render_card( $student_id, $list, $section_id = null, $scope = '' ) {
		$cfg   = self::config( $list );
		$items = self::open_items( $student_id, $list, $section_id );
		if ( ! $items ) return;

		$todo  = 0;
		foreach ( $items as $item ) {
			if ( empty( $item['checked'] ) ) $todo++;
		}
		$done = count( $items ) - $todo;
		?>
		<section class="bf-items bf-items-<?php echo esc_attr( $cfg['tone'] ); ?>" data-student="<?php echo (int) $student_id; ?>" data-list="<?php echo esc_attr( $list ); ?>">
			<button type="button" class="bf-items-head" aria-expanded="false">
				<span class="bf-items-t">
					<span class="bf-eyebrow"><?php echo esc_html( $cfg['eyebrow'] ); ?></span>
					<span class="bf-items-title"><?php echo esc_html( $cfg['title'] ); ?><?php
						// Which part of the report these belong to, so a card
						// sitting at the top is not mistaken for the whole
						// family's list.
						if ( $scope ) : ?><span class="bf-scope-chip"><?php echo esc_html( $scope ); ?></span><?php endif;
					?></span>
				</span>
				<span class="bf-items-badge <?php echo $todo ? '' : 'is-clear'; ?>">
					<?php if ( $todo ) : ?>
						<b><?php echo (int) $todo; ?></b> to do
					<?php else : ?>
						<?php echo esc_html( $cfg['done'] ); ?>
					<?php endif; ?>
				</span>
				<span class="bf-items-count"><?php echo (int) $done; ?> / <?php echo esc_html( count( $items ) ); ?></span>
				<span class="bf-items-chev" aria-hidden="true">
					<svg viewBox="0 0 20 20"><path d="M5 8l5 5 5-5"/></svg>
				</span>
			</button>

			<div class="bf-items-acc"><div class="bf-items-acc-in"><div class="bf-items-list">
				<?php foreach ( $items as $item ) self::render_item( $student_id, $list, $cfg, $item ); ?>
			</div></div></div>
		</section>
		<?php
	}

	private static function render_item( $student_id, $list, $cfg, $item ) {
		$checked = ! empty( $item['checked'] );
		$who     = $checked && ! empty( $item['checked_by'] ) ? get_userdata( $item['checked_by'] ) : null;
		$thread  = BFTD_Threads::find_thread( $student_id, 'item-' . $item['id'] );
		$count   = $thread ? BFTD_Threads::reply_count( $thread->comment_ID ) : 0;
		?>
		<div class="bf-item<?php echo $checked ? ' is-done' : ''; ?>" data-item="<?php echo esc_attr( $item['id'] ); ?>">
			<div class="bf-item-row" role="button" tabindex="0" aria-pressed="<?php echo $checked ? 'true' : 'false'; ?>">
				<span class="bf-item-check" aria-hidden="true">
					<svg viewBox="0 0 20 20"><path d="M4.5 10.5l3.5 3.5 7.5-8"/></svg>
				</span>
				<div class="bf-item-main">
					<span class="bf-item-text"><?php echo esc_html( $item['text'] ); ?></span>

					<?php if ( BFTD_Fields::has_value( $item['details'] ) ) : ?>
						<button type="button" class="bf-item-det">
							<svg class="bf-item-chev" viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2l4 4-4 4"/></svg>
							<span><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $item['details'] ), 12 ) ); ?></span>
						</button>
						<div class="bf-items-acc bf-item-det-acc"><div class="bf-items-acc-in">
							<div class="bf-item-det-b"><?php echo wp_kses_post( wpautop( $item['details'] ) ); ?></div>
						</div></div>
					<?php endif; ?>

					<?php if ( BFTD_Fields::has_value( $item['link'] ) ) : ?>
						<a class="bf-item-link" href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( $item['link_text'] ? $item['link_text'] : 'Open it' ); ?>
						</a>
					<?php endif; ?>

					<span class="bf-item-meta"><?php echo $who ? esc_html( $cfg['verb'] . ' ' . $who->display_name ) : ''; ?></span>
				</div>
			</div>

			<button type="button" class="bf-item-talk">
				<?php echo $count
					? esc_html( $count . ' ' . _n( 'message', 'messages', $count, 'bftd' ) . ' on this' )
					: 'Add a note or a question'; ?>
			</button>
			<div class="bf-items-acc bf-item-thread"><div class="bf-items-acc-in">
				<?php BFTD_Dashboard::render_thread( $student_id, 'item-' . $item['id'], $item['text'] ); ?>
			</div></div>
		</div>
		<?php
	}
}
