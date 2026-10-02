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

	/** The track everything written before tracks reached this library is in. */
	const TRACK_DEFAULT = 't1';

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
	 * The track of one skill, always one of the two.
	 *
	 * A skill written before tracks reached this library has none stored, and
	 * every one of those is a Track 1 skill: Track 1 is the programme the
	 * practice was already running. Answering "unknown" would drop the whole
	 * existing library out of both filters at once.
	 */
	public static function track_of( $id ) {
		if ( null !== self::$fixture ) {
			$rows = self::$fixture;
			$one  = $rows[ (int) $id ] ?? null;
			$has  = ( is_array( $one ) && isset( $one['track'] ) ) ? (string) $one['track'] : '';
			return isset( self::tracks()[ $has ] ) ? $has : self::TRACK_DEFAULT;
		}
		$stored = (string) get_post_meta( (int) $id, self::TRACK_KEY, true );
		return isset( self::tracks()[ $stored ] ) ? $stored : self::TRACK_DEFAULT;
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
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_ui' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_list_filter' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'admin_list_order' ), 10, 2 );
		add_filter( 'post_class', array( __CLASS__, 'row_class' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Writing one                                                         */
	/* ------------------------------------------------------------------ */

	public static function boxes() {
		add_meta_box( 'bftd_skill_place', 'Where this sits', array( __CLASS__, 'box_place' ), self::POST_TYPE, 'side', 'high' );
		add_meta_box( 'bftd_skill_group', 'Which group', array( __CLASS__, 'box_group' ), self::POST_TYPE, 'side', 'default' );
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
		$track = self::track_of( $post->ID );
		$n     = self::number_of( $post->ID );
		?>
		<p>
			<label for="bftd_skill_track"><strong>Track</strong></label><br>
			<select name="bftd_skill_track" id="bftd_skill_track" style="width:100%">
				<?php foreach ( self::tracks() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $track, $key ); ?>><?php
						echo esc_html( $label );
					?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Which part of the programme this skill belongs to.</p>
		<p style="margin-top:14px">
			<label for="bftd_skill_number"><strong>Number in that track</strong></label><br>
			<input type="number" min="1" step="1" style="width:100%" name="bftd_skill_number"
				id="bftd_skill_number" value="<?php echo esc_attr( $n ? $n : '' ); ?>" placeholder="e.g. 12">
		</p>
		<p class="description">Where this sits in its own track's sequence. Each track counts from one, so there is a 12 in both and the track above is what tells them apart. A student's starting point is a number in this sequence, so a skill with no number is in the library but not in the programme, and is listed last.</p>
		<?php
	}

	public static function box_group( $post ) {
		// The nonce is printed once, by the box above. Both post into the same
		// save, so a second copy would be one more thing to keep in step.
		$now = self::group_of( $post->ID );
		?>
		<p>
			<select name="bftd_skill_group" style="width:100%">
				<?php foreach ( self::groups() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $now, $key ); ?>><?php
						echo esc_html( $label );
					?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Wordwall entries are words a child can read on sight. They are kept as their own group so the two lists stay readable.</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( empty( $_POST['bftd_skill_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_skill_nonce'] ) ), 'bftd_skill_' . $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// Always one of the two. Anything else posted from anywhere falls back
		// to the ordinary group rather than inventing a third.
		$group = isset( $_POST['bftd_skill_group'] ) ? sanitize_key( wp_unslash( $_POST['bftd_skill_group'] ) ) : '';
		if ( ! isset( self::groups()[ $group ] ) ) $group = self::GROUP_DEFAULT;
		update_post_meta( $post_id, self::GROUP_KEY, $group );

		// Always one of the two tracks. Anything else posted from anywhere
		// falls back to Track 1, which is where the existing library lives,
		// rather than to a third track that is not real.
		$track = isset( $_POST['bftd_skill_track'] ) ? sanitize_key( wp_unslash( $_POST['bftd_skill_track'] ) ) : '';
		if ( ! isset( self::tracks()[ $track ] ) ) $track = self::TRACK_DEFAULT;
		update_post_meta( $post_id, self::TRACK_KEY, $track );

		// No number is a real answer: a skill in the library that is not a
		// position in the programme. Stored as absent rather than as zero, so
		// nothing has to remember that zero means no.
		$n = isset( $_POST['bftd_skill_number'] ) ? absint( $_POST['bftd_skill_number'] ) : 0;
		if ( $n ) {
			update_post_meta( $post_id, self::NUMBER_KEY, $n );
		} else {
			delete_post_meta( $post_id, self::NUMBER_KEY );
		}

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
		return $out;
	}

	public static function column( $col, $post_id ) {
		if ( 'bftd_number' !== $col ) return;
		$n = self::number_of( $post_id );
		echo $n ? '<strong>' . (int) $n . '</strong>' : '<span class="bftd-none">&ndash;</span>';
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
		?>
		<select name="bftd_skill_track_filter">
			<option value="">Every track</option>
			<?php foreach ( self::tracks() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $track, $key ); ?>><?php
					echo esc_html( $label );
				?></option>
			<?php endforeach; ?>
		</select>
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
			// Narrowed by track rather than by group. Two tracks of numbered
			// skills is the division a tutor is working within; Skills against
			// Wordwall is a division of the library's own housekeeping, and
			// only one row of buttons fits above the box.
			$groups[ $id ]  = $row['track'];
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
			'buckets'      => self::tracks(),
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
