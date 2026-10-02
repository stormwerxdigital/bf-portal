<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The five post types the whole plugin hangs off.
 *
 *   bftd_student     the container. One learner. Everything else points back
 *                    to a student by _bftd_student_id.
 *   bftd_assessment  one Reading Diagnostic event. A student can have several
 *                    over time (intake, Activity 70, Activity 140).
 *   bftd_progress    the living Progress Report for a student's engagement.
 *   bftd_session     one lesson record, belonging to a progress report.
 *   bftd_resource    the shared library: at-home suggestions and setup videos.
 *
 * Every one of them is admin-only (no public single or archive) — the parent
 * never lands on a WordPress permalink, they read everything through the
 * dashboard renderer.
 */
class BFTD_CPT {

	const STUDENT    = 'bftd_student';
	const ASSESSMENT = 'bftd_assessment';
	const PROGRESS   = 'bftd_progress';
	const SESSION    = 'bftd_session';
	const RESOURCE   = 'bftd_resource';

	/** Postmeta pointer from any child record back to its student. */
	const STUDENT_KEY = '_bftd_student_id';
	/** Postmeta pointer from a session back to its progress report. */
	const REPORT_KEY  = '_bftd_report_id';
	/** Repeatable postmeta: every client user linked to a student. */
	const CLIENT_KEY  = '_bftd_client_user_id';
	/**
	 * Single postmeta: which of them is the client.
	 *
	 * A caregiver is anyone responsible for the child — both parents, a
	 * grandmother, a teacher — and they all see the same portal. The client
	 * is one person: the contact the practice deals with and the one the
	 * invoice goes to. Every client is a caregiver (designating somebody
	 * gives them access), but most caregivers are not the client.
	 */
	const PRIMARY_KEY = '_bftd_client_primary';
	/** Repeatable postmeta: every tutor assigned to a student. */
	const STAFF_KEY   = '_bftd_assigned_staff';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_add_new_pages' ), 20 );
		add_filter( 'manage_' . self::STUDENT . '_posts_columns', array( __CLASS__, 'student_columns' ) );
		add_action( 'manage_' . self::STUDENT . '_posts_custom_column', array( __CLASS__, 'student_column' ), 10, 2 );

		// The Lessons list, which is a list of lessons for fifteen different
		// children and was sorted by nothing anybody cares about.
		add_filter( 'manage_' . self::SESSION . '_posts_columns', array( __CLASS__, 'session_columns' ) );
		add_action( 'manage_' . self::SESSION . '_posts_custom_column', array( __CLASS__, 'session_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::SESSION . '_sortable_columns', array( __CLASS__, 'session_sortable' ) );
		// With no Title column there is nothing for WP to hang Edit and Trash
		// off, so Student becomes the column that carries them.
		add_filter( 'list_table_primary_column', array( __CLASS__, 'session_primary' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'session_row_actions' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'session_filter' ) );
		// After BFTD_Access::restrict_lists, which is on the same hook at the
		// default priority and narrows the list to what this tutor may see.
		// These filters narrow it further, so they have to read what it left.
		add_action( 'pre_get_posts', array( __CLASS__, 'session_query' ), 20 );
		add_filter( 'posts_search', array( __CLASS__, 'session_search' ), 10, 2 );
		add_filter( 'posts_clauses', array( __CLASS__, 'session_grouping' ), 10, 2 );

		// The title of a session is generated, never typed. See session_title.
		add_action( 'save_post', array( __CLASS__, 'retitle_on_save' ), 99, 2 );
		add_action( 'post_updated', array( __CLASS__, 'student_renamed' ), 10, 3 );
		add_action( 'admin_init', array( __CLASS__, 'backfill_titles' ) );

		// See forget_related_queries. A postmeta write does not move the
		// marker that list-query caches are keyed on, so writing a
		// relationship has to move it or every query that reads that
		// relationship keeps answering from before it existed.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $when ) {
			add_action( $when, array( __CLASS__, 'forget_related_queries' ), 10, 3 );
		}
	}

	/**
	 * The plural comes from one map in BFTD_Roles, so the capability names the
	 * post type declares and the ones the roles are granted can never drift
	 * apart — they are generated from the same source.
	 */
	private static function args( $singular, $plural, $labels, $menu = false, $icon = '', $position = null ) {
		return array(
			'labels'             => $labels,
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => $menu,
			'menu_icon'          => $icon,
			'menu_position'      => $position,
			'capability_type'    => array( $singular, $plural ),
			'map_meta_cap'       => true,
			// Every capability spelled out, and create_posts mapped to the
			// edit capability rather than a separate create_ one — the same
			// arrangement the Stormwerx plugin has run in production for
			// years. Nothing is inferred, so nothing can be inferred wrongly.
			'capabilities'       => array(
				'edit_post'              => 'edit_' . $singular,
				'read_post'              => 'read_' . $singular,
				'delete_post'            => 'delete_' . $singular,
				'edit_posts'             => 'edit_' . $plural,
				'edit_others_posts'      => 'edit_others_' . $plural,
				'edit_private_posts'     => 'edit_private_' . $plural,
				'edit_published_posts'   => 'edit_published_' . $plural,
				'publish_posts'          => 'publish_' . $plural,
				'read_private_posts'     => 'read_private_' . $plural,
				'delete_posts'           => 'delete_' . $plural,
				'delete_private_posts'   => 'delete_private_' . $plural,
				'delete_published_posts' => 'delete_published_' . $plural,
				'delete_others_posts'    => 'delete_others_' . $plural,
				'create_posts'           => 'edit_' . $plural,
			),
			'hierarchical'       => false,
			'supports'           => array( 'title', 'author' ),
			'has_archive'        => false,
			'rewrite'            => false,
			'exclude_from_search'=> true,
			'publicly_queryable' => false,
		);
	}

	public static function register() {
		register_post_type( self::STUDENT, self::args( self::STUDENT, BFTD_Roles::plural_for( self::STUDENT ), array(
			'name'               => 'Students',
			'singular_name'      => 'Student',
			'add_new_item'       => 'Add Student',
			'edit_item'          => 'Student',
			'all_items'          => 'Students',
			'search_items'       => 'Search students',
			'not_found'          => 'No students yet.',
		), BFTD_Admin::MENU_SLUG ) );

		register_post_type( self::ASSESSMENT, self::args( self::ASSESSMENT, BFTD_Roles::plural_for( self::ASSESSMENT ), array(
			'name'               => 'Reading Diagnostics',
			'singular_name'      => 'Reading Diagnostic',
			'add_new_item'       => 'New Reading Diagnostic',
			'edit_item'          => 'Reading Diagnostic',
			'all_items'          => 'Reading Diagnostics',
			'not_found'          => 'No diagnostics yet.',
		), BFTD_Admin::MENU_SLUG ) );

		register_post_type( self::PROGRESS, self::args( self::PROGRESS, BFTD_Roles::plural_for( self::PROGRESS ), array(
			'name'               => 'Progress Reports',
			'singular_name'      => 'Progress Report',
			'add_new_item'       => 'New Progress Report',
			'edit_item'          => 'Progress Report',
			'all_items'          => 'Progress Reports',
			'not_found'          => 'No progress reports yet.',
		), BFTD_Admin::MENU_SLUG ) );

		register_post_type( self::SESSION, self::args( self::SESSION, BFTD_Roles::plural_for( self::SESSION ), array(
			'name'               => 'Sessions',
			'singular_name'      => 'Session',
			'add_new_item'       => 'Record a Session',
			'edit_item'          => 'Session',
			'all_items'          => 'Sessions',
			'not_found'          => 'No sessions recorded yet.',
		), BFTD_Admin::MENU_SLUG ) );

		register_post_type( self::RESOURCE, self::args( self::RESOURCE, BFTD_Roles::plural_for( self::RESOURCE ), array(
			'name'               => 'Resources',
			'singular_name'      => 'Resource',
			'add_new_item'       => 'Add Resource',
			'edit_item'          => 'Resource',
			'all_items'          => 'Resources',
			'not_found'          => 'No resources yet.',
		), BFTD_Admin::MENU_SLUG ) );

		/*
		 * A session has no title to type.
		 *
		 * It is one child on one afternoon, and the name it needs is exactly
		 * that: who and when. Left to a person it was "Wednesday", or the
		 * child's name on its own three times over, and none of it was
		 * anything a list could be sorted or searched by. So the field is
		 * taken off the screen and the title is generated — see
		 * session_title() for what it says and retitle_session() for when.
		 */
		remove_post_type_support( self::SESSION, 'title' );
	}

