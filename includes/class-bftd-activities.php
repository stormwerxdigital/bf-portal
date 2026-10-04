<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The activity library.
 *
 * The programme is a numbered sequence of a hundred and fifty or so
 * activities, and every lesson is some of them. Until now a tutor typed the
 * name of each one into the lesson record, which meant the same activity
 * arrived as "blending 2 sounds", "Blending two sounds" and "blend 2 snds"
 * across three lessons. On the family's report those are three different
 * skills, so the grid that is supposed to show a spiral showed a scatter,
 * and the count of activities covered was whatever the typing happened to
 * produce.
 *
 * So an activity is a record: a number, a name, and a description written
 * once by somebody who can explain it properly. A lesson points at it. Rename
 * an activity and every report that ever used it says the new name, because
 * there is only ever one copy of the words.
 *
 * WHO MAY WRITE ONE. Senior managers and administrators. This is the
 * practice's curriculum rather than a tutor's notes, and a tutor inventing an
 * activity mid-lesson is how the scatter came back. Tutors read the library
 * through the picker; nothing here is editable to them.
 *
 * WHY ITS OWN CAPABILITIES. An activity is not a student record. It carries
 * no student, no family, no thread and no autosave, so it stays out of
 * BFTD_Roles::post_types() and out of everything driven by that list.
 */
class BFTD_Activities {

	const POST_TYPE = 'bftd_activity';

	/** Where the number lives. The name is the title, the description is the body. */
	const NUMBER_KEY = '_bftd_activity_number';

	/** Which part of the programme an activity belongs to. */
	const TRACK_KEY = '_bftd_activity_track';

	/** The track everything written before tracks existed belongs to. */
	const TRACK_DEFAULT = 't1';

	/**
	 * The two parts of the programme.
	 *
	 * Track 1 counts its hundred and forty as lessons; Tracks 2 and 3 count
	 * their hundred and forty as skills. They are the same kind of record to
	 * this plugin — a numbered thing a tutor does in a lesson — so they are one
	 * library rather than two, and the track is what tells them apart.
	 *
	 * This matters because the numbers now collide: there is a 12 in each
	 * track, and they are different activities. A tutor searching "12" has to
	 * be able to say which one they mean, and a report that prints "12" beside
	 * a child's work has to say which programme that 12 is from.
	 *
	 * Tracks 2 and 3 are one entry, not two, because the practice runs them as
	 * one numbered sequence. Splitting them here would mean inventing a
	 * division the programme does not have.
	 */
	public static function tracks() {
		return array(
			't1'  => 'Track 1',
			't23' => 'Track 2 & 3',
		);
	}

	/** What the numbered items of a track are called, for the screens that ask. */
	public static function track_noun( $track ) {
		return ( 't23' === $track ) ? 'skill' : 'lesson';
	}

	public static function track_label( $track ) {
		$all = self::tracks();
		return isset( $all[ $track ] ) ? $all[ $track ] : '';
	}

	/**
	 * The track of one activity, always one of the two.
	 *
	 * An activity written before tracks existed has no stored track, and every
	 * one of those is a Track 1 activity: Track 1 is the programme the practice
	 * was already running. Answering "unknown" instead would drop the whole
	 * existing library out of both filters at once.
	 */
	public static function track_of( $id ) {
		if ( null !== self::$fixture ) {
			$one = self::get( $id );
			$has = ( $one && isset( $one['track'] ) ) ? (string) $one['track'] : '';
			return isset( self::tracks()[ $has ] ) ? $has : self::TRACK_DEFAULT;
		}
		$stored = (string) get_post_meta( (int) $id, self::TRACK_KEY, true );
		return isset( self::tracks()[ $stored ] ) ? $stored : self::TRACK_DEFAULT;
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'above_the_editor' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		// A tutor reads this list and writes nothing on it. See BFTD_Library_View.
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( 'BFTD_Library_View', 'reader_bulk' ), 20 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable' ) );

		// The list is a sequence, so it is shown as one. Sorted by number
		// rather than by when somebody happened to add it, because a
		// curriculum read out of order is not a curriculum.
		add_action( 'pre_get_posts', array( __CLASS__, 'order_admin_list' ) );
		add_filter( 'posts_clauses', array( __CLASS__, 'admin_list_order' ), 10, 2 );

		// Two tracks of a hundred and forty in one list needs narrowing, and a
		// heading where one ends and the other begins.
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_ui' ) );
		add_filter( 'post_class', array( __CLASS__, 'row_class' ), 10, 3 );

		// The same trap BFTD_CPT::register_add_new_pages documents at length:
		// a post type nested under a custom parent gets no "Add New" submenu
		// entry, get_admin_page_parent() then finds nothing for post-new.php,
		// and the screen is refused to somebody holding every capability for
		// it. The row is registered so the parent lookup has something to
		// find. It is not drawn, for the same reason none of the others are.
		add_action( 'admin_menu', array( __CLASS__, 'register_add_new_page' ), 11 );
	}

