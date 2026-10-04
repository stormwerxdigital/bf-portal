<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The sessions screen.
 *
 * A flat list of sessions is the wrong shape twice over. Every row repeats
 * the child's name, their client and their tutor, so three columns out of
 * four say the same thing on twenty consecutive rows; and the question a
 * practice actually asks is never "show me all sessions", it is "how is this
 * child getting on" — which the flat list answers by making somebody read
 * down it looking for a name.
 *
 * So the shape follows the practice: a section per tutor, a row per student,
 * and the sessions themselves behind the row. The child's name is written
 * once. What stands in the row instead is the thing a flat list could never
 * show, which is the shape of their attendance.
 *
 * The sessions load when a row is opened, twenty at a time. A practice of
 * four hundred children with a hundred and forty sessions each is fifty-six
 * thousand records, and no screen should try to hold them just in case
 * somebody looks.
 */
class BFTD_Sessions {

	const SLUG     = 'bftd-sessions';
	const PREVIEW  = 25;
	/** Sessions in one page of an opened row. */
	const PER_PAGE = 20;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 21 );
		add_action( 'wp_ajax_bftd_student_sessions', array( __CLASS__, 'ajax_sessions' ) );
		add_action( 'admin_post_bftd_publish_session', array( __CLASS__, 'publish_from_list' ) );
		add_action( 'admin_notices', array( __CLASS__, 'published_notice' ) );
	}

	/** May the current person publish this session from the list? */
	public static function can_publish( $sid ) {
		$type = get_post_type_object( BFTD_CPT::SESSION );
		return $type && current_user_can( 'edit_post', $sid ) && current_user_can( $type->cap->publish_posts );
	}

	public static function publish_url( $sid ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=bftd_publish_session&session=' . (int) $sid ),
			'bftd_publish_session_' . (int) $sid
		);
	}

	/**
	 * Publish a draft session straight from the Sessions screen.
	 *
	 * Through the same checks the session screen's Publish button runs, in
	 * the same order: Taught by filled in from whoever created it, then held
	 * at draft if it duplicates another draft, is a cancellation with no
	 * reason, or still has nobody to pay. A held session opens on its own
	 * screen, where the notice says why; a published one comes back here.
	 */
	public static function publish_from_list() {
		$sid = isset( $_GET['session'] ) ? absint( $_GET['session'] ) : 0;
		check_admin_referer( 'bftd_publish_session_' . $sid );
		if ( ! $sid || BFTD_CPT::SESSION !== get_post_type( $sid ) || ! self::can_publish( $sid ) ) {
			wp_die( 'You cannot publish this session.', 403 );
		}
		$back = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::SLUG );
		if ( 'publish' === get_post_status( $sid ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		BFTD_MetaBoxes::claim_session( $sid );

		// The checks run before anything is published, against the session as
		// it would be once published, so a session that is going to be held
		// never reaches a family, not even for a moment. Each one that holds
		// keeps it a draft and leaves the reason for its own screen to show.
		$as_published = clone get_post( $sid );
		$as_published->post_status = 'publish';
		$held = BFTD_MetaBoxes::hold_duplicate_draft( $sid, $as_published )
			|| BFTD_MetaBoxes::hold_unexplained( $sid, $as_published )
			|| BFTD_MetaBoxes::hold_unclaimed( $sid, $as_published );

		if ( ! $held ) wp_update_post( array( 'ID' => $sid, 'post_status' => 'publish' ) );

		if ( $held || 'publish' !== get_post_status( $sid ) ) {
			wp_safe_redirect( (string) get_edit_post_link( $sid, 'raw' ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( 'bftd_published', $sid, remove_query_arg( 'bftd_published', $back ) ) );
		exit;
	}

	public static function published_notice() {
		if ( empty( $_GET['bftd_published'] ) ) return;
		$sid = absint( $_GET['bftd_published'] );
		if ( ! $sid || 'publish' !== get_post_status( $sid ) ) return;
		$student = BFTD_CPT::student_id( $sid );
		$date    = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
		$when    = '' !== $date ? BFTD_Time::format( 'D j M Y', BFTD_Time::stamp( $date, '12:00' ) ) : '';
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>Published.</strong> %s</p></div>',
			esc_html( trim( 'The session' . ( $student ? ' for ' . get_the_title( $student ) : '' ) . ( $when ? ' on ' . $when : '' ) ) . ' is now on the progress report.' )
		);
	}

	/** In place of the WordPress list, at the index it occupies. */
	public static function menu() {
		global $submenu;

		$parent = BFTD_Admin::MENU_SLUG;
		$old    = 'edit.php?post_type=' . BFTD_CPT::SESSION;
		$at     = null;

		if ( isset( $submenu[ $parent ] ) ) {
			foreach ( $submenu[ $parent ] as $i => $row ) {
				if ( isset( $row[2] ) && $old === $row[2] ) { $at = $i; break; }
			}
		}

		add_submenu_page(
			$parent,
			'Sessions',
			'Sessions',
			BFTD_Roles::STAFF_CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			$at
		);
		// Spelled out rather than passed in a variable: a rule elsewhere reads
		// these calls to check that no post-new row is ever taken back.
		if ( null !== $at ) remove_submenu_page( $parent, 'edit.php?post_type=' . BFTD_CPT::SESSION );
	}

	/* ------------------------------------------------------------------ */
	/* What each student's sessions add up to                              */
	/* ------------------------------------------------------------------ */

	/**
	 * The attendance record, per student, from one fetch.
	 *
	 * Drafts included, because a draft session is one a tutor has started and
	 * not finished, and a management screen that hides them is a screen that
	 * says a child has eleven sessions when twelve have happened. It is
	 * counted separately rather than folded in.
	 */
	public static function records( $student_ids ) {
		$out = array();
		foreach ( (array) $student_ids as $id ) {
			$out[ (int) $id ] = array(
				'held' => 0, 'rescheduled' => 0, 'missed' => 0,
				'draft' => 0, 'total' => 0, 'last' => '',
			);
		}

		foreach ( BFTD_CPT::sessions_of( $student_ids, true ) as $owner => $sessions ) {
			foreach ( $sessions as $sid ) {
				$out[ $owner ]['total']++;

				if ( 'publish' !== get_post_status( $sid ) ) {
					$out[ $owner ]['draft']++;
					continue;   // not yet a record of anything
				}

				$status = (string) BFTD_Fields::get( $sid, 'session', 'status' );
				if ( isset( $out[ $owner ][ $status ] ) ) $out[ $owner ][ $status ]++;

				$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
				if ( $date > $out[ $owner ]['last'] ) $out[ $owner ]['last'] = $date;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The screen                                                          */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! BFTD_Roles::is_staff() ) return;

		$f      = BFTD_Students::filters();
		$ids    = BFTD_Students::student_ids( $f );
		$rows   = self::rows( $ids );
		$groups = BFTD_Students::by_tutor( $rows );
		$narrow = ( '' !== $f['q'] || $f['tutor'] || $f['client'] );
		?>
		<div class="wrap bftd-wrap bftd-stu">
			<h1 class="wp-heading-inline">Sessions</h1>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::SESSION ) ); ?>" class="page-title-action">Record a Session</a>
			<hr class="wp-header-end">

			<?php BFTD_Students::filter_bar( $f, count( $rows ), self::SLUG, false ); ?>

			<?php if ( ! $rows ) : ?>
				<div class="bftd-stu-empty">
					<p><strong><?php echo $narrow ? 'Nobody matches that.' : 'No students yet.'; ?></strong></p>
					<p class="description"><?php echo $narrow
						? 'The search looks at a student\'s name, and at the name or email of their client, their caregivers and their tutors.'
						: 'Sessions are listed under the child they belong to, so a student comes first.'; ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $groups as $uid => $group ) self::section( $uid, $group, $f ); ?>
			<?php endif; ?>

			<p class="bftd-stu-foot description">
				The plain WordPress list is still there if you want it:
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::SESSION ) ); ?>">all sessions, one flat list</a>.
			</p>
		</div>
		<?php
	}

	/** The student rows, with the attendance record already worked out. */
	public static function rows( $ids ) {
		$rows = BFTD_Students::rows( $ids, false );
		if ( ! $rows ) return array();

		$records = self::records( $ids );
		foreach ( $rows as $id => $row ) {
			$rows[ $id ]['record'] = isset( $records[ $id ] ) ? $records[ $id ] : array();
		}
		return $rows;
	}

	private static function section( $uid, $group, $f ) {
		$rows  = isset( $group['rows'] ) ? $group['rows'] : array();
		$total = count( $rows );
		$full  = ( $f['tutor'] && (int) $f['tutor'] === (int) $uid ) || $total <= self::PREVIEW;
		$shown = $full ? $rows : array_slice( $rows, 0, self::PREVIEW );
		$only  = add_query_arg( array( 'page' => self::SLUG, 'tutor' => (int) $uid ), admin_url( 'admin.php' ) );
		?>
		<section class="bftd-stu-sec<?php echo $uid ? '' : ' is-none'; ?>">
			<header class="bftd-stu-h">
				<h2><?php echo esc_html( $group['name'] ); ?></h2>
				<span class="bftd-stu-n"><?php echo esc_html( $total . ' ' . ( 1 === $total ? 'student' : 'students' ) ); ?></span>
				<?php if ( $uid ) : ?>
					<a class="bftd-stu-only" href="<?php echo esc_url( $only ); ?>">Only this tutor</a>
				<?php endif; ?>
			</header>

			<table class="bftd-stu-t bftd-ses-t">
				<thead>
					<tr>
						<th>Student</th>
						<th>Client</th>
						<th>Attendance</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $shown as $row ) self::row( $row ); ?>
				</tbody>
			</table>

			<?php if ( ! $full ) : ?>
				<p class="bftd-stu-more"><a href="<?php echo esc_url( $only ); ?>">Show all <?php echo (int) $total; ?></a></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * One student, and the drawer their sessions arrive in.
	 *
	 * The button ships closed and the drawer empty. Nothing is fetched until
	 * somebody asks, because the alternative is a screen that reads fifty
	 * thousand records in case one row is opened.
	 */
	private static function row( $row ) {
		$rec   = $row['record'];
		$panel = 'bftd-ses-' . (int) $row['id'];
		?>
		<tr class="bftd-ses-r" data-student="<?php echo (int) $row['id']; ?>">
			<td class="bftd-stu-name">
				<button type="button" class="bftd-ses-open" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel ); ?>">
					<span class="bftd-ses-chev" aria-hidden="true"></span>
					<span class="bftd-ses-who"><?php echo esc_html( $row['name'] ); ?></span>
				</button>
				<span class="bftd-stu-acts">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::HUB_SLUG . '&student=' . (int) $row['id'] ) ); ?>">View student</a>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::SESSION . '&student=' . (int) $row['id'] ) ); ?>">Record a session</a>
				</span>
			</td>
			<td data-l="Client"><?php echo $row['client']
				? esc_html( $row['client'] )
				: '<span class="bftd-none">Nobody yet</span>'; ?></td>
			<td data-l="Attendance"><?php self::record_cell( $rec ); ?></td>
		</tr>
		<tr class="bftd-ses-drawer" id="<?php echo esc_attr( $panel ); ?>" hidden>
			<td colspan="3"><div class="bftd-ses-body" data-loaded="0"></div></td>
		</tr>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* What is behind a row                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * One page of one student's sessions.
	 *
	 * Nonce first, then whether this particular person may see this
	 * particular child. A valid nonce only proves the request came from our
	 * page; it proves nothing about permission, and a tutor guessing student
	 * ids must not be handed another tutor's caseload.
	 */
	public static function ajax_sessions() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );

		$student = isset( $_POST['student'] ) ? (int) $_POST['student'] : 0;
		$page    = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;

		if ( ! $student || ! BFTD_Roles::is_staff() || ! BFTD_Access::can_staff_view( $student ) ) {
			wp_send_json_error( array( 'message' => 'That student is not one of yours.' ), 403 );
		}

		ob_start();
		self::drawer( $student, $page );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/** A page of sessions, and the strip that moves between pages. */
	public static function drawer( $student, $page = 1 ) {
		$all   = BFTD_CPT::sessions_of( array( $student ), true );
		$list  = isset( $all[ (int) $student ] ) ? $all[ (int) $student ] : array();
		$total = count( $list );

		if ( ! $total ) {
			echo '<p class="bftd-ses-empty">No sessions recorded for this student yet.</p>';
			return;
		}

		$pages = (int) ceil( $total / self::PER_PAGE );
		$page  = min( max( 1, (int) $page ), $pages );
		$slice = array_slice( $list, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );

		// The number a session carries on the family's report, so the two
		// screens agree about which session is which. sessions_in_order()
		// hands them back oldest first, which is what numbering counts along;
		// reversing it here would number the newest session one.
		$report  = BFTD_CPT::report_for( $student, BFTD_CPT::PROGRESS );
		$numbers = $report ? BFTD_CPT::lesson_numbers( BFTD_CPT::sessions_in_order( $report ) ) : array();
		?>
		<table class="bftd-ses-inner">
			<thead>
				<tr><th>Session</th><th>Date and time</th><th>What happened</th><th>Record</th><th></th></tr>
			</thead>
			<tbody>
				<?php foreach ( $slice as $sid ) self::session_row( $sid, $numbers ); ?>
			</tbody>
		</table>
		<?php if ( $pages > 1 ) : ?>
			<nav class="bftd-ses-pages" aria-label="Sessions, page by page">
				<span class="bftd-ses-of"><?php
					$from = ( $page - 1 ) * self::PER_PAGE + 1;
					$to   = $from + count( $slice ) - 1;
					// The character, not the entity: this goes through esc_html,
					// which would print "&ndash;" at somebody letter by letter.
					echo esc_html( $from . '–' . $to . ' of ' . $total );
				?></span>
				<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
					<button type="button" class="bftd-ses-p<?php echo $i === $page ? ' is-on' : ''; ?>"
						data-page="<?php echo (int) $i; ?>"<?php echo $i === $page ? ' aria-current="page"' : ''; ?>><?php
						echo (int) $i;
					?></button>
				<?php endfor; ?>
			</nav>
		<?php endif;
	}

	private static function session_row( $sid, $numbers ) {
		$date   = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
		$time   = (string) BFTD_Fields::get( $sid, 'session', 'session_time' );
		$status = (string) BFTD_Fields::get( $sid, 'session', 'status' );
		$live   = ( 'publish' === get_post_status( $sid ) );
		$words  = array( 'held' => 'Attended', 'rescheduled' => 'Rescheduled', 'missed' => 'Missed' );
		$kind   = array( 'held' => 'held', 'rescheduled' => 'moved', 'missed' => 'missed' );
		?>
		<tr>
			<td class="bftd-ses-num"><?php
				echo isset( $numbers[ $sid ] ) && $numbers[ $sid ]
					? esc_html( 'Session ' . (int) $numbers[ $sid ] )
					: '<span class="bftd-none">Not numbered yet</span>';
			?></td>
			<td data-l="Date"><?php
				if ( '' === $date ) {
					echo '<span class="bftd-none">No date yet</span>';
				} else {
					$ts = BFTD_Time::stamp( $date, '12:00' );
					echo '<b>' . esc_html( BFTD_Time::format( 'D j M Y', $ts ) ) . '</b>';
					if ( '' !== $time ) echo '<span class="bftd-ses-t">' . esc_html( BFTD_Schedule::pretty_time( $time ) ) . '</span>';
				}
			?></td>
			<td data-l="What happened"><?php
				echo isset( $words[ $status ] )
					? '<span class="bftd-att-b is-' . esc_attr( $kind[ $status ] ) . '">' . esc_html( $words[ $status ] ) . '</span>'
					: '<span class="bftd-none">Not recorded</span>';
			?></td>
			<td data-l="Record"><?php
				// Published is what a family can see. A draft is a tutor's
				// notes in progress and reaches nobody, which is the one
				// thing a management list has to make obvious.
				echo $live
					? '<span class="bftd-pill is-active">Published</span>'
					: '<span class="bftd-pill is-past">Draft</span>';
			?></td>
			<td class="bftd-ses-go">
				<a href="<?php echo esc_url( (string) get_edit_post_link( $sid ) ); ?>">Open</a>
				<?php
				// The progress report with this session in it, as the family
				// will read it, without opening the session first. The same
				// link as the session screen's own preview button.
				?>
				<a href="<?php echo esc_url( BFTD_Preview::url( $sid ) ); ?>" target="_blank" rel="noopener">View preview</a>
				<?php if ( ! $live && self::can_publish( $sid ) ) : ?>
					<a class="bftd-ses-publish" href="<?php echo esc_url( self::publish_url( $sid ) ); ?>">Publish</a>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Attendance as three figures, not a percentage.
	 *
	 * A percentage hides the two things worth knowing: a child at 90% with
	 * one missed session is a different conversation from one at 90% with
	 * six. The words are there beside the numbers, so the record is not read
	 * by colour alone.
	 */
	public static function record_cell( $rec ) {
		if ( empty( $rec['total'] ) ) {
			echo '<span class="bftd-none">Nothing recorded yet</span>';
			return;
		}
		$parts = array(
			array( 'held', $rec['held'], 'attended' ),
			array( 'moved', $rec['rescheduled'], 'moved' ),
			array( 'missed', $rec['missed'], 'missed' ),
		);
		echo '<span class="bftd-att">';
		foreach ( $parts as $part ) {
			list( $kind, $n, $word ) = $part;
			if ( ! $n ) continue;
			echo '<span class="bftd-att-b is-' . esc_attr( $kind ) . '"><b>' . (int) $n . '</b> ' . esc_html( $word ) . '</span>';
		}
		if ( ! empty( $rec['draft'] ) ) {
			echo '<span class="bftd-att-b is-draft"><b>' . (int) $rec['draft'] . '</b> draft</span>';
		}
		echo '</span>';
	}
}
