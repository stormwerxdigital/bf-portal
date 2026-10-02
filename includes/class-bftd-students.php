<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The students screen.
 *
 * WordPress gives a post type a list table, and for a library of activities
 * that is the right shape: a flat list of things, sorted, twenty at a time.
 * A tutoring practice is not that. It is people, held by other people, and
 * the questions asked of this screen are about the holding: who is Rae's,
 * who is paying for this child, who has sessions left, who has quietly gone
 * quiet. A flat list of a thousand names answers none of them, and a
 * thousand names is where this is going.
 *
 * So the screen is a section per tutor. That is the unit of the practice: a
 * tutor opens it and reads their own list, a manager reads down the sections
 * and sees the shape of the caseload. A child with two tutors appears under
 * both, because they belong to both.
 *
 * EVERY FIGURE ON IT IS BATCHED. Read one student at a time, the sessions
 * remaining figure alone is four queries a row, and four thousand queries is
 * a page that never finishes. The counts come back in one query, the meta in
 * one, the people in one, so the page costs the same whether it is showing
 * twelve students or two hundred.
 */
class BFTD_Students {

	const SLUG = 'bftd-students';

	/** How many of a tutor's students the overview shows before it stops. */
	const PREVIEW = 25;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
	}

	/**
	 * In place of the WordPress list, not beside it.
	 *
	 * Registered at the index the post type's own row occupies, and then that
	 * row is removed, so Students stays exactly where it was in the menu. A
	 * plain add_submenu_page would put it at the bottom under Settings, which
	 * is not where anybody looks for students.
	 *
	 * The WordPress list is still there and still reachable; it is linked
	 * from this screen. Nothing routine sends anybody to it.
	 */
	public static function menu() {
		global $submenu;

		$parent = BFTD_Admin::MENU_SLUG;
		$old    = 'edit.php?post_type=' . BFTD_CPT::STUDENT;
		$at     = null;

		if ( isset( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $i => $row ) {
				if ( isset( $row[2] ) && $old === $row[2] ) { $at = $i; break; }
			}
		}

		add_submenu_page(
			$parent,
			'Students',
			'Students',
			BFTD_Roles::STAFF_CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			$at
		);
		// Spelled out rather than passed in a variable: a rule elsewhere
		// reads these calls to check that no post-new row is ever taken back,
		// and it can only read what is written here.
		if ( null !== $at ) remove_submenu_page( $parent, 'edit.php?post_type=' . BFTD_CPT::STUDENT );
	}

	/* ------------------------------------------------------------------ */
	/* What is being asked for                                             */
	/* ------------------------------------------------------------------ */

	/** Shared with the sessions screen, which asks the same four questions. */
	public static function filters() {
		return array(
			'q'      => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
			'tutor'  => isset( $_GET['tutor'] ) ? (int) $_GET['tutor'] : 0,
			'client' => isset( $_GET['client'] ) ? (int) $_GET['client'] : 0,
			'status' => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			// Which part of the programme. A student is on one track at a
			// time, so this narrows to the children working through it.
			'track'  => isset( $_GET['track'] ) ? sanitize_key( wp_unslash( $_GET['track'] ) ) : '',
		);
	}

	/**
	 * Students on one track.
	 *
	 * A student with no track stored is on no track, not on Track 1. The
	 * library defaults an untracked SKILL to Track 1 because every skill
	 * written before tracks existed is a Track 1 skill. A student is the other
	 * way round: an untracked one is a child nobody has placed yet, and
	 * sweeping them into Track 1 would answer a question about them that
	 * nobody has answered.
	 */
	public static function students_on_track( $track ) {
		if ( ! class_exists( 'BFTD_Activities' ) || ! isset( BFTD_Activities::tracks()[ $track ] ) ) return null;
		return get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array( array(
				'key'   => BFTD_Schema::meta_key( 'student', 'track' ),
				'value' => $track,
			) ),
		) );
	}

	/**
	 * Which students this screen is about.
	 *
	 * Each filter reduces to a set of student ids and they narrow each other,
	 * the same arrangement the sessions list uses, so three filters can never
	 * contradict one another into showing everything.
	 */
	public static function student_ids( $filters ) {
		$only = BFTD_Access::visible_student_ids( get_current_user_id() );
		$only = array_map( 'absint', (array) $only );

		$narrow = function ( $set ) use ( &$only ) {
			$only = array_values( array_intersect( $only, array_map( 'absint', (array) $set ) ) );
		};

		if ( '' !== $filters['q'] ) $narrow( BFTD_CPT::students_matching( $filters['q'] ) );
		if ( ! empty( $filters['track'] ) ) {
			$on = self::students_on_track( $filters['track'] );
			if ( null !== $on ) $narrow( $on );
		}
		if ( $filters['tutor'] )    $narrow( BFTD_CPT::students_of_staff( $filters['tutor'] ) );
		if ( $filters['client'] )   $narrow( BFTD_CPT::students_of_client( $filters['client'] ) );

		if ( ! $only ) return array();

		// One query for the records, which also primes the post cache so
		// every get_the_title below is free.
		$posts = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'post__in'       => $only,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$ids = wp_list_pluck( $posts, 'ID' );
		if ( $ids && function_exists( 'update_meta_cache' ) ) update_meta_cache( 'post', $ids );

		if ( '' !== $filters['status'] ) {
			$keep = array();
			foreach ( $ids as $id ) {
				if ( $filters['status'] === (string) BFTD_Fields::get( $id, 'student', 'status' ) ) $keep[] = $id;
			}
			$ids = $keep;
		}

		return array_map( 'absint', $ids );
	}

	/**
	 * One row per student, with every figure on it already worked out.
	 *
	 * Built for the whole screen at once rather than per row: the people are
	 * fetched in one go and the sessions counted in one, so the cost of this
	 * does not grow with the number of rows.
	 */
	public static function rows( $ids ) {
		if ( ! $ids ) return array();

		$used = BFTD_Schedule::used_counts( $ids );

		// Every account named on any of them, primed in one query, so the
		// names below cost nothing.
		$people = array();
		$meta   = array();
		foreach ( $ids as $id ) {
			$m = array(
				'client'  => BFTD_CPT::primary_client_id( $id ),
				'care'    => BFTD_CPT::client_ids( $id ),
				'tutors'  => BFTD_CPT::staff_ids( $id ),
			);
			$meta[ $id ] = $m;
			if ( $m['client'] ) $people[ $m['client'] ] = true;
			foreach ( $m['care'] as $u ) $people[ $u ] = true;
			foreach ( $m['tutors'] as $u ) $people[ $u ] = true;
		}
		if ( $people && function_exists( 'cache_users' ) ) cache_users( array_keys( $people ) );

		$name = function ( $uid ) {
			$u = get_userdata( (int) $uid );
			return $u ? $u->display_name : '';
		};

		$out = array();
		foreach ( $ids as $id ) {
			$m         = $meta[ $id ];
			$purchased = (int) BFTD_Fields::get( $id, 'student', 'lessons_bank' );
			$offset    = (int) get_post_meta( $id, BFTD_Schedule::OFFSET_KEY, true );
			$spent     = ( isset( $used[ $id ] ) ? (int) $used[ $id ] : 0 ) + $offset;

			/*
			 * EVERYONE WITH ACCESS, the client among them.
			 *
			 * This column used to leave the client out, on the reasoning
			 * that they were named in the column beside it. That was a
			 * column answering the wrong question: it says who can see this
			 * child's portal, and a family where the client is the only
			 * caregiver read as a dash — nobody can see it — which is the
			 * opposite of true, and true of most families here.
			 *
			 * The one who is also the client is marked, so the repetition
			 * reads as deliberate rather than as the same name twice by
			 * accident.
			 */
			$care = array();
			foreach ( $m['care'] as $uid ) {
				$n = $name( $uid );
				if ( '' === $n ) continue;
				$care[] = array(
					'name'   => $n,
					'client' => ( (int) $uid === (int) $m['client'] ),
				);
			}
			$tutors = array();
			foreach ( $m['tutors'] as $uid ) {
				$n = $name( $uid );
				if ( '' !== $n ) $tutors[ (int) $uid ] = $n;
			}

			$out[ $id ] = array(
				'id'        => $id,
				'name'      => get_the_title( $id ),
				'client'    => $m['client'] ? $name( $m['client'] ) : '',
				'client_id' => (int) $m['client'],
				'care'      => $care,
				'tutors'    => $tutors,
				'status'    => (string) BFTD_Fields::get( $id, 'student', 'status' ),
				'purchased' => $purchased,
				'used'      => $spent,
				'left'      => max( 0, $purchased - $spent ),
				'draft'     => ( 'publish' !== get_post_status( $id ) ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The screen                                                          */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! BFTD_Roles::is_staff() ) return;

		$f      = self::filters();
		$ids    = self::student_ids( $f );
		$rows   = self::rows( $ids );
		$groups = self::by_tutor( $rows );
		$narrow = ( '' !== $f['q'] || $f['tutor'] || $f['client'] || '' !== $f['status'] );
		?>
		<div class="wrap bftd-wrap bftd-stu">
			<h1 class="wp-heading-inline">Students</h1>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::STUDENT ) ); ?>" class="page-title-action">Add Student</a>
			<hr class="wp-header-end">

			<?php self::filter_bar( $f, count( $rows ), self::SLUG ); ?>

			<?php if ( ! $rows ) : ?>
				<div class="bftd-stu-empty">
					<p><strong><?php echo $narrow ? 'Nobody matches that.' : 'No students yet.'; ?></strong></p>
					<p class="description"><?php echo $narrow
						? 'The search looks at a student\'s name, and at the name or email of their client, their caregivers and their tutors.'
						: 'Add one and it will appear here, under whoever is assigned to them.'; ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $groups as $uid => $group ) self::section( $uid, $group, $f ); ?>
			<?php endif; ?>

			<p class="bftd-stu-foot description">
				The plain WordPress list is still there if you want it:
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::STUDENT ) ); ?>">all students, one flat list</a>.
			</p>
		</div>
		<?php
	}

	/**
	 * @param string $slug        Which screen this bar belongs to, so its own
	 *                            filters come back to it.
	 * @param bool   $with_status Whether the student-status select is offered.
	 *                            The sessions screen is about what happened,
	 *                            not about where a family is in the pipeline.
	 */
	public static function filter_bar( $f, $showing, $slug = self::SLUG, $with_status = true ) {
		$mine    = BFTD_Access::visible_student_ids( get_current_user_id() );
		$tutors  = BFTD_CPT::staffed_tutor_ids( $mine );
		$clients = BFTD_CPT::client_ids_in_use( $mine );
		?>
		<form class="bftd-stu-bar" method="get">
			<input type="hidden" name="page" value="<?php echo esc_attr( $slug ); ?>">

			<div class="bftd-stu-find">
				<label class="screen-reader-text" for="bftd-stu-q">Search students</label>
				<input type="search" id="bftd-stu-q" name="q" value="<?php echo esc_attr( $f['q'] ); ?>"
					placeholder="Student, client, caregiver or tutor. Name or email.">
			</div>

			<?php
			self::people_select( 'tutor', 'Every tutor', $tutors, $f['tutor'] );
			self::people_select( 'client', 'Every client', $clients, $f['client'] );
			?>

			<?php
			// The track sits with the other narrowing selects rather than in a
			// row of its own, because it is the same kind of question.
			if ( class_exists( 'BFTD_Activities' ) ) :
				$tracks = BFTD_Activities::tracks();
				if ( $tracks ) :
				?>
				<select name="track" aria-label="Track">
					<option value="">Any track</option>
					<?php foreach ( $tracks as $k => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $k ),
							selected( $f['track'] ?? '', $k, false ), esc_html( $label ) );
					} ?>
				</select>
				<?php endif;
			endif; ?>

			<?php if ( $with_status ) : ?>
				<select name="status" aria-label="Status">
					<?php
					$states = array( '' => 'Any status', 'prospect' => 'Prospects', 'active' => 'Active', 'past' => 'Past students' );
					foreach ( $states as $k => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $f['status'], $k, false ), esc_html( $label ) );
					}
					?>
				</select>
			<?php endif; ?>

			<button type="submit" class="button">Filter</button>
			<?php if ( '' !== $f['q'] || $f['tutor'] || $f['client'] || '' !== $f['status'] ) : ?>
				<a class="button-link bftd-stu-clear" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>">Clear</a>
			<?php endif; ?>

			<span class="bftd-stu-count"><?php
				echo esc_html( $showing . ' ' . ( 1 === $showing ? 'student' : 'students' ) );
			?></span>
		</form>
		<?php
	}

	private static function people_select( $name, $all_label, $ids, $now ) {
		if ( ! $ids ) return;
		echo '<select name="' . esc_attr( $name ) . '" aria-label="' . esc_attr( $all_label ) . '">';
		echo '<option value="">' . esc_html( $all_label ) . '</option>';
		foreach ( $ids as $uid ) {
			$u = get_userdata( $uid );
			if ( ! $u ) continue;
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $uid,
				selected( (int) $now, (int) $uid, false ),
				esc_html( $u->display_name )
			);
		}
		echo '</select>';
	}

	/**
	 * One tutor's caseload.
	 *
	 * Capped at PREVIEW on the overview, because a manager reading down five
	 * tutors does not want four hundred names between one heading and the
	 * next. The rest is one click: the same screen filtered to that tutor,
	 * which is a page a person can link to and come back to.
	 */
	private static function section( $uid, $group, $f ) {
		$rows  = isset( $group['rows'] ) ? $group['rows'] : array();
		$total = count( $rows );
		$full  = ( $f['tutor'] && (int) $f['tutor'] === (int) $uid ) || $total <= self::PREVIEW;
		$shown = $full ? $rows : array_slice( $rows, 0, self::PREVIEW );
		?>
		<section class="bftd-stu-sec<?php echo $uid ? '' : ' is-none'; ?>">
			<header class="bftd-stu-h">
				<h2><?php echo esc_html( $group['name'] ); ?></h2>
				<span class="bftd-stu-n"><?php echo esc_html( $total . ' ' . ( 1 === $total ? 'student' : 'students' ) ); ?></span>
				<?php if ( $uid ) : ?>
					<a class="bftd-stu-only" href="<?php echo esc_url( add_query_arg(
						array( 'page' => self::SLUG, 'tutor' => (int) $uid ),
						admin_url( 'admin.php' )
					) ); ?>">Only this tutor</a>
				<?php endif; ?>
			</header>

			<table class="bftd-stu-t">
				<thead>
					<tr>
						<th>Student</th>
						<th>Client</th>
						<th>Caregivers</th>
						<th>Tutors</th>
						<th class="num">Sessions left</th>
						<th>Status</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $row ) self::row( $row ); ?>
				</tbody>
			</table>

			<?php if ( ! $full ) : ?>
				<p class="bftd-stu-more">
					<a href="<?php echo esc_url( add_query_arg(
						array( 'page' => self::SLUG, 'tutor' => (int) $uid ),
						admin_url( 'admin.php' )
					) ); ?>">Show all <?php echo (int) $total; ?></a>
				</p>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function row( $row ) {
		$hub = admin_url( 'admin.php?page=' . BFTD_Admin::HUB_SLUG . '&student=' . (int) $row['id'] );
		?>
		<tr>
			<td class="bftd-stu-name">
				<a href="<?php echo esc_url( $hub ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
				<?php if ( $row['draft'] ) : ?><span class="bftd-stu-draft">Draft</span><?php endif; ?>
				<span class="bftd-stu-acts">
					<a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>">Edit</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::SESSION . '&student=' . (int) $row['id'] ) ); ?>">Record a session</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::SESSION . '&bftd_student=' . (int) $row['id'] ) ); ?>">Sessions</a>
				</span>
			</td>
			<td data-l="Client"><?php echo $row['client']
				? esc_html( $row['client'] )
				: '<span class="bftd-none">Nobody yet</span>'; ?></td>
			<td data-l="Caregivers"><?php self::care_cell( $row['care'] ); ?></td>
			<td data-l="Tutors"><?php echo $row['tutors']
				? esc_html( implode( ', ', $row['tutors'] ) )
				: '<span class="bftd-none">Unassigned</span>'; ?></td>
			<td class="num" data-l="Sessions left"><?php self::bank_cell( $row ); ?></td>
			<td data-l="Status"><?php self::status_cell( $row['status'] ); ?></td>
		</tr>
		<?php
	}

	/** Who can open this child's portal, with the client marked among them. */
	private static function care_cell( $care ) {
		if ( ! $care ) {
			echo '<span class="bftd-none">Nobody yet</span>';
			return;
		}
		$out = array();
		foreach ( $care as $one ) {
			$out[] = esc_html( $one['name'] )
				. ( $one['client'] ? ' <span class="bftd-is-client">client</span>' : '' );
		}
		echo wp_kses_post( implode( ', ', $out ) );
	}

	/**
	 * Sessions left, and the one number under it that says how much of the
	 * block has gone. Nothing purchased says so rather than showing a zero,
	 * which reads as a family who has run out.
	 */
	private static function bank_cell( $row ) {
		if ( ! $row['purchased'] ) {
			echo '<span class="bftd-none">None bought</span>';
			return;
		}
		$low = ( $row['left'] <= 2 );
		echo '<b class="bftd-stu-left' . ( $low ? ' is-low' : '' ) . '">' . (int) $row['left'] . '</b>';
		echo '<span class="bftd-stu-of">/ ' . (int) $row['purchased'] . '</span>';
	}

	private static function status_cell( $status ) {
		$words = array( 'prospect' => 'Prospect', 'active' => 'Active', 'past' => 'Past student' );
		if ( ! isset( $words[ $status ] ) ) {
			echo '<span class="bftd-none">Not set</span>';
			return;
		}
		echo '<span class="bftd-pill is-' . esc_attr( $status ) . '">' . esc_html( $words[ $status ] ) . '</span>';
	}

	/** Rows arranged into the sections the screen is made of. */
	public static function by_tutor( $rows ) {
		$groups = array();
		foreach ( $rows as $row ) {
			if ( ! $row['tutors'] ) {
				$groups[0]['name'] = 'Nobody assigned';
				$groups[0]['rows'][] = $row;
				continue;
			}
			// A child with two tutors is in both sections, because they
			// belong to both and either tutor reading their own list has to
			// find them there.
			foreach ( $row['tutors'] as $uid => $tname ) {
				$groups[ $uid ]['name'] = $tname;
				$groups[ $uid ]['rows'][] = $row;
			}
		}

		// Named tutors by name, and nobody-assigned last: it is a list of
		// things to fix rather than somebody's caseload.
		$unassigned = isset( $groups[0] ) ? array( 0 => $groups[0] ) : array();
		unset( $groups[0] );
		uasort( $groups, function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );

		return $groups + $unassigned;
	}
}
