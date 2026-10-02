<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Roles and capabilities.
 *
 * Five tiers, in order:
 *
 *   Client (bftd_client)          a parent, guardian, teacher or adult
 *                                 learner. Sees the portal for the students
 *                                 their account is linked to, nothing else.
 *                                 The relationship label is usermeta rather
 *                                 than a separate role, so someone who is both
 *                                 a parent and a teacher still has one account.
 *   Tutor (bftd_tutor)            teaches. Create and edit on the students
 *                                 assigned to them, and nothing about anyone
 *                                 else's students.
 *   Tutor Manager                 runs the teaching side. Everything a tutor
 *   (bftd_tutor_manager)          has, on every student, plus assigning
 *                                 tutors, the settings, the email wording and
 *                                 the full activity log. Manages tutors and
 *                                 families.
 *   Senior Manager                runs the practice. Everything a manager has,
 *   (bftd_senior_manager)         plus adding and removing managers.
 *   Administrator                 everything, including WordPress itself.
 *
 * Three capabilities carry the difference:
 *
 *   bftd_manage_students   do the work — tutor and above.
 *   bftd_manage_all        see and change everything — manager and above.
 *   bftd_manage_team       hire and fire the manager tier — senior and above.
 *
 * Without that first split a tutor could rewrite the email templates every
 * family receives and read another tutor's students' history, which is not
 * what "staff" should mean once there is more than one tutor.
 *
 * ONE RULE GOVERNS WHO MAY MANAGE WHOM: you may edit, delete or hand out a
 * role strictly BELOW your own rank, never at or above it. That single
 * comparison gives the whole table — a senior manager appoints managers but
 * cannot appoint another senior manager, a manager appoints tutors but not
 * managers, a tutor appoints nobody — and it means the next tier, if there
 * ever is one, is one entry in tiers() rather than a new special case.
 *
 * A role that can clone its own tier is a privilege escalation dressed as a
 * convenience: demote such a person and they simply re-promote themselves, so
 * the tier stops meaning anything. Hence "strictly below", not "at or below".
 *
 * Administrators are exempt from the rule, so WordPress's own behaviour — an
 * administrator managing another administrator — is left exactly as it ships.
 *
 * None of the staff tiers are WordPress administrators. No plugins, no themes,
 * no core settings, and manage_options is never granted to any of them.
 *
 * Staff always get full edit rights on every screen they can reach, however
 * they navigated there. There is deliberately no read-only "preview the
 * family's view" mode anywhere in this plugin.
 */
class BFTD_Roles {

	const CLIENT_ROLE  = 'bftd_client';
	const TUTOR_ROLE   = 'bftd_tutor';
	const MANAGER_ROLE = 'bftd_tutor_manager';
	const SENIOR_ROLE  = 'bftd_senior_manager';

	/** Do the teaching work: tutor and above. */
	const STAFF_CAP = 'bftd_manage_students';
	/** See and change everything: manager and above. */
	const MANAGE_CAP = 'bftd_manage_all';
	/** Add and remove managers: senior and above. */
	const TEAM_CAP = 'bftd_manage_team';
	/**
	 * Erase a record for good: senior and above.
	 *
	 * Kept apart from TEAM_CAP even though the same two tiers hold both,
	 * because they answer different questions. One is about who may change
	 * the staff list; this one is about who may destroy a child's assessment
	 * history. A future tier that manages the team without that power is one
	 * entry in the ladder, not a rewrite of the delete rule.
	 */
	const ERASE_CAP = 'bftd_erase_records';
	/**
	 * Run the practice itself: senior and above.
	 *
	 * Four screens answer to this one capability, because they are four faces
	 * of the same thing — how the practice is set up rather than how a
	 * student is taught. Settings, the email wording, the activity log, and
	 * the libraries a tutor draws on. A tutor manager runs the teaching; this
	 * is the layer under it.
	 */
	const ADMIN_CAP = 'bftd_administer';

