<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The skills library.
 *
 * An activity is a thing a tutor does in a lesson. A skill is what the child
 * can do afterwards, and it is the thing a parent actually wants to read: not
 * "we did Listen, Tally, Say, Write" but "he can hear the middle sound in a
 * word now".
 *
 * They are separate because they are not the same list and do not change
 * together. One activity teaches several skills, the same skill is taught by
 * several activities, and an activity can be renamed without the skill it
 * builds changing at all.
 *
 * Kept as records for the reason activities are: the skills grid on a
 * progress report groups by them, and a skill typed out per lesson arrives
 * spelled three ways and draws three rows for one thing.
 *
 * WHO MAY WRITE ONE. Senior managers and administrators, like every other
 * library. This is what the practice claims to teach.
 */
class BFTD_Skills {

	const POST_TYPE = 'bftd_skill';

	/** Which group of the library a skill belongs to. */
	const GROUP_KEY = '_bftd_skill_group';

	/** Which part of the programme a skill belongs to, and where in it. */
	const TRACK_KEY  = '_bftd_skill_track';
	const NUMBER_KEY = '_bftd_skill_number';

	/**
	 * The track a skill is in when none is stored. Skills are grouped by the
	 * track they belong to, and the library is mostly Tracks 2 and 3.
	 */
	const TRACK_DEFAULT = 't23';

	/** Stamp for the one-time write of the default onto unset skills. */
	const TRACK_FILL_OPTION  = 'bftd_skills_track_fill';
	const TRACK_FILL_VERSION = 1;

	/** The group everything written before groups existed belongs to. */
	const GROUP_DEFAULT = 'skill';

	/**
	 * The two groups of the library.
	 *
	 * A Wordwall entry is a word a child can now read on sight. It is a skill
	 * in the sense that matters — something they can do that they could not —
	 * but it is not the same kind of thing as "hearing the middle sound in a
	 * word", and a practice maintaining a hundred of each does not want them
	 * shuffled together in one alphabetical list.
	 *
	 * Same shape as the tracks on activities, deliberately: one library, a
	 * group on each record, headings on the list, and a filter on the picker.
	 * A second post type would have meant two of everything that reads skills.
	 */
	public static function groups() {
		return array(
			'skill'    => 'Skills',
			'wordwall' => 'Wordwall',
		);
	}

	/**
	 * The tracks, which are the programme's and not this library's.
	 *
	 * Read from BFTD_Activities rather than written out again. A skill in
	 * Track 1 and an activity in Track 1 are in the same Track 1, and two
	 * copies of that list is how a track gets renamed in one library and not
	 * the other.
	 */
	public static function tracks() {
		return class_exists( 'BFTD_Activities' )
			? BFTD_Activities::tracks()
			: array( 't1' => 'Track 1', 't23' => 'Track 2 & 3' );
	}

	public static function track_label( $track ) {
		$all = self::tracks();
		return isset( $all[ $track ] ) ? $all[ $track ] : '';
	}

	/**
	 * The three places a skill can be: Track 1, Tracks 2 and 3, or Wordwall.
	 *
	 * One list for the one dropdown that chooses between them. Wordwall is
	 * not a track of the programme, so it stays out of tracks(): activities,
	 * the starting point and the report's sequence all ask about tracks and
	 * mean only the two. A Wordwall entry is filed under its group, and this
	 * is where the two questions meet.
	 */
	public static function places() {
		return self::tracks() + array( 'wordwall' => self::groups()['wordwall'] );
	}

	/** Where one skill is: its track, or 'wordwall' for a Wordwall entry. */
	public static function place_of( $id ) {
		if ( null !== self::$fixture ) {
			$one = self::$fixture[ (int) $id ] ?? null;
			if ( is_array( $one ) && 'wordwall' === ( $one['group'] ?? '' ) ) return 'wordwall';
			return self::track_of( $id );
		}
		return 'wordwall' === self::group_of( $id ) ? 'wordwall' : self::track_of( $id );
	}

	/**
	 * The place actually chosen for a skill, or '' when none has been. A
	 * Wordwall entry has been placed whatever track is stored on it.
	 */
	public static function stored_place( $id ) {
		$stored = (string) get_post_meta( (int) $id, self::GROUP_KEY, true );
		return 'wordwall' === $stored ? 'wordwall' : self::stored_track( $id );
	}

