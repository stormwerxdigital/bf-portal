<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The family-facing portal.
 *
 * Rendered on a normal WordPress page through the [bf_portal] shortcode, so
 * the theme, the menu and the rest of bftutoring.com stay exactly as they are.
 *
 * There is deliberately no "redirect everyone to the dashboard" behaviour here.
 * Every core route — the login form, password reset, the public site — is left
 * untouched, because forcing traffic through one endpoint is what turned a
 * hosting rate limit into a password reset outage once already.
 *
 * Sections are drawn from BFTD_Schema in the same order the tutor filled them
 * in, and a section with nothing in it, or one a tutor has switched off, is not
 * rendered at all.
 */
class BFTD_Dashboard {

	const OPTION_PAGE = 'bftd_portal_page';
	const SHORTCODE   = 'bf_portal';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Where the portal lives                                              */
	/* ------------------------------------------------------------------ */

	public static function page_id() {
		return (int) get_option( self::OPTION_PAGE, 0 );
	}

	/**
	 * Which family's portal is being drawn, while it is being drawn.
	 *
	 * A staff member reaching /portal/ without saying which family gets the
	 * chooser, which is right — but it meant every link BUILT inside a
	 * family's portal sent a staff member back to the chooser, because url()
	 * did not carry the family through. The nav knew to pass it; nothing else
	 * did, and the first link that needed to was the one to a child's reading
	 * diagnostic, which landed a tutor on a search box.
	 *
	 * So the renderer records it once and url() adds it, rather than every
	 * caller being expected to remember.
	 */
	private static $viewing_family = 0;

	public static function url( $student_id = 0, $section_id = '' ) {
		$page = self::page_id();
		$base = $page ? get_permalink( $page ) : home_url( '/portal/' );

		$args = array();
		if ( self::$viewing_family ) $args['family'] = (int) self::$viewing_family;
		if ( $student_id ) $args['student'] = (int) $student_id;
		if ( $section_id ) {
			$section = BFTD_Schema::section( $section_id );
			if ( $section ) $args['screen'] = $section['screen'];
		}
		$url = $args ? add_query_arg( $args, $base ) : $base;
		return $section_id ? $url . '#' . rawurlencode( $section_id ) : $url;
	}

	public static function url_for_family( $client_id, $student_id = 0, $section_id = '' ) {
		$args = array( 'family' => (int) $client_id );
		if ( $student_id ) $args['student'] = (int) $student_id;
		if ( $section_id ) {
			$section = BFTD_Schema::section( $section_id );
			if ( $section ) $args['screen'] = $section['screen'];
		}
		return add_query_arg( $args, self::url() );
	}