	const CAPS_VERSION = 9;

	/**
	 * The ladder. Rank is the only thing that decides who may manage whom, so
	 * a new tier is one entry here and nothing else.
	 */
	public static function tiers() {
		return array(
			self::CLIENT_ROLE  => array( 'label' => 'Client',        'rank' => 0 ),
			self::TUTOR_ROLE   => array( 'label' => 'Tutor',         'rank' => 1 ),
			self::MANAGER_ROLE => array( 'label' => 'Tutor Manager', 'rank' => 2 ),
			self::SENIOR_ROLE  => array( 'label' => 'Senior Manager','rank' => 3 ),
			'administrator'    => array( 'label' => 'Administrator', 'rank' => 4 ),
		);
	}

	/** The rank of one role slug. Anything we do not know sits below everything. */
	public static function role_rank( $slug ) {
		$tiers = self::tiers();
		return isset( $tiers[ $slug ] ) ? $tiers[ $slug ]['rank'] : -1;
	}

	/** The rank of a person, which is the highest of the roles they hold. */
	public static function rank( $user_id ) {
		$user = is_object( $user_id ) ? $user_id : get_userdata( $user_id );
		if ( ! $user ) return -1;
		$rank = -1;
		foreach ( (array) $user->roles as $slug ) {
			$rank = max( $rank, self::role_rank( $slug ) );
		}
		return $rank;
	}

	/**
	 * The whole permission model for user management, in one comparison.
	 * Administrators are exempt so core behaviour is untouched.
	 */
	public static function outranks( $actor_id, $target_rank ) {
		if ( user_can( $actor_id, 'manage_options' ) ) return true;
		return self::rank( $actor_id ) > $target_rank;
	}

	/**
	 * Everyone who could be teaching or assessing: tutors, managers, senior
	 * managers and administrators.
	 *
	 * Gathered in one place because five screens were each writing out the
	 * same role list, and a sixth role would have had to be found in all of
	 * them.
	 */
	public static function staff_roles() {
		return array( self::TUTOR_ROLE, self::MANAGER_ROLE, self::SENIOR_ROLE, 'administrator' );
	}

	public static function staff_users() {
		return get_users( array( 'role__in' => self::staff_roles(), 'orderby' => 'display_name' ) );
	}

	/** The relationship label stored against a client account. */
	const REL_META = '_bftd_relationship';

