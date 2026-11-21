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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'hide_list_row' ), 0 );
		add_action( 'admin_post_bftd_trash_student', array( __CLASS__, 'trash_request' ) );
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
	}

	/**
	 * The WordPress list's own row comes out of the menu here, not in
	 * menu(). WordPress decides who may open edit.php?post_type=... by
	 * finding that row in the menu; with the row already gone it judges the
	 * page against Posts instead, which staff cannot see, and the list (the
	 * advanced view, and every link to it) refused everyone but an
	 * administrator. admin_enqueue_scripts runs after that check and before
	 * the sidebar or the command palette reads the menu.
	 */
	public static function hide_list_row() {
		// Spelled out rather than passed in a variable: a rule elsewhere reads
		// these calls to check that no post-new row is ever taken back.
		remove_submenu_page( BFTD_Admin::MENU_SLUG, 'edit.php?post_type=' . BFTD_CPT::STUDENT );
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
		);
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
	/**
	 * $drafts counts each student's unpublished sessions. The Sessions screen
	 * passes false, because it counts every session itself and asking twice
	 * would be the same query twice.
	 */
	public static function rows( $ids, $drafts = true ) {
		if ( ! $ids ) return array();

		$used = BFTD_Schedule::used_counts( $ids );

		// Sessions written up but not published, counted in the same batch
		// the Sessions screen uses. A draft reaches no family and pays no
		// tutor, and a student marked Active with six of them waiting looked
		// exactly like one with none.
		$records = ( $drafts && class_exists( 'BFTD_Sessions' ) ) ? BFTD_Sessions::records( $ids ) : array();

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
				'record'    => (string) get_post_status( $id ),
				'draft'     => ( 'publish' !== get_post_status( $id ) ),
				'drafts'    => isset( $records[ $id ] ) ? (int) $records[ $id ]['draft'] : 0,
				// Its GoHighLevel record, or '' (1.178).
				'crm'       => class_exists( 'BFTD_CRM' ) ? BFTD_CRM::linked( $id ) : '',
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

			<?php if ( isset( $_GET['trashed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification -- a message only ?>
				<div class="notice notice-success is-dismissible"><p><?php
					echo esc_html( sanitize_text_field( wp_unslash( $_GET['trashed'] ) ) . ' was moved to the trash.' ); // phpcs:ignore WordPress.Security.NonceVerification
					?> <a href="<?php echo esc_url( admin_url( 'edit.php?post_status=trash&post_type=' . BFTD_CPT::STUDENT ) ); ?>">Open the trash</a></p></div>
			<?php endif; ?>

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

			<?php if ( $with_status ) : ?>
				<select name="status" aria-label="Status">
					<?php
					$states = array( '' => 'Any status', 'prospect' => 'Pending', 'active' => 'Current students', 'past' => 'Past students' );
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

			<?php
			// Scrolls sideways rather than squeezing, so there is room for
			// more columns (Karl, 1.184). Focusable, so it scrolls from the
			// keyboard too.
			?>
			<div class="bftd-stu-scroll" tabindex="0" role="region" aria-label="<?php echo esc_attr( $group['name'] . ', students' ); ?>">
			<table class="bftd-stu-t">
				<thead>
					<tr>
						<th>Student</th>
						<?php if ( self::show_crm() ) : ?><th>Linked</th><?php endif; ?>
						<th>Client</th>
						<th>Caregivers</th>
						<th>Tutors</th>
						<th class="num">Sessions left</th>
						<th>Status</th>
						<th>Record</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $row ) self::row( $row ); ?>
				</tbody>
			</table>
			</div>

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
		$ghl = self::ghl_waiting( $row['id'] );
		?>
		<tr>
			<td class="bftd-stu-name">
				<a href="<?php echo esc_url( $hub ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
				<span class="bftd-stu-acts">
					<a href="<?php echo esc_url( (string) get_edit_post_link( $row['id'] ) ); ?>">Edit</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::SESSION . '&student=' . (int) $row['id'] ) ); ?>">Record a session</a>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::SESSION . '&bftd_student=' . (int) $row['id'] ) ); ?>">Sessions</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=bftd-tutor-notes&student=' . (int) $row['id'] ) ); ?>">Tutor notes</a>
				</span>
			</td>
			<?php if ( self::show_crm() ) : ?>
				<td data-l="Linked"><?php echo self::crm_mark( $row ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in crm_mark ?></td>
			<?php endif; ?>
			<td data-l="Client"><?php echo $row['client']
				? esc_html( $row['client'] )
				: '<span class="bftd-none">Nobody yet</span>'; ?></td>
			<td data-l="Caregivers"><?php self::care_cell( $row['care'] ); echo self::ghl_note( $ghl['care'], $row['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ghl_note ?></td>
			<td data-l="Tutors"><?php echo $row['tutors']
				? esc_html( implode( ', ', $row['tutors'] ) )
				: '<span class="bftd-none">Unassigned</span>';
				echo self::ghl_note( $ghl['tutors'], $row['id'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in ghl_note ?></td>
			<td class="num" data-l="Sessions left"><?php self::bank_cell( $row ); ?></td>
			<td data-l="Status"><?php
				self::status_cell( $row['status'] );
				if ( ! empty( $row['drafts'] ) ) {
					printf(
						'<a class="bftd-stu-drafts" href="%s">%s</a>',
						esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::SESSION . '&post_status=draft&bftd_student=' . (int) $row['id'] ) ),
						esc_html( 1 === (int) $row['drafts'] ? '1 session in draft' : (int) $row['drafts'] . ' sessions in draft' )
					);
				}
			?></td>
			<td data-l="Record"><?php self::record_cell( $row['record'] ); echo self::trash_link( $row ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in trash_link ?></td>
		</tr>
		<?php
	}

	/**
	 * A red trash can on a draft student, for managers and up (1.179).
	 * The trash, not a permanent delete: it can be brought back from there,
	 * and only a senior manager can empty it. A published student has none;
	 * that is a family's record.
	 */
	public static function trash_link( $row ) {
		if ( 'draft' !== (string) $row['record'] ) return '';
		if ( ! BFTD_Roles::can_manage() || ! current_user_can( 'delete_post', (int) $row['id'] ) ) return '';
		$url  = wp_nonce_url( admin_url( 'admin-post.php?action=bftd_trash_student&student=' . (int) $row['id'] ), 'bftd_trash_student_' . (int) $row['id'] );
		$name = '' !== (string) $row['name'] ? (string) $row['name'] : 'this student';
		return sprintf(
			'<a class="bftd-stu-trash" href="%s" title="Delete" aria-label="%s" onclick="return confirm(%s)"><span class="dashicons dashicons-trash" aria-hidden="true"></span></a>',
			esc_url( $url ),
			esc_attr( 'Delete ' . $name . ', a draft student' ),
			esc_attr( wp_json_encode( 'Move ' . $name . ' to the trash? It can be brought back from there.' ) )
		);
	}

	/** The trash can, pressed. Checked again here, not only when drawn. */
	public static function trash_request() {
		$sid = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0;
		check_admin_referer( 'bftd_trash_student_' . $sid );
		$post = $sid ? get_post( $sid ) : null;
		if ( ! $post || BFTD_CPT::STUDENT !== $post->post_type || 'draft' !== $post->post_status
			|| ! BFTD_Roles::can_manage() || ! current_user_can( 'delete_post', $sid ) ) {
			wp_die( 'Only a draft student can be deleted here, by a manager.', 403 );
		}
		$name = get_the_title( $sid );
		if ( ! wp_trash_post( $sid ) ) wp_die( 'That student could not be moved to the trash.', 500 );
		if ( class_exists( 'BFTD_Audit' ) ) {
			BFTD_Audit::log( 'student_trashed', array(
				'post_id'    => $sid,
				'student_id' => $sid,
				'summary'    => 'Draft student ' . ( '' !== $name ? $name : '#' . $sid ) . ' moved to the trash.',
			) );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'trashed' => rawurlencode( '' !== $name ? $name : 'The student' ) ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Whether the Linked column is shown: managers only, and only once
	 * GoHighLevel is connected (1.184).
	 */
	public static function show_crm() {
		return class_exists( 'BFTD_GHL' ) && BFTD_GHL::configured() && BFTD_Roles::can_manage();
	}

	/**
	 * Linked or not linked to GoHighLevel, in its own column (1.184; beside
	 * the name from 1.178). Goes to this student's row on Settings, Link
	 * students.
	 */
	public static function crm_mark( $row ) {
		if ( ! self::show_crm() ) return '';
		$on  = '' !== (string) ( $row['crm'] ?? '' );
		$url = admin_url( 'admin.php?page=' . BFTD_Settings::PAGE_SLUG . '&tab=link' ) . '#bftd-link-' . (int) $row['id'];
		return sprintf(
			'<a class="bftd-stu-crm%s" href="%s" title="%s">%s</a>',
			$on ? ' is-linked' : '',
			esc_url( $url ),
			esc_attr( $on ? 'Linked to GoHighLevel' : 'Not linked to GoHighLevel' ),
			esc_html( $on ? 'Linked' : 'Not linked' )
		);
	}

	/**
	 * GoHighLevel people waiting to be added to this student (1.177): the
	 * caregivers and the tutors, counted apart so each shows in its own
	 * column. Managers only, the same as the link in the student's CRM box;
	 * a tutor reading their caseload has nothing to do with it, and is not
	 * made to wait for the lookups.
	 */
	private static function ghl_waiting( $sid ) {
		$n = array( 'care' => 0, 'tutors' => 0 );
		if ( ! class_exists( 'BFTD_GHL' ) || ! BFTD_Roles::can_manage() ) return $n;
		foreach ( BFTD_GHL::offers_for( $sid ) as $o ) {
			if ( 'assign' === $o['kind'] ) $n['tutors']++;
			else $n['care']++;
		}
		return $n;
	}

	/** "2 to add from GoHighLevel", linked to this student on the Caregivers and tutors tab. */
	public static function ghl_note( $n, $sid ) {
		$n = (int) $n;
		if ( $n < 1 ) return '';
		$url = admin_url( 'admin.php?page=' . BFTD_Settings::PAGE_SLUG . '&tab=family' ) . '#bftd-offers-' . (int) $sid;
		return sprintf(
			'<a class="bftd-stu-ghl" href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $n . ' to add from GoHighLevel' )
		);
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

	/**
	 * Where the student record itself stands, in the same pills the Sessions
	 * screen uses for a session. Status says where the family is; this says
	 * whether the record is finished. A draft student is somebody's entry in
	 * progress, and a family cannot sign in to it.
	 */
	private static function record_cell( $state ) {
		$words = array( 'publish' => 'Published', 'pending' => 'Pending review', 'private' => 'Private', 'future' => 'Scheduled' );
		echo 'publish' === $state
			? '<span class="bftd-pill is-active">Published</span>'
			: '<span class="bftd-pill is-past">' . esc_html( isset( $words[ $state ] ) ? $words[ $state ] : 'Draft' ) . '</span>';
	}

	private static function status_cell( $status ) {
		$words = array( 'prospect' => 'Pending', 'active' => 'Current student', 'past' => 'Past student' );
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