	/* ------------------------------------------------------------------ */
	/* The name a session carries                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Who, and when. Nothing else.
	 *
	 * This is closer to a reference number than to a title, which is why no
	 * report uses it: a family reads "Session 4", because what they want to
	 * know is where in the programme they are, not which row of a database
	 * they are looking at. The title exists so a tutor can find one session
	 * among four hundred in the admin, and so trash, revisions and audit
	 * entries name something a person recognises.
	 *
	 * The time is in it because two sessions on one day are otherwise the
	 * same string, and telling them apart is the entire job.
	 */
	/* ------------------------------------------------------------------ */
	/* One draft per session                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * What makes two session records the same session.
	 *
	 * A student and a date. Not the time: a session moved from four o'clock to
	 * half past is the same hour of that child's week, written up once, and two
	 * drafts an hour apart is the duplicate this exists to stop.
	 *
	 * Returns '' when either half is missing, because a record with no student
	 * or no date is not yet a session and cannot be a duplicate of one.
	 */
	public static function session_identity( $session_id ) {
		$student = (int) self::student_id( $session_id );
		$date    = (string) BFTD_Fields::get( $session_id, 'session', 'session_date' );
		if ( ! $student || '' === $date ) return '';
		return $student . '|' . $date;
	}

	/**
	 * Other DRAFTS of the same session, oldest first.
	 *
	 * Drafts only, and that is the whole point. A tutor working a fortnight
	 * ahead has twenty draft sessions open and every one of them is wanted;
	 * what is not wanted is two drafts of the same student on the same day,
	 * because the work goes into one of them and the other is what gets opened
	 * next time. That is how a write-up appears to vanish.
	 *
	 * Published records are left alone. A second published session on one day
	 * is a real thing — a child who came twice — and refusing it would be the
	 * software telling the practice what its own timetable may contain.
	 */
	public static function sibling_drafts( $session_id ) {
		$identity = self::session_identity( $session_id );
		if ( '' === $identity ) return array();

		list( $student, $date ) = explode( '|', $identity );

		$ids = get_posts( array(
			'post_type'      => self::SESSION,
			'post_status'    => array( 'draft', 'pending', 'auto-draft' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'post__not_in'   => array( (int) $session_id ),
			'meta_query'     => array(
				'relation' => 'AND',
				array( 'key' => self::STUDENT_KEY, 'value' => (int) $student ),
				array( 'key' => BFTD_Schema::meta_key( 'session', 'session_date' ), 'value' => $date ),
			),
		) );

		return array_map( 'absint', (array) $ids );
	}

	public static function session_title( $session_id ) {
		$student = self::student_id( $session_id );
		$name    = $student ? trim( (string) get_the_title( $student ) ) : '';
		$date    = (string) BFTD_Fields::get( $session_id, 'session', 'session_date' );
		$time    = (string) BFTD_Fields::get( $session_id, 'session', 'session_time' );

		$when = '';
		if ( '' !== $date ) {
			$when = BFTD_Time::format( 'j M Y', BFTD_Time::stamp( $date, '12:00' ) );
			if ( '' !== $time && class_exists( 'BFTD_Schedule' ) ) {
				$when .= ', ' . BFTD_Schedule::pretty_time( $time );
			}
		}

		$bits = array();
		if ( '' !== $name ) $bits[] = $name;
		if ( '' !== $when ) $bits[] = $when;

		// A record with neither yet is somebody who has just pressed Record a
		// Session. It gets a name rather than "(no title)", and takes the real
		// one the moment either half is filled in.
		// The character, not the entity. This string is a post title: it is
		// read back into plain-text places (an audit line, a quick edit box)
		// where "&middot;" would be four visible characters and a semicolon.
		return $bits ? implode( ' · ', $bits ) : 'New session';
	}

	/**
	 * Write that name, if it is not already the name.
	 *
	 * Guarded against itself: this runs on save_post and writes a post, which
	 * is save_post again. The guard is a flag rather than unhooking, because
	 * unhooking inside a hook that something else may also be nesting inside
	 * leaves the hook off for whatever runs next.
	 */
	private static $retitling = false;

	public static function retitle_session( $session_id ) {
		if ( self::$retitling ) return false;

		$session_id = (int) $session_id;
		$post       = get_post( $session_id );
		if ( ! $post || self::SESSION !== $post->post_type ) return false;
		if ( 'auto-draft' === $post->post_status ) return false;

		$want = self::session_title( $session_id );
		if ( $want === $post->post_title ) return false;

		self::$retitling = true;
		wp_update_post( array( 'ID' => $session_id, 'post_title' => $want ) );
		self::$retitling = false;
		return true;
	}

	/** Every session of a student, so a rename reaches all of them. */
	public static function retitle_for_student( $student_id ) {
		$ids = get_posts( array(
			'post_type'      => self::SESSION,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );
		$n = 0;
		foreach ( $ids as $sid ) { if ( self::retitle_session( $sid ) ) $n++; }
		return $n;
	}

	/**
	 * Late on save_post, because the date this reads is written by the
	 * metabox save at the default priority. Hooked on save_post rather than
	 * save_post_{type}, which fires before it.
	 */
	public static function retitle_on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! $post || self::SESSION !== $post->post_type ) return;
		self::retitle_session( $post_id );
	}

	/**
	 * The sessions that already exist, named once.
	 *
	 * Everything above only fires when something is saved, so without this
	 * the records already on a site keep whatever was typed into them until
	 * somebody happens to open each one. Same shape as the capability heal:
	 * a version option, checked on every admin load, which is one autoloaded
	 * read once the work is done.
	 *
	 * A hundred at a time, carrying an offset, because a site with four
	 * hundred sessions should not spend a page load on all of them. The
	 * offset is stable under this walk: renaming a post does not move it in
	 * an ID ordering.
	 */
	const TITLES_VERSION = 1;