	public static function relationships() {
		return array(
			'parent'   => 'Parent or guardian',
			'teacher'  => 'Teacher',
			'learner'  => 'Adult learner',
			'other'    => 'Other',
		);
	}

	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'protect_admin_accounts' ), 10, 4 );
		add_filter( 'map_meta_cap', array( __CLASS__, 'guard_permanent_delete' ), 10, 4 );
		add_filter( 'editable_roles', array( __CLASS__, 'hide_administrator_role' ) );
		foreach ( self::post_types() as $pt ) {
			add_filter( 'bulk_actions-edit-' . $pt, array( __CLASS__, 'strip_delete_bulk_action' ) );
		}
		// Priority 1 on init, which fires before the post types register and
		// long before any screen checks a capability. On admin_init as well,
		// because a request that somehow skips the first still heals on the
		// second. Both are a single autoloaded option read when nothing has
		// changed, so running twice costs nothing.
		add_action( 'init', array( __CLASS__, 'maybe_heal_capabilities' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_heal_capabilities' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'relationship_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'relationship_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_relationship' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_relationship' ) );
	}

	public static function activate() {
		self::grant_capabilities();
		update_option( 'bftd_caps_version', self::CAPS_VERSION );
	}

	/**
	 * WordPress only fires register_activation_hook on a full deactivate,
	 * delete, re-upload, activate cycle, never on a plain file overwrite. A
	 * cheap version check on every admin load re-grants capabilities if they
	 * ever drift, so an in-place update cannot leave an account short.
	 */
	/**
	 * Put back any capability a role should hold and does not.
	 *
	 * This used to trust a version stamp: heal once per bump, then never look
	 * again. A stamp answers "has this build healed yet", and the question
	 * that matters is "do the roles hold what they should" — which drifts
	 * afterwards, when another plugin edits a role, an update stops half way,
	 * or somebody tidies capabilities by hand. Once the stamp was current the
	 * drift was permanent, and on a live site that meant administrators
	 * getting 403 on a record they owned, with a self-healing plugin
	 * reporting itself healthy.
	 *
	 * So it checks the roles instead. The check is array lookups against roles
	 * WordPress has already loaded, and it writes nothing when nothing is
	 * missing, which is every request but the one after something broke. The
	 * version stamp stays, because a bump still forces a full re-grant that
	 * also takes back capabilities we have stopped handing out, and missing
	 * capabilities are only half of drift.
	 */
	public static function maybe_heal_capabilities() {
		$stale = (int) get_option( 'bftd_caps_version', 0 ) < self::CAPS_VERSION;

		if ( ! $stale && ! self::missing_caps() ) return;

		self::grant_capabilities();
		update_option( 'bftd_caps_version', self::CAPS_VERSION );
	}

	/**
	 * Our post types, each with the plural used to build its capability names.
	 *
	 * The plural is written out rather than derived by appending an "s",
	 * because "bftd_progress" + "s" gives "bftd_progresss" — a capability with
	 * a typo in it works perfectly as long as both sides agree, right up until
	 * someone reads it, or grants it by hand, or writes the correct spelling
	 * in a filter and cannot work out why nothing happens.
	 */
	public static function post_types() {
		return array_keys( self::post_type_plurals() );
	}

	public static function post_type_plurals() {
		return array(
			'bftd_student'    => 'bftd_students',
			'bftd_assessment' => 'bftd_assessments',
			'bftd_progress'   => 'bftd_progress_reports',
			'bftd_session'    => 'bftd_sessions',
			'bftd_resource'   => 'bftd_resources',
		);
	}

	public static function plural_for( $post_type ) {
		$map = self::post_type_plurals();
		return isset( $map[ $post_type ] ) ? $map[ $post_type ] : $post_type . 's';
	}

	/** The custom capability set for one of our post types. */
	public static function caps_for( $singular, $plural, $include_delete = false ) {
		$caps = array(
			// WordPress derives "create_posts" from "edit_posts" unless the
			// post type says otherwise, which works until something in the
			// chain disagrees and the only symptom is "Sorry, you are not
			// allowed to access this page" on the Add New screen. Declared
			// and granted explicitly here, and declared on the post type to
			// match, so creating never depends on an implicit default.
			'create_' . $plural,
			'edit_' . $singular,
			'read_' . $singular,
			'edit_' . $plural,
			'edit_others_' . $plural,
			'edit_published_' . $plural,
			'edit_private_' . $plural,
			'publish_' . $plural,
			'read_private_' . $plural,
		);
		if ( $include_delete ) {
			$caps = array_merge( $caps, array(
				'delete_' . $singular,
				'delete_' . $plural,
				'delete_others_' . $plural,
				'delete_published_' . $plural,
				'delete_private_' . $plural,
			) );
		}
		return $caps;
	}

	/**
	 * The activity library's own capabilities.
	 *
	 * Kept out of all_caps() on purpose. That list is granted to every staff
	 * tier, and the library is the practice's curriculum rather than a
	 * tutor's notes: a tutor inventing an activity mid-lesson is exactly the
	 * drift the library exists to end. Senior managers and administrators
	 * only, and nobody else holds so much as create_.
	 */
	public static function activity_caps() {
		return array_merge(
			self::caps_for( 'bftd_activity', 'bftd_activities', true ),
			self::caps_for( 'bftd_skill', 'bftd_skills', true )
		);
	}

	/**
	 * The record types every tier works in, against the libraries only the
	 * practice's own people maintain.
	 *
	 * A student, a diagnostic, a progress report and a lesson are the work. A
	 * resource and an activity are the material a tutor draws on, written once and
	 * reused across every family, and a tutor editing one edits what every
	 * other tutor's students see. So the two lists are granted differently,
	 * and the split lives here rather than in five screens each remembering
	 * to ask a different question.
	 */
	public static function library_types() {
		return array( 'bftd_resource' );
	}

	private static function all_caps( $include_delete ) {
		$out = array();
		foreach ( self::post_type_plurals() as $singular => $plural ) {
			if ( in_array( $singular, self::library_types(), true ) ) continue;
			$out = array_merge( $out, self::caps_for( $singular, $plural, $include_delete ) );
		}
		return $out;
	}

	/** The libraries: resources here, activities in activity_caps(). */
	private static function library_caps() {
		$out = array();
		foreach ( self::post_type_plurals() as $singular => $plural ) {
			if ( ! in_array( $singular, self::library_types(), true ) ) continue;
			$out = array_merge( $out, self::caps_for( $singular, $plural, true ) );
		}
		return array_merge( $out, self::activity_caps() );
	}

	/**
	 * Re-grant every capability from scratch, on demand.
	 *
	 * The version check heals a drift automatically, but only once per bump.
	 * When something has gone wrong on a live site — a role edited by another
	 * plugin, a half-finished update — the useful thing is a button that
	 * simply does it again, rather than a version number to fiddle with.
	 */
	public static function repair() {
		self::grant_capabilities();
		update_option( 'bftd_caps_version', self::CAPS_VERSION );

		if ( class_exists( 'BFTD_Audit' ) ) {
			BFTD_Audit::log( 'settings_updated', array(
				'summary' => wp_get_current_user()->display_name . ' re-applied every role capability.',
			) );
		}
	}

	/**
	 * What each role actually holds on this site right now — read back from
	 * WordPress rather than from what we intended to grant, so the two can be
	 * compared.
	 */
	public static function audit_capabilities() {
		$expected = array(
			self::CLIENT_ROLE  => array( 'read', 'bftd_view_dashboard' ),
			self::TUTOR_ROLE   => array_merge( array( 'read', 'bftd_view_dashboard', self::STAFF_CAP, 'upload_files' ), self::all_caps( false ) ),
			self::MANAGER_ROLE => array_merge( array( 'read', 'bftd_view_dashboard', self::STAFF_CAP, self::MANAGE_CAP, 'upload_files' ), self::all_caps( true ) ),
			self::SENIOR_ROLE  => array_merge( array( 'read', 'bftd_view_dashboard', self::STAFF_CAP, self::MANAGE_CAP, self::TEAM_CAP, self::ERASE_CAP, self::ADMIN_CAP, 'upload_files' ), self::all_caps( true ), self::library_caps() ),
		);

		$out = array();
		foreach ( $expected as $slug => $caps ) {
			$role    = get_role( $slug );
			$missing = array();
			if ( $role ) {
				foreach ( array_unique( $caps ) as $cap ) {
					if ( empty( $role->capabilities[ $cap ] ) ) $missing[] = $cap;
				}
			}
			$out[ $slug ] = array(
				'exists'   => (bool) $role,
				'expected' => count( array_unique( $caps ) ),
				'missing'  => $missing,
			);
		}
		return $out;
	}

	/**
	 * What every role should hold, as role slug => list of capabilities.
	 *
	 * One definition, because granting and checking are the same question
	 * asked twice. When they were two lists, the check could pass against a
	 * set the grant had moved on from, and the only symptom was a live site
	 * where an administrator could not open a student.
	 *
	 * The Client role is not in here: it holds three capabilities, none of
	 * ours except the dashboard, and it is created rather than reconciled.
	 */
	public static function expected_caps() {
		$base = array(
			'read',
			'bftd_view_dashboard',
			self::STAFF_CAP,
			'upload_files',
			'list_users',
			'create_users',
			'edit_users',
			'delete_users',
			'promote_users',
		);

		// A manager gets the delete capabilities a tutor does not, because
		// tidying up a mistyped session or a duplicate report is their job.
		$manager = array_merge( $base, array( self::MANAGE_CAP ), self::all_caps( true ) );

		$senior = array_merge(
			$manager,
			array( self::TEAM_CAP, self::ERASE_CAP, self::ADMIN_CAP ),
			self::library_caps()
		);

		// An administrator holds everything a senior manager does. They also
		// hold no second role of ours, so nothing unions in a capability they
		// are missing: whatever is absent here is absent in practice, which is
		// why they are the tier a drift shows up on first.
		return array(
			self::TUTOR_ROLE   => array_values( array_unique( array_merge( $base, self::all_caps( false ) ) ) ),
			self::MANAGER_ROLE => array_values( array_unique( $manager ) ),
			self::SENIOR_ROLE  => array_values( array_unique( $senior ) ),
			'administrator'    => array_values( array_unique( $senior ) ),
		);
	}

	/** The human label for each role we create, for when it has to be made. */
	private static function role_labels() {
		return array(
			self::TUTOR_ROLE   => 'Tutor',
			self::MANAGER_ROLE => 'Tutor Manager',
			self::SENIOR_ROLE  => 'Senior Manager',
		);
	}

	/**
	 * Every capability a role should hold but does not, as role => missing.
	 *
	 * Reads the roles already in memory — WordPress loads all of them from one
	 * option on every request — so this costs array lookups and no queries.
	 * An absent role counts as missing everything, which is what makes a role
	 * another plugin deleted repair itself.
	 */
	public static function missing_caps() {
		$out = array();
		foreach ( self::expected_caps() as $slug => $caps ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				$out[ $slug ] = $caps;
				continue;
			}
			$gaps = array();
			foreach ( $caps as $cap ) {
				if ( empty( $role->capabilities[ $cap ] ) ) $gaps[] = $cap;
			}
			if ( $gaps ) $out[ $slug ] = $gaps;
		}
		return $out;
	}

	private static function grant_capabilities() {
		if ( ! get_role( self::CLIENT_ROLE ) ) {
			add_role( self::CLIENT_ROLE, 'Client', array(
				'read'                => true,
				'level_0'             => true,
				'bftd_view_dashboard' => true,
			) );
		}

		// Roles are updated in place rather than removed and re-added.
		//
		// remove_role() deletes the role outright, and every user holding it
		// loses every capability until add_role() runs again a few lines
		// later. If anything interrupts the request in between — a fatal, a
		// timeout, a plugin conflict — the role is simply gone and the people
		// in it can no longer do anything at all. Updating in place has no
		// such window: at every instant the role exists with a complete,
		// coherent capability set.
		$expected = self::expected_caps();
		$labels   = self::role_labels();

		foreach ( $labels as $slug => $label ) {
			self::sync_role( $slug, $label, $expected[ $slug ] );
		}

		// The administrator role is WordPress's own. Ours are reconciled, so a
		// capability we no longer hand out is taken back; this one is only ever
		// added to, because what else lives on it is not our business.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $expected['administrator'] as $cap ) {
				if ( empty( $admin->capabilities[ $cap ] ) ) $admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Create the role if it is missing, otherwise bring its capabilities into
	 * line — adding what it should have and removing only the ones we own and
	 * it should not. Capabilities granted by anything else are left alone,
	 * because this is our role but not necessarily our only concern with it.
	 */
	private static function sync_role( $slug, $label, $caps ) {
		$caps = array_values( array_unique( $caps ) );

		$role = get_role( $slug );
		if ( ! $role ) {
			add_role( $slug, $label, array_fill_keys( $caps, true ) );
			return;
		}

		foreach ( $caps as $cap ) {
			if ( empty( $role->capabilities[ $cap ] ) ) $role->add_cap( $cap );
		}

		// Only ever take back capabilities this plugin hands out, so a
		// capability another plugin added to our role survives.
		$ours = array_merge(
			array( self::STAFF_CAP, self::MANAGE_CAP, self::TEAM_CAP, 'bftd_view_dashboard' ),
			self::all_caps( true )
		);
		foreach ( array_diff( $ours, $caps ) as $cap ) {
			if ( ! empty( $role->capabilities[ $cap ] ) ) $role->remove_cap( $cap );
		}
	}

	public static function is_client( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return in_array( self::CLIENT_ROLE, (array) $user->roles, true );
	}

	/** Anyone on the teaching side: tutor, manager or senior manager. */
	public static function is_tutor( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		$rank = self::rank( $user );
		return $rank >= self::role_rank( self::TUTOR_ROLE ) && $rank < self::role_rank( 'administrator' );
	}

	public static function is_manager_role( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return in_array( self::MANAGER_ROLE, (array) $user->roles, true );
	}

	public static function is_senior_role( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return in_array( self::SENIOR_ROLE, (array) $user->roles, true );
	}

	/**
	 * Can this person run the practice — see every student, assign tutors,
	 * manage accounts, change the settings and the email wording, and read
	 * the whole activity log? Managers and administrators.
	 *
	 * Falls back to manage_options so a real Administrator is never locked
	 * out in the one page load before maybe_heal_capabilities() has run.
	 */
	public static function can_manage( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, self::MANAGE_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * Can this person run the practice: settings, email wording, the activity
	 * log, and the libraries? Senior managers and administrators.
	 */
	public static function can_administer( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, self::ADMIN_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/** Can this person add and remove managers? Senior managers and admins. */
	public static function can_manage_team( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, self::TEAM_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * Can this person destroy a record for good? Senior managers and admins.
	 *
	 * Trashing and erasing are different acts. A tutor manager tidying a
	 * mistyped lesson or a duplicate report puts it in the trash, which is
	 * reversible and is what the role is for. Emptying the trash is not:
	 * a diagnostic is a child's assessment history and the sessions, notes
	 * and figures hanging off it do not come back.
	 */
	public static function can_erase( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, self::ERASE_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/**
	 * Refuse the irreversible delete to anyone below a senior manager.
	 *
	 * WordPress has one capability for both acts, delete_post, and decides
	 * which one is happening from the record's status: a published record
	 * goes to the trash, one already in the trash is erased. So the rule is
	 * written the same way, on the status rather than on the request, and it
	 * therefore holds on the Delete Permanently link, the bulk action, Empty
	 * Trash and any wp_delete_post a future screen calls.
	 */
	public static function guard_permanent_delete( $caps, $cap, $user_id, $args ) {
		if ( 'delete_post' !== $cap && 'delete_page' !== $cap ) return $caps;
		if ( empty( $args[0] ) ) return $caps;

		$post = get_post( (int) $args[0] );
		if ( ! $post ) return $caps;
		if ( ! in_array( $post->post_type, self::post_types(), true ) ) return $caps;
		if ( 'trash' !== $post->post_status ) return $caps;   // trashing is still theirs
		if ( self::can_erase( $user_id ) ) return $caps;

		return array( 'do_not_allow' );
	}

	/**
	 * And take the offer off the screen, rather than letting somebody pick it
	 * and be refused. A bulk action that always fails is a worse answer than
	 * one that is not listed.
	 */
	public static function strip_delete_bulk_action( $actions ) {
		if ( self::can_erase() ) return $actions;
		unset( $actions['delete'] );
		return $actions;
	}

	/**
	 * Administrator or Tutor. Falls back to core's own manage_options check
	 * so a real Administrator is never mistaken for a client in the one page
	 * load before maybe_heal_capabilities() has re-granted the staff cap.
	 */
	public static function is_staff( $user_id = null ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return user_can( $user_id, self::STAFF_CAP ) || user_can( $user_id, 'manage_options' );
	}

	/** What to call this person's role in a list, in plain words. */
	public static function role_name( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) return 'Unknown';
		if ( user_can( $user_id, 'manage_options' ) ) return 'Administrator';

		$rank  = self::rank( $user );
		$tiers = self::tiers();

		if ( $rank === self::role_rank( self::CLIENT_ROLE ) ) return self::relationship( $user_id );

		foreach ( $tiers as $slug => $tier ) {
			if ( $tier['rank'] === $rank ) return $tier['label'];
		}
		return 'No access';
	}

	public static function relationship( $user_id ) {
		$rel = get_user_meta( $user_id, self::REL_META, true );
		$all = self::relationships();
		return isset( $all[ $rel ] ) ? $all[ $rel ] : 'Parent or guardian';
	}

	/**
	 * Only on a family's profile. A tutor is not a parent or guardian of
	 * anybody, and asking staff for their "relationship to the student" is
	 * confusing enough to look like a fault.
	 */
	public static function relationship_field( $user ) {
		if ( ! self::is_staff() ) return;
		if ( ! self::is_client( $user ) ) return;
		$current = get_user_meta( $user->ID, self::REL_META, true );
		?>
		<h2>Brilliant Futures</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="bftd_relationship">Relationship to the student</label></th>
				<td>
					<select name="bftd_relationship" id="bftd_relationship">
						<?php foreach ( self::relationships() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $current, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Shown beside this person's name on the student record and in conversations.</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_relationship( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) return;
		if ( ! isset( $_POST['bftd_relationship'] ) ) return;
		$rel = sanitize_key( wp_unslash( $_POST['bftd_relationship'] ) );
		if ( ! array_key_exists( $rel, self::relationships() ) ) return;
		update_user_meta( $user_id, self::REL_META, $rel );
	}

	/**
	 * The whole user-management model: you may act on a role strictly below
	 * your own rank, never at or above it.
	 *
	 * Editing and handing out are both covered, because they are the same
	 * risk. Being able to edit an account of your own tier means being able to
	 * change its email address and take it over, which is a sideways route
	 * into a tier you are not allowed to hand out — so "cannot promote to
	 * senior manager" would mean nothing on its own if you could simply edit
	 * an existing one.
	 *
	 * Administrators are exempt, so WordPress's own behaviour is untouched.
	 */
	public static function protect_admin_accounts( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'edit_user', 'delete_user', 'remove_user', 'promote_user' ), true ) ) return $caps;
		if ( user_can( $user_id, 'manage_options' ) ) return $caps;

		// Handing out a role is refused before the target is even looked at,
		// so this also covers creating a brand new account with that role.
		if ( 'promote_user' === $cap && isset( $args[1] ) ) {
			if ( ! self::outranks( $user_id, self::role_rank( (string) $args[1] ) ) ) {
				$caps[] = 'do_not_allow';
				return $caps;
			}
		}

		if ( empty( $args[0] ) ) return $caps;

		$target_id = (int) $args[0];
		if ( $target_id === (int) $user_id ) return $caps; // always your own profile

		$target = get_userdata( $target_id );
		if ( ! $target ) return $caps;

		if ( ! self::outranks( $user_id, self::rank( $target ) ) ) {
			$caps[] = 'do_not_allow';
		}

		return $caps;
	}

	/**
	 * The role dropdown offers only what this person may actually hand out.
	 * Roles from other plugins, which have no rank here, are left alone for
	 * administrators and hidden from everyone else.
	 */
	public static function hide_administrator_role( $roles ) {
		if ( current_user_can( 'manage_options' ) ) return $roles;

		$actor = get_current_user_id();
		foreach ( array_keys( $roles ) as $slug ) {
			if ( ! self::outranks( $actor, self::role_rank( $slug ) ) ) {
				unset( $roles[ $slug ] );
			}
		}
		return $roles;
	}
}