	public static function assets() {
		if ( ! is_singular() ) return;
		$page = self::page_id();
		if ( $page && get_the_ID() !== $page ) return;

		wp_enqueue_style( 'bftd-fonts', BFTD_Report_View::fonts_url(), array(), null );
		wp_enqueue_style( 'bftd-portal', BFTD_URL . 'assets/css/bftd-portal.css', array( 'bftd-fonts' ), BFTD_VERSION );

		// The report is drawn by BFTD_Report_View in the prototype's own markup,
		// and that markup is meaningless without the prototype's own stylesheet.
		// The portal renders reports on two of its screens, so both files belong
		// here, not only on the preview: for three versions the preview loaded
		// them and the portal did not, which is why every preview looked right
		// and the page the family actually opened did not.
		//
		// bftd-report.css is listed after bftd-portal.css so it wins where the
		// two touch the same element, and every one of its selectors is scoped
		// under .bf-report, so nothing in it reaches the theme.
		wp_enqueue_style( 'bftd-report', BFTD_URL . 'assets/css/bftd-report.css', array( 'bftd-fonts', 'bftd-portal' ), BFTD_VERSION );

		wp_enqueue_script( 'bftd-portal', BFTD_URL . 'assets/js/bftd-portal.js', array(), BFTD_VERSION, true );

		// Without this the sections, the lesson accordions and the per-item
		// detail toggles are drawn but dead. A control that does nothing is
		// worse than one that is not there, because a parent will keep pressing it.
		wp_enqueue_script( 'bftd-report', BFTD_URL . 'assets/js/bftd-report.js', array(), BFTD_VERSION, true );

		wp_localize_script( 'bftd-portal', 'BFTD', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'bftd_nonce' ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="bf-portal bf-signin"><p>Please sign in to see your portal.</p><p><a class="bf-btn" href="' . esc_url( wp_login_url( self::url() ) ) . '">Sign in</a></p></div>';
		}

		$user_id = get_current_user_id();
		$staff   = BFTD_Roles::is_staff( $user_id );

		// A staff member arrives at a chooser, not at somebody's portal. They
		// pick a family, and from then on the page renders exactly what that
		// family sees — the same sections, the same wording, the same order.
		//
		// This is a rendering choice, not a permission one. The staff member
		// stays entirely themselves: full rights on every screen, and anything
		// they post here posts under their own name, as staff, exactly as it
		// would from the back end. There is no degraded preview mode, no
		// impersonation, and nothing a family writes can be answered as though
		// it came from them.
		$family_id = 0;
		if ( $staff ) {
			$family_id = isset( $_GET['family'] ) ? (int) $_GET['family'] : 0;
			if ( ! $family_id ) return self::staff_chooser( $user_id );

			$students = self::family_students( $family_id, $user_id );
			if ( ! $students ) return self::staff_chooser( $user_id, 'That family is not linked to a student you can see.' );

			self::log_view( $family_id, $students );
			self::$viewing_family = $family_id;
		} else {
			$students = BFTD_CPT::students_for_client( $user_id );
		}

		if ( ! $students ) return self::empty_state( $user_id );

		$student_id = isset( $_GET['student'] ) ? (int) $_GET['student'] : (int) $students[0];
		if ( ! in_array( $student_id, array_map( 'absint', $students ), true ) ) $student_id = (int) $students[0];

		$screen = isset( $_GET['screen'] ) ? sanitize_key( wp_unslash( $_GET['screen'] ) ) : BFTD_Schema::SCREEN_HOME;
		if ( ! array_key_exists( $screen, BFTD_Schema::screens() ) && 'messages' !== $screen ) {
			$screen = BFTD_Schema::SCREEN_HOME;
		}

		ob_start();
		?>
		<div class="bf-portal" data-student="<?php echo (int) $student_id; ?>">
			<?php if ( $family_id ) self::staff_bar( $family_id, $student_id ); ?>
			<?php self::nav( $student_id, $screen, $students, $family_id ); ?>
			<div class="bf-screen">
				<?php BFTD_Settings::render_banner( $user_id ); ?>
				<?php
				// Items pinned to a section only surface while that section is
				// on screen; unpinned ones follow the family everywhere.
				BFTD_Items::render_card( $student_id, BFTD_Items::PRIORITY, '' );
				BFTD_Items::render_card( $student_id, BFTD_Items::REVIEW, '' );
				?>
				<?php
				switch ( $screen ) {
					case BFTD_Schema::SCREEN_PROGRESS:  self::screen_report( $student_id, BFTD_CPT::PROGRESS, BFTD_Schema::SCREEN_PROGRESS ); break;
					case BFTD_Schema::SCREEN_REPORT:    self::screen_report( $student_id, BFTD_CPT::ASSESSMENT, BFTD_Schema::SCREEN_REPORT ); break;
					case BFTD_Schema::SCREEN_RESOURCES: self::screen_library( $student_id, 'sec-home-practice' ); break;
					case BFTD_Schema::SCREEN_SETUP:     self::screen_library( $student_id, 'sec-setup' ); break;
					case 'messages':                    self::screen_messages( $student_id ); break;
					default:                            self::screen_home( $student_id );
				}
				?>
				<?php BFTD_Settings::render_help_bar(); ?>
				<?php $contact = BFTD_Settings::portal()['contact_line']; ?>
				<?php if ( trim( (string) $contact ) ) : ?>
					<p class="bf-foot"><?php echo esc_html( $contact ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Nothing to show. Staff and families need different words here: a family
	 * with no student linked has a problem someone else has to fix, while a
	 * tutor looking at an empty portal is simply looking at a practice with no
	 * students in it yet, and needs the way to add one.
	 *
	 * Showing a tutor the family's copy — "reply to any email from us" — reads
	 * as a broken install rather than an empty one.
	 */
	private static function empty_state( $user_id ) {
		ob_start();
		?>
		<?php $logo = BFTD_Brand::logo_url(); ?>
		<div class="bf-portal">
			<div class="bf-bar">
				<?php if ( $logo ) : ?>
					<img class="bf-logo" src="<?php echo esc_url( $logo ); ?>" alt="Brilliant Futures Tutoring">
				<?php else : ?>
					<div class="bf-mark">Brilliant Futures <span>Tutoring</span></div>
				<?php endif; ?>
			</div>
			<div class="bf-screen">
				<?php if ( BFTD_Roles::is_staff( $user_id ) ) : ?>
					<div class="bf-head">
						<p class="bf-eyebrow">Staff view</p>
						<h1>This is what a family sees</h1>
						<p class="lede">There are no students on your account yet, so there is nothing to show. Add a student and link a parent to it, and this page becomes their portal.</p>
					</div>
					<div class="bf-card">
						<p><a class="bf-btn" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . BFTD_CPT::STUDENT ) ); ?>">Add a student</a>
						<a class="bf-btn bf-btn-ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::MENU_SLUG ) ); ?>">Open the dashboard</a></p>
						<p class="bf-sub">You are seeing this because you are signed in as staff. Families only ever reach this page for a student they are linked to.</p>
					</div>
				<?php else : ?>
					<div class="bf-head">
						<h1>Nothing here yet</h1>
						<p class="lede">There is no student linked to your account. If that seems wrong, reply to any email from us and we will sort it out.</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The students a given family account can reach, narrowed to what this
	 * staff member is allowed to see. The intersection is the point: a tutor
	 * opening a family who also has a child with another tutor sees only their
	 * own.
	 */
	private static function family_students( $family_id, $staff_id ) {
		$theirs  = BFTD_CPT::students_for_client( $family_id );
		$visible = BFTD_Access::visible_student_ids( $staff_id );
		return array_values( array_intersect( array_map( 'absint', $theirs ), array_map( 'absint', $visible ) ) );
	}

	/**
	 * Opening a family's portal is recorded, once per staff member per family
	 * per day rather than on every page turn, so the log stays readable while
	 * still answering "who has been looking at this child's file".
	 */
	private static function log_view( $family_id, $students ) {
		$key = 'bftd_view_' . get_current_user_id() . '_' . $family_id . '_' . BFTD_Time::format( 'Ymd' );
		if ( get_transient( $key ) ) return;
		set_transient( $key, 1, DAY_IN_SECONDS );

		$family = get_userdata( $family_id );
		BFTD_Audit::log( 'portal_viewed', array(
			'student_id' => $students ? (int) $students[0] : 0,
			'post_id'    => $students ? (int) $students[0] : 0,
			'summary'    => wp_get_current_user()->display_name . ' opened the portal as it appears to '
				. ( $family ? $family->display_name : 'a family' ) . '.',
		) );
	}

	/**
	 * The bar a staff member sees, and a family never does. It says plainly
	 * whose view this is, and carries the links back to the records behind it
	 * — which is also what makes it obvious this is not a locked-down preview:
	 * everything is still editable, one click away.
	 */
	private static function staff_bar( $family_id, $student_id ) {
		$family = get_userdata( $family_id );
		$report = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		$dx     = BFTD_CPT::report_for( $student_id, BFTD_CPT::ASSESSMENT );
		?>
		<div class="bf-staffbar">
			<div class="bf-staffbar-t">
				<span class="bf-staffbar-eyebrow">You are seeing what this family sees</span>
				<strong><?php echo esc_html( $family ? $family->display_name : 'A family' ); ?></strong>
				<span class="bf-staffbar-sub">
					<?php echo esc_html( $family ? BFTD_Roles::relationship( $family_id ) . ' · ' . $family->user_email : '' ); ?>
					&middot; <?php echo esc_html( get_the_title( $student_id ) ); ?>
				</span>
			</div>
			<div class="bf-staffbar-a">
				<a href="<?php echo esc_url( (string) get_edit_post_link( $student_id ) ); ?>">Student record</a>
				<?php if ( $report ) : ?><a href="<?php echo esc_url( (string) get_edit_post_link( $report ) ); ?>">Progress report</a><?php endif; ?>
				<?php if ( $dx ) : ?><a href="<?php echo esc_url( (string) get_edit_post_link( $dx ) ); ?>">Diagnostic</a><?php endif; ?>
				<a class="bf-staffbar-x" href="<?php echo esc_url( self::url() ); ?>">Choose someone else</a>
			</div>
			<p class="bf-staffbar-note">Anything you post here goes on under your own name, from Brilliant Futures, exactly as it would from the dashboard. You are not signed in as them.</p>
		</div>
		<?php
	}

	/**
	 * The searchable family picker. Search runs server side over name,
	 * username and email; the list is pre-filled with everyone this person can
	 * reach, so it is useful before a single keystroke.
	 */
	public static function staff_chooser( $user_id, $error = '' ) {
		$visible = BFTD_Access::visible_student_ids( $user_id );
		$count   = 0;
		foreach ( $visible as $sid ) $count += count( BFTD_CPT::client_ids( $sid ) );

		ob_start();
		$logo = BFTD_Brand::logo_url();
		?>
		<div class="bf-portal bf-portal-chooser">
			<div class="bf-bar">
				<?php if ( $logo ) : ?>
					<img class="bf-logo" src="<?php echo esc_url( $logo ); ?>" alt="Brilliant Futures Tutoring">
				<?php else : ?>
					<div class="bf-mark">Brilliant Futures <span>Tutoring</span></div>
				<?php endif; ?>
				<span class="bf-who"><?php echo esc_html( BFTD_Roles::role_name( $user_id ) ); ?></span>
			</div>

			<div class="bf-screen">
				<div class="bf-head">
					<p class="bf-eyebrow">Staff view</p>
					<h1>Open a family's portal</h1>
					<p class="lede">Search by student name, or by the parent's name, username or email address. Open their portal to see exactly what they see. You stay signed in as yourself throughout.</p>
				</div>

				<?php if ( $error ) : ?>
					<div class="bf-banner bf-banner-clay"><p><?php echo esc_html( $error ); ?></p></div>
				<?php endif; ?>

				<?php if ( ! $visible ) : ?>
					<p class="bf-empty">There are no students on your account yet, so there is nobody to look at.</p>
				<?php else : ?>
					<div class="bf-find" data-empty="<?php echo (int) ( 0 === $count ); ?>">
						<label class="bf-find-l" for="bf-find-in">Find a student or family</label>
						<div class="bf-find-box">
							<input type="search" id="bf-find-in" class="bf-find-in" autocomplete="off"
								role="combobox" aria-expanded="false" aria-controls="bf-find-list" aria-autocomplete="list"
								placeholder="Student name, parent name, username or email">
							<span class="bf-find-spin" hidden aria-hidden="true"></span>
						</div>
						<ul class="bf-find-list" id="bf-find-list" role="listbox" aria-label="Families"></ul>
						<p class="bf-find-none" hidden>Nobody matches that.</p>
					</div>
				<?php endif; ?>

				<p class="bf-foot">Looking for the dashboard instead? <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::MENU_SLUG ) ); ?>">Open Today</a>.</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function nav( $student_id, $current, $students, $family_id = 0 ) {
		$p       = BFTD_Settings::portal();
		$screens = BFTD_Schema::screens();
		$screens['messages'] = array( 'label' => 'Messages' );