	public static function register_add_new_page() {
		$obj = get_post_type_object( self::POST_TYPE );
		if ( ! $obj ) return;
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			$obj->labels->add_new_item,
			'Add Activity',
			$obj->cap->create_posts,
			'post-new.php?post_type=' . self::POST_TYPE
		);
	}

	public static function register() {
		register_post_type( self::POST_TYPE, array(
			'labels' => array(
				'name'          => 'Activities',
				'singular_name' => 'Activity',
				'add_new_item'  => 'Add Activity',
				'edit_item'     => 'Activity',
				'all_items'     => 'Activities',
				'search_items'  => 'Search activities',
				'not_found'     => 'No activities yet.',
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => BFTD_Admin::MENU_SLUG,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'editor', 'revisions' ),
			'capability_type'     => array( 'bftd_activity', 'bftd_activities' ),
			'map_meta_cap'        => true,
			'capabilities'        => array( 'create_posts' => 'create_bftd_activities' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Reading the library                                                 */
	/* ------------------------------------------------------------------ */

	private static $cache = null;

	/**
	 * A stand-in library, for the sample reports.
	 *
	 * The samples exist so a family or a new tutor can see a finished report
	 * without one having been written, and a lesson on a finished report names
	 * its activities the way the library names them: track, number, name. A
	 * sample holding typed names showed "Sound Lines" where a real report shows
	 * "Track 1 · 2 · Sound Lines", which is the one thing a sample must not do.
	 *
	 * Never set on a real request. The preview switches it on around the sample
	 * and clears it immediately after.
	 */
	private static $fixture = null;

	public static function use_fixture( $rows ) {
		self::$fixture = is_array( $rows ) ? $rows : null;
		self::flush();
	}

	public static function clear_fixture() {
		self::$fixture = null;
		self::flush();
	}

	/** Every activity, in programme order: id => array( number, name, track ). */
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

		$out = array();
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = array(
				'number' => (int) get_post_meta( $id, self::NUMBER_KEY, true ),
				'name'   => (string) get_the_title( $id ),
				'track'  => self::track_of( $id ),
			);
		}

		// Sorted here rather than by the query, because the number is meta
		// and a meta_value sort drops every activity that has not been given
		// one. An unnumbered activity belongs at the end of the list, not
		// missing from it.
		//
		// Track first, because the library is two sequences rather than one
		// interleaved run of two hundred and eighty. Sorting on the number
		// alone put Track 1's twelve next to Track 2 and 3's twelve, which
		// reads as one programme that repeats itself.
		$order = array_keys( self::tracks() );
		uasort( $out, function ( $a, $b ) use ( $order ) {
			$ta = array_search( $a['track'], $order, true );
			$tb = array_search( $b['track'], $order, true );
			if ( $ta !== $tb ) return $ta - $tb;
			if ( $a['number'] === $b['number'] ) return strcasecmp( $a['name'], $b['name'] );
			if ( ! $a['number'] ) return 1;
			if ( ! $b['number'] ) return -1;
			return $a['number'] - $b['number'];
		} );

		self::$cache = $out;
		return $out;
	}

	/** Forget what was read, so a test or a save sees the library again. */
	public static function flush() { self::$cache = null; }

	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	/**
	 * Is there an activity behind this id at all, trashed or not?
	 *
	 * A different question from get(), which answers "is it in the library"
	 * and is publish-only on purpose: a trashed activity must not be offered
	 * in a picker or drawn on a family's report.
	 *
	 * But trash is not deletion. Every lesson that used the activity is still
	 * holding its id, with a tutor's notes and work samples hanging off it,
	 * and the point of the trash is that putting something in it can be
	 * undone. So the two questions are kept apart: the library one decides
	 * what is shown, and this one decides what is kept.
	 *
	 * Emptying the trash is the end of it. The post is gone, this answers no,
	 * and the row goes with it on the next save, which is the right answer for
	 * a pointer to something that no longer exists anywhere.
	 */
	public static function exists( $id ) {
		$id = (int) $id;
		if ( ! $id ) return false;
		if ( null !== self::get( $id ) ) return true;

		// A sample has no trash: its library is the fixture, entire.
		if ( null !== self::$fixture ) return false;

		$post = get_post( $id );
		return (bool) ( $post && self::POST_TYPE === $post->post_type );
	}

	/**
	 * "Track 1 · 12 · Blending two sounds".
	 *
	 * The track is part of the name now, everywhere the name appears: in the
	 * picker, on the chosen row, in the reading log and on a family's report.
	 * It has to be, because each track numbers its own hundred and forty from
	 * one, so "12 · Blending two sounds" and "12 · Sound Search" are both true
	 * and a reader cannot tell which programme either belongs to.
	 *
	 * An activity with no number is just its track and its name; one that is
	 * somehow in neither track is just its name, which is the old behaviour and
	 * the right answer for a record that predates all of this.
	 */
	public static function label( $id ) {
		$a = self::get( $id );
		if ( ! $a ) return '';

		$bits = array();
		$track = self::track_label( isset( $a['track'] ) ? $a['track'] : '' );
		if ( '' !== $track ) $bits[] = $track;
		if ( $a['number'] ) $bits[] = $a['number'];
		$bits[] = $a['name'];

		return implode( ' · ', $bits );
	}

	public static function description( $id ) {
		if ( null !== self::$fixture ) {
			$one = self::get( $id );
			return $one && isset( $one['about'] ) ? (string) $one['about'] : '';
		}
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) return '';
		return (string) $post->post_content;
	}

	/** How many activities the programme has, for the "N of M" on a report. */
	public static function total() {
		return count( self::all() );
	}

	/**
	 * What a lesson row is pointing at, whichever way it was written.
	 *
	 * A row picked from the library carries an id. A row typed before the
	 * library existed, and the sample report's own rows, carry a name. Both
	 * are answered here so one change of model does not blank out every
	 * lesson recorded before it, and so the sample keeps standing in its own
	 * activities without needing records in the database.
	 *
	 * The key is what the coverage grid groups by: an id where there is one,
	 * the lowercased name otherwise, so the same typed name still lines up
	 * with itself down the years.
	 */
	public static function resolve( $row ) {
		$id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$name = isset( $row['name'] ) ? trim( (string) $row['name'] ) : '';

		if ( $id && self::get( $id ) ) {
			$a = self::get( $id );
			return array(
				'key'    => 'a' . $id,
				'id'     => $id,
				'number' => $a['number'],
				'name'   => $a['name'],
				'label'  => self::label( $id ),
				'about'  => self::description( $id ),
			);
		}

		if ( '' === $name ) return null;

		return array(
			'key'    => 'n' . strtolower( $name ),
			'id'     => 0,
			'number' => 0,
			'name'   => $name,
			'label'  => $name,
			'about'  => isset( $row['about'] ) ? (string) $row['about'] : '',
		);
	}

	/** Every activity on one lesson, resolved and in the order recorded. */
	public static function for_session( $session_id ) {
		$rows = (array) BFTD_Fields::get( $session_id, 'session', 'activities' );
		$out  = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			$one = self::resolve( $row );
			if ( ! $one ) continue;
			$one['note']    = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
			$one['samples'] = isset( $row['samples'] )
				? array_values( array_filter( array_map( 'absint', (array) $row['samples'] ) ) )
				: array();
			$out[] = $one;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The picker                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * One activity chosen from the library, with a box to search it.
	 *
	 * A hundred and fifty options is past the point where a select is usable
	 * on its own: a browser's own type-ahead matches the start of the label
	 * and nothing else, so a tutor who knows the activity is "blending" but
	 * not that it is number 12 has to scroll. The filter box above narrows
	 * the list on either half of the label as they type.
	 *
	 * It is a plain select underneath. With the script blocked, or on a
	 * browser this was never tested on, the field still works: it is a long
	 * list instead of a short one, which is the failure everybody can recover
	 * from.
	 */
	public static function picker( $name, $selected = 0, $id_attr = '' ) {
		$options = array();
		$groups  = array();
		$numbers = array();
		$names   = array();
		foreach ( self::all() as $id => $a ) {
			// The track is on the buttons above the box rather than repeated
			// down every line of the list: with it in both, two thirds of the
			// width of a hundred and forty rows says "Track 1" and the name a
			// tutor is reading for is pushed off the end.
			$options[ $id ] = $a['number'] ? $a['number'] . ' · ' . $a['name'] : $a['name'];
			$groups[ $id ]  = $a['track'];
			$numbers[ $id ] = $a['number'] ? (string) $a['number'] : '';
			$names[ $id ]   = $a['name'];
		}
		return BFTD_Library::picker( array(
			'groups'       => $groups,
			'numbers'      => $numbers,
			'names'        => $names,
			'buckets'      => self::tracks(),
			'bucket_label' => 'Filter by track',
			'chosen_label' => self::label( $selected ),
			'name'        => $name,
			'selected'    => $selected,
			'options'     => $options,
			'noun'        => 'activity',
			'placeholder' => 'Search by number or name',
			'add_url'     => admin_url( 'post-new.php?post_type=' . self::POST_TYPE ),
			'id_attr'     => $id_attr,
		) );
	}

	/* ------------------------------------------------------------------ */
	/* What an activity teaches, and what it is read from                  */
	/* ------------------------------------------------------------------ */

	const SKILLS_KEY = '_bftd_activity_skills';

	/**
	 * The skills this activity builds.
	 *
	 * Written once, on the activity, rather than on every lesson that uses
	 * it. A tutor recording six activities is not also retyping the twelve
	 * skills behind them, and the skills grid on a family's report is built
	 * from the curriculum rather than from what somebody remembered to put.
	 */
	public static function skills_of( $activity_id ) {
		if ( null !== self::$fixture ) {
			$one = self::get( $activity_id );
			$ids = ( $one && isset( $one['skills'] ) ) ? (array) $one['skills'] : array();
		} else {
			$ids = get_post_meta( (int) $activity_id, self::SKILLS_KEY, true );
		}
		$out = array();
		foreach ( (array) $ids as $sid ) {
			$sid = (int) $sid;
			if ( $sid && '' !== BFTD_Skills::label( $sid ) ) $out[] = $sid;
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* Writing one                                                         */
	/* ------------------------------------------------------------------ */

	public static function boxes() {
		add_meta_box( 'bftd_activity_number', 'Number in the programme', array( __CLASS__, 'box_number' ), self::POST_TYPE, 'side', 'high' );

		// Skills are not a metabox. See above_the_editor.
	}

	/**
	 * Which track, and where in it.
	 *
	 * The two belong together: the number is meaningless without the track,
	 * because each track counts its own hundred and forty from one.
	 *
	 * This box was once deleted along with the texts box that sat beside it,
	 * while the add_meta_box line above stayed. A registered callback that
	 * does not exist is a fatal on every activity screen, and the screen dies
	 * part-drawn: no Publish button, no way to set a track, and a save that
	 * finds no posted track quietly puts the activity back in Track 1. Hence
	 * the test that every box this plugin registers is a method that is
	 * really there.
	 */
	public static function box_number( $post ) {
		// The nonce is printed once, by the skills box above the editor.
		// Both boxes post into the same save, so a second copy of the same
		// field would be one more thing to keep in step for no gain.
		$n     = get_post_meta( $post->ID, self::NUMBER_KEY, true );
		$track = self::track_of( $post->ID );
		?>
		<p>
			<label for="bftd_activity_track"><strong>Track</strong></label><br>
			<select name="bftd_activity_track" id="bftd_activity_track" style="width:100%">
				<?php foreach ( self::tracks() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $track, $key ); ?>><?php
						echo esc_html( $label );
					?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Which part of the programme this belongs to. Track 1 counts its hundred and forty as sessions; Tracks 2 and 3 count theirs as skills.</p>
		<p style="margin-top:14px">
			<label for="bftd_activity_number"><strong>Number in that track</strong></label><br>
			<input type="number" min="1" step="1" style="width:100%" name="bftd_activity_number"
				id="bftd_activity_number" value="<?php echo esc_attr( $n ); ?>" placeholder="e.g. 12">
		</p>
		<p class="description">Where this sits in its own track's sequence, one to a hundred and forty. Each track counts from one, so there is a 12 in both and the track above is what tells them apart. An activity with no number is listed last.</p>
		<?php
	}

	/**
	 * The skills box sits ABOVE the description, not below it.
	 *
	 * Choosing a skill writes that skill's own words into the description
	 * underneath, so the order on screen is the order of the work: say what
	 * this activity builds, and the description starts itself. A box below the
	 * editor would have been filling something the person had already scrolled
	 * past and, more likely, already written.
	 *
	 * edit_form_after_title is the one place WordPress offers between the
	 * title and the editor. A metabox cannot go there, which is why this is
	 * printed rather than registered.
	 */
	public static function above_the_editor( $post ) {
		if ( ! $post || self::POST_TYPE !== $post->post_type ) return;
		echo '<div class="bftd-abovebox postbox"><div class="postbox-header"><h2 class="hndle">Skills this builds</h2></div><div class="inside">';
		self::box_skills( $post );
		echo '</div></div>';
	}

	public static function box_skills( $post ) {
		echo self::box_skills_html( $post );
	}

	/** The same box, returned rather than echoed, so it can be looked at. */
	public static function box_skills_html( $post ) {
		ob_start();
		wp_nonce_field( 'bftd_activity_' . $post->ID, 'bftd_activity_nonce' );
		$chosen = self::skills_of( $post->ID );
		// The pickers in this box only offer skills in the activity's own
		// track. The script follows the track select if it is changed.
		echo '<div class="bftd-box bftd-skillpull" data-bftd-lock="' . esc_attr( self::track_of( $post->ID ) ) . '"><p class="description">What a child can do after this activity. Only skills in this activity\'s track are offered. These are what the skills grid on a progress report is built from, so they are chosen here once rather than retyped on every session. Choosing one writes what that skill says about itself into the description below, where you can reword it.</p>';
		BFTD_Fields::render_field( $post->ID, 'activity', 'skills', array(
			'type'      => 'rows',
			'label'     => 'Skills',
			'layout'    => 'stack',
			'add_label' => 'Add skill',
			'columns'   => array( 'id' => array( 'type' => 'skill', 'label' => 'Skill' ) ),
		), array_map( function ( $sid ) { return array( 'id' => (string) $sid ); }, $chosen ) );
		echo '</div>';
		return ob_get_clean();
	}

	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( empty( $_POST['bftd_activity_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_activity_nonce'] ) ), 'bftd_activity_' . $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		// Always one of the two. An unrecognised value posted from anywhere
		// falls back to Track 1, which is where everything written before
		// tracks existed lives, rather than to a third track that is not real.
		$track = isset( $_POST['bftd_activity_track'] ) ? sanitize_key( wp_unslash( $_POST['bftd_activity_track'] ) ) : '';
		if ( ! isset( self::tracks()[ $track ] ) ) $track = self::TRACK_DEFAULT;
		update_post_meta( $post_id, self::TRACK_KEY, $track );

		$n = isset( $_POST['bftd_activity_number'] ) ? absint( $_POST['bftd_activity_number'] ) : 0;
		if ( $n ) {
			update_post_meta( $post_id, self::NUMBER_KEY, $n );
		} else {
			delete_post_meta( $post_id, self::NUMBER_KEY );
		}

		$rows = isset( $_POST['bftd_rows']['activity'] ) ? (array) wp_unslash( $_POST['bftd_rows']['activity'] ) : array();

		// Skills are stored as a plain list of ids rather than as rows,
		// because that is what every reader wants and unpacking rows at each
		// of them is how the same loop ends up written four times.
		$skills = array();
		$before = self::skills_of( $post_id );
		foreach ( (array) ( $rows['skills'] ?? array() ) as $row ) {
			$sid = is_array( $row ) && isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( ! $sid || '' === BFTD_Skills::label( $sid ) ) continue;
			// A skill from the other track, or a Wordwall entry, is not added.
			// One that was already on this activity is kept, so nothing
			// attached before is dropped by a save; the box marks it for a
			// person to fix.
			if ( BFTD_Skills::place_of( $sid ) !== $track && ! in_array( $sid, $before, true ) ) continue;
			$skills[] = $sid;
		}
		update_post_meta( $post_id, self::SKILLS_KEY, array_values( array_unique( $skills ) ) );

		self::flush();
	}

	/* ------------------------------------------------------------------ */
	/* The library screen                                                  */
	/* ------------------------------------------------------------------ */

	public static function columns( $cols ) {
		$out = array( 'cb' => isset( $cols['cb'] ) ? $cols['cb'] : '' );
		$out['bftd_number'] = 'No.';
		// "Name", not "Activity". The screen is already headed Activities and
		// every row on it is one, so the column was spending its width saying
		// what the page had just said.
		$out['title']       = 'Name';
		$out['date']        = isset( $cols['date'] ) ? $cols['date'] : 'Date';
		return BFTD_Library_View::reader_columns( $out, self::POST_TYPE );
	}

	/**
	 * The track on the row itself, not in a column of its own.
	 *
	 * A column repeating "Track 1" down a hundred and forty rows says the same
	 * thing a hundred and forty times, and the person reading it already knows:
	 * they are reading Track 1. What they need is to see where one programme
	 * ends and the other begins, which is a heading, not a column. The class
	 * here is what the heading rows are worked out from.
	 */
	public static function row_class( $classes, $class, $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) return $classes;
		$classes[] = 'bftd-track-' . self::track_of( $post_id );
		return $classes;
	}

	public static function column( $col, $post_id ) {
		if ( 'bftd_read' === $col ) { BFTD_Library_View::read_cell( $post_id ); return; }
		if ( 'bftd_number' !== $col ) return;
		$n = (int) get_post_meta( $post_id, self::NUMBER_KEY, true );
		echo $n ? '<strong>' . (int) $n . '</strong>' : '<span class="bftd-none">&mdash;</span>';
	}

	/**
	 * A track filter above the library list.
	 *
	 * Two hundred and eighty activities in one list, numbered one to a hundred
	 * and forty twice over, is not a list anybody maintains. The same
	 * narrowing the picker gives a tutor is given to the person writing them.
	 */
	public static function filter_ui( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) return;
		$now = isset( $_GET['bftd_track'] ) ? sanitize_key( wp_unslash( $_GET['bftd_track'] ) ) : '';
		if ( ! isset( self::tracks()[ $now ] ) ) $now = '';
		BFTD_Library::track_pills( 'bftd_track', self::tracks(), $now );
	}

	/** Every activity on the list screen, for its search and suggestions. */
	public static function list_index() {
		return BFTD_Library::index( self::POST_TYPE, self::NUMBER_KEY, array( __CLASS__, 'track_of' ) );
	}

	/**
	 * Which activities the track filter asks for, or null for all of them.
	 *
	 * Track 1 is also everything with no track stored at all, because every
	 * activity written before tracks existed is a Track 1 activity. Asking
	 * only for the stored value would have hidden the entire existing library
	 * the moment somebody used the filter.
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

	public static function sortable( $cols ) {
		$cols['bftd_number'] = 'bftd_number';
		return $cols;
	}

	/**
	 * Why the Track column is not sortable.
	 *
	 * Sorting on it would mean sorting on a meta value that most rows do not
	 * have yet, which drops them out of the list rather than putting them
	 * first. Reading one track in order is what the filter above the list is
	 * for, and inside a track the number order is already right.
	 */

	public static function order_admin_list( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) return;

		/*
		 * This method decides WHICH activities. The order they come back in is
		 * decided by admin_list_order, in SQL, and the two do not touch: the
		 * meta_query is written here and nowhere else, which is what stopped
		 * one hook silently undoing the other's.
		 */
		$track = self::track_clause(
			isset( $_GET['bftd_track'] ) ? sanitize_key( wp_unslash( $_GET['bftd_track'] ) ) : ''
		);
		if ( $track ) $query->set( 'meta_query', array( $track ) );

		BFTD_Library::list_search( $query, self::list_index() );
	}

	/**
	 * Track first, then number within it.
	 *
	 * The list is two programmes, and it is read as two: Track 1 from one to a
	 * hundred and forty, then Tracks 2 and 3 from one to a hundred and forty.
	 * Ordering on the number alone put the two twelves next to each other,
	 * which reads as one programme that repeats itself.
	 *
	 * Done in SQL rather than through meta_query ordering because most of the
	 * library has no track stored — everything written before tracks existed —
	 * and an ordered meta clause drops the rows that do not have the key. A
	 * LEFT JOIN keeps them and COALESCE puts them where they belong, which is
	 * Track 1.
	 */
	public static function admin_list_order( $clauses, $query ) {
		global $wpdb;
		if ( ! is_admin() || ! $query->is_main_query() ) return $clauses;
		if ( self::POST_TYPE !== $query->get( 'post_type' ) ) return $clauses;

		$by = $query->get( 'orderby' );
		if ( $by && 'bftd_number' !== $by ) return $clauses;   // somebody chose a column

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} bftd_tk ON ( bftd_tk.post_id = {$wpdb->posts}.ID AND bftd_tk.meta_key = %s )"
			. " LEFT JOIN {$wpdb->postmeta} bftd_no ON ( bftd_no.post_id = {$wpdb->posts}.ID AND bftd_no.meta_key = %s )",
			self::TRACK_KEY,
			self::NUMBER_KEY
		);

		// The track order is stated rather than left to alphabetical luck: 't1'
		// sorting before 't23' is true today and is not a thing to rely on.
		$order = array();
		$n     = 0;
		foreach ( array_keys( self::tracks() ) as $key ) {
			$order[] = $wpdb->prepare( 'WHEN %s THEN %d', $key, $n++ );
		}
		$case = 'CASE COALESCE( bftd_tk.meta_value, ' . $wpdb->prepare( '%s', self::TRACK_DEFAULT ) . ' ) '
			. implode( ' ', $order ) . ' ELSE ' . (int) $n . ' END';

		$clauses['orderby'] = $case . ' ASC,'
			// An activity with no number belongs at the end of its own track,
			// not at the front of it, which is where an empty string sorts.
			. ' ( bftd_no.meta_value IS NULL ) ASC,'
			. ' CAST( bftd_no.meta_value AS UNSIGNED ) ASC,'
			. " {$wpdb->posts}.post_title ASC";
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}
}