	public static function backfill_titles() {
		if ( (int) get_option( 'bftd_titles_version', 0 ) >= self::TITLES_VERSION ) return;

		$batch = 100;
		$done  = (int) get_option( 'bftd_titles_done', 0 );
		$ids   = get_posts( array(
			'post_type'      => self::SESSION,
			'post_status'    => 'any',
			'posts_per_page' => $batch,
			'offset'         => $done,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
		) );
		foreach ( $ids as $sid ) self::retitle_session( $sid );

		if ( count( $ids ) < $batch ) {
			update_option( 'bftd_titles_version', self::TITLES_VERSION );
			delete_option( 'bftd_titles_done' );
			return;
		}
		update_option( 'bftd_titles_done', $done + count( $ids ) );
	}

	/**
	 * A child's name changed, so every session of theirs is now named after
	 * somebody who does not exist.
	 *
	 * On post_updated rather than save_post because it hands over the record
	 * as it was as well as as it is, so the walk only happens when the name
	 * actually moved rather than on every save of a student.
	 */
	public static function student_renamed( $post_id, $after, $before ) {
		if ( ! $after || self::STUDENT !== $after->post_type ) return;
		if ( ! $before || $after->post_title === $before->post_title ) return;
		self::retitle_for_student( $post_id );
	}

	/**
	 * Registers the "Add New" submenu entry for each of our post types.
	 *
	 * THIS IS WHAT MAKES post-new.php REACHABLE, and it is the difference
	 * between this plugin and the Stormwerx one that has worked in production
	 * for years.
	 *
	 * When a post type has its own top-level menu (show_in_menu => true, which
	 * is what Stormwerx uses), WordPress registers both "All Items" and
	 * "Add New" for it. When it is nested under a custom parent, as ours are
	 * so that everything sits together under Brilliant Futures, WordPress
	 * registers only "All Items".
	 *
	 * That omission matters far more than it looks. get_admin_page_parent()
	 * finds the parent of post-new.php by searching the submenus for an entry
	 * matching "post-new.php?post_type=X". With no such entry it returns
	 * empty, and user_can_access_admin_page() then falls into a branch that
	 * scans every blacklisted submenu for the bare filename "post-new.php" —
	 * which core's own Posts menu has put there for anyone without edit_posts.
	 * The screen is refused for a post type the person has every capability
	 * for, and the message says only "Sorry, you are not allowed to access
	 * this page".
	 *
	 * Registering the entry ourselves gives the parent lookup something to
	 * find, so the check takes the same branch it takes for a top-level post
	 * type. It also puts a useful "Add" row in the menu, which we wanted
	 * anyway.
	 */
	public static function register_add_new_pages() {
		// EVERY post type, without exception. This loop is driven by the full
		// list rather than a hand-picked one, because a type left out of it
		// has no submenu entry and therefore cannot be created at all — the
		// screen refuses with "Sorry, you are not allowed to access this
		// page" for someone holding every capability. Leaving Progress
		// Reports out on the grounds that they are usually created
		// automatically is exactly that mistake, and it is not obvious from
		// reading either half on its own.
		foreach ( BFTD_Roles::post_types() as $post_type ) {
			$obj = get_post_type_object( $post_type );
			if ( ! $obj ) continue;

			add_submenu_page(
				BFTD_Admin::MENU_SLUG,
				$obj->labels->add_new_item,
				self::add_label( $post_type ),
				$obj->cap->create_posts,
				'post-new.php?post_type=' . $post_type
			);
		}

		// Nothing is removed afterwards. remove_submenu_page() unsets the
		// entry from $submenu, and $submenu is the exact array
		// get_admin_page_parent() searches — so hiding the row is not a
		// cosmetic act, it is the same as never registering it, and the
		// screen goes straight back to being refused.
		//
		// (This differs from the student hub, which is reached through
		// admin.php?page=... That lookup uses $_registered_pages, which
		// remove_submenu_page() leaves alone, so hiding that row is safe.
		// Two similar-looking calls, two different mechanisms.)
	}

	/** What the "Add" row is called, per post type. */
	public static function add_label( $post_type ) {
		$labels = array(
			self::STUDENT    => 'Add Student',
			self::ASSESSMENT => 'New Reading Diagnostic',
			self::PROGRESS   => 'New Progress Report',
			self::SESSION    => 'Record a Session',
			self::RESOURCE   => 'Add Resource',
		);
		return isset( $labels[ $post_type ] ) ? $labels[ $post_type ] : 'Add New';
	}

	/** The post types offered as buttons on the Today screen. */
	public static function menu_types() {
		return array(
			self::STUDENT    => 'Add Student',
			self::ASSESSMENT => 'New Reading Diagnostic',
			self::SESSION    => 'Record a Session',
			self::RESOURCE   => 'Add Resource',
		);
	}

	/** Kept so an older call site cannot fatal; nothing is hidden any more. */
	public static function hidden_add_rows() {
		return array();
	}

	/* ------------------------------------------------------------------ */
	/* Relationships                                                       */
	/* ------------------------------------------------------------------ */

	public static function student_id( $post_id ) {
		if ( self::STUDENT === get_post_type( $post_id ) ) return (int) $post_id;
		return (int) get_post_meta( $post_id, self::STUDENT_KEY, true );
	}

	/**
	 * The day a student started with us, as a plain Y-m-d.
	 *
	 * Derived from when the record was created rather than stored, so there
	 * is nothing to fill in, nothing to leave blank, and no second version of
	 * the truth to drift from the first. A record that has never been saved
	 * has no date yet, and says so rather than claiming today.
	 */
	public static function started_on( $post ) {
		$post = get_post( $post );
		if ( ! $post ) return '';
		if ( 'auto-draft' === $post->post_status ) return '';
		if ( ! $post->post_date_gmt || '0000-00-00 00:00:00' === $post->post_date_gmt ) return '';

		$ts = strtotime( $post->post_date_gmt . ' UTC' );
		return $ts ? BFTD_Time::format( 'Y-m-d', $ts ) : '';
	}

	/** The same date, written out for a person. */
	public static function started_on_label( $post, $format = 'j F Y' ) {
		$ymd = self::started_on( $post );
		return $ymd ? BFTD_Time::day( $ymd, $format ) : 'Not yet, this student has not been saved.';
	}

	public static function client_ids( $student_id ) {
		$ids = array_map( 'absint', (array) get_post_meta( $student_id, self::CLIENT_KEY ) );
		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/**
	 * Everyone attached to a child, as id => name, with what they are to the
	 * child beside the name.
	 *
	 * The people a cancellation can come from: whoever looks after the child,
	 * and whoever teaches them. "Cancelled by: Family" told a practice
	 * nothing it could act on — which parent, or whether it was the tutor who
	 * moved it — and "Family" is also what it said when a tutor cancelled and
	 * somebody picked the wrong one of two options. A name cannot be that
	 * kind of wrong.
	 *
	 * The client is named as such, because on a cancellation the person who
	 * pays is the person to ring.
	 */
	public static function people_on( $student_id ) {
		$student_id = (int) $student_id;
		if ( ! $student_id ) return array();

		$primary = self::primary_client_id( $student_id );
		$out     = array();

		foreach ( self::client_ids( $student_id ) as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) continue;
			$what = ( $uid === $primary ) ? 'Client' : BFTD_Roles::relationship( $uid );
			$out[ (int) $uid ] = $u->display_name . ( $what ? ' (' . $what . ')' : '' );
		}
		foreach ( self::staff_ids( $student_id ) as $uid ) {
			if ( isset( $out[ (int) $uid ] ) ) continue;
			$u = get_userdata( $uid );
			if ( ! $u ) continue;
			$out[ (int) $uid ] = $u->display_name . ' (' . BFTD_Roles::role_name( $uid ) . ')';
		}

		return $out;
	}

