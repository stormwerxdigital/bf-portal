<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Which tutor can see which student.
 *
 * A tutor sees the students they are assigned to, plus anything they created
 * themselves — so creating a student never locks its creator out before the
 * assignment is saved. Administrators are never restricted.
 *
 * Records that hang off a student (reports, lessons) inherit the student's
 * restriction rather than carrying their own, so there is one rule to reason
 * about and no way for the two to disagree.
 */
class BFTD_Access {

	public static function init() {
		add_filter( 'map_meta_cap', array( __CLASS__, 'restrict_tutor' ), 10, 4 );
		add_action( 'pre_get_posts', array( __CLASS__, 'restrict_lists' ) );
	}

	/**
	 * Reports and lessons carry their own assignment list, separate from the
	 * student's.
	 *
	 * A student's list decides who a family's message reaches. A report's list
	 * decides who may read that particular piece of work — which is a
	 * different question, and a narrower one. A tutor who covered a term for
	 * someone should not automatically gain the diagnostic another tutor wrote
	 * two years earlier just because they are both attached to the child.
	 *
	 * Whoever creates a report is assigned to it at once, so nobody is ever
	 * locked out of something they just made.
	 */
	public static function report_types() {
		return array( BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS, BFTD_CPT::SESSION );
	}

	public static function can_staff_view( $post_id, $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		// Managers and administrators see everything, always.
		if ( BFTD_Roles::can_manage( $user_id ) ) return true;
		if ( ! BFTD_Roles::is_tutor( get_userdata( $user_id ) ) ) return false;

		$type = get_post_type( $post_id );

		if ( in_array( $type, self::report_types(), true ) ) {
			if ( (int) get_post_field( 'post_author', $post_id ) === $user_id ) return true;
			return in_array( $user_id, BFTD_CPT::staff_ids( $post_id ), true );
		}

		$student_id = BFTD_CPT::student_id( $post_id );
		if ( ! $student_id ) return true; // the shared library belongs to everyone

		if ( (int) get_post_field( 'post_author', $student_id ) === $user_id ) return true;
		return in_array( $user_id, BFTD_CPT::staff_ids( $student_id ), true );
	}

	/** Every report of a given type this tutor wrote or was assigned to. */
	public static function visible_report_ids( $user_id, $post_type ) {
		$assigned = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => BFTD_CPT::STAFF_KEY, 'value' => (int) $user_id ) ),
		) );
		$authored = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'author'         => (int) $user_id,
		) );
		return array_values( array_unique( array_merge( $assigned, $authored ) ) );
	}

	/**
	 * Who to tell when something happens on a student. The assigned tutors,
	 * and if nobody is assigned yet, every administrator — a family's message
	 * must never fall into a gap because the record was set up but not staffed.
	 */
	public static function notify_staff_ids( $student_id ) {
		$ids = BFTD_CPT::staff_ids( $student_id );
		if ( $ids ) return $ids;

		// Nobody assigned yet. Where an unstaffed student's messages go is a
		// setting, because the right answer differs between a one-tutor
		// practice and one with an office. Whatever it is, the message still
		// lands in Conversations with a count on the menu, so the choice here
		// is about email volume, never about whether anyone finds out.
		$fallback = class_exists( 'BFTD_Settings' ) ? BFTD_Settings::routing()['fallback'] : 'admins';
		if ( 'admins' !== $fallback ) return array();

		$fallback_users = get_users( array(
			'role__in' => array( 'administrator', BFTD_Roles::SENIOR_ROLE, BFTD_Roles::MANAGER_ROLE ),
			'fields'   => 'ID',
		) );
		return array_map( 'absint', $fallback_users );
	}

	/**
	 * The capability names this filter has to watch for.
	 *
	 * WordPress calls the `map_meta_cap` filter with its OWN meta capability
	 * names — `edit_post`, `read_post`, `delete_post` — never with the ones a
	 * post type declares. A post type's `edit_bftd_student` is the name core
	 * resolves away before the filter runs, so a filter watching for it is
	 * never called and silently restricts nobody.
	 *
	 * That is exactly what happened here. The names below were
	 * `edit_bftd_student` and friends, the test harness called the filter with
	 * those names because that is what the filter was looking for, the matrix
	 * printed a tidy "NO" for an unassigned tutor, and in production any tutor
	 * could open any student. Both names are matched now: core's, which is
	 * what actually arrives, and the declared ones, which arrive when our own
	 * code asks with a post type's capability name.
	 */
	private static function watched_caps() {
		$caps = array( 'edit_post', 'read_post', 'delete_post' );
		foreach ( BFTD_Roles::post_types() as $pt ) {
			$caps[] = 'edit_' . $pt;
			$caps[] = 'read_' . $pt;
			$caps[] = 'delete_' . $pt;
		}
		return $caps;
	}

	public static function restrict_tutor( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, self::watched_caps(), true ) ) return $caps;
		if ( empty( $args[0] ) ) return $caps;

		$post_id = (int) $args[0];

		// `edit_post` arrives for every post type on the site, so the post has
		// to be one of ours before anything here applies. Without this a
		// tutor would lose the ability to edit their own profile's media.
		if ( ! in_array( get_post_type( $post_id ), BFTD_Roles::post_types(), true ) ) return $caps;

		if ( BFTD_Roles::can_manage( $user_id ) ) return $caps;
		if ( ! BFTD_Roles::is_tutor( get_userdata( $user_id ) ) ) return $caps;

		if ( (int) get_post_field( 'post_author', $post_id ) === (int) $user_id ) return $caps;
		if ( ! self::can_staff_view( $post_id, $user_id ) ) $caps[] = 'do_not_allow';
		return $caps;
	}

	/**
	 * Every student this staff member may work with. A manager gets all of
	 * them; a tutor gets the ones assigned to them plus anything they created,
	 * so setting up a student never locks its creator out before the
	 * assignment is saved.
	 */
	public static function visible_student_ids( $user_id ) {
		if ( BFTD_Roles::can_manage( $user_id ) ) {
			return get_posts( array(
				'post_type'      => BFTD_CPT::STUDENT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			) );
		}

		$assigned = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array( 'key' => BFTD_CPT::STAFF_KEY, 'value' => (int) $user_id ) ),
		) );
		$authored = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'author'         => (int) $user_id,
		) );
		return array_values( array_unique( array_merge( $assigned, $authored ) ) );
	}

	public static function restrict_lists( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;

		$pt = $query->get( 'post_type' );
		if ( ! in_array( $pt, array( BFTD_CPT::STUDENT, BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS, BFTD_CPT::SESSION ), true ) ) return;

		$user_id = get_current_user_id();
		if ( ! $user_id || BFTD_Roles::can_manage( $user_id ) ) return;
		if ( ! BFTD_Roles::is_tutor() ) return;

		$students = self::visible_student_ids( $user_id );

		if ( BFTD_CPT::STUDENT === $pt ) {
			// An empty post__in means "no filter" to WP_Query, so force a
			// no-match rather than accidentally showing everything.
			$query->set( 'post__in', $students ? $students : array( 0 ) );
			return;
		}

		// Reports and lessons list by their own assignment, not the student's.
		$ids = self::visible_report_ids( $user_id, $pt );
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
	}
}