	/**
	 * The track of one skill, always one of the two.
	 *
	 * A skill with no track stored is a Tracks 2 and 3 skill. Answering
	 * "unknown" would drop it out of both filters and every activity picker.
	 */
	public static function track_of( $id ) {
		if ( null !== self::$fixture ) {
			$rows = self::$fixture;
			$one  = $rows[ (int) $id ] ?? null;
			$has  = ( is_array( $one ) && isset( $one['track'] ) ) ? (string) $one['track'] : '';
			return isset( self::tracks()[ $has ] ) ? $has : self::TRACK_DEFAULT;
		}
		$stored = self::stored_track( $id );
		return '' !== $stored ? $stored : self::TRACK_DEFAULT;
	}

	/**
	 * The track actually stored on a skill, or '' when none has been chosen.
	 * A new skill has none, and its edit screen asks for one rather than
	 * quietly offering the default as if somebody had picked it.
	 */
	public static function stored_track( $id ) {
		$stored = (string) get_post_meta( (int) $id, self::TRACK_KEY, true );
		return isset( self::tracks()[ $stored ] ) ? $stored : '';
	}

	/**
	 * Where this skill sits in its own track's sequence, or 0 for unnumbered.
	 *
	 * Each track counts from one, so there is a 12 in both and the track is
	 * what tells them apart. The number is what a student's starting point is
	 * measured against, so an unnumbered skill cannot be reasoned about as a
	 * position: it is listed, and left out of the arithmetic.
	 */
	public static function number_of( $id ) {
		if ( null !== self::$fixture ) {
			$one = self::$fixture[ (int) $id ] ?? null;
			return ( is_array( $one ) && isset( $one['number'] ) ) ? (int) $one['number'] : 0;
		}
		return (int) get_post_meta( (int) $id, self::NUMBER_KEY, true );
	}

	/** How many numbered skills a track has, which is its length. */
	public static function count_in_track( $track ) {
		$n = 0;
		foreach ( self::all() as $row ) {
			if ( 'wordwall' === ( $row['group'] ?? '' ) ) continue;   // not in a track
			if ( $row['track'] === $track && $row['number'] ) $n++;
		}
		return $n;
	}

	/**
	 * Every skill in one track, in programme order, as number => id.
	 *
	 * Numbered only, because this is the sequence. Unnumbered skills are in
	 * the library and in the pickers; they are not a position in a programme.
	 */
	public static function sequence( $track ) {
		$out = array();
		foreach ( self::all() as $id => $row ) {
			if ( 'wordwall' === ( $row['group'] ?? '' ) ) continue;   // not in a track
			if ( $row['track'] !== $track || ! $row['number'] ) continue;
			$out[ (int) $row['number'] ] = (int) $id;
		}
		ksort( $out );
		return $out;
	}

	public static function group_label( $group ) {
		$all = self::groups();
		return isset( $all[ $group ] ) ? $all[ $group ] : '';
	}

	/**
	 * The group of one skill, always one of the two.
	 *
	 * A skill written before groups existed has none stored, and every one of
	 * those is an ordinary skill. Answering "unknown" would drop the whole
	 * existing library out of both filters at once.
	 */
	public static function group_of( $id ) {
		$stored = (string) get_post_meta( (int) $id, self::GROUP_KEY, true );
		return isset( self::groups()[ $stored ] ) ? $stored : self::GROUP_DEFAULT;
	}

	/* ------------------------------------------------------------------ */
	/* What a student has actually completed                               */
	/* ------------------------------------------------------------------ */

	/** Where a student's completed skills are kept, as a list of skill ids. */
	const DONE_KEY = '_bftd_skills_done';

	/**
	 * Every skill this student has completed, as skill id => true.
	 *
	 * Two things make a skill complete and they are different claims, so both
	 * count and neither is derived from the other.
	 *
	 * A skill RECORDED on a published session is the tutor saying the child got
	 * there in that hour. That is the ordinary way, it needs nobody to remember
	 * anything afterwards, and it is why the grid on a report has always been
	 * built from the records.
	 *
	 * A skill TICKED on the student is the tutor saying it is done, whether or
	 * not a session happened to be the place it happened. A child arriving
	 * already able to do the first thirty is the case this exists for: nobody
	 * is going to invent thirty sessions to say so.
	 *
	 * Published sessions only. A draft is a tutor thinking, and a skill in a
	 * draft is not a claim about a child yet.
	 */
	public static function done_for_student( $student_id ) {
		$done = array();

		foreach ( (array) get_post_meta( (int) $student_id, self::DONE_KEY, true ) as $id ) {
			$id = (int) $id;
			if ( $id ) $done[ $id ] = true;
		}

		// The one bulk fetch, rather than a second query that would have to be
		// kept in step with it. Published only, which is its default.
		$sessions = BFTD_CPT::sessions_of( array( (int) $student_id ) );
		foreach ( (array) ( $sessions[ (int) $student_id ] ?? array() ) as $sid ) {
			foreach ( (array) self::for_session( $sid ) as $row ) {
				$id = (int) ( is_array( $row ) ? ( $row['id'] ?? 0 ) : $row );
				if ( $id ) $done[ $id ] = true;
			}
		}

		return $done;
	}

