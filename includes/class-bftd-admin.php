<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The back end a tutor actually works in.
 *
 * The menu is organised the way the work is: a student, then the things that
 * belong to that student. Every screen is named for what it is to a family,
 * not for the post type behind it, and every report builder is laid out in the
 * same order as the section it produces on the parent dashboard — so a tutor
 * filling in "Hearing sounds in words" is looking at the same heading the
 * parent will read.
 *
 * There is no read-only view of the family's dashboard for staff. A tutor
 * opening any screen gets full staff rights on it, always.
 */
class BFTD_Admin {

	const MENU_SLUG  = 'bftd';
	const INBOX_SLUG = 'bftd-conversations';
	const HUB_SLUG   = 'bftd-student';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'parent_file', array( __CLASS__, 'keep_menu_open' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'current_row' ) );
		add_action( 'admin_head', array( __CLASS__, 'menu_badge' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'hide_hub_row' ), 0 );
		add_action( 'admin_post_bftd_reply', array( __CLASS__, 'handle_reply' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	public static function menu() {
		add_menu_page(
			'Brilliant Futures',
			'Brilliant Futures',
			BFTD_Roles::STAFF_CAP,
			self::MENU_SLUG,
			array( __CLASS__, 'render_home' ),
			'dashicons-book-alt',
			3
		);

		add_submenu_page( self::MENU_SLUG, 'Today', 'Today', BFTD_Roles::STAFF_CAP, self::MENU_SLUG, array( __CLASS__, 'render_home' ) );

		add_submenu_page(
			self::MENU_SLUG,
			'Conversations',
			'Conversations',
			BFTD_Roles::STAFF_CAP,
			self::INBOX_SLUG,
			array( __CLASS__, 'render_inbox' )
		);

		// The student hub is reachable but is not a menu row of its own: it is
		// where a tutor lands from a student, and it links out to everything
		// belonging to them.
		//
		// It is registered under the real parent, never with a null parent.
		// Passing null is deprecated on PHP 8.1 and up and leaves no hookname
		// that user_can_access_admin_page() recognises.
		//
		// The row is hidden later, on admin_enqueue_scripts, not here.
		// Removing it during admin_menu takes it out of $submenu before the
		// access check runs.
		// get_admin_page_parent() then finds no parent, the hookname it builds
		// is admin_page_bftd-student instead of the one registered, and
		// WordPress refuses the page for everyone, administrators included.
		// admin_enqueue_scripts fires after that check, and at priority 0 it
		// runs before both the command palette and the sidebar read the menu.
		add_submenu_page(
			self::MENU_SLUG,
			'Student',
			'Student',
			BFTD_Roles::STAFF_CAP,
			self::HUB_SLUG,
			array( __CLASS__, 'render_student_hub' )
		);
	}

	/** Hide the student hub row once access has been decided. See menu(). */
	public static function hide_hub_row() {
		remove_submenu_page( self::MENU_SLUG, self::HUB_SLUG );
	}

	public static function keep_menu_open( $parent_file ) {
		global $current_screen;
		if ( $current_screen && isset( self::menu_rows()[ (string) $current_screen->post_type ] ) ) {
			return self::MENU_SLUG;
		}
		return $parent_file;
	}

	/**
	 * Which menu row each kind of record lives under.
	 *
	 * Students and sessions have screens of their own; everything else is its
	 * WordPress list. Opening, adding or listing one of them lights that row,
	 * so the menu always says which part of the portal you are in.
	 */
	public static function menu_rows() {
		$rows = array(
			'bftd_student'    => 'bftd-students',
			'bftd_session'    => 'bftd-sessions',
			'bftd_assessment' => 'edit.php?post_type=bftd_assessment',
			'bftd_progress'   => 'edit.php?post_type=bftd_progress',
			'bftd_resource'   => 'edit.php?post_type=bftd_resource',
			'bftd_skill'      => 'edit.php?post_type=bftd_skill',
			'bftd_activity'   => 'edit.php?post_type=bftd_activity',
		);
		return $rows;
	}

	/**
	 * The menu row to light on this screen.
	 *
	 * Without this, a session being edited lit nothing: WordPress looks for a
	 * row named post.php, finds none, and leaves the menu open with no row
	 * marked. Adding one lit the hidden "Record a Session" row, which is the
	 * same as lighting nothing.
	 */
	public static function current_row( $submenu_file ) {
		global $current_screen, $plugin_page;
		if ( isset( $plugin_page ) && self::HUB_SLUG === $plugin_page ) return 'bftd-students';
		if ( ! $current_screen ) return $submenu_file;
		$rows = self::menu_rows();
		$type = (string) $current_screen->post_type;
		if ( isset( $rows[ $type ] ) && in_array( $current_screen->base, array( 'edit', 'post' ), true ) ) {
			return $rows[ $type ];
		}
		return $submenu_file;
	}

	/** A count of families waiting on a reply, on the menu row itself. */
	public static function menu_badge() {
		if ( ! BFTD_Roles::is_staff() ) return;
		$n = self::waiting_count();
		if ( ! $n ) return;
		?>
		<style>#adminmenu .toplevel_page_<?php echo esc_attr( self::MENU_SLUG ); ?> .wp-menu-name:after{content:"<?php echo (int) $n; ?>";display:inline-block;margin-left:7px;padding:1px 7px;border-radius:9px;background:#B85C2E;color:#fff;font-size:10px;font-weight:600;line-height:17px;vertical-align:1px;}</style>
		<?php
	}

	private static function my_student_ids() {
		return BFTD_Access::visible_student_ids( get_current_user_id() );
	}

	public static function waiting_count() {
		$n = 0;
		foreach ( self::my_student_ids() as $sid ) {
			$n += BFTD_Threads::staff_unread_count( $sid );
		}
		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Today                                                               */
	/* ------------------------------------------------------------------ */

	public static function render_home() {
		if ( ! BFTD_Roles::is_staff() ) return;
		$students = self::my_student_ids();
		$waiting  = array();

		foreach ( $students as $sid ) {
			foreach ( BFTD_Threads::threads_for_student( $sid, array( 'number' => 50 ) ) as $t ) {
				$last = BFTD_Threads::last_message( $t->comment_ID );
				if ( ! $last || ! $last->user_id || BFTD_Roles::is_staff( $last->user_id ) ) continue;
				$waiting[] = array( 'student' => $sid, 'thread' => $t, 'last' => $last );
			}
		}
		usort( $waiting, function ( $a, $b ) {
			return strcmp( $b['last']->comment_date_gmt, $a['last']->comment_date_gmt );
		} );
		?>
		<div class="wrap bftd-wrap">
			<h1>Today</h1>
			<p class="bftd-lede">What is waiting on you, and where every student stands. Everything here is scoped to the students you are assigned to.</p>

			<div class="bftd-cols">
				<div class="bftd-col-main">
					<h2>Families waiting on a reply</h2>
					<?php if ( ! $waiting ) : ?>
						<p class="bftd-empty">Nothing waiting. Every question has an answer on it.</p>
					<?php else : ?>
						<div class="bftd-waiting">
						<?php foreach ( array_slice( $waiting, 0, 12 ) as $row ) :
							$who = get_userdata( $row['last']->user_id );
							$sec = (string) get_comment_meta( $row['thread']->comment_ID, BFTD_Threads::SECTION_KEY, true ); ?>
							<a class="bftd-waiting-row" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INBOX_SLUG . '&thread=' . (int) $row['thread']->comment_ID ) ); ?>">
								<span class="bftd-w-student"><?php echo esc_html( get_the_title( $row['student'] ) ); ?></span>
								<span class="bftd-w-section"><?php echo esc_html( BFTD_Threads::label_for( $sec, $row['thread']->comment_post_ID ) ); ?></span>
								<span class="bftd-w-body"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $row['last']->comment_content ), 18 ) ); ?></span>
								<span class="bftd-w-meta"><?php echo esc_html( $who ? $who->display_name : 'Someone' ); ?> &middot; <?php echo esc_html( human_time_diff( strtotime( $row['last']->comment_date_gmt . ' UTC' ) ) ); ?> ago</span>
							</a>
						<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<h2>Students</h2>
					<table class="widefat striped bftd-students">
						<thead><tr><th>Student</th><th style="width:120px;">Sessions</th><th style="width:150px;">Diagnostic</th><th style="width:150px;">Progress report</th><th style="width:130px;">Waiting</th></tr></thead>
						<tbody>
						<?php if ( ! $students ) : ?>
							<tr><td colspan="5">No students assigned to you yet.</td></tr>
						<?php else : foreach ( $students as $sid ) :
							$dx   = BFTD_CPT::report_for( $sid, BFTD_CPT::ASSESSMENT );
							$pr   = BFTD_CPT::report_for( $sid, BFTD_CPT::PROGRESS );
							$sess = $pr ? count( BFTD_CPT::sessions_for( $pr, true ) ) : 0;
							$w    = BFTD_Threads::staff_unread_count( $sid ); ?>
							<tr>
								<td><a class="row-title" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::HUB_SLUG . '&student=' . (int) $sid ) ); ?>"><?php echo esc_html( get_the_title( $sid ) ); ?></a></td>
								<td><?php echo (int) $sess; ?></td>
								<td><?php echo $dx ? '<a href="' . esc_url( (string) get_edit_post_link( $dx ) ) . '">Open</a>' : '<span class="bftd-none">Not started</span>'; ?></td>
								<td><?php echo $pr ? '<a href="' . esc_url( (string) get_edit_post_link( $pr ) ) . '">Open</a>' : '<span class="bftd-none">Not started</span>'; ?></td>
								<td><?php echo $w ? '<span class="bftd-count">' . (int) $w . '</span>' : '<span class="bftd-none">Nothing</span>'; ?></td>
							</tr>
						<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>

				<div class="bftd-col-side">
					<div class="bftd-card">
						<h3>Add</h3>
						<?php foreach ( BFTD_CPT::menu_types() as $pt => $label ) : ?>
							<?php $obj = get_post_type_object( $pt ); ?>
							<?php if ( ! $obj || ! current_user_can( $obj->cap->create_posts ) ) continue; ?>
							<p><a class="button<?php echo BFTD_CPT::STUDENT === $pt ? ' button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . $pt ) ); ?>"><?php echo esc_html( $label ); ?></a></p>
						<?php endforeach; ?>
					</div>
					<?php
					// The practice's activity log in brief, so it answers to the
					// same rule as the log: senior managers and administrators.
					if ( BFTD_Roles::can_administer() ) : ?>
					<div class="bftd-card">
						<h3>Recently</h3>
						<?php
						$recent = BFTD_Audit::get_entries( array( 'per_page' => 8 ) );
						if ( ! $recent ) {
							echo '<p class="bftd-none">Nothing yet.</p>';
						} else {
							echo '<ul class="bftd-recent">';
							foreach ( $recent as $r ) {
								echo '<li><b>' . esc_html( $r->actor_name ) . '</b> ' . esc_html( strtolower( BFTD_Audit::event_label( $r->event_type ) ) )
									. '<span>' . esc_html( human_time_diff( BFTD_Time::from_mysql( $r->created_at ) ) ) . ' ago</span></li>';
							}
							echo '</ul>';
						}
						?>
						<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Audit::PAGE_SLUG ) ); ?>">Full activity log</a></p>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Student hub                                                         */
	/* ------------------------------------------------------------------ */

	public static function render_student_hub() {
		if ( ! BFTD_Roles::is_staff() ) return;
		$student_id = isset( $_GET['student'] ) ? (int) $_GET['student'] : 0;
		if ( ! $student_id || ! BFTD_Access::can_staff_view( $student_id ) ) {
			echo '<div class="wrap"><p>That student is not one of yours.</p></div>';
			return;
		}

		$dx  = BFTD_CPT::report_for( $student_id, BFTD_CPT::ASSESSMENT );
		$pr  = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		$all = BFTD_CPT::assessments_for( $student_id );
		?>
		<div class="wrap bftd-wrap">
			<h1>Student overview: <?php echo esc_html( get_the_title( $student_id ) ); ?></h1>
			<p class="bftd-lede">Everything this family sees, and everything behind it. Each row below is one screen of their portal.</p>

			<div class="bftd-hub">
				<?php
				$rows = array(
					array( 'Home',              'What they land on: next session, last session, reading level.', $student_id ),
					array( 'Reading diagnostic','The full diagnostic, section by section.', $dx ),
					array( 'Progress report',   'The living report: overview, fluency, activities, every session.', $pr ),
				);
				foreach ( $rows as $row ) :
					list( $label, $blurb, $target ) = $row; ?>
					<div class="bftd-hub-row">
						<div>
							<h3><?php echo esc_html( $label ); ?></h3>
							<p class="description"><?php echo esc_html( $blurb ); ?></p>
						</div>
						<div>
							<?php if ( $target ) : ?>
								<a class="button button-primary" href="<?php echo esc_url( (string) get_edit_post_link( $target ) ); ?>">Edit</a>
							<?php else : ?>
								<span class="bftd-none">Not created yet</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<div class="bftd-hub-row">
					<div>
						<h3>Sessions</h3>
						<p class="description">One record per session. These build the session stream, the activity map and the texts read.</p>
						<?php
						// Unpublished sessions, said plainly, because a draft
						// reaches no family and pays no tutor.
						$rec    = class_exists( 'BFTD_Sessions' ) ? BFTD_Sessions::records( array( $student_id ) ) : array();
						$drafts = isset( $rec[ $student_id ] ) ? (int) $rec[ $student_id ]['draft'] : 0;
						if ( $drafts ) :
							?>
							<p class="bftd-hub-drafts"><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::SESSION . '&post_status=draft&bftd_student=' . (int) $student_id ) ); ?>"><?php
								echo esc_html( 1 === $drafts ? '1 session is still a draft' : $drafts . ' sessions are still drafts' );
							?></a>, not yet on the progress report.</p>
						<?php endif; ?>
					</div>
					<div>
						<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BFTD_CPT::SESSION . '&bftd_student=' . (int) $student_id ) ); ?>">All sessions</a>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::SESSION . '&student=' . (int) $student_id ) ); ?>">Record a session</a>
					</div>
				</div>

				<div class="bftd-hub-row">
					<div>
						<h3>Conversations</h3>
						<p class="description">Every question this family has asked, on every section.</p>
					</div>
					<div><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::INBOX_SLUG . '&student=' . (int) $student_id ) ); ?>">Open</a></div>
				</div>
			</div>

			<?php if ( count( $all ) > 1 ) : ?>
				<h2>Every diagnostic on file</h2>
				<table class="widefat striped">
					<thead><tr><th>Assessment</th><th style="width:150px;">Date</th><th style="width:120px;">Status</th></tr></thead>
					<tbody>
					<?php foreach ( $all as $aid ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( (string) get_edit_post_link( $aid ) ); ?>"><?php echo esc_html( get_the_title( $aid ) ); ?></a></td>
							<td><?php echo esc_html( get_the_date( get_option( 'date_format' ), $aid ) ); ?></td>
							<td><?php echo esc_html( ucfirst( get_post_status( $aid ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Activity on this student</h2>
			<?php BFTD_Audit::render_for_student( $student_id, 40 ); ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Conversations                                                       */
	/* ------------------------------------------------------------------ */

	public static function render_inbox() {
		if ( ! BFTD_Roles::is_staff() ) return;

		$thread_id  = isset( $_GET['thread'] ) ? (int) $_GET['thread'] : 0;
		$student_id = isset( $_GET['student'] ) ? (int) $_GET['student'] : 0;
		$filter     = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'waiting';

		$students = $student_id ? array( $student_id ) : self::my_student_ids();
		$threads  = array();

		foreach ( $students as $sid ) {
			if ( ! BFTD_Access::can_staff_view( $sid ) ) continue;
			foreach ( BFTD_Threads::threads_for_student( $sid, array( 'number' => 200 ) ) as $t ) {
				$last = BFTD_Threads::last_message( $t->comment_ID );
				if ( ! $last ) continue;
				$from_family = $last->user_id && ! BFTD_Roles::is_staff( $last->user_id );
				if ( 'waiting' === $filter && ! $from_family ) continue;
				$threads[] = array( 'student' => $sid, 'thread' => $t, 'last' => $last, 'waiting' => $from_family );
			}
		}
		usort( $threads, function ( $a, $b ) {
			return strcmp( $b['last']->comment_date_gmt, $a['last']->comment_date_gmt );
		} );

		$open = $thread_id ? BFTD_Threads::get_thread( $thread_id ) : ( $threads ? $threads[0]['thread'] : null );
		if ( $open && ! BFTD_Threads::can_access( $open->comment_post_ID ) ) $open = null;
		$base = admin_url( 'admin.php?page=' . self::INBOX_SLUG );
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'inbox';
		?>
		<div class="wrap bftd-wrap">
			<h1>Conversations</h1>
			<p class="bftd-lede">Every thread from every section of every report, in one place. Replying here posts back into the section it came from, so the family reads it where they asked.</p>

			<div class="bftd-view-switch">
				<a class="bftd-chip <?php echo 'inbox' === $view ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => 'inbox', 'show' => $filter ), $base ) ); ?>">Read and reply</a>
				<a class="bftd-chip <?php echo 'list' === $view ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'view' => 'list', 'show' => $filter ), $base ) ); ?>">Every message</a>
			</div>

			<?php if ( 'list' === $view ) : self::render_message_list( $students, $base, $filter ); return; endif; ?>

			<div class="bftd-inbox">
				<div class="bftd-inbox-list">
					<div class="bftd-inbox-filters">
						<a class="bftd-chip <?php echo 'waiting' === $filter ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'show', 'waiting', $base ) ); ?>">Waiting on us</a>
						<a class="bftd-chip <?php echo 'all' === $filter ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'show', 'all', $base ) ); ?>">Everything</a>
					</div>
					<?php if ( ! $threads ) : ?>
						<p class="bftd-empty">Nothing here.</p>
					<?php else : foreach ( $threads as $row ) :
						$t   = $row['thread'];
						$sec = (string) get_comment_meta( $t->comment_ID, BFTD_Threads::SECTION_KEY, true );
						$on  = $open && (int) $open->comment_ID === (int) $t->comment_ID; ?>
						<a class="bftd-inbox-item <?php echo $on ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'thread' => (int) $t->comment_ID, 'show' => $filter ), $base ) ); ?>">
							<span class="bftd-i-top">
								<?php if ( $row['waiting'] ) : ?><span class="bftd-dot" aria-label="Waiting on us"></span><?php endif; ?>
								<span class="bftd-i-student"><?php echo esc_html( get_the_title( $row['student'] ) ); ?></span>
								<span class="bftd-i-when"><?php echo esc_html( human_time_diff( strtotime( $row['last']->comment_date_gmt . ' UTC' ) ) ); ?></span>
							</span>
							<span class="bftd-i-section"><?php echo esc_html( BFTD_Threads::label_for( $sec, $t->comment_post_ID ) ); ?></span>
							<span class="bftd-i-prev"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $row['last']->comment_content ), 16 ) ); ?></span>
						</a>
					<?php endforeach; endif; ?>
				</div>

				<div class="bftd-inbox-pane">
					<?php if ( ! $open ) : ?>
						<p class="bftd-empty">Pick a conversation.</p>
					<?php else :
						$sec        = (string) get_comment_meta( $open->comment_ID, BFTD_Threads::SECTION_KEY, true );
						$section    = BFTD_Schema::section( $sec );
						$post_id    = (int) $open->comment_post_ID;
						$student_id = BFTD_CPT::student_id( $post_id );
						BFTD_Threads::mark_read( $open->comment_ID ); ?>
						<div class="bftd-pane-head">
							<div>
								<h2><?php echo esc_html( BFTD_Threads::label_for( $sec, $post_id ) ); ?></h2>
								<p class="description">
									<?php echo esc_html( get_the_title( $student_id ) ); ?>
									&middot; <?php echo esc_html( $section ? BFTD_Schema::screen_label( $section['screen'] ) : 'Portal' ); ?>
								</p>
							</div>
							<div>
								<a class="button" href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>">Open the report</a>
							</div>
						</div>

						<div class="bftd-msgs">
							<?php foreach ( BFTD_Threads::messages( $open->comment_ID ) as $m ) :
								$u    = $m->user_id ? get_userdata( $m->user_id ) : null;
								$mine = $u && BFTD_Roles::is_staff( $u->ID ); ?>
								<div class="bftd-msg <?php echo $mine ? 'is-staff' : 'is-family'; ?>">
									<div class="bftd-msg-h">
										<b><?php echo esc_html( $u ? $u->display_name : 'Someone' ); ?></b>
										<span class="bftd-msg-rel"><?php echo esc_html( $u && ! $mine ? BFTD_Roles::relationship( $u->ID ) : 'Brilliant Futures' ); ?></span>
										<time><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $m->comment_date ) ); ?></time>
									</div>
									<div class="bftd-msg-b"><?php echo wp_kses_post( wpautop( $m->comment_content ) ); ?></div>
									<?php $files = BFTD_Attachments::for_comment( $m->comment_ID ); ?>
									<?php if ( $files ) : ?>
										<div class="bftd-msg-files">
											<?php foreach ( $files as $f ) : ?>
												<a class="bftd-file" href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener">
													<?php if ( $f['thumb'] ) : ?><img alt="" src="<?php echo esc_url( $f['thumb'] ); ?>"><?php else : ?><span class="bftd-file-ic"><?php echo esc_html( strtoupper( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) ); ?></span><?php endif; ?>
													<span class="bftd-file-n"><?php echo esc_html( $f['name'] ); ?></span>
													<span class="bftd-file-s"><?php echo esc_html( $f['size'] ); ?></span>
												</a>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							<?php endforeach; ?>
						</div>

						<form class="bftd-reply" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'bftd_reply_' . (int) $open->comment_ID, 'bftd_reply_nonce' ); ?>
							<input type="hidden" name="action" value="bftd_reply">
							<input type="hidden" name="thread" value="<?php echo (int) $open->comment_ID; ?>">
							<label class="screen-reader-text" for="bftd_reply_body">Your reply</label>
							<textarea id="bftd_reply_body" name="body" rows="5" class="large-text" placeholder="Reply to the family. This appears on the section they asked about."></textarea>
							<div class="bftd-reply-foot">
								<label class="bftd-attach-label">
									<input type="file" name="files[]" multiple accept="image/*,.pdf,.doc,.docx,.txt,.heic">
									<span>Attach up to 5 files</span>
								</label>
								<button class="button button-primary">Post reply</button>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The flat view: every individual message rather than every thread, most
	 * recent first. The two-pane inbox above is for answering; this is for
	 * finding — "what did that parent say in July" is a scan, not a
	 * conversation, and a thread list hides it one click deep.
	 */
	private static function render_message_list( $students, $base, $filter ) {
		$post_ids = array();
		foreach ( $students as $sid ) {
			if ( ! BFTD_Access::can_staff_view( $sid ) ) continue;
			$post_ids = array_merge( $post_ids, BFTD_Threads::record_ids( $sid ) );
		}

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per    = 40;

		$args = array(
			'post__in' => $post_ids ? $post_ids : array( 0 ),
			'type'     => BFTD_Threads::TYPE,
			'status'   => 'approve',
			'orderby'  => 'comment_date_gmt',
			'order'    => 'DESC',
			'number'   => $per,
			'offset'   => ( $paged - 1 ) * $per,
		);
		if ( $search ) $args['search'] = $search;

		$messages = get_comments( $args );
		$total    = (int) get_comments( array_merge( $args, array( 'count' => true, 'number' => 0, 'offset' => 0 ) ) );
		$pages    = max( 1, (int) ceil( $total / $per ) );
		$fmt      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<form method="get" class="bftd-log-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::INBOX_SLUG ); ?>">
			<input type="hidden" name="view" value="list">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search every message">
			<button class="button">Search</button>
			<?php if ( $search ) : ?>
				<a class="button-link" href="<?php echo esc_url( add_query_arg( 'view', 'list', $base ) ); ?>">Clear</a>
			<?php endif; ?>
			<span class="bftd-log-count"><?php echo esc_html( number_format_i18n( $total ) ); ?> messages</span>
		</form>

		<table class="widefat striped bftd-msg-list">
			<thead><tr>
				<th style="width:190px;">Who</th>
				<th>Message</th>
				<th style="width:230px;">Where</th>
				<th style="width:170px;">When</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $messages ) : ?>
				<tr><td colspan="4">Nothing here.</td></tr>
			<?php else : foreach ( $messages as $m ) :
				$u       = $m->user_id ? get_userdata( $m->user_id ) : null;
				$staff   = $u && BFTD_Roles::is_staff( $u->ID );
				$sec     = BFTD_Threads::section_of( $m->comment_ID );
				$root    = $m->comment_parent ? (int) $m->comment_parent : (int) $m->comment_ID;
				$student = BFTD_CPT::student_id( $m->comment_post_ID );
				$files   = BFTD_Attachments::for_comment( $m->comment_ID );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $u ? $u->display_name : 'Someone' ); ?></strong>
						<div class="bftd-msg-who"><?php echo esc_html( $staff ? 'Brilliant Futures' : ( $u ? BFTD_Roles::relationship( $u->ID ) : '' ) ); ?></div>
						<?php if ( $u ) : ?><div class="bftd-msg-who"><?php echo esc_html( $u->user_email ); ?></div><?php endif; ?>
					</td>
					<td>
						<?php echo wp_kses_post( wpautop( $m->comment_content ) ); ?>
						<?php if ( $files ) : ?>
							<div class="bftd-msg-who"><?php echo esc_html( count( $files ) ); ?> <?php echo esc_html( _n( 'file attached', 'files attached', count( $files ), 'bftd' ) ); ?></div>
						<?php endif; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( add_query_arg( array( 'view' => 'inbox', 'thread' => $root ), $base ) ); ?>"><?php echo esc_html( BFTD_Threads::label_for( $sec, $m->comment_post_ID ) ); ?></a>
						<div class="bftd-msg-who"><?php echo esc_html( $student ? get_the_title( $student ) : '' ); ?></div>
					</td>
					<td><?php echo esc_html( mysql2date( $fmt, $m->comment_date ) ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>

		<?php if ( $pages > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post( (string) paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				) ) );
				?>
			</div></div>
		<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_reply() {
		$thread_id = isset( $_POST['thread'] ) ? (int) $_POST['thread'] : 0;
		check_admin_referer( 'bftd_reply_' . $thread_id, 'bftd_reply_nonce' );

		if ( ! BFTD_Roles::is_staff() ) wp_die( 'You cannot reply here.', 403 );

		$root = BFTD_Threads::get_thread( $thread_id );
		if ( ! $root ) wp_die( 'That conversation is gone.', 404 );
		if ( ! BFTD_Threads::can_access( $root->comment_post_ID ) ) wp_die( 'That is not one of your students.', 403 );

		$body    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '';
		$section = (string) get_comment_meta( $thread_id, BFTD_Threads::SECTION_KEY, true );

		$files = array();
		if ( ! empty( $_FILES['files']['name'] ) && is_array( $_FILES['files']['name'] ) ) {
			foreach ( array_keys( $_FILES['files']['name'] ) as $i ) {
				if ( empty( $_FILES['files']['name'][ $i ] ) ) continue;
				$files[] = array(
					'name'     => $_FILES['files']['name'][ $i ],
					'type'     => $_FILES['files']['type'][ $i ],
					'tmp_name' => $_FILES['files']['tmp_name'][ $i ],
					'error'    => $_FILES['files']['error'][ $i ],
					'size'     => $_FILES['files']['size'][ $i ],
				);
			}
		}

		$result = BFTD_Threads::post_message( (int) $root->comment_post_ID, $section, $body, $files );

		$back = add_query_arg( array(
			'page'   => self::INBOX_SLUG,
			'thread' => $thread_id,
			'bftd'   => is_wp_error( $result ) ? 'error' : 'sent',
		), admin_url( 'admin.php' ) );

		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * A family signing in goes to their portal, not to wp-admin. Staff go
	 * where they were headed. Nothing else about the login flow is touched:
	 * password reset, rate limiting and every other core endpoint are left
	 * exactly as WordPress ships them.
	 */
	public static function login_redirect( $redirect_to, $requested, $user ) {
		if ( ! $user instanceof WP_User ) return $redirect_to;
		if ( BFTD_Roles::is_staff( $user->ID ) ) return $redirect_to;
		if ( ! BFTD_Roles::is_client( $user ) ) return $redirect_to;
		return BFTD_Dashboard::url();
	}
}