	/**
	 * What to print where a cancellation says who.
	 *
	 * An id resolves to a name. Records made before this asked for a name
	 * hold "family" or "tutor", and they still have to read as something, so
	 * the two old answers keep their words. One function, because the editor,
	 * the report and the email all have to say the same thing about the same
	 * record.
	 */
	public static function cancelled_by_label( $value, $student_id = 0 ) {
		$value = trim( (string) $value );
		if ( '' === $value ) return '';

		if ( ctype_digit( $value ) ) {
			$people = self::people_on( $student_id );
			if ( isset( $people[ (int) $value ] ) ) return $people[ (int) $value ];
			$u = get_userdata( (int) $value );
			return $u ? $u->display_name : '';
		}

		// What the two old options said.
		$was = array( 'family' => 'Family', 'tutor' => 'Brilliant Futures' );
		return isset( $was[ $value ] ) ? $was[ $value ] : '';
	}

	/** The one client, or 0. Never a guess at which caregiver is meant. */
	public static function primary_client_id( $student_id ) {
		return (int) get_post_meta( $student_id, self::PRIMARY_KEY, true );
	}

	/** Every student whose client is this person. */
	public static function students_of_client( $user_id ) {
		return array_map( 'absint', (array) get_posts( array(
			'post_type'      => self::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::PRIMARY_KEY, 'value' => (int) $user_id ) ),
		) ) );
	}

	public static function staff_ids( $student_id ) {
		$ids = array_map( 'absint', (array) get_post_meta( $student_id, self::STAFF_KEY ) );
		return array_values( array_filter( array_unique( $ids ) ) );
	}

	/** Every student a given client account can see. */
	public static function students_for_client( $user_id ) {
		return get_posts( array(
			'post_type'      => self::STUDENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::CLIENT_KEY, 'value' => (int) $user_id ) ),
		) );
	}

	/** The current published report of a given type for a student. */
	public static function report_for( $student_id, $post_type ) {
		$ids = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * A relationship changed, so any list query that asked about one is stale.
	 *
	 * Every "which lessons belong to this report", "which report belongs to
	 * this student" and "which students can this tutor see" is a meta_query.
	 * WordPress caches those results, and the cache key is built from the
	 * posts last-changed marker — which a POSTMETA write does not move. Saving
	 * a lesson therefore wrote the link correctly and left every query that
	 * reads it answering from before the link existed.
	 *
	 * With no persistent object cache the cache is per request, so the stale
	 * answer usually dies before anybody sees it. With Redis or memcached
	 * behind the site it does not: a tutor publishes a lesson, the progress
	 * report is created, and the report stays empty. The same silence hides a
	 * published diagnostic from a family, because that is found the same way.
	 *
	 * So the marker is moved here, on the metadata hooks rather than at each
	 * call site. A writer added next year is covered without knowing this
	 * exists, which is the only version of this rule that stays true.
	 */
	public static function relationship_keys() {
		return array( self::STUDENT_KEY, self::REPORT_KEY, self::STAFF_KEY, self::CLIENT_KEY );
	}

	public static function forget_related_queries( $mid, $post_id, $meta_key ) {
		if ( ! in_array( $meta_key, self::relationship_keys(), true ) ) return;
		if ( function_exists( 'wp_cache_set_last_changed' ) ) {
			wp_cache_set_last_changed( 'posts' );
		}
	}

	/**
	 * The lessons behind a report, oldest first, which is the sequence a
	 * lesson number counts along.
	 *
	 * Sorted on the date the lesson carries rather than on whatever order the
	 * query returned, because that date is what a tutor means by "the third
	 * lesson" and the query's order is an implementation detail that has
	 * changed before. Same date, older record first.
	 */
	public static function sessions_in_order( $report_id, $include_drafts = false ) {
		$ids = (array) self::sessions_for( $report_id, $include_drafts );
		$by  = array();
		foreach ( $ids as $sid ) {
			$by[] = array( 'id' => (int) $sid, 'd' => (string) BFTD_Fields::get( $sid, 'session', 'session_date' ) );
		}
		usort( $by, function ( $a, $b ) {
			if ( $a['d'] === $b['d'] ) return $a['id'] - $b['id'];
			return ( $a['d'] < $b['d'] ) ? -1 : 1;
		} );
		return wp_list_pluck( $by, 'id' );
	}

	/**
	 * Lesson numbers for a whole report: id => 1, 2, 3.
	 *
	 * Derived, never stored. A number that is written down has to be kept in
	 * step by hand, and it never is: two tutors sharing a student reach for
	 * the same one, and deleting a lesson leaves a gap nobody goes back to
	 * close. Counting the sequence instead means a new lesson takes the next
	 * number by existing, and a deleted one closes the gap by the same
	 * arithmetic, with nothing to renumber and nothing to drift.
	 *
	 * The caller passes the ordered ids where it already has them, which is
	 * every screen that draws more than one lesson, so the report does not
	 * query again per lesson.
	 */
	public static function lesson_numbers( $ordered_ids ) {
		$out = array();
		$n   = 0;
		foreach ( (array) $ordered_ids as $sid ) {
			$out[ (int) $sid ] = ++$n;
		}
		return $out;
	}

	/**
	 * The number one lesson has, or the one it would take on publishing.
	 *
	 * Only published lessons are numbered. The family is served published
	 * lessons and nothing else, so numbering drafts as well would leave their
	 * report reading "Lesson 1, Lesson 3" with no lesson 2 anywhere on it. A
	 * draft is told where it will land instead, which is the useful thing
	 * while writing it.
	 */
	public static function lesson_number( $session_id ) {
		$session_id = (int) $session_id;
		$student_id = self::student_id( $session_id );
		if ( ! $student_id ) return 0;

		$report = self::report_for( $student_id, self::PROGRESS );
		if ( ! $report ) return ( 'publish' === get_post_status( $session_id ) ) ? 0 : 1;

		$order = self::sessions_in_order( $report );
		$nums  = self::lesson_numbers( $order );
		if ( isset( $nums[ $session_id ] ) ) return $nums[ $session_id ];

		// Not in the published list, so this is a draft. Where would it fall?
		$mine = (string) BFTD_Fields::get( $session_id, 'session', 'session_date' );
		$n    = 1;
		foreach ( $order as $sid ) {
			$d = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			if ( $d < $mine || ( $d === $mine && (int) $sid < $session_id ) ) $n++;
		}
		return $n;
	}

	/** Every assessment for a student, oldest first — the summary table. */
	public static function assessments_for( $student_id ) {
		return get_posts( array(
			'post_type'      => self::ASSESSMENT,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );
	}

	/**
	 * Every reading diagnostic a family can see, oldest first.
	 *
	 * The progress report now draws the six assessed measures as lines over
	 * time, and a line needs the assessments in the order they happened. That
	 * is the date on the assessment, not the date the record was typed up: a
	 * mid-programme reassessment written up a fortnight late would otherwise
	 * jump ahead of itself and the line would run backwards.
	 *
	 * Published only. A diagnostic still being written is a tutor's working
	 * notes, and a half-entered score plotted as a drop is the worst kind of
	 * wrong: it reads as a child going backwards.
	 *
	 * Three is the usual shape — initial, mid programme, final — but nothing
	 * here counts to three. A practice that reassesses a fourth time gets a
	 * fourth point by doing it.
	 */
	public static function diagnostics_for( $student_id ) {
		$ids = get_posts( array(
			'post_type'      => self::ASSESSMENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );

		$by = array();
		foreach ( $ids as $id ) {
			$on = (string) BFTD_Fields::get( $id, 'sec-assessment-overview', 'assessed_on' );
			$by[] = array(
				'id' => (int) $id,
				'on' => $on,
				'ts' => $on ? BFTD_Time::stamp( $on ) : 0,
			);
		}

		// An assessment with no date on it still happened, so it is kept
		// rather than dropped; it sorts by its record order, after the dated
		// ones, which is the only honest place for it.
		usort( $by, function ( $a, $b ) {
			if ( $a['ts'] === $b['ts'] ) return $a['id'] - $b['id'];
			if ( ! $a['ts'] ) return 1;
			if ( ! $b['ts'] ) return -1;
			return $a['ts'] - $b['ts'];
		} );

		return $by;
	}

	/**
	 * Every progress report a student has, newest record first.
	 *
	 * There should be one. A student's progress report is a living document
	 * rather than a thing issued per term, and the plugin makes one
	 * automatically with the first lesson, so a second is almost always
	 * somebody pressing New Progress Report without realising one existed.
	 *
	 * Nothing here stops them, because a practice may have a reason. What it
	 * does is let the screen say so, which is the part that was missing: a
	 * second report used to appear identical to the first and quietly become
	 * the one the family was served.
	 */
	public static function progress_reports_for( $student_id ) {
		return get_posts( array(
			'post_type'      => self::PROGRESS,
			'post_status'    => array( 'publish', 'draft', 'future', 'pending' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );
	}

	/**
	 * The diagnostic that describes where a child is NOW.
	 *
	 * The last one they sat, by the date of the assessment rather than the date
	 * somebody typed it up. A mid-programme reassessment written up a fortnight
	 * late is still the newer assessment, and ordering on the record's own date
	 * would have handed a family the older one — while the chart beside it,
	 * which does order by the assessment date, ended on the newer. Two answers
	 * to "how is he doing now", on the same page.
	 */
	public static function current_diagnostic( $student_id ) {
		$all = self::diagnostics_for( $student_id );
		if ( ! $all ) return 0;
		$last = $all[ count( $all ) - 1 ];
		return (int) $last['id'];
	}

	/**
	 * Every session of a set of students, in one query, keyed by student.
	 *
	 * The management screens are lists of people, and every figure on them is
	 * a fact about that person's sessions. Asked one student at a time, each
	 * of those figures is a query a row, and a screen of two hundred rows is
	 * a page that never finishes. Asked like this, a screen costs the same
	 * whether it is showing ten students or two hundred.
	 *
	 * The meta is primed in a second query, so everything read off these
	 * sessions afterwards — the date, the status, who they belong to — is
	 * already in memory.
	 *
	 * Newest first, by the date on the session rather than the day somebody
	 * typed it up. A session with no date yet sorts to the end, where a tutor
	 * can see it needs finishing, rather than disappearing.
	 */
	public static function sessions_of( $student_ids, $include_drafts = false ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $student_ids ) ) ) );
		$out = array();
		foreach ( $ids as $id ) $out[ $id ] = array();
		if ( ! $ids ) return $out;

		$found = get_posts( array(
			'post_type'      => self::SESSION,
			'post_status'    => $include_drafts ? array( 'publish', 'draft', 'pending', 'future' ) : array( 'publish' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array(
				'key'     => self::STUDENT_KEY,
				'value'   => $ids,
				'compare' => 'IN',
			) ),
		) );
		if ( $found && function_exists( 'update_meta_cache' ) ) update_meta_cache( 'post', $found );

		$dated = array();
		foreach ( (array) $found as $sid ) {
			$owner = self::student_id( $sid );
			if ( ! isset( $out[ $owner ] ) ) continue;
			$out[ $owner ][] = (int) $sid;
			$dated[ (int) $sid ] = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
		}

		foreach ( $out as $owner => $list ) {
			usort( $list, function ( $a, $b ) use ( $dated ) {
				$da = isset( $dated[ $a ] ) ? $dated[ $a ] : '';
				$db = isset( $dated[ $b ] ) ? $dated[ $b ] : '';
				if ( '' === $da && '' === $db ) return $b - $a;
				if ( '' === $da ) return 1;    // undated last, not first
				if ( '' === $db ) return -1;
				if ( $da === $db ) return $b - $a;
				return strcmp( $db, $da );     // newest first
			} );
			$out[ $owner ] = $list;
		}
		return $out;
	}

	/**
	 * What the diagnostics say about a child, so nobody types it twice.
	 *
	 * The student record used to carry a reading level, a reading speed and
	 * a starting speed, typed by hand, beside the reading diagnostics that
	 * measure exactly those three things. Two answers to one question, and
	 * the typed one went stale the moment a reassessment was written up —
	 * the portal greeted a family with a level the child had passed months
	 * earlier.
	 *
	 * "Now" is the most recent diagnostic by the date of the assessment, the
	 * same one the progress report calls the last reading assessment. "At
	 * the start" is the first. A student with one diagnostic has the same
	 * one answering both, which is correct: that is where they started and
	 * that is where they are.
	 */
	public static function from_diagnostics( $student_id ) {
		$out = array( 'on' => '', 'level' => '', 'wpm' => 0, 'wpm_start' => 0 );

		$all = self::diagnostics_for( $student_id );
		if ( ! $all ) return $out;

		$last  = $all[ count( $all ) - 1 ];
		$first = $all[0];

		$out['on']    = (string) $last['on'];
		$out['level'] = (string) BFTD_Fields::get( $last['id'], 'dxsec-3', 'score' );

		// Words correct per minute is worked out from the passage rather than
		// stored, so it has to be read through the section. A bare get()
		// returns an empty string and the speed silently reads as nothing.
		$now   = BFTD_Fields::get_section( $last['id'], 'dxsec-4' );
		$start = BFTD_Fields::get_section( $first['id'], 'dxsec-4' );
		$out['wpm']       = isset( $now['score'] ) ? (int) $now['score'] : 0;
		$out['wpm_start'] = isset( $start['score'] ) ? (int) $start['score'] : 0;

		return $out;
	}

	/**
	 * When the diagnostic was, without anybody keeping it in step.
	 *
	 * The assessment record if there is one, because that is the thing that
	 * actually happened. Failing that, whatever the CRM seeded when it
	 * created the student, which for a prospect booked in but not yet seen is
	 * the only answer there is.
	 */
	public static function diagnostic_on( $student_id ) {
		$dx = self::from_diagnostics( $student_id );
		if ( '' !== $dx['on'] ) return $dx['on'];
		return (string) BFTD_Fields::get( $student_id, 'student', 'diagnostic_on' );
	}

	public static function sessions_for( $report_id, $include_drafts = false ) {
		/**
		 * The lessons behind a report.
		 *
		 * PUBLISHED ONLY, unless a staff screen asks otherwise. A draft
		 * lesson is a tutor's notes in progress: it must not be counted,
		 * charted, or shown. The filter used to live at the one place that
		 * drew the lesson list, which left every other reader of this method
		 * counting drafts — the lesson tally at the top of a family's report,
		 * the "All N lessons" link, the skills-covered grid, and, worst, the
		 * lessons-used figure the paid-for bank is calculated from. A rule
		 * kept at one call site is not a rule.
		 *
		 * Filtered so a sample report can stand in a set of its own without
		 * writing sixteen posts into the database, and so an external
		 * calendar could later supply them. The filter short-circuits before
		 * the status rule, which is what a sample wants: its lessons are
		 * fixtures, not records anybody published.
		 */
		$pre = apply_filters( 'bftd_pre_sessions_for', null, $report_id );
		if ( null !== $pre ) return (array) $pre;

		/*
		 * ASKED OF THE STUDENT, not of a stored link.
		 *
		 * A lesson belongs to a child. The progress report belongs to the same
		 * child. So the lessons on a report are that child's lessons, and
		 * working it out is the only version of this that cannot come apart.
		 *
		 * It used to read a link written onto the lesson when it published,
		 * pointing at whichever progress report was newest at that moment.
		 * That held right up until a student had two progress reports — which
		 * happens by somebody pressing New Progress Report — and then the
		 * lessons pointed at the first while a tutor sat looking at the
		 * second. The report showed no lessons, the editor said "Empty, so not
		 * shown to the family", and nothing anywhere was wrong enough to
		 * error. Previewing FROM a lesson still worked, because that path adds
		 * the lesson by hand, which is exactly the kind of near miss that
		 * keeps a bug like this alive.
		 *
		 * The link is still written, because it is worth having in the
		 * database and it is what the audit trail reads. It is no longer what
		 * anybody asks.
		 */
		$student_id = self::student_id( $report_id );
		if ( ! $student_id ) return array();

		/*
		 * NO META ORDERING HERE, and this is the whole bug that hid every
		 * lesson on every progress report.
		 *
		 * It used to ask for 'orderby' => 'meta_value' with 'meta_key' =>
		 * '_bftd_session_date'. The key a lesson's date is actually stored
		 * under is '_bftd_session__session_date' — two underscores, built by
		 * BFTD_Schema::meta_key from the section and the field. So the key
		 * named here existed on nothing.
		 *
		 * WordPress does not shrug at that. An orderby meta_key becomes an
		 * INNER JOIN, so every lesson without that key was filtered out of the
		 * result: not mis-sorted, GONE. The query returned an empty array on
		 * every report, for every student, every time. Nothing errored, and
		 * previewing from a lesson still worked because that path hands its own
		 * lesson in, which is what kept it alive through three versions.
		 *
		 * Order is not this method's job anyway. sessions_in_order() sorts by
		 * the date on the lesson, in PHP, where a lesson with no date yet sorts
		 * instead of disappearing. So this asks for WHICH, and nothing else.
		 */
		return get_posts( array(
			'post_type'      => self::SESSION,
			'post_status'    => $include_drafts ? array( 'publish', 'draft' ) : array( 'publish' ),
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STUDENT_KEY, 'value' => (int) $student_id ) ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Students list table                                                 */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* The Lessons list                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Lessons belong to children, and the list did not say which.
	 *
	 * Every lesson takes its title from the student, so a tutor with fifteen
	 * of them was reading fifteen rows of names with no way to tell one
	 * child's Wednesday from another's, and the only date on the row was the
	 * day somebody happened to type it up.
	 */
	public static function session_columns( $cols ) {
		/*
		 * Built rather than filtered, so what the list shows is what is
		 * written here. The title is gone: it is generated from the student
		 * and the date, so a Title column was those two columns again in one
		 * string, and the only thing it added was the temptation to read it
		 * as a name somebody chose.
		 */
		$out = array();
		if ( isset( $cols['cb'] ) ) $out['cb'] = $cols['cb'];
		$out['bftd_student'] = 'Student';
		$out['bftd_client']  = 'Client';
		$out['bftd_tutor']   = 'Tutor';
		$out['bftd_when']    = 'Session';
		return $out;
	}

	/**
	 * Student carries the row.
	 *
	 * WordPress hangs Edit, Quick Edit and Trash off whichever column it is
	 * told is primary, and it is told Title by default. Removing Title without
	 * saying this leaves a list nobody can open anything from.
	 */
	public static function session_primary( $default, $screen ) {
		return ( 'edit-' . self::SESSION === $screen ) ? 'bftd_student' : $default;
	}

	/**
	 * The one thing the Student cell used to do that the link no longer can:
	 * show this child's sessions and nothing else. It is a row action now,
	 * beside Edit, rather than the only thing the name was good for.
	 */
	public static function session_row_actions( $actions, $post ) {
		if ( ! $post || self::SESSION !== $post->post_type ) return $actions;
		$sid = self::student_id( $post->ID );
		if ( ! $sid ) return $actions;

		$actions['bftd_only'] = '<a href="' . esc_url( admin_url(
			'edit.php?post_type=' . self::SESSION . '&bftd_student=' . (int) $sid
		) ) . '">Only this student</a>';
		return $actions;
	}

	public static function session_column( $col, $post_id ) {
		if ( 'bftd_student' === $col ) {
			$sid  = self::student_id( $post_id );
			$name = $sid ? get_the_title( $sid ) : 'Not linked yet';
			$edit = get_edit_post_link( $post_id );
			// The primary column, so this is the link that opens the record.
			echo '<strong><a class="row-title" href="' . esc_url( (string) $edit ) . '">'
				. esc_html( $name ) . '</a></strong>';
			return;
		}

		if ( 'bftd_client' === $col ) {
			$sid = self::student_id( $post_id );
			$uid = $sid ? self::primary_client_id( $sid ) : 0;
			$u   = $uid ? get_userdata( $uid ) : false;
			echo $u ? esc_html( $u->display_name ) : '<span class="bftd-none">Nobody yet</span>';
			return;
		}

		if ( 'bftd_tutor' === $col ) {
			$names = array();
			foreach ( self::session_staff_ids( $post_id ) as $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) $names[] = $u->display_name;
			}
			echo $names ? esc_html( implode( ', ', $names ) ) : '<span class="bftd-none">Nobody yet</span>';
			return;
		}

		if ( 'bftd_when' !== $col ) return;

		echo wp_kses_post( self::when_label( $post_id ) );
	}

	/**
	 * Who is on this session.
	 *
	 * Two lists answer to "assigned", and they answer different questions: a
	 * session carries its own, which decides who may open that piece of work,
	 * and the student carries one, which is who looks after the child. A
	 * session with nobody named on it belongs to whoever has the child, so
	 * that is the fallback rather than an empty cell.
	 *
	 * One definition, because the column, the dropdown and the search all
	 * have to agree about what "their sessions" means.
	 */
	public static function session_staff_ids( $session_id ) {
		$ids = self::staff_ids( $session_id );
		if ( ! $ids ) {
			$student = self::student_id( $session_id );
			if ( $student ) $ids = self::staff_ids( $student );
		}
		return $ids;
	}

	/**
	 * "Wed: 09/14/26", with the time under it where there is one.
	 *
	 * The day name first because that is how a tutor holds a week in their
	 * head: they are looking for Wednesday's lesson, not for the fourteenth.
	 * It is the date on the lesson, not the date the record was created,
	 * which is the whole point of the column.
	 */
	public static function when_label( $post_id ) {
		$date = (string) BFTD_Fields::get( $post_id, 'session', 'session_date' );
		$time = (string) BFTD_Fields::get( $post_id, 'session', 'session_time' );
		if ( '' === $date ) return '<span class="bftd-none">No date yet</span>';

		$ts  = BFTD_Time::stamp( $date, '12:00' );
		$out = esc_html( BFTD_Time::format( 'D', $ts ) . ': ' . BFTD_Time::format( 'm/d/y', $ts ) );
		if ( '' !== $time && class_exists( 'BFTD_Schedule' ) ) {
			$out .= '<br><span class="bftd-none">' . esc_html( BFTD_Schedule::pretty_time( $time ) ) . '</span>';
		}
		return $out;
	}

	public static function session_sortable( $cols ) {
		$cols['bftd_when'] = 'bftd_when';
		return $cols;
	}

	/** One student, or one tutor, at a time. */
	public static function session_filter( $post_type ) {
		if ( self::SESSION !== $post_type ) return;

		$ids = BFTD_Access::visible_student_ids( get_current_user_id() );
		if ( $ids ) {
			$now = isset( $_GET['bftd_student'] ) ? (int) $_GET['bftd_student'] : 0;
			echo '<select name="bftd_student"><option value="">Every student</option>';
			foreach ( $ids as $sid ) {
				printf(
					'<option value="%d"%s>%s</option>',
					(int) $sid,
					selected( $now, (int) $sid, false ),
					esc_html( get_the_title( $sid ) )
				);
			}
			echo '</select>';
		}

		/*
		 * Only the people who actually have somebody. A practice of two
		 * tutors and eleven accounts that can technically hold a caseload
		 * makes a dropdown of thirteen, eleven of which return nothing. The
		 * same is true of the clients, only more so.
		 */
		self::people_select( 'bftd_client', 'Every client', self::client_ids_in_use( $ids ) );
		self::people_select( 'bftd_tutor', 'Every tutor', self::staffed_tutor_ids( $ids ) );
	}

	/** One dropdown of people, in the order a person reads a list of names. */
	private static function people_select( $name, $all_label, $ids ) {
		if ( ! $ids ) return;
		$now = isset( $_GET[ $name ] ) ? (int) $_GET[ $name ] : 0;
		echo '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all_label ) . '</option>';
		foreach ( $ids as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) continue;
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $uid,
				selected( $now, (int) $uid, false ),
				esc_html( $u->display_name )
			);
		}
		echo '</select>';
	}

	/** Every client named on at least one of these students. */
	public static function client_ids_in_use( $student_ids ) {
		$out = array();
		foreach ( (array) $student_ids as $sid ) {
			$uid = self::primary_client_id( $sid );
			if ( $uid ) $out[ $uid ] = true;
		}
		return self::by_display_name( array_keys( $out ) );
	}

	/** Every tutor with at least one of these students on their list. */
	public static function staffed_tutor_ids( $student_ids ) {
		$out = array();
		foreach ( (array) $student_ids as $sid ) {
			foreach ( self::staff_ids( $sid ) as $uid ) $out[ $uid ] = true;
		}
		return self::by_display_name( array_keys( $out ) );
	}

	/** People, by the name they are listed under. */
	private static function by_display_name( $ids ) {
		$named = array();
		foreach ( array_map( 'absint', (array) $ids ) as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) $named[ $uid ] = $u->display_name;
		}
		natcasesort( $named );
		return array_map( 'absint', array_keys( $named ) );
	}

	public static function session_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( self::SESSION !== $query->get( 'post_type' ) ) return;

		$student = isset( $_GET['bftd_student'] ) ? (int) $_GET['bftd_student'] : 0;
		$tutor   = isset( $_GET['bftd_tutor'] ) ? (int) $_GET['bftd_tutor'] : 0;
		$client  = isset( $_GET['bftd_client'] ) ? (int) $_GET['bftd_client'] : 0;

		// A tutor is assigned to children and a client pays for children, so
		// all three filters reduce to the same thing — a set of students —
		// and narrow each other by intersection rather than by three clauses
		// that have to be kept from contradicting one another.
		$only = null;
		$narrow = function ( $only, $set ) {
			return ( null === $only ) ? $set : array_values( array_intersect( $only, $set ) );
		};
		if ( $student ) $only = array( $student );
		if ( $tutor ) $only = $narrow( $only, self::students_of_staff( $tutor ) );
		if ( $client ) $only = $narrow( $only, self::students_of_client( $client ) );

		if ( null !== $only ) {
			// An empty set means nobody matched, which is a result, not the
			// absence of a filter. Left empty WP_Query drops the clause and
			// answers with every session there is.
			$query->set( 'meta_query', array( array(
				'key'     => self::STUDENT_KEY,
				'value'   => $only ? array_map( 'absint', $only ) : array( 0 ),
				'compare' => 'IN',
			) ) );
		}

		if ( 'bftd_when' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', BFTD_Schema::meta_key( 'session', 'session_date' ) );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/** Every student on one staff member's list. */
	public static function students_of_staff( $user_id ) {
		return array_map( 'absint', (array) get_posts( array(
			'post_type'      => self::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => self::STAFF_KEY, 'value' => (int) $user_id ) ),
		) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Searching the sessions list                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * What somebody types into the search box, and what it ought to find.
	 *
	 * A session holds everything it knows in postmeta, so WordPress searching
	 * post_content and post_excerpt was searching two columns that are always
	 * empty, and post_title, which is now generated. Typing a parent's email
	 * address found nothing, and that is the thing people have in front of
	 * them when they go looking: an email from a family, or a tutor asking
	 * about a child.
	 *
	 * So the whole clause is replaced rather than added to. It matches the
	 * generated title, and it matches any session belonging to a student
	 * whose own name, whose family's name or email, or whose tutor's name or
	 * email contains the term.
	 */
	public static function session_search( $search, $query ) {
		global $wpdb;
		if ( ! is_admin() || ! $query->is_main_query() ) return $search;
		if ( self::SESSION !== $query->get( 'post_type' ) ) return $search;

		$term = trim( (string) $query->get( 's' ) );
		if ( '' === $term ) return $search;

		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$parts = array( $wpdb->prepare( "{$wpdb->posts}.post_title LIKE %s", $like ) );

		$students = self::students_matching( $term );
		if ( $students ) {
			$in      = implode( ',', array_map( 'absint', $students ) );
			$parts[] = $wpdb->prepare(
				"{$wpdb->posts}.ID IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ( $in ) )",
				self::STUDENT_KEY
			);
		}

		return ' AND ( ' . implode( ' OR ', $parts ) . ' ) ';
	}

	/**
	 * Every student the term could mean: their own name, the name or email of
	 * anyone in their family, or of any tutor on their list.
	 */
	public static function students_matching( $term ) {
		global $wpdb;
		$term = trim( (string) $term );
		if ( '' === $term ) return array();

		$like = '%' . $wpdb->esc_like( $term ) . '%';
		$out  = array();

		$by_name = $wpdb->get_col( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' AND post_title LIKE %s",
			self::STUDENT,
			$like
		) );
		foreach ( (array) $by_name as $id ) $out[ (int) $id ] = true;

		$users = get_users( array(
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
			'fields'         => 'ID',
			'number'         => 200,
		) );
		if ( $users ) {
			$ids  = implode( ',', array_map( 'absint', $users ) );
			// Both lists in one pass: a family account and a tutor account
			// hang off the same student by different keys.
			// Joined to the posts table because both keys are used on
			// sessions and reports as well, and only the students are wanted.
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m"
				. " INNER JOIN {$wpdb->posts} p ON ( p.ID = m.post_id AND p.post_type = %s )"
				. " WHERE m.meta_key IN ( %s, %s, %s ) AND m.meta_value IN ( $ids )",
				self::STUDENT,
				self::CLIENT_KEY,
				self::PRIMARY_KEY,
				self::STAFF_KEY
			) );
			foreach ( (array) $rows as $id ) $out[ (int) $id ] = true;
		}

		return array_map( 'absint', array_keys( $out ) );
	}

	/**
	 * Grouped by child, newest lesson first inside each.
	 *
	 * Done in SQL rather than by sorting in PHP, because the list is paged:
	 * re-ordering the twenty rows this page happened to return would put a
	 * child's lessons in one order on page one and another on page two.
	 *
	 * A LEFT JOIN, so a lesson with no student yet still appears. An INNER
	 * one would have hidden exactly the records somebody needs to find and
	 * finish.
	 */
	public static function session_grouping( $clauses, $query ) {
		global $wpdb;
		if ( ! is_admin() || ! $query->is_main_query() ) return $clauses;
		if ( self::SESSION !== $query->get( 'post_type' ) ) return $clauses;
		if ( $query->get( 'orderby' ) ) return $clauses;   // somebody chose a column

		$date_key = BFTD_Schema::meta_key( 'session', 'session_date' );

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} bftd_sm ON ( bftd_sm.post_id = {$wpdb->posts}.ID AND bftd_sm.meta_key = %s )"
			. " LEFT JOIN {$wpdb->posts} bftd_st ON ( bftd_st.ID = bftd_sm.meta_value )"
			. " LEFT JOIN {$wpdb->postmeta} bftd_dm ON ( bftd_dm.post_id = {$wpdb->posts}.ID AND bftd_dm.meta_key = %s )",
			self::STUDENT_KEY,
			$date_key
		);
		$by_student = "bftd_st.post_title ASC, bftd_dm.meta_value DESC, {$wpdb->posts}.post_date DESC";

		/*
		 * Looking at one family, the question changes.
		 *
		 * A client with three children spread across two tutors wants to see
		 * them a tutor at a time — that is the conversation, and it is the
		 * one the invoice follows. Anywhere else, the child is the thing
		 * being read down, so that stays the default.
		 *
		 * MIN() rather than the column, because a student may have two tutors
		 * and the row is already grouped: ordering by a non-aggregated column
		 * from a joined row is a query MySQL refuses outright under
		 * ONLY_FULL_GROUP_BY, which is on by default.
		 */
		if ( empty( $_GET['bftd_client'] ) ) {
			$clauses['orderby'] = $by_student;
			$clauses['groupby'] = "{$wpdb->posts}.ID";
			return $clauses;
		}

		$clauses['join'] .= $wpdb->prepare(
			" LEFT JOIN {$wpdb->postmeta} bftd_am ON ( bftd_am.post_id = bftd_st.ID AND bftd_am.meta_key = %s )"
			. " LEFT JOIN {$wpdb->users} bftd_tu ON ( bftd_tu.ID = bftd_am.meta_value )",
			self::STAFF_KEY
		);
		// Aggregated throughout, not only on the tutor: a child with two
		// tutors now matches two joined rows, and a bare column off one of
		// them is what ONLY_FULL_GROUP_BY refuses. Each of these is the same
		// value on every row of the group, so MIN changes nothing but the
		// query's legality.
		$clauses['orderby'] = "MIN(bftd_tu.display_name) ASC, MIN(bftd_st.post_title) ASC,"
			. " MAX(bftd_dm.meta_value) DESC, {$wpdb->posts}.post_date DESC";
		$clauses['groupby'] = "{$wpdb->posts}.ID";

		return $clauses;
	}

	public static function student_columns( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['bftd_family'] = 'Family';
				$out['bftd_tutor']  = 'Tutor';
				$out['bftd_last']   = 'Last session';
				$out['bftd_unread'] = 'Waiting on us';
			}
		}
		return $out;
	}

	public static function student_column( $col, $post_id ) {
		if ( 'bftd_family' === $col ) {
			$names = array();
			foreach ( self::client_ids( $post_id ) as $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) $names[] = $u->display_name . ' (' . BFTD_Roles::relationship( $uid ) . ')';
			}
			echo $names ? esc_html( implode( ', ', $names ) ) : '<span style="color:#a7aaad;">Not linked yet</span>';
		}
		if ( 'bftd_tutor' === $col ) {
			$names = array();
			foreach ( self::staff_ids( $post_id ) as $uid ) {
				$u = get_userdata( $uid );
				if ( $u ) $names[] = $u->display_name;
			}
			echo $names ? esc_html( implode( ', ', $names ) ) : '<span style="color:#a7aaad;">Unassigned</span>';
		}
		if ( 'bftd_last' === $col ) {
			$report = self::report_for( $post_id, self::PROGRESS );
			// Staff view: a draft lesson is still the last one somebody
			// taught, and hiding it here would have a tutor wondering where
			// their record went.
			// Last by the date on the lesson, read through the one place that
			// knows where a lesson's date is kept. Reaching for the meta key by
			// hand is what put a key that existed on nothing into the query
			// above, and this column was reading the same wrong one.
			$ids  = $report ? self::sessions_in_order( $report, true ) : array();
			if ( ! $ids ) { echo '<span style="color:#a7aaad;">&mdash;</span>'; return; }
			$date = BFTD_Fields::get( $ids[ count( $ids ) - 1 ], 'session', 'session_date' );
			echo $date ? esc_html( mysql2date( get_option( 'date_format' ), $date ) ) : '<span style="color:#a7aaad;">&mdash;</span>';
		}
		if ( 'bftd_unread' === $col ) {
			$n = class_exists( 'BFTD_Threads' ) ? BFTD_Threads::staff_unread_count( $post_id ) : 0;
			if ( $n ) {
				echo '<span class="bftd-count">' . (int) $n . ' to answer</span>';
			} else {
				echo '<span style="color:#a7aaad;">Nothing waiting</span>';
			}
		}
	}
}