	/** Just the ticked ones, which is the only half anybody edits. */
	public static function ticked_for_student( $student_id ) {
		$out = array();
		foreach ( (array) get_post_meta( (int) $student_id, self::DONE_KEY, true ) as $id ) {
			$id = (int) $id;
			if ( $id && '' !== self::label( $id ) ) $out[] = $id;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Save the ticks, when the form was carrying them.
	 *
	 * Absent means this form did not have the field, which is every form except
	 * the student editor. An empty array arrives as an empty array, because the
	 * box always posts a hidden marker saying it was there.
	 */
	public static function save_ticks( $student_id ) {
		if ( ! isset( $_POST['bftd_skills_done_present'] ) ) return 0;

		$before = self::ticked_for_student( $student_id );
		$after  = array();
		foreach ( (array) ( $_POST['bftd_skills_done'] ?? array() ) as $id ) {
			$id = (int) $id;
			if ( $id && '' !== self::label( $id ) ) $after[] = $id;
		}
		$after = array_values( array_unique( $after ) );

		sort( $before );
		$sorted_after = $after;
		sort( $sorted_after );
		if ( $before === $sorted_after ) return 0;

		update_post_meta( (int) $student_id, self::DONE_KEY, $after );

		if ( class_exists( 'BFTD_Audit' ) ) {
			BFTD_Audit::log( 'section_updated', array(
				'post_id'    => (int) $student_id,
				'student_id' => (int) $student_id,
				'summary'    => get_the_title( $student_id ) . ': skills marked complete changed from '
					. count( $before ) . ' to ' . count( $after ) . '.',
			) );
		}
		return 1;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_add_new_page' ), 11 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );

		// One library read as two, the same way the activity library is.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		// A tutor reads this list and writes nothing on it. See BFTD_Library_View.
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( 'BFTD_Library_View', 'reader_bulk' ), 20 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_ui' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_list_filter' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'admin_list_order' ), 10, 2 );
		add_filter( 'post_class', array( __CLASS__, 'row_class' ), 10, 3 );

		// Track and number from the list itself, without opening the skill.
		add_action( 'quick_edit_custom_box', array( __CLASS__, 'quick_box' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_quick' ), 10, 2 );

		// A skill cannot be saved or published without a track.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'hold_without_track' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'flag_held' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'held_notice' ) );

		add_action( 'admin_init', array( __CLASS__, 'maybe_fill_tracks' ) );
	}

	/**
	 * Write Tracks 2 and 3 onto every skill that has no track stored, once.
	 *
	 * track_of() already reads an unset skill as Tracks 2 and 3, so nothing
	 * changes for anybody reading. This makes the stored data say the same
	 * thing. A skill that already has a track keeps it.
	 */
	public static function maybe_fill_tracks() {
		if ( (int) get_option( self::TRACK_FILL_OPTION, 0 ) >= self::TRACK_FILL_VERSION ) return;
		$ids = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => self::TRACK_KEY, 'compare' => 'NOT EXISTS' ) ),
		) );
		foreach ( (array) $ids as $id ) {
			update_post_meta( (int) $id, self::TRACK_KEY, self::TRACK_DEFAULT );
		}
		update_option( self::TRACK_FILL_OPTION, self::TRACK_FILL_VERSION );
		self::flush();
	}

	/**
	 * Is this a skill edit-screen save that posted a real place? The field
	 * keeps its old name, bftd_skill_track, and now also offers Wordwall.
	 */
	private static function posted_place() {
		$t = isset( $_POST['bftd_skill_track'] ) ? sanitize_key( wp_unslash( $_POST['bftd_skill_track'] ) ) : '';
		return isset( self::places()[ $t ] ) ? $t : '';
	}

	/**
	 * Keep a skill a draft when it is saved from its edit screen with no
	 * track. The browser stops the form first; this is for when it does not.
	 * Only the edit screen is checked, which is the one form that sends the
	 * skill nonce: quick edit always posts a track.
	 */
	public static function hold_without_track( $data, $postarr ) {
		if ( self::POST_TYPE !== ( $data['post_type'] ?? '' ) ) return $data;
		if ( empty( $_POST['bftd_skill_nonce'] ) ) return $data;
		if ( '' !== self::posted_place() ) return $data;
		if ( in_array( $data['post_status'], array( 'publish', 'future', 'pending', 'private' ), true ) ) {
			$data['post_status'] = 'draft';
			self::$held = true;
		}
		return $data;
	}

	/** Set by hold_without_track() for the redirect that follows. */
	private static $held = false;

	public static function flag_held( $location, $post_id ) {
		if ( ! self::$held ) return $location;
		return add_query_arg( 'bftd_skill_held', 1, remove_query_arg( 'message', $location ) );
	}

	public static function held_notice() {
		if ( empty( $_GET['bftd_skill_held'] ) ) return;
		echo '<div class="notice notice-error"><p><strong>Choose a track or Wordwall for this skill.</strong> It has been kept as a draft and will not be offered anywhere until it has one.</p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Writing one                                                         */
	/* ------------------------------------------------------------------ */

	public static function boxes() {
		// One box. Track 1, Tracks 2 and 3 and Wordwall are chosen in the same
		// dropdown, because a skill is in exactly one of the three.
		add_meta_box( 'bftd_skill_place', 'Where this sits', array( __CLASS__, 'box_place' ), self::POST_TYPE, 'side', 'high' );
	}

	/**
	 * The track and the number in it.
	 *
	 * Written the way the activity library's is, because it is the same
	 * question about the same programme, and a tutor who has learned one
	 * screen has learned both.
	 */
	public static function box_place( $post ) {
		wp_nonce_field( 'bftd_skill_' . $post->ID, 'bftd_skill_nonce' );
		$track = self::stored_place( $post->ID );
		$n     = self::number_of( $post->ID );
		?>
		<p>
			<label for="bftd_skill_track"><strong>Track</strong></label><br>
			<select name="bftd_skill_track" id="bftd_skill_track" style="width:100%" required>
				<option value="" <?php selected( $track, '' ); ?> disabled>Choose a track or Wordwall</option>
				<?php foreach ( self::places() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $track, $key ); ?>><?php
						echo esc_html( $label );
					?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Which part of the programme this skill belongs to, or Wordwall for a word a child can read on sight. Required: an activity only offers the skills in its own track, and Wordwall entries are in neither.</p>
		<p style="margin-top:14px">
			<label for="bftd_skill_number"><strong>Number in that track</strong></label><br>
			<input type="number" min="1" step="1" style="width:100%" name="bftd_skill_number"
				id="bftd_skill_number" value="<?php echo esc_attr( $n ? $n : '' ); ?>" placeholder="e.g. 12">
		</p>
		<p class="description">Where this sits in its own track's sequence. Each track counts from one, so there is a 12 in both and the track above is what tells them apart. A student's starting point is a number in this sequence, so a skill with no number is in the library but not in the programme, and is listed last.</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( empty( $_POST['bftd_skill_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_skill_nonce'] ) ), 'bftd_skill_' . $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// Nothing chosen: nothing is written for it, and the skill was kept a
		// draft by hold_without_track(). The number is still saved.
		$place = self::posted_place();
		if ( '' !== $place ) self::store_choice( $post_id, $place );
		self::store_number( $post_id, isset( $_POST['bftd_skill_number'] ) ? wp_unslash( $_POST['bftd_skill_number'] ) : '' );

		self::flush();
	}

	/**
	 * Write a skill's track and number. Used by the edit screen and by quick
	 * edit, so the two cannot disagree about what a track or a number is.
	 */
	public static function store_place( $post_id, $track, $number ) {
		self::store_choice( $post_id, $track );
		self::store_number( $post_id, $number );
	}

	/**
	 * Write what the one dropdown chose. Wordwall is the group and leaves the
	 * stored track alone, since a Wordwall entry is read as in no track. A
	 * track makes it an ordinary skill in that track. Anything else falls
	 * back to the default track, as store_track() always has.
	 */
	public static function store_choice( $post_id, $place ) {
		$place = sanitize_key( (string) $place );
		if ( 'wordwall' === $place ) {
			update_post_meta( $post_id, self::GROUP_KEY, 'wordwall' );
			return;
		}
		update_post_meta( $post_id, self::GROUP_KEY, self::GROUP_DEFAULT );
		self::store_track( $post_id, $place );
	}

	/**
	 * Always one of the two tracks. Anything else falls back to the default
	 * rather than to a third track that is not real.
	 */
	public static function store_track( $post_id, $track ) {
		$track = sanitize_key( (string) $track );
		if ( ! isset( self::tracks()[ $track ] ) ) $track = self::TRACK_DEFAULT;
		update_post_meta( $post_id, self::TRACK_KEY, $track );
	}

	public static function store_number( $post_id, $number ) {
		// No number is a real answer: a skill in the library that is not a
		// position in the programme. Stored as absent rather than as zero, so
		// nothing has to remember that zero means no.
		$n = absint( $number );
		if ( $n ) {
			update_post_meta( $post_id, self::NUMBER_KEY, $n );
		} else {
			delete_post_meta( $post_id, self::NUMBER_KEY );
		}
	}

	/**
	 * Quick edit on the skills list: the track (or Wordwall) and the number,
	 * nothing else.
	 *
	 * One box serves every row, so it carries its own nonce rather than the
	 * per-skill one the edit screen uses. The script fills it from the row.
	 */
	public static function quick_box( $col, $post_type ) {
		if ( self::POST_TYPE !== $post_type || 'bftd_number' !== $col ) return;
		wp_nonce_field( 'bftd_skill_quick', 'bftd_skill_qe_nonce' );
		?>
		<fieldset class="inline-edit-col-right bftd-skill-qe">
			<div class="inline-edit-col">
				<label>
					<span class="title">Track</span>
					<select name="bftd_skill_track">
						<?php foreach ( self::places() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span class="title">Number</span>
					<span class="input-text-wrap"><input type="number" min="1" step="1" name="bftd_skill_number" value="" placeholder="None"></span>
				</label>
			</div>
		</fieldset>
		<?php
	}

	/**
	 * Save from quick edit. Only the track (or Wordwall) and the number are
	 * written, and only when both were posted.
	 */
	public static function save_quick( $post_id, $post ) {
		if ( empty( $_POST['bftd_skill_qe_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_skill_qe_nonce'] ) ), 'bftd_skill_quick' ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;
		if ( ! array_key_exists( 'bftd_skill_track', $_POST ) || ! array_key_exists( 'bftd_skill_number', $_POST ) ) return;

		self::store_place( $post_id, wp_unslash( $_POST['bftd_skill_track'] ), wp_unslash( $_POST['bftd_skill_number'] ) );
		self::flush();
	}

	/* ------------------------------------------------------------------ */
	/* The library list                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * No group column. A heading instead, for the reason the activity library
	 * has one: a column repeating "Skills" down a hundred rows tells the
	 * person reading it what they already know. The class is what the heading
	 * rows are worked out from.
	 */
	public static function columns( $cols ) {
		$out = array( 'cb' => isset( $cols['cb'] ) ? $cols['cb'] : '' );
		$out['bftd_number'] = 'No.';
		$out['title']       = 'Name';
		$out['date']        = isset( $cols['date'] ) ? $cols['date'] : 'Date';
		return BFTD_Library_View::reader_columns( $out, self::POST_TYPE );
	}

	public static function column( $col, $post_id ) {
		if ( 'bftd_read' === $col ) { BFTD_Library_View::read_cell( $post_id ); return; }
		if ( 'bftd_number' !== $col ) return;
		$n = self::number_of( $post_id );
		echo $n ? '<strong>' . (int) $n . '</strong>' : '<span class="bftd-none">&ndash;</span>';
		// What quick edit opens with. Read by the script, never shown.
		printf(
			'<span class="bftd-skill-place" hidden data-track="%s" data-number="%s"></span>',
			esc_attr( self::place_of( $post_id ) ),
			$n ? (int) $n : ''
		);
	}

	public static function sortable( $cols ) {
		$cols['bftd_number'] = 'bftd_number';
		return $cols;
	}

	/**
	 * Both axes on the row, because the list is headed by both.
	 *
	 * The track says which programme, the group says Skills or Wordwall, and
	 * neither is a column: a column repeating the same word down a hundred
	 * rows spends its width telling the reader what the heading already said.
	 */
	public static function row_class( $classes, $class, $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) return $classes;
		$classes[] = 'bftd-group-' . self::group_of( $post_id );
		$classes[] = 'bftd-track-' . self::track_of( $post_id );
		return $classes;
	}

	public static function filter_ui( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) return;
		$group = isset( $_GET['bftd_group'] ) ? sanitize_key( wp_unslash( $_GET['bftd_group'] ) ) : '';
		$track = isset( $_GET['bftd_skill_track_filter'] ) ? sanitize_key( wp_unslash( $_GET['bftd_skill_track_filter'] ) ) : '';
		if ( ! isset( self::tracks()[ $track ] ) ) $track = '';
		BFTD_Library::track_pills( 'bftd_skill_track_filter', self::tracks(), $track );
		?>
		<select name="bftd_group">
			<option value="">Every group</option>
			<?php foreach ( self::groups() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $group, $key ); ?>><?php
					echo esc_html( $label );
				?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Which skills the track filter asks for, or null for all of them.
	 *
	 * Track 1 is also everything with no track stored, because every skill
	 * written before tracks reached this library is a Track 1 skill. Asking
	 * only for the stored value would hide the whole existing library the
	 * moment somebody used the filter.
	 */
	public static function track_clause( $want ) {
		if ( ! isset( self::tracks()[ $want ] ) ) return null;
		if ( self::TRACK_DEFAULT !== $want ) {
			return array( 'key' => self::TRACK_KEY, 'value' => $want );
		}
		return array(
			'relation' => 'OR',
			array( 'key' => self::TRACK_KEY, 'value' => $want ),
			array( 'key' => self::TRACK_KEY, 'compare' => 'NOT EXISTS' ),
		);
	}

	/**
	 * Which skills the group filter asks for, or null for all of them.
	 *
	 * The ordinary group is also everything with no group stored, because
	 * every skill written before groups existed is one. Asking only for the
	 * stored value would have hidden the whole existing library the moment
	 * somebody used the filter.
	 */
	public static function group_clause( $want ) {
		if ( ! isset( self::groups()[ $want ] ) ) return null;
		if ( self::GROUP_DEFAULT !== $want ) {
			return array( 'key' => self::GROUP_KEY, 'value' => $want );
		}
		return array(
			'relation' => 'OR',
			array( 'key' => self::GROUP_KEY, 'value' => $want ),
			array( 'key' => self::GROUP_KEY, 'compare' => 'NOT EXISTS' ),
		);
	}

	/** Every skill on the list screen, for its search and suggestions. */
	public static function list_index() {
		return BFTD_Library::index( self::POST_TYPE, self::NUMBER_KEY, array( __CLASS__, 'track_of' ) );
	}

	public static function admin_list_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) return;

		$want = array();
		$g = self::group_clause(
			isset( $_GET['bftd_group'] ) ? sanitize_key( wp_unslash( $_GET['bftd_group'] ) ) : ''
		);
		$t = self::track_clause(
			isset( $_GET['bftd_skill_track_filter'] ) ? sanitize_key( wp_unslash( $_GET['bftd_skill_track_filter'] ) ) : ''
		);
		if ( $g ) $want[] = $g;
		if ( $t ) $want[] = $t;

		BFTD_Library::list_search( $query, self::list_index() );

		// Both filters at once narrows to the intersection, which is what
		// picking two things from two lists means to the person doing it.
		if ( $want ) {
			if ( count( $want ) > 1 ) $want['relation'] = 'AND';
			$query->set( 'meta_query', $want );
		}
	}

	/**
	 * Group first, then by name inside it.
	 *
	 * In SQL rather than through meta_query ordering, because most of the
	 * library has no group stored and an ordered meta clause drops the rows
	 * that do not have the key. A LEFT JOIN keeps them and COALESCE puts them
	 * where they belong.
	 */
	/**
	 * Track, then number inside it, then group, then name.
	 *
	 * In SQL rather than through meta_query ordering, because most of the
	 * library has no track or number stored and an ordered meta clause drops
	 * the rows that do not have the key. LEFT JOINs keep them and COALESCE
	 * puts them where they belong, which is Track 1.
	 *
	 * The track order is stated rather than left to alphabetical luck: 't1'
	 * sorting before 't23' is true today and is not a thing to rely on.
	 */
	public static function admin_list_order( $clauses, $query ) {
		global $wpdb;
		if ( ! is_admin() || ! $query->is_main_query() ) return $clauses;
		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) return $clauses;

		$by = $query->get( 'orderby' );
		if ( $by && 'bftd_number' !== $by ) return $clauses;   // somebody chose a column

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} bftd_gp ON ( bftd_gp.post_id = {$wpdb->posts}.ID AND bftd_gp.meta_key = %s )"
			. " LEFT JOIN {$wpdb->postmeta} bftd_sk ON ( bftd_sk.post_id = {$wpdb->posts}.ID AND bftd_sk.meta_key = %s )"
			. " LEFT JOIN {$wpdb->postmeta} bftd_sn ON ( bftd_sn.post_id = {$wpdb->posts}.ID AND bftd_sn.meta_key = %s )",
			self::GROUP_KEY,
			self::TRACK_KEY,
			self::NUMBER_KEY
		);

		$tracks = array();
		$t      = 0;
		foreach ( array_keys( self::tracks() ) as $key ) {
			$tracks[] = $wpdb->prepare( 'WHEN %s THEN %d', $key, $t++ );
		}
		$track_case = 'CASE COALESCE( bftd_sk.meta_value, ' . $wpdb->prepare( '%s', self::TRACK_DEFAULT ) . ' ) '
			. implode( ' ', $tracks ) . ' ELSE ' . (int) $t . ' END';

		$groups = array();
		$g      = 0;
		foreach ( array_keys( self::groups() ) as $key ) {
			$groups[] = $wpdb->prepare( 'WHEN %s THEN %d', $key, $g++ );
		}
		$group_case = 'CASE COALESCE( bftd_gp.meta_value, ' . $wpdb->prepare( '%s', self::GROUP_DEFAULT ) . ' ) '
			. implode( ' ', $groups ) . ' ELSE ' . (int) $g . ' END';

		$clauses['orderby'] = $track_case . ' ASC,'
			// An unnumbered skill belongs at the end of its own track, not at
			// the front of it, which is where an empty value sorts.
			. ' ( bftd_sn.meta_value IS NULL ) ASC,'
			. ' CAST( bftd_sn.meta_value AS UNSIGNED ) ASC,'
			. ' ' . $group_case . ' ASC,'
			. " {$wpdb->posts}.post_title ASC";
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}

	public static function register() {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name'          => 'Skills',
				'singular_name' => 'Skill',
				'add_new_item'  => 'Add Skill',
				'edit_item'     => 'Skill',
				'all_items'     => 'Skills',
				'search_items'  => 'Search skills',
				'not_found'     => 'No skills yet.',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => BFTD_Admin::MENU_SLUG,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'hierarchical'        => false,
			// The body is what a family reads when the skill is first
			// reached, the same way an activity's description is.
			'supports'            => array( 'title', 'editor', 'revisions' ),
			'capability_type'     => array( 'bftd_skill', 'bftd_skills' ),
			'map_meta_cap'        => true,
			'capabilities'        => array( 'create_posts' => 'create_bftd_skills' ),
		) );
	}

	/** See BFTD_CPT::register_add_new_pages for why this row has to exist. */
	public static function register_add_new_page() {
		$obj = get_post_type_object( self::POST_TYPE );
		if ( ! $obj ) return;
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			$obj->labels->add_new_item,
			'Add Skill',
			$obj->cap->create_posts,
			'post-new.php?post_type=' . self::POST_TYPE
		);
	}

	/* ------------------------------------------------------------------ */
	/* Reading the library                                                 */
	/* ------------------------------------------------------------------ */

	private static $cache = null;

	/** Every skill, by name: id => name. */
	/**
	 * A stand-in library, for the sample report.
	 *
	 * Same trick BFTD_Activities uses: the sample has no skill records behind
	 * it, so it carries its own and the real code reads through them. What a
	 * family sees on the sample is then drawn by the code that draws their own
	 * report, rather than by a second renderer that can drift away from it.
	 */
	private static $fixture = null;

	public static function use_fixture( $library ) {
		self::$fixture = is_array( $library ) ? $library : null;
		self::flush();
	}

	public static function clear_fixture() {
		self::$fixture = null;
		self::flush();
	}

	public static function all() {
		if ( null !== self::$cache ) return self::$cache;

		if ( null !== self::$fixture ) {
			self::$cache = self::$fixture;
			return self::$cache;
		}

		$ids = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		/*
		 * Track, then number inside the track, then group, then name.
		 *
		 * The same order the activity library reads in, and for the same
		 * reason: the library is two programmes and is read as two. The number
		 * comes before the group because the sequence is the thing a tutor
		 * follows; an unnumbered skill belongs at the end of its own track
		 * rather than at the front of it, which is where an empty value sorts.
		 */
		$tracks = array_keys( self::tracks() );
		$groups = array_keys( self::groups() );
		$rows   = array();
		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'     => (int) $id,
				'number' => self::number_of( $id ),
				'name'   => (string) get_the_title( $id ),
				'track'  => self::track_of( $id ),
				'group'  => self::group_of( $id ),
			);
		}
		usort( $rows, function ( $a, $b ) use ( $tracks, $groups ) {
			$ta = array_search( $a['track'], $tracks, true );
			$tb = array_search( $b['track'], $tracks, true );
			if ( $ta !== $tb ) return $ta - $tb;
			if ( ! $a['number'] && $b['number'] ) return 1;
			if ( $a['number'] && ! $b['number'] ) return -1;
			if ( $a['number'] !== $b['number'] ) return $a['number'] - $b['number'];
			$ga = array_search( $a['group'], $groups, true );
			$gb = array_search( $b['group'], $groups, true );
			if ( $ga !== $gb ) return $ga - $gb;
			return strcasecmp( $a['name'], $b['name'] );
		} );

		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['id'] ] = array(
				'number' => $r['number'],
				'name'   => $r['name'],
				'track'  => $r['track'],
				'group'  => $r['group'],
			);
		}

		self::$cache = $out;
		return $out;
	}

	/**
	 * Just the names, by id, in the same order.
	 *
	 * all() carries the number and the track because the report has to reason
	 * about a sequence. Plenty of callers only want to put a name on a screen,
	 * and this is that, derived from the one list rather than fetched again.
	 */
	public static function labels() {
		$out = array();
		foreach ( self::all() as $id => $row ) $out[ $id ] = $row['name'];
		return $out;
	}

	public static function flush() { self::$cache = null; }

	public static function label( $id ) {
		$all = self::all();
		$id  = (int) $id;
		return isset( $all[ $id ] ) ? (string) $all[ $id ]['name'] : '';
	}

	/** The name with its number in front, for a line that has to say which. */
	public static function numbered_label( $id ) {
		$all = self::all();
		$id  = (int) $id;
		if ( ! isset( $all[ $id ] ) ) return '';
		$row = $all[ $id ];
		return $row['number'] ? $row['number'] . ' · ' . $row['name'] : $row['name'];
	}

	/**
	 * Is there a skill behind this id at all, trashed or not?
	 *
	 * The same split BFTD_Activities::exists documents: label() answers "is it
	 * in the library" and is publish-only, so a trashed skill is offered
	 * nowhere and drawn nowhere. This answers "is it still recoverable", which
	 * is what decides whether a save is allowed to throw the row away.
	 */
	/** How many skills the practice claims to teach. */
	public static function total() {
		return count( self::all() );
	}

	public static function exists( $id ) {
		$id = (int) $id;
		if ( ! $id ) return false;
		if ( '' !== self::label( $id ) ) return true;

		// A sample has no trash, only its own library.
		if ( null !== self::$fixture ) return false;

		$post = get_post( $id );
		return (bool) ( $post && self::POST_TYPE === $post->post_type );
	}

	public static function description( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) return '';
		return (string) $post->post_content;
	}

	public static function picker( $name, $selected = 0 ) {
		$options = array();
		$groups  = array();
		$numbers = array();
		$names   = array();
		foreach ( self::all() as $id => $row ) {
			// The number in front of the name, the way the activity picker
			// reads, because a tutor looking for skill forty looks for "40".
			$options[ $id ] = $row['number'] ? $row['number'] . ' · ' . $row['name'] : $row['name'];
			// Narrowed by where the skill is: Track 1, Tracks 2 and 3, or
			// Wordwall. One row of buttons, the same three the skill's own
			// dropdown offers. An activity's box is locked to one track, so
			// Wordwall entries are not offered there.
			$groups[ $id ]  = 'wordwall' === ( $row['group'] ?? '' ) ? 'wordwall' : $row['track'];
			$numbers[ $id ] = $row['number'] ? (string) $row['number'] : '';
			$names[ $id ]   = $row['name'];
		}

		return BFTD_Library::picker( array(
			'name'         => $name,
			'selected'     => $selected,
			'options'      => $options,
			'names'        => $names,
			'numbers'      => $numbers,
			'groups'       => $groups,
			'buckets'      => self::places(),
			'bucket_label' => 'Filter by track',
			'chosen_label' => self::numbered_label( $selected ),
			'noun'         => 'skill',
			'placeholder'  => 'Search by number or name',
			'add_url'      => admin_url( 'post-new.php?post_type=' . self::POST_TYPE ),
		) );
	}

	/**
	 * Every skill a lesson reached, and how.
	 *
	 * Two ways in, and they are different claims. A skill comes with the
	 * activity because that is what the activity is for; a skill added to the
	 * lesson itself is the tutor saying this child got there today, whatever
	 * the programme said the activity was for. Both belong on the report, and
	 * a skill that arrived both ways is one skill.
	 */
	public static function for_session( $session_id ) {
		$out = array();

		foreach ( BFTD_Activities::for_session( $session_id ) as $a ) {
			foreach ( BFTD_Activities::skills_of( $a['id'] ) as $sid ) {
				if ( ! isset( $out[ $sid ] ) ) {
					$out[ $sid ] = array( 'id' => $sid, 'name' => self::label( $sid ), 'from' => array() );
				}
				$out[ $sid ]['from'][] = $a['label'];
			}
		}

		foreach ( (array) BFTD_Fields::get( $session_id, 'session', 'skills' ) as $row ) {
			if ( ! is_array( $row ) ) continue;
			$sid = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( ! $sid || '' === self::label( $sid ) ) continue;
			if ( ! isset( $out[ $sid ] ) ) {
				$out[ $sid ] = array( 'id' => $sid, 'name' => self::label( $sid ), 'from' => array() );
			}
			$note = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
			if ( '' !== $note ) $out[ $sid ]['note'] = $note;
		}

		foreach ( $out as $sid => $one ) {
			if ( ! isset( $out[ $sid ]['note'] ) ) $out[ $sid ]['note'] = '';
			$out[ $sid ]['about'] = self::description( $sid );
		}

		return $out;
	}
}