		if ( empty( $p['show_resources'] ) ) unset( $screens[ BFTD_Schema::SCREEN_RESOURCES ] );
		if ( empty( $p['show_setup'] ) )     unset( $screens[ BFTD_Schema::SCREEN_SETUP ] );
		if ( empty( $p['show_messages'] ) )  unset( $screens['messages'] );

		$logo = BFTD_Brand::logo_url();
		$unread = BFTD_Threads::unread_count_for_user( $student_id );
		?>
		<div class="bf-bar">
			<?php if ( $logo ) : ?>
				<img class="bf-logo" src="<?php echo esc_url( $logo ); ?>" alt="Brilliant Futures Tutoring">
			<?php else : ?>
				<div class="bf-mark">Brilliant Futures <span>Tutoring</span></div>
			<?php endif; ?>
			<?php if ( count( $students ) > 1 ) : ?>
				<select class="bf-switch" onchange="window.location=this.value">
					<?php foreach ( $students as $sid ) : ?>
						<option value="<?php echo esc_url( $family_id ? self::url_for_family( $family_id, $sid ) : self::url( $sid ) ); ?>" <?php selected( $sid, $student_id ); ?>><?php echo esc_html( get_the_title( $sid ) ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<span class="bf-who"><?php echo esc_html( get_the_title( $student_id ) ); ?></span>
			<?php endif; ?>
		</div>
		<nav class="bf-nav" aria-label="Portal sections">
			<?php foreach ( $screens as $key => $cfg ) : ?>
				<?php
				$link_args = array( 'student' => $student_id, 'screen' => $key );
				if ( $family_id ) $link_args['family'] = $family_id;
				?>
				<a class="<?php echo $current === $key ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( $link_args, self::url() ) ); ?>">
					<?php echo esc_html( $cfg['label'] ); ?>
					<?php if ( 'messages' === $key && $unread ) : ?><span class="bf-dot" aria-label="<?php echo esc_attr( $unread . ' unread' ); ?>"></span><?php endif; ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Home                                                                */
	/* ------------------------------------------------------------------ */

	private static function screen_home( $student_id ) {
		$f = function ( $key ) use ( $student_id ) {
			return BFTD_Fields::get( $student_id, 'student', $key );
		};

		$report   = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		$sessions = $report ? BFTD_CPT::sessions_for( $report ) : array();
		$last     = $sessions ? $sessions[0] : 0;
		$user     = wp_get_current_user();
		?>
		<div class="bf-head">
			<p class="bf-eyebrow"><?php echo esc_html( BFTD_Time::format( 'l, j F' ) ); ?></p>
			<h1>Good <?php echo esc_html( self::part_of_day() ); ?>, <?php echo esc_html( $user->first_name ? $user->first_name : $user->display_name ); ?></h1>
			<?php
			/*
			 * From the diagnostic, not from a field somebody typed.
			 *
			 * This line greeted a family with whatever had last been entered
			 * on the student record, which stopped being true the moment a
			 * reassessment was written up: the chart further down the same
			 * portal said Grade 4 while this said Grade 2.
			 */
			$levels = BFTD_Schema::reading_levels();
			$level  = BFTD_CPT::from_diagnostics( $student_id )['level'];
			$level  = isset( $levels[ $level ] ) && $level ? $levels[ $level ] : '';
			?>
			<?php if ( $level ) : ?>
				<p class="lede"><?php echo esc_html( get_the_title( $student_id ) . ' is reading at ' . $level . '.' ); ?></p>
			<?php else : ?>
				<?php $welcome = BFTD_Settings::portal()['welcome']; ?>
				<?php if ( trim( (string) $welcome ) ) : ?><p class="lede"><?php echo esc_html( $welcome ); ?></p><?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
		// Never a typed-in string. The first booked date in the schedule, so
		// a reschedule updates the family's home screen by itself.
		$next_label = BFTD_Schedule::next_lesson_label( $student_id );
		$bank       = BFTD_Schedule::bank( $student_id );
		?>
		<?php if ( $next_label ) : ?>
			<div class="bf-next">
				<p class="bf-eyebrow">Next session</p>
				<p class="bf-when"><?php echo esc_html( $next_label ); ?></p>
				<?php if ( BFTD_Fields::has_value( $f( 'join_url' ) ) ) : ?>
					<p><a class="bf-btn" href="<?php echo esc_url( $f( 'join_url' ) ); ?>">Join the session</a></p>
				<?php endif; ?>
				<?php if ( $bank['purchased'] ) : ?>
					<p class="bf-bank"><?php
						echo esc_html( $bank['used'] . ' of ' . $bank['purchased'] . ' sessions used' );
						echo esc_html( ', ' . $bank['remaining'] . ' remaining' );
					?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( $last ) : self::session_card( $last, $student_id ); endif; ?>

		<?php
		/*
		 * Measured, not typed. Both figures come off the diagnostics now, the
		 * same two the chart on the progress report is drawn between: the
		 * speed on the last assessment, and the speed on the first. When
		 * there has only been one, there is a figure but no journey, so the
		 * second line stays away rather than saying the child started where
		 * they are.
		 */
		$speed = BFTD_CPT::from_diagnostics( $student_id );
		?>
		<?php if ( $speed['wpm'] ) : ?>
			<div class="bf-card">
				<p class="bf-eyebrow">Reading speed</p>
				<p class="bf-fig"><?php echo (int) $speed['wpm']; ?> <span>words correct per minute</span></p>
				<?php if ( $speed['wpm_start'] && $speed['wpm_start'] !== $speed['wpm'] ) : ?>
					<p class="bf-sub">Started at <?php echo (int) $speed['wpm_start']; ?>.</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function part_of_day() {
		$h = (int) BFTD_Time::format( 'G' );
		if ( $h < 12 ) return 'morning';
		if ( $h < 18 ) return 'afternoon';
		return 'evening';
	}

	private static function session_card( $session_id, $student_id ) {
		$get = function ( $k ) use ( $session_id ) { return BFTD_Fields::get( $session_id, 'session', $k ); };
		$date = $get( 'session_date' );
		?>
		<div class="bf-card">
			<p class="bf-eyebrow">Last session<?php echo $date ? esc_html( ' · ' . BFTD_Time::day( $date, self::date_format() ) ) : ''; ?></p>
			<?php
			$acts = BFTD_Activities::for_session( $session_id );
			if ( $acts ) : ?>
				<ul class="bf-tags">
					<?php foreach ( $acts as $a ) : ?>
						<?php
						// The name, not the label. "Track 2 & 3 · 1 · Up tea
						// earn weigh" is how a tutor finds an activity in a
						// library of two hundred and eighty; to a family the
						// track and the number are filing references for a
						// cabinet they have never seen.
						?>
						<li class="bf-tag"><?php echo esc_html( $a['name'] ); ?><?php
							echo '' !== $a['note'] ? ' <i>' . esc_html( $a['note'] ) . '</i>' : '';
						?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( self::recording_live( $session_id, $student_id ) ) : ?>
				<p><a class="bf-btn bf-btn-ghost" href="<?php echo esc_url( $get( 'recording_url' ) ); ?>">Watch the recording</a>
				<span class="bf-expiry"><?php echo esc_html( self::recording_note( $session_id, $student_id ) ); ?></span></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Recordings are available for a fixed window, set per student. The check
	 * is done at render, not by a cleanup job, so a link can never outlive the
	 * window because a scheduled task did not run.
	 */
	private static function recording_live( $session_id, $student_id ) {
		$url = BFTD_Fields::get( $session_id, 'session', 'recording_url' );
		if ( ! BFTD_Fields::has_value( $url ) ) return false;
		return self::recording_days_left( $session_id, $student_id ) > 0;
	}

	private static function recording_days_left( $session_id, $student_id ) {
		$date = BFTD_Fields::get( $session_id, 'session', 'session_date' );
		if ( ! $date ) return 0;
		$days = (int) BFTD_Fields::get( $student_id, 'student', 'recording_days' );
		if ( $days <= 0 ) $days = (int) BFTD_Settings::portal()['recording_days'];
		if ( $days <= 0 ) return 0;
		$expires = BFTD_Time::stamp( BFTD_Time::add_days( $date, $days ), '23:59' );
		return (int) max( 0, ceil( ( $expires - time() ) / DAY_IN_SECONDS ) );
	}

	private static function recording_note( $session_id, $student_id ) {
		$left = self::recording_days_left( $session_id, $student_id );
		return 1 === $left ? 'Available for one more day' : 'Available for ' . $left . ' more days';
	}

	/* ------------------------------------------------------------------ */
	/* A report screen                                                     */
	/* ------------------------------------------------------------------ */

	private static function screen_report( $student_id, $post_type, $screen ) {
		// A diagnostic is picked by the date of the assessment, not by the date
		// the record was written, so the family opens the same one the cards on
		// their progress report were drawn from.
		$report = ( BFTD_CPT::ASSESSMENT === $post_type )
			? BFTD_CPT::current_diagnostic( $student_id )
			: BFTD_CPT::report_for( $student_id, $post_type );
		if ( ! $report ) {
			echo '<p class="bf-empty">This is not ready yet. Your tutor will let you know the moment it is.</p>';
			return;
		}
		self::report_body( $report, $student_id, $screen );
	}

	/**
	 * One report, rendered.
	 *
	 * Split out from the screen so a preview can render a particular report,
	 * including a draft, rather than whatever the family would currently see.
	 * It is the same method the portal itself uses, which is the point: a
	 * preview that went through a second template would start telling a
	 * comfortable lie about the design the week after it was written.
	 */
	public static function report_body( $report, $student_id, $screen, $heading = '' ) {
		// One renderer, and it is the one that matches the design. See
		// BFTD_Report_View for why the markup is the prototype's own.
		BFTD_Report_View::render( $report, $student_id, $screen, $heading );
	}

	/**
	 * Draw a section's chart, or nothing.
	 *
	 * Nothing is the right answer more often than it looks: a fluency chart
	 * needs at least two readings and a coverage grid needs at least one
	 * lesson with activities recorded. An empty axis is worse than no chart,
	 * because it reads as something broken rather than something not started.
	 */
	private static function chart( $kind, $report_id, $section_id ) {
		if ( ! class_exists( 'BFTD_Charts' ) ) return '';
		// No milestone cards here. They belong to the assessments rather than
		// to either measure, and the card that knows whether it is drawing one
		// chart or two is the only place that can draw them exactly once.
		if ( 'journey' === $kind )  return BFTD_Charts::journey( $report_id );
		if ( 'coverage' === $kind ) return BFTD_Charts::coverage( $report_id );
		if ( 'texts' === $kind )    return BFTD_Charts::texts( $report_id );
		if ( 'attendance' === $kind ) return BFTD_Charts::attendance( $report_id );
		if ( 'wpm' === $kind )      return BFTD_Charts::wpm( $report_id );
		return '';
	}

	/** The site's date format, or a readable one when it has none. */
	private static function date_format() {
		$f = get_option( 'date_format' );
		return $f ? $f : 'j F Y';
	}

	/** A single field, as the report renders it. Used by BFTD_Report_View. */
	public static function report_field( $key, $field, $value ) {
		self::render_value( $key, $field, $value );
	}

	/** A section's picture, if it has one. Used by BFTD_Report_View. */
	public static function report_chart( $kind, $report_id, $section_id ) {
		return self::chart( $kind, $report_id, $section_id );
	}

	private static function render_value( $key, $field, $value ) {
		$type = isset( $field['type'] ) ? $field['type'] : 'text';

		// A sentence introducing a section is a sentence, not a row in a
		// table of properties. The prototype sets these as a lede above the
		// thing they introduce, and so does this.
		if ( ! empty( $field['lede'] ) ) {
			echo '<p class="lede">' . esc_html( (string) $value ) . '</p>';
			return;
		}

		if ( 'staff' === $type ) {
			// Stored as an id, read as a name, so the report stays right when
			// somebody's name changes.
			$u = get_userdata( (int) $value );
			if ( ! $u ) return;
			$value = $u->display_name;
			$type  = 'text';
		}

		// A fixed checklist becomes the same rows the design's list already
		// draws, so there is one renderer for both and they cannot drift
		// into looking like two different components.
		if ( isset( $field['type'] ) && 'checklist' === $field['type'] ) {
			$made = array();
			foreach ( $field['rows'] as $rid => $label ) {
				$row = isset( $value[ $rid ] ) && is_array( $value[ $rid ] ) ? $value[ $rid ] : array();
				if ( empty( $row['value'] ) ) continue;
				$made[] = array(
					'item'  => $label,
					'value' => $row['value'],
					'note'  => isset( $row['note'] ) ? $row['note'] : '',
				);
			}
			if ( $made ) {
				self::conv_list( array(
					'title'    => isset( $field['title'] ) ? $field['title'] : '',
					'label'    => $field['label'],
					'headings' => isset( $field['headings'] ) ? $field['headings'] : array(),
					'columns'  => array( 'value' => array( 'options' => $field['options'] ) ),
				), $made );
			}
			return;
		}

		if ( 'rows' === $type ) {
			$rows = BFTD_Fields::visible_rows( $value );
			if ( ! $rows ) return;

			if ( isset( $field['style'] ) && 'conv' === $field['style'] ) {
				self::conv_list( $field, $rows );
				return;
			}

			// A column nobody has filled in is not shown. An empty column is
			// not information, and a family reading a table of blanks assumes
			// something has gone wrong rather than that it is early days.
			$cols = array();
			foreach ( $field['columns'] as $ck => $col ) {
				foreach ( $rows as $row ) {
					if ( isset( $row[ $ck ] ) && '' !== trim( (string) $row[ $ck ] ) ) { $cols[ $ck ] = $col; break; }
				}
			}
			if ( ! $cols ) return;

			echo '<div class="tablewrap"><table class="doc"><thead><tr>';
			foreach ( $cols as $col ) echo '<th>' . esc_html( $col['label'] ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( $cols as $ck => $col ) {
					$cell = isset( $row[ $ck ] ) ? $row[ $ck ] : '';
					if ( 'select' === $col['type'] && isset( $col['options'][ $cell ] ) ) $cell = $col['options'][ $cell ];
					if ( 'date' === $col['type'] && $cell ) $cell = BFTD_Time::day( $cell, self::date_format() );
					echo '<td>' . esc_html( $cell ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table></div>';
			return;
		}

		if ( 'gallery' === $type ) {
			// The design draws a work sample as a figure in the flow of the
			// document, not as a thumbnail strip. A scan of what a child
			// actually wrote is the evidence behind the paragraph above it.
			//
			// An entry is normally an attachment id. A plain string is taken
			// as a URL, which is what lets a sample report and an import both
			// show a picture without inventing a media library entry.
			foreach ( (array) $value as $entry ) {
				if ( is_numeric( $entry ) ) {
					$img  = wp_get_attachment_image( (int) $entry, 'large', false, array( 'loading' => 'lazy', 'alt' => 'Work sample' ) );
					$full = wp_get_attachment_image_url( (int) $entry, 'full' );
				} else {
					$url  = esc_url( (string) $entry );
					if ( ! $url ) continue;
					$img  = '<img loading="lazy" src="' . $url . '" alt="Work sample">';
					$full = '';
				}
				if ( ! $img ) continue;
				echo '<figure class="shot">';
				echo $full ? '<a href="' . esc_url( $full ) . '">' . $img . '</a>' : $img;
				echo '</figure>';
			}
			return;
		}

		if ( 'media' === $type ) {
			$img = wp_get_attachment_image( (int) $value, 'large', false, array( 'loading' => 'lazy', 'alt' => 'Work sample' ) );
			if ( $img ) echo '<figure class="shot">' . $img . '</figure>';
			return;
		}

		$label = $field['label'];

		if ( 'rich' === $type ) {
			// Filtered on save and filtered again here. A stored value is not
			// trusted just because it was clean when it was written.
			echo self::rich_html( $value );
			return;
		}

		if ( 'select' === $type && isset( $field['options'][ $value ] ) ) {
			$value = $field['options'][ $value ];
		}
		if ( 'date' === $type ) {
			// Through the plugin's own clock, so a date says the same thing
			// here as it does everywhere else, and an unset date_format
			// cannot render it as nothing at all.
			$value = BFTD_Time::day( $value, self::date_format() );
		}

		echo '<p class="kv"><span class="k">' . esc_html( $label ) . '</span><span class="v">' . esc_html( $value ) . '</span></p>';
	}

	/**
	 * A checklist, as the design draws one.
	 *
	 * A verdict chip beside the convention and its note, rather than three
	 * columns of a table. Seventeen writing conventions in a table is a
	 * horizontal scroll on a phone and a wall of repeated words on a desk;
	 * as a list it reads down the page, and the yes, no and not applicable
	 * chips are scannable without reading a single row.
	 *
	 * A row marked as a heading divides the list. It carries no verdict,
	 * because it is not a finding.
	 */
	private static function conv_list( $field, $rows ) {
		$opts = isset( $field['columns']['value']['options'] ) ? $field['columns']['value']['options'] : array();
		$head = isset( $field['headings'] ) ? $field['headings'] : array( 'value' => 'Result', 'item' => $field['label'] );

		// A heading above the list, where the section gives it one. A table of
		// six findings with no title is a table a parent has to infer the
		// purpose of from its rows.
		if ( ! empty( $field['title'] ) ) {
			echo '<h4 class="figs-t">' . esc_html( $field['title'] ) . '</h4>';
		}

		// Where the column heading only repeats the title just drawn, it is
		// left off. A parent reading the same words on two lines running
		// reads it as a fault in the report, not as a column heading.
		$item_head = ( ! empty( $field['title'] ) && BFTD_Schema::heading_repeats( $head['item'], $field['title'] ) )
			? ''
			: $head['item'];

		echo '<div class="conv">';
		echo '<div class="conv-head"><span>' . esc_html( $head['value'] ) . '</span><span>' . esc_html( $item_head ) . '</span></div>';

		foreach ( $rows as $row ) {
			$item = isset( $row['item'] ) ? trim( (string) $row['item'] ) : '';
			$val  = isset( $row['value'] ) ? (string) $row['value'] : '';
			$note = isset( $row['note'] ) ? trim( (string) $row['note'] ) : '';
			if ( '' === $item ) continue;

			if ( 'group' === $val ) {
				echo '<div class="conv-group">' . wp_kses_post( $item ) . '</div>';
				continue;
			}

			$label = isset( $opts[ $val ] ) ? $opts[ $val ] : $val;
			echo '<div class="conv-row">';
			echo '<span class="conv-v ' . esc_attr( $val ) . '">' . esc_html( $label ) . '</span>';
			echo '<div class="conv-b"><div class="conv-t">' . wp_kses_post( $item ) . '</div>';
			if ( '' !== $note ) echo '<div class="conv-n">' . wp_kses_post( $note ) . '</div>';
			echo '</div></div>';
		}
		echo '</div>';
	}

	/**
	 * A rich section body, as the design's document.
	 *
	 * Two things happen here beyond filtering. A table gets the scroll
	 * wrapper the design gives it, because an assessment table is wider than
	 * a phone and without the wrapper it pushes the whole page sideways. And
	 * a figure that a paragraph has swallowed is lifted back out, because
	 * wpautop wraps a block-level figure inside a <p> and the design's
	 * figure styling then has nothing to hang on.
	 */
	public static function rich_html( $value ) {
		$html = wp_kses_post( wpautop( $value ) );

		// Any wrapper already there is dropped and put back, so this is the
		// same whether the body was written once or edited five times.
		$html = preg_replace( '#<div class="tablewrap">\s*(<table\b.*?</table>)\s*</div>#is', '$1', $html );

		// Every table the design draws is a .doc table in a scroll wrapper.
		// An assessment table is wider than a phone, and without the wrapper
		// it pushes the whole page sideways.
		$html = preg_replace_callback( '#<table\b([^>]*)>(.*?)</table>#is', function ( $m ) {
			$attrs = $m[1];
			if ( false === stripos( $attrs, 'class=' ) ) {
				$attrs .= ' class="doc"';
			} elseif ( ! preg_match( '#class=("|\')([^"\']*\bdoc\b[^"\']*)\1#i', $attrs ) ) {
				$attrs = preg_replace( '#class=("|\')#i', 'class=$1doc ', $attrs, 1 );
			}
			return '<div class="tablewrap"><table' . $attrs . '>' . $m[2] . '</table></div>';
		}, $html );

		// wpautop wraps a block in a paragraph it has no business being in,
		// and the design's styling then has nothing to hang on.
		$html = preg_replace( '#<p>\s*(<figure\b.*?</figure>)\s*</p>#is', '$1', $html );
		$html = preg_replace( '#<p>\s*(<div class="tablewrap">.*?</table></div>)\s*</p>#is', '$1', $html );

		return $html;
	}

	public static function render_sessions( $report, $student_id ) {
		// Oldest first is the order a lesson number counts along; the list is
		// drawn newest first. One sort, then reversed, so the numbers and the
		// order on the page cannot disagree about which lesson is the third.
		$order = BFTD_CPT::sessions_in_order( $report );
		if ( ! $order ) return;

		$numbers = BFTD_CPT::lesson_numbers( $order );
		$ids     = array_reverse( $order );

		// Which lesson each activity was introduced on, worked out across the
		// whole report before any of it is drawn. A lesson cannot answer this
		// from inside itself, and the list runs newest first, so asking per
		// lesson would call the last lesson of the year the first time
		// everything was taught.
		$first = array();
		foreach ( array_reverse( $ids ) as $sid ) {
			foreach ( BFTD_Activities::for_session( $sid ) as $a ) {
				if ( ! isset( $first[ $a['key'] ] ) ) $first[ $a['key'] ] = (int) $sid;
			}
		}

		// No status check here. BFTD_CPT::sessions_for answers that once, for
		// every reader, which is what stopped the lesson count and the skills
		// grid disagreeing with this list about what a family can see.
		foreach ( $ids as $sid ) {
			self::render_session( $sid, $student_id, $first, $numbers );
		}
	}

	/**
	 * One lesson, as the design draws it.
	 *
	 * A date block, the activities as tags so a parent can see at a glance
	 * what a lesson contained, and the detail behind a chevron. An activity
	 * taught for the first time gets its own explained block, which is what
	 * makes the report readable to somebody who has never heard of phoneme
	 * segmentation.
	 */
	private static function render_session( $session_id, $student_id, $first = null, $numbers = null ) {
		$get  = function ( $k ) use ( $session_id ) { return BFTD_Fields::get( $session_id, 'session', $k ); };
		$date = $get( 'session_date' );
		$time = $get( 'session_time' );

		// The number is the lesson's place in the sequence. Handed in where
		// the caller has already counted the whole report, so drawing
		// sixteen lessons is one count rather than sixteen; worked out here
		// for a lesson drawn on its own. Either way it is the same rule, and
		// nothing reads a stored number, because there is not one.
		$num  = ( null !== $numbers && isset( $numbers[ $session_id ] ) )
			? $numbers[ $session_id ]
			: BFTD_CPT::lesson_number( $session_id );
		$ts   = $date ? BFTD_Time::stamp( $date, '12:00' ) : 0;

		$acts = BFTD_Activities::for_session( $session_id );

		// Introduced here, or simply practised. Where the caller has worked
		// it out across the report it is used; a lesson rendered on its own
		// treats everything on it as introduced, which is true of the only
		// lesson anybody is looking at.
		$new = array();
		foreach ( $acts as $a ) {
			$is_new = ( null === $first )
				? true
				: ( isset( $first[ $a['key'] ] ) && (int) $first[ $a['key'] ] === (int) $session_id );
			if ( $is_new ) $new[] = $a;
		}

		// How much is written behind the chevron. The tags above say what the
		// lesson contained; this says whether there is anything to read.
		// How much is written behind the chevron. An activity counts once if
		// anything at all is said about it, which is the same test the blocks
		// below use, so the number never promises a part that is not there.
		$blocks = 0;
		foreach ( $acts as $a ) {
			$is_new = false;
			foreach ( $new as $n ) { if ( $n['key'] === $a['key'] ) { $is_new = true; break; } }
			$has = ( $is_new && '' !== trim( (string) $a['about'] ) )
				|| '' !== trim( wp_strip_all_tags( (string) $a['note'] ) )
				|| array_filter( (array) $a['samples'] );
			if ( $has ) $blocks++;
		}
		foreach ( array( 'notes', 'homework' ) as $k ) {
			if ( BFTD_Fields::has_value( $get( $k ) ) ) $blocks++;
		}

		// What the session reached and what was read in it. Both were being
		// counted into the report's own summaries and neither was shown on the
		// session itself, so a parent reading one session could see the
		// activity and the tutor's notes but not the skills behind them or the
		// book in front of the child.
		$skills = BFTD_Skills::for_session( $session_id );
		$texts  = array();
		foreach ( (array) $get( 'texts' ) as $row ) {
			if ( ! is_array( $row ) ) continue;
			$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
			if ( '' === $title ) continue;
			$texts[] = array(
				'title' => $title,
				// "Level 1" rather than "1". One rule, asked of the schema, so
				// the session record and the reading log cannot write the same
				// book's level two different ways.
				'level' => BFTD_Charts::text_level_label( isset( $row['level'] ) ? $row['level'] : '' ),
			);
		}
		if ( $skills ) $blocks++;
		if ( $texts )  $blocks++;
		?>
		<article class="session" id="session-<?php echo (int) $session_id; ?>" data-n="<?php echo esc_attr( $num ); ?>">
			<button class="session-head" type="button" aria-expanded="false">
				<span class="session-date">
					<span class="d"><?php echo esc_html( $ts ? BFTD_Time::format( 'j', $ts ) : '' ); ?></span>
					<span class="m"><?php echo esc_html( $ts ? BFTD_Time::format( 'M', $ts ) : '' ); ?></span>
				</span>
				<span class="session-body">
					<span class="session-title"><?php echo esc_html( $num ? 'Session ' . $num : 'Session' ); ?></span>
					<span class="meta"><?php
						$bits = array();
						if ( $ts ) $bits[] = BFTD_Time::format( 'j M Y', $ts );
						if ( $time ) $bits[] = BFTD_Schedule::pretty_time( $time );
						if ( $blocks ) $bits[] = $blocks . ( 1 === $blocks ? ' part' : ' parts' );
						echo esc_html( implode( ' · ', $bits ) );
					?></span>
					<?php if ( $acts ) : ?>
						<span class="tags">
							<?php foreach ( $acts as $a ) :
								$is_new = ( null === $first )
									|| ( isset( $first[ $a['key'] ] ) && (int) $first[ $a['key'] ] === (int) $session_id );
								?>
								<span class="tag-act"><?php
									if ( $is_new ) echo '<span class="new">New</span>';
									// The name alone. See the bf-tag above.
									echo esc_html( $a['name'] );
								?></span>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</span>
				<span class="chev"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></span>
			</button>

			<div class="acc"><div class="acc-in"><div class="acc-pad">
				<?php
				/*
				 * One block per activity, where there is anything to say about
				 * it. Three things can appear, and which ones do is what makes
				 * the block worth drawing at all:
				 *
				 *   the explanation, the first lesson it was taught, written
				 *   once in the library rather than retyped every time;
				 *   what the tutor wrote about it on this lesson;
				 *   the child's work from it.
				 *
				 * An activity with none of the three is already named in the
				 * tags above, so drawing an empty panel for it would only push
				 * the parts that do have something further down the page.
				 */
				$newkeys = array();
				foreach ( $new as $a ) $newkeys[ $a['key'] ] = true;

				foreach ( $acts as $a ) :
					$is_new  = isset( $newkeys[ $a['key'] ] );
					$about   = $is_new ? trim( (string) $a['about'] ) : '';
					$note    = trim( (string) $a['note'] );
					$shots   = array_filter( (array) $a['samples'] );
					if ( '' === $about && '' === wp_strip_all_tags( $note ) && ! $shots ) continue;
					?>
					<div class="blk<?php echo $is_new ? ' blk-new' : ''; ?>">
						<div class="blk-h"><?php
							if ( $is_new ) echo '<span class="newpill">New</span>';
							/*
							 * Named as an activity, not just named. On a
							 * session record these blocks sit among "What we
							 * did", "Homework" and "Skills practiced", all of
							 * which say what they are; a bare title like
							 * "Up tea earn weigh" read as one more heading
							 * rather than as the thing the child worked on.
							 */
							echo '<span class="blk-k">Activity:</span> ';
							echo esc_html( $a['name'] );
						?></div>
						<?php if ( '' !== $about ) : ?>
							<?php
							/*
							 * One sentence, with the rest a click away.
							 *
							 * An activity's description is written for a parent
							 * who wants to know what the thing is, and a session
							 * with six activities was six paragraphs of
							 * curriculum before the first word about their own
							 * child. The first sentence says what it is; the
							 * rest is there for whoever wants it.
							 *
							 * Shipped OPEN, with the script closing it. A
							 * family whose scripts did not run reads the whole
							 * description, which is the old behaviour, rather
							 * than one sentence and a button that does nothing.
							 */
							$gist = self::first_sentence( $about );
							$more = '' !== $gist && wp_strip_all_tags( $gist ) !== trim( wp_strip_all_tags( $about ) );
							?>
							<div class="doc-body about<?php echo $more ? ' has-more' : ''; ?>"<?php
								echo $more ? ' data-gist="' . esc_attr( $gist ) . '"' : ''; ?>><?php
								echo wp_kses_post( wpautop( $about ) );
							?></div>
						<?php endif; ?>
						<?php if ( '' !== wp_strip_all_tags( $note ) ) : ?>
							<div class="doc-body"><?php echo wp_kses_post( wpautop( $note ) ); ?></div>
						<?php endif; ?>
						<?php if ( $shots ) : ?>
							<div class="samples"><?php
								foreach ( $shots as $aid ) echo self::work_shot( (int) $aid );
							?></div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php
				/*
				 * The skills this session reached, named.
				 *
				 * Most of them come from the activities rather than from
				 * anything typed here, which is the point: a parent should not
				 * have to know that "Sound Lines" is where a child learns to
				 * map sounds to their spellings. Where a tutor wrote something
				 * about one, that is under it.
				 */
				?>
				<?php if ( $skills ) : ?>
					<div class="blk blk-skills">
						<div class="blk-h">Skills practiced</div>
						<ul class="skill-list">
							<?php foreach ( $skills as $one ) : ?>
								<li>
									<span class="s-n"><?php echo esc_html( $one['name'] ); ?></span>
									<?php if ( '' !== trim( (string) $one['note'] ) ) : ?>
										<span class="s-note"><?php echo esc_html( $one['note'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php if ( $texts ) : ?>
					<div class="blk blk-read">
						<div class="blk-h">What we read</div>
						<ul class="text-list">
							<?php foreach ( $texts as $t ) : ?>
								<li>
									<span class="t-n"><?php echo esc_html( $t['title'] ); ?></span>
									<?php if ( '' !== $t['level'] ) : ?>
										<span class="t-l"><?php echo esc_html( $t['level'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>

				<?php
				/*
				 * The headings say who is speaking.
				 *
				 * An activity description is the practice explaining what an
				 * activity is. A skill description is the practice explaining
				 * what a skill is. This is the tutor writing to this family
				 * about this child, and it was headed "What we did", which read
				 * as one more block of the same curriculum text. It is the part
				 * a parent came for, so it says so.
				 */
				$blks = array(
					'notes'    => array( 'A note from your tutor', 'blk-notes' ),
					// Homework keeps its name. Only the tutor's note needed one.
					'homework' => array( 'Homework', 'blk-hw' ),
				);
				foreach ( $blks as $k => $meta ) :
					if ( ! BFTD_Fields::has_value( $get( $k ) ) ) continue; ?>
					<div class="blk <?php echo esc_attr( $meta[1] ); ?>">
						<div class="blk-h"><?php echo esc_html( $meta[0] ); ?></div>
						<div class="doc-body"><?php echo wp_kses_post( wpautop( $get( $k ) ) ); ?></div>
					</div>
				<?php endforeach; ?>

				<?php $samples = array_filter( (array) $get( 'samples' ) ); ?>
				<?php if ( $samples ) : ?>
					<div class="blk blk-work">
						<div class="blk-h">Work from the session</div>
						<div class="samples"><?php
							foreach ( $samples as $aid ) echo self::work_shot( (int) $aid );
						?></div>
					</div>
				<?php endif; ?>

				<?php if ( self::recording_live( $session_id, $student_id ) ) : ?>
					<p><a class="btn btn-ghost" href="<?php echo esc_url( $get( 'recording_url' ) ); ?>">Watch the recording</a>
					<span class="meta"><?php echo esc_html( self::recording_note( $session_id, $student_id ) ); ?></span></p>
				<?php endif; ?>

				<?php self::render_thread( $session_id, 'session-' . $session_id, 'this session' ); ?>
			</div></div></div>
		</article>
		<?php
	}

	/**
	 * A piece of a child's work, openable at full size.
	 *
	 * The medium size is what fits the page; the full size is what a parent
	 * actually wants when they are trying to read their own child's
	 * handwriting. One helper, because this is drawn in two places and two
	 * copies is how one of them keeps the old behaviour.
	 *
	 * A plain link to the file, so it works with no script at all: the image
	 * opens in a tab. The script upgrades that to a lightbox in place.
	 */
	public static function work_shot( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$img = wp_get_attachment_image( $attachment_id, 'medium', false, array( 'loading' => 'lazy' ) );
		if ( ! $img ) return '';

		$full = wp_get_attachment_image_url( $attachment_id, 'full' );
		if ( ! $full ) return '<figure class="shot">' . $img . '</figure>';

		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		$label = '' !== $alt ? $alt : 'this piece of work';

		return '<figure class="shot"><a class="shot-open" href="' . esc_url( $full ) . '"'
			. ' aria-label="' . esc_attr( 'Open ' . $label . ' at full size' ) . '">'
			. $img . '</a></figure>';
	}

	/** Words that end in a stop without ending a sentence. */
	private static function abbreviations() {
		return array( 'mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'rev', 'st', 'sr', 'jr',
			'etc', 'eg', 'ie', 'vs', 'approx', 'no', 'fig', 'al', 'mt', 'co', 'inc', 'ltd' );
	}

	/**
	 * The first sentence of some text.
	 *
	 * Used to show the gist of an activity description with the rest a click
	 * away, so being roughly right is enough: the whole description is one
	 * click from the reader and the cost of a bad guess is a first line that is
	 * a little long or a little short.
	 *
	 * Roughly right still has to mean a sentence. A full stop is not a sentence
	 * ending when the word before it is an abbreviation, and "Mrs. Pike reads
	 * with him first" cut at the first stop gives a parent the word "Mrs." A
	 * very short result is the signal that happened, so each candidate break is
	 * tried in turn and one that leaves almost nothing is passed over.
	 */
	public static function first_sentence( $html ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $html ) ) );
		if ( '' === $text ) return '';

		$abbr = self::abbreviations();
		$len  = function ( $str ) {
			return function_exists( 'mb_strlen' ) ? mb_strlen( $str ) : strlen( $str );
		};

		// Every full stop, question mark or exclamation followed by a space and
		// something that could start a sentence.
		if ( preg_match_all( '/[.!?](?=\s+["\x27(]?[A-Z0-9])/u', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				$cut   = (int) $hit[1] + 1;
				$first = trim( mb_substr( $text, 0, $cut ) );

				// The word carrying the stop. An abbreviation is not an ending.
				if ( preg_match( '/([A-Za-z]+)\.$/u', $first, $w )
					&& in_array( strtolower( $w[1] ), $abbr, true ) ) {
					continue;
				}

				// A sentence of three words is almost always a mis-split, and a
				// gist that short tells the reader nothing anyway.
				if ( $len( $first ) < 30 && $len( $text ) > 60 ) continue;

				return self::shorten( $first, $len );
			}
		}

		return self::shorten( $text, $len );
	}

	/** A gist longer than the point of having one is cut on length. */
	private static function shorten( $first, $len ) {
		if ( $len( $first ) > 220 ) {
			$first = rtrim( mb_substr( $first, 0, 200 ) ) . '…';
		}
		return $first;
	}

	/* ------------------------------------------------------------------ */
	/* Resources and setup                                                 */
	/* ------------------------------------------------------------------ */

	private static function screen_library( $student_id, $area ) {
		$items = get_posts( array(
			'post_type'      => BFTD_CPT::RESOURCE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'meta_query'     => array( array( 'key' => BFTD_Schema::meta_key( 'resource', 'area' ), 'value' => $area ) ),
		) );

		$section = BFTD_Schema::section( $area );
		echo '<div class="bf-head"><h1>' . esc_html( $section ? $section['label'] : 'Resources' ) . '</h1></div>';

		if ( ! $items ) {
			echo '<p class="bf-empty">Nothing here yet.</p>';
			return;
		}

		echo '<div class="bf-lib">';
		$n = 1;
		foreach ( $items as $item ) {
			$get = function ( $k ) use ( $item ) { return BFTD_Fields::get( $item->ID, 'resource', $k ); };
			echo '<article class="bf-lib-item"><span class="bf-lib-n">' . (int) $n . '</span><div>';
			echo '<h3>' . esc_html( $item->post_title ) . '</h3>';
			if ( BFTD_Fields::has_value( $get( 'lead' ) ) ) echo '<p class="lede">' . esc_html( $get( 'lead' ) ) . '</p>';
			if ( BFTD_Fields::has_value( $get( 'body' ) ) ) echo '<div class="doc-body">' . wp_kses_post( wpautop( $get( 'body' ) ) ) . '</div>';
			if ( BFTD_Fields::has_value( $get( 'video' ) ) ) echo '<p><a class="bf-btn bf-btn-ghost" href="' . esc_url( $get( 'video' ) ) . '">Watch</a></p>';
			if ( BFTD_Fields::has_value( $get( 'for_who' ) ) ) echo '<p class="bf-for">' . esc_html( $get( 'for_who' ) ) . '</p>';
			echo '</div></article>';
			$n++;
		}
		echo '</div>';

		$student_report = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		if ( $student_report ) self::render_thread( $student_report, $area, $section ? $section['label'] : 'resources' );
	}

	/* ------------------------------------------------------------------ */
	/* Conversations                                                       */
	/* ------------------------------------------------------------------ */

	public static function render_thread( $post_id, $section_id, $label ) {
		// A preview is for looking, not for writing. Offering a composer that
		// posts into a report somebody is still drafting would be a trap.
		if ( class_exists( 'BFTD_Preview' ) && BFTD_Preview::active() ) return;

		$thread = BFTD_Threads::find_thread( $post_id, $section_id );
		$msgs   = $thread ? BFTD_Threads::messages( $thread->comment_ID ) : array();
		?>
		<div class="bf-thread" data-post="<?php echo (int) $post_id; ?>" data-section="<?php echo esc_attr( $section_id ); ?>">
			<div class="bf-thread-h">
				<span class="bf-thread-ic" aria-hidden="true">
					<svg viewBox="0 0 20 20"><path d="M17 11.5a2.5 2.5 0 01-2.5 2.5H7l-4 3v-3H4.5A2.5 2.5 0 012 11.5v-6A2.5 2.5 0 014.5 3h10A2.5 2.5 0 0117 5.5z"/><path d="M6.5 7.2h7M6.5 10h4.5"/></svg>
				</span>
				<h3>Questions about this section</h3>
				<?php if ( $msgs ) : ?>
					<span class="bf-thread-n"><?php echo esc_html( count( $msgs ) ); ?> <?php echo esc_html( _n( 'message', 'messages', count( $msgs ), 'bftd' ) ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( ! $msgs ) : ?>
				<p class="bf-thread-empty">No one has asked about this yet. Anything you write here goes straight to your tutor and stays attached to this section.</p>
			<?php else : foreach ( $msgs as $m ) :
				$u    = $m->user_id ? get_userdata( $m->user_id ) : null;
				$mine = $u && (int) $u->ID === get_current_user_id(); ?>
				<div class="bf-msg <?php echo $mine ? 'is-mine' : ''; ?>">
					<span class="bf-avatar"><?php echo esc_html( self::initials( $u ) ); ?></span>
					<div class="bf-msg-b">
						<p class="bf-msg-h"><b><?php echo esc_html( $u ? $u->display_name : 'Someone' ); ?></b>
							<time datetime="<?php echo esc_attr( $m->comment_date_gmt ); ?>"><?php echo esc_html( human_time_diff( strtotime( $m->comment_date_gmt . ' UTC' ) ) ); ?> ago</time></p>
						<div class="bf-msg-t"><?php echo wp_kses_post( wpautop( $m->comment_content ) ); ?></div>
						<?php $files = BFTD_Attachments::for_comment( $m->comment_ID ); ?>
						<?php if ( $files ) : ?>
							<div class="bf-msg-att">
								<?php foreach ( $files as $f ) : ?>
									<a class="bf-att <?php echo $f['image'] ? 'is-img' : ''; ?>" href="<?php echo esc_url( $f['url'] ); ?>" target="_blank" rel="noopener">
										<?php if ( $f['thumb'] ) : ?><img alt="" src="<?php echo esc_url( $f['thumb'] ); ?>"><?php else : ?><span class="bf-att-ic"><?php echo esc_html( strtoupper( pathinfo( $f['name'], PATHINFO_EXTENSION ) ) ); ?></span><?php endif; ?>
										<span class="bf-att-n"><?php echo esc_html( $f['name'] ); ?></span>
										<span class="bf-att-s"><?php echo esc_html( $f['size'] ); ?></span>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; endif; ?>

			<div class="bf-rt">
				<div class="bf-rt-bar">
					<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
					<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
					<button type="button" data-cmd="insertUnorderedList" title="Bullet list">&#8226;</button>
					<span class="bf-rt-sep"></span>
					<button type="button" class="bf-rt-attach">Attach</button>
				</div>
				<div class="bf-rt-in" contenteditable="true" role="textbox" aria-label="Write a message"
					data-ph="<?php echo esc_attr( 'Ask about ' . $label ); ?>"></div>
				<div class="bf-rt-files" hidden></div>
				<div class="bf-rt-foot">
					<span class="bf-rt-hint">Paste a screenshot, drag files in, or attach. Up to 5.</span>
					<button type="button" class="bf-btn bf-rt-post">Post</button>
				</div>
				<input type="file" class="bf-rt-file" multiple hidden accept="image/*,.pdf,.doc,.docx,.txt,.heic">
			</div>
		</div>
		<?php
	}

	private static function initials( $user ) {
		if ( ! $user ) return '?';
		$parts = preg_split( '/\s+/', trim( $user->display_name ) );
		$out   = '';
		foreach ( array_slice( $parts, 0, 2 ) as $p ) $out .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		return $out ? $out : '?';
	}

	private static function screen_messages( $student_id ) {
		$threads = BFTD_Threads::threads_for_student( $student_id );
		echo '<div class="bf-head"><p class="bf-eyebrow">Every conversation in one place</p><h1>Messages</h1>';
		echo '<p class="lede">Threads started on a section, a report or a session all land here too, so nothing gets lost in a section you have not opened lately.</p></div>';

		if ( ! $threads ) {
			echo '<p class="bf-empty">No conversations yet. Ask a question on any section and it appears here.</p>';
			return;
		}

		echo '<div class="bf-hub">';
		foreach ( $threads as $t ) {
			$sec     = (string) get_comment_meta( $t->comment_ID, BFTD_Threads::SECTION_KEY, true );
			$section = BFTD_Schema::section( $sec );
			$last    = BFTD_Threads::last_message( $t->comment_ID );
			$who     = $last && $last->user_id ? get_userdata( $last->user_id ) : null;
			$unread  = BFTD_Threads::is_unread( $t->comment_ID );
			?>
			<a class="bf-hub-item <?php echo $unread ? 'is-unread' : ''; ?>" href="<?php echo esc_url( self::url( $student_id, $sec ) ); ?>">
				<span class="bf-hub-top">
					<?php if ( $unread ) : ?><span class="bf-dot" aria-hidden="true"></span><?php endif; ?>
					<span class="bf-hub-src"><?php echo esc_html( $section ? BFTD_Schema::screen_label( $section['screen'] ) : 'Portal' ); ?></span>
					<span class="bf-hub-when"><?php echo esc_html( human_time_diff( strtotime( $t->comment_date_gmt . ' UTC' ) ) ); ?></span>
				</span>
				<span class="bf-hub-title"><?php echo esc_html( BFTD_Threads::label_for( $sec, $t->comment_post_ID ) ); ?></span>
				<span class="bf-hub-prev"><?php echo esc_html( ( $who ? $who->display_name . ': ' : '' ) . wp_trim_words( wp_strip_all_tags( $last ? $last->comment_content : '' ), 18 ) ); ?></span>
			</a>
			<?php
		}
		echo '</div>';
	}
}
