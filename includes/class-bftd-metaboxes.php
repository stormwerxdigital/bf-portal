<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The edit screens.
 *
 * A report's builder is generated from BFTD_Schema, in the same order the
 * family reads it, so there is no separate template to keep in step. The
 * student record and the lesson record have their own hand-built boxes,
 * because those hold relationships and scheduling rather than report copy.
 */
class BFTD_MetaBoxes {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'subtitle_field' ) );
		add_action( 'wp_ajax_bftd_student_header', array( __CLASS__, 'ajax_student_header' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		// A lesson joins its progress report when it is published, by
		// whatever route: the form, Quick Edit, a bulk action, or a scheduled
		// post going live. Only the form posts our nonce, so the form alone
		// is not enough.
		add_action( 'transition_post_status', array( __CLASS__, 'on_session_published' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'flash' ) );
	}

	public static function on_session_published( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) return;
		if ( ! $post || BFTD_CPT::SESSION !== $post->post_type ) return;
		self::attach_session_to_report( $post->ID );
	}

	/**
	 * Say so when a student has two progress reports.
	 *
	 * A progress report is a living document, one per child, made
	 * automatically with their first lesson. A second one is almost always
	 * somebody pressing New Progress Report without knowing that.
	 *
	 * It used to be worse than redundant. The lessons were found through a
	 * link written onto each lesson pointing at whichever report was newest
	 * when it published, so a second report inherited none of them: a tutor
	 * sat looking at a report that said "Empty, so not shown to the family"
	 * over a term of lessons. The lessons are worked out from the child now,
	 * so both reports show them — but the family is still served only one of
	 * the two, and anything written into the wrong one is written nowhere a
	 * family will read. That is worth a sentence on the screen.
	 */
	private static function warn_about_duplicate_reports() {
		$post = get_post();
		if ( ! $post || BFTD_CPT::PROGRESS !== $post->post_type ) return;

		$student_id = BFTD_CPT::student_id( $post->ID );
		if ( ! $student_id ) return;

		$all = BFTD_CPT::progress_reports_for( $student_id );
		if ( count( $all ) < 2 ) return;

		$live  = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		$name  = get_the_title( $student_id );
		$mine  = ( $live === (int) $post->ID );

		echo '<div class="notice notice-warning"><p><strong>'
			. esc_html( $name . ' has ' . count( $all ) . ' progress reports.' )
			. '</strong> ';
		echo esc_html( $mine
			? 'This is the one the family is being shown. The others are not.'
			: 'The family is being shown a different one, so anything written here will not reach them.' );
		echo '</p><p>';
		if ( ! $mine && $live ) {
			$edit = get_edit_post_link( $live );
			if ( $edit ) echo '<a href="' . esc_url( $edit ) . '">Open the one the family sees</a>. ';
		}
		echo esc_html( 'Every session, text and skill shows on all of them, because those are read from ' . $name . '\'s own records. Only the written sections differ.' );
		echo '</p></div>';
	}

	/** One nonce per edit screen, however many boxes that screen shows. */
	private static $nonce_done = false;

	public static function nonce( $post_id ) {
		if ( self::$nonce_done ) return;
		self::$nonce_done = true;
		wp_nonce_field( 'bftd_save_' . $post_id, 'bftd_nonce' );
	}

	public static function boxes() {
		add_meta_box( 'bftd_student_family', 'Caregivers and tutors', array( __CLASS__, 'box_family' ), BFTD_CPT::STUDENT, 'normal', 'high' );
		add_meta_box( 'bftd_student_detail', 'Student details', array( __CLASS__, 'box_student' ), BFTD_CPT::STUDENT, 'normal', 'high' );
		add_meta_box( 'bftd_skills_done', 'Skills completed', array( __CLASS__, 'box_skills_done' ), BFTD_CPT::STUDENT, 'normal', 'default' );
		add_meta_box( 'bftd_student_crm', 'Contact and history', array( __CLASS__, 'box_crm' ), BFTD_CPT::STUDENT, 'normal', 'high' );
		add_meta_box( 'bftd_schedule', 'Sessions and schedule', array( __CLASS__, 'box_schedule' ), BFTD_CPT::STUDENT, 'normal', 'high' );
		add_meta_box( 'bftd_priority', 'Priority items', array( __CLASS__, 'box_priority' ), BFTD_CPT::STUDENT, 'normal', 'default' );
		add_meta_box( 'bftd_review', 'For review', array( __CLASS__, 'box_review' ), BFTD_CPT::STUDENT, 'normal', 'default' );

		// The same two lists on a report screen.
		//
		// A family meets these two cards at the top of the report, above the
		// sections, and the item editor's own "pin to section" control names
		// report sections. So the report is where a tutor writing the report
		// looks for them, and until now they were only on the student record
		// a screen away: the lists were being written in one place and read
		// in another, which is how a diagnostic goes out with the sections
		// finished and nothing asked of the family.
		//
		// The store does not move. Both doors open onto the student's own two
		// lists, because an item is a thing asked of a family, not a thing
		// belonging to one report, and a copy per report would leave a parent
		// ticking one list while the tutor read another.
		add_meta_box( 'bftd_priority', 'Priority items', array( __CLASS__, 'box_priority' ), array( BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS ), 'normal', 'default' );
		add_meta_box( 'bftd_review', 'For review', array( __CLASS__, 'box_review' ), array( BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS ), 'normal', 'default' );

		add_meta_box( 'bftd_parent', 'Student', array( __CLASS__, 'box_parent' ), BFTD_Access::report_types(), 'side', 'high' );
		add_meta_box( 'bftd_report_staff', 'Who can see this', array( __CLASS__, 'box_report_staff' ), BFTD_Access::report_types(), 'side', 'high' );
		add_meta_box( 'bftd_taught_by', 'Taught by', array( __CLASS__, 'box_taught_by' ), BFTD_CPT::SESSION, 'side', 'high' );
		add_meta_box( 'bftd_preview', 'See it as a family does', array( __CLASS__, 'box_preview' ), BFTD_Access::report_types(), 'side', 'high' );

		add_meta_box( 'bftd_report', 'The report, section by section', array( __CLASS__, 'box_report' ), array( BFTD_CPT::ASSESSMENT, BFTD_CPT::PROGRESS ), 'normal', 'high' );

		add_meta_box( 'bftd_session', 'Session record', array( __CLASS__, 'box_session' ), BFTD_CPT::SESSION, 'normal', 'high' );

		add_meta_box( 'bftd_resource', 'Resource', array( __CLASS__, 'box_resource' ), BFTD_CPT::RESOURCE, 'normal', 'high' );

		// The History panel is the activity log, one record at a time, so it
		// answers to the same rule as the Activity Log screen. Drawing it for
		// a tutor while hiding the page it comes from would be the same
		// information behind a thinner door.
		if ( BFTD_Roles::can_administer() ) {
			foreach ( BFTD_Roles::post_types() as $pt ) {
				add_meta_box( 'bftd_log', 'History', array( __CLASS__, 'box_log' ), $pt, 'normal', 'low' );
			}
		}
	}


	/* ------------------------------------------------------------------ */
	/* Shared: the assignment picker                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * A searchable multi-select for handing a record to people.
	 *
	 * The <select multiple> is the thing that actually posts, so the form
	 * still works with JavaScript off and nothing about saving depends on the
	 * widget. bftd-admin.js hides the select once it has painted the chips,
	 * and only ever toggles options that are already in it.
	 *
	 * When $editable is false the select is disabled, which posts nothing at
	 * all. Both save routines read that as "no opinion" rather than "remove
	 * everyone", so a tutor saving a record cannot strip its assignments.
	 *
	 * @param string        $name       Field name; posts as $name[].
	 * @param WP_User[]     $users      Everyone who could be picked.
	 * @param int[]         $selected   Who is picked now.
	 * @param string        $empty_text What to say when nobody is picked.
	 * @param string        $help_html  Help under the field. Limited HTML.
	 * @param bool          $editable   False renders it read only.
	 * @param callable|null $meta_cb    Given a WP_User, returns the small grey
	 *                                  line beside the name (role, or a
	 *                                  caregiver's relationship).
	 */
	public static function assign_field( $name, $users, $selected, $empty_text, $help_html, $editable = true, $meta_cb = null, $max = 0 ) {
		$selected = array_map( 'absint', (array) $selected );
		if ( 1 === (int) $max ) $selected = $selected ? array( $selected[0] ) : array();
		$id       = $name . '-' . wp_rand( 1000, 9999 );

		// Everything about who is picked is known right here, so the finished
		// state is what gets sent. Rendering an empty chip list and a bare
		// multi-select and then letting JavaScript rearrange it is what makes
		// the box flash into a different shape a moment after the page opens.
		// The select is hidden from the first byte and the chips arrive
		// already painted; bftd-admin.js repaints to exactly the same markup,
		// so nothing moves.
		$chosen = array();
		foreach ( $users as $u ) {
			if ( in_array( (int) $u->ID, $selected, true ) ) $chosen[] = $u;
		}
		?>
		<div class="bftd-assign"
			data-can-assign="<?php echo $editable ? '1' : '0'; ?>"
			data-max="<?php echo (int) $max; ?>"
			data-empty="<?php echo esc_attr( $empty_text ); ?>">

			<div class="bftd-chips">
				<?php if ( ! $chosen ) : ?>
					<span class="bftd-chip-none description"><?php echo esc_html( $empty_text ); ?></span>
				<?php else : ?>
					<?php foreach ( $chosen as $u ) : ?>
						<span class="bftd-chip" data-value="<?php echo (int) $u->ID; ?>">
							<span class="bftd-chip-name"><?php echo esc_html( $u->display_name ); ?></span>
							<span class="bftd-chip-role"><?php echo esc_html( $meta_cb ? (string) call_user_func( $meta_cb, $u ) : '' ); ?></span>
							<?php if ( $editable ) : ?>
								<button type="button" class="bftd-chip-x" aria-label="Remove"><span aria-hidden="true">&times;</span></button>
							<?php endif; ?>
						</span>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<?php if ( $editable ) : ?>
				<div class="bftd-assign-search">
					<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">Search people by name or email</label>
					<input type="search" id="<?php echo esc_attr( $id ); ?>" class="bftd-assign-in regular-text"
						placeholder="Search by name or email" autocomplete="off">
					<div class="bftd-assign-results" hidden></div>
				</div>
			<?php endif; ?>

			<select name="<?php echo esc_attr( $name ); ?>[]" class="bftd-assign-select bftd-assign-hidden" multiple size="6"
				<?php disabled( ! $editable ); ?>>
				<?php
				foreach ( $users as $u ) {
					$meta   = $meta_cb ? (string) call_user_func( $meta_cb, $u ) : '';
					$search = strtolower( $u->display_name . ' ' . $u->user_email . ' ' . $u->user_login . ' ' . $meta );
					printf(
						'<option value="%d" data-role="%s" data-email="%s" data-search="%s"%s>%s</option>',
						(int) $u->ID,
						esc_attr( $meta ),
						esc_attr( $u->user_email ),
						esc_attr( $search ),
						selected( in_array( (int) $u->ID, $selected, true ), true, false ),
						esc_html( $u->display_name )
					);
				}
				?>
			</select>

			<?php if ( $help_html ) : ?>
				<p class="description"><?php echo wp_kses_post( $help_html ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		self::assign_noscript();
	}

	/**
	 * Without JavaScript the chips are decoration and the select is the only
	 * way to change anything, so it comes back and they go away. Printed once
	 * per request rather than once per field.
	 */
	private static function assign_noscript() {
		static $done = false;
		if ( $done ) return;
		$done = true;
		?>
		<noscript><style>
			.bftd-assign .bftd-assign-select{position:static;width:auto;height:auto;margin:0;
				overflow:visible;clip:auto;white-space:normal}
			.bftd-assign .bftd-chips,.bftd-assign .bftd-assign-search{display:none}
		</style></noscript>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Student                                                             */
	/* ------------------------------------------------------------------ */

	public static function box_family( $post ) {
		self::nonce( $post->ID );
		$clients    = BFTD_CPT::client_ids( $post->ID );
		$primary    = BFTD_CPT::primary_client_id( $post->ID );
		$staff      = BFTD_CPT::staff_ids( $post->ID );
		$can_assign = BFTD_Roles::can_manage();
		?>
		<div class="bftd-box">
			<div class="bftd-field">
				<span class="bftd-label">Client</span>
				<?php
				self::assign_field(
					'bftd_client_primary',
					get_users( array( 'role__in' => array( BFTD_Roles::CLIENT_ROLE ), 'orderby' => 'display_name' ) ),
					$primary ? array( $primary ) : array(),
					'Nobody yet.',
					'The person the practice deals with, and the one the invoice goes to. One only. Whoever is named here is given access to the portal as well, so they do not also have to be added below.',
					true,
					function ( $u ) { return BFTD_Roles::relationship( $u->ID ); },
					1
				);
				?>
			</div>
			<div class="bftd-field">
				<span class="bftd-label">Caregivers who can see this student</span>
				<?php
				self::assign_field(
					'bftd_clients',
					get_users( array( 'role__in' => array( BFTD_Roles::CLIENT_ROLE ), 'orderby' => 'display_name' ) ),
					$clients,
					'Nobody linked yet.',
					'Parents, guardians, teachers and anyone else responsible for this student. Everyone listed sees the same portal and the same conversations. <a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Add an account</a>.',
					true,
					function ( $u ) { return BFTD_Roles::relationship( $u->ID ); }
				);
				?>
			</div>
			<div class="bftd-field">
				<span class="bftd-label">Tutors assigned</span>
				<?php
				self::assign_field(
					'bftd_staff',
					BFTD_Roles::staff_users(),
					$staff,
					'Unassigned.',
					"Only assigned tutors see this student, and they are who a family's question reaches. A Tutor Manager sees every student whether assigned or not."
						. ( $can_assign ? '' : "<br><strong>Changing who is assigned is a Tutor Manager's job</strong>, so this list is read only for you." ),
					$can_assign,
					function ( $u ) { return BFTD_Roles::role_name( $u->ID ); }
				);
				?>
			</div>
		</div>
		<?php
	}

	/** Kindergarten through Grade 12, then adult learners. */
	/**
	 * The details the CRM will keep in step once it is connected.
	 *
	 * Separate from Student details because these have a second author. Until
	 * the CRM is linked they are ordinary fields; afterwards the sync fills
	 * the blanks and leaves anything typed here alone.
	 */
	public static function box_crm( $post ) {
		self::nonce( $post->ID );
		$linked = BFTD_CRM::linked( $post->ID );
		?>
		<div class="bftd-box">
			<p class="description" style="margin:0 0 12px">
				<?php if ( $linked ) : ?>
					Linked to the CRM as <code><?php echo esc_html( $linked ); ?></code>.
					Last checked <?php echo esc_html( BFTD_CRM::last_synced_label( $post->ID ) ); ?>.
					The sync fills anything left blank here and leaves whatever you have typed alone.
				<?php else : ?>
					<strong>Not linked to the CRM yet.</strong> Fill these in by hand for now.
					Once this student is linked, the sync will fill any that are still blank and will
					never overwrite something you have typed.
				<?php endif; ?>
			</p>

			<div class="bftd-grid">
				<?php foreach ( self::crm_fields() as $key => $field ) : ?>
					<?php BFTD_Fields::render_field( $post->ID, 'student', $key, $field ); ?>
				<?php endforeach; ?>

				<?php
				/*
				 * Read from the reading diagnostics rather than typed.
				 *
				 * It was a date field next to the records that hold the date,
				 * so it was wrong on every student whose reassessment had
				 * been written up and whose CRM entry had not.
				 */
				$dx_on = BFTD_CPT::diagnostic_on( $post->ID );
				?>
				<div class="bftd-field">
					<span class="bftd-label">Diagnostic assessment</span>
					<p class="bftd-readonly"><?php
						echo $dx_on
							? esc_html( BFTD_Time::day( $dx_on, 'j M Y' ) )
							: '<span class="bftd-none">Not assessed yet</span>';
					?></p>
					<p class="description">The most recent reading diagnostic. Record one and this follows it.</p>
				</div>
			</div>

			<?php $held = BFTD_CRM::held_back( $post->ID ); ?>
			<?php if ( $held ) : ?>
				<p class="description" style="margin-top:10px">
					The CRM has a different value for
					<strong><?php echo esc_html( implode( ', ', $held ) ); ?></strong>,
					and is leaving <?php echo count( $held ) === 1 ? 'it' : 'them'; ?> alone because
					<?php echo count( $held ) === 1 ? 'it was' : 'they were'; ?> changed here.
					Clear the field to let the CRM fill it again.
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function grade_options() {
		$out = array( '' => 'Not set', '0' => 'Kindergarten' );
		for ( $i = 1; $i <= 12; $i++ ) $out[ (string) $i ] = 'Grade ' . $i;
		$out['adult'] = 'Adult';
		return $out;
	}

	/** The reading levels the diagnostic actually reports against. */
	public static function level_options() {
		$out = array( '' => 'Not set', 'preprimer' => 'Preprimer', 'primer' => 'Primer' );
		for ( $i = 1; $i <= 12; $i++ ) $out[ 'g' . $i ] = 'Grade ' . $i;
		return $out;
	}

	/** The fields the CRM will own once it is connected. */
	public static function crm_fields() {
		return array(
			'birth_date'    => array( 'type' => 'date',   'label' => 'Birth date' ),
			'school'        => array( 'type' => 'text',   'label' => 'School' ),
			'phone'         => array( 'type' => 'text',   'label' => 'Phone' ),
			'email'         => array( 'type' => 'text',   'label' => 'Email' ),
			'status'        => array( 'type' => 'select', 'label' => 'Status', 'options' => array(
				// A record starts life at the reading diagnostic, before
				// anybody has committed to a programme, so prospect comes
				// first and is where most new students begin.
				'prospect' => 'Prospect',
				'active'   => 'Active',
				'past'     => 'Past student',
			), 'help' => 'A new student starts as a prospect at their reading diagnostic.' ),
			'attend_from'   => array( 'type' => 'date',   'label' => 'Attending from' ),
			'attend_to'     => array( 'type' => 'date',   'label' => 'Attending to', 'help' => 'Left empty while they are still with us.' ),
		);
	}

	/**
	 * Where this student is in the programme.
	 *
	 * A track and a number in it, because the progress report counts from
	 * there: what is left in the track they are in, and what they have already
	 * done in the tracks they have been in before.
	 *
	 * Each track keeps its OWN starting number rather than sharing one.
	 * Students move from Track 1 into Tracks 2 and 3, and sometimes back, and
	 * they do not always start a track at one: somebody joining Tracks 2 and 3
	 * at skill ten starts at ten. One shared field would be overwritten by the
	 * move and the first track's history would be gone, which is the thing the
	 * report is for.
	 *
	 * The blank option is deliberate and is first. A select with no blank
	 * option posts its first real option, so a student nobody has placed yet
	 * would be silently stamped Track 1 by the first save, and the report would
	 * then count a programme they are not on.
	 */
	public static function track_fields() {
		$tracks = class_exists( 'BFTD_Activities' ) ? BFTD_Activities::tracks() : array();
		$out = array(
			'track' => array(
				'type'    => 'select',
				'label'   => 'Track',
				'options' => array( '' => 'Not chosen yet' ) + $tracks,
				'help'    => 'Which part of the programme this student is working through now. It can be changed as they move on.',
			),
		);
		foreach ( $tracks as $key => $label ) {
			$out[ 'start_' . $key ] = array(
				'type'  => 'number',
				'label' => 'Starting skill number in ' . $label,
				'help'  => 'The skill they began this track at. Not always one: somebody joining part way through starts where they joined.',
				// Only the one matching the chosen track is shown, by the
				// script. Both are rendered so switching track does not need a
				// page load, and so the number a student had in the track they
				// came from is still on the record when they go back to it.
				'class' => 'bftd-track-start bftd-track-start-' . $key,
			);
		}
		return $out;
	}

	private static function student_fields() {
		return array(
			'grade'        => array( 'type' => 'select', 'label' => 'Grade', 'options' => self::grade_options() ),
			'mode'         => array( 'type' => 'select', 'label' => 'Sessions are', 'options' => array(
				'online'    => 'Online',
				'in_person' => 'In person',
				'both'      => 'Both',
			) ),
			'lessons_bank' => array( 'type' => 'number', 'label' => 'Sessions purchased' ),
			'join_url'     => array( 'type' => 'text',   'label' => 'Joining link' ),
			'recording_days' => array( 'type' => 'number', 'label' => 'Recordings stay available for, in days', 'default' => '14' ),
		);
	}

	public static function box_student( $post ) {
		echo '<div class="bftd-box bftd-grid">';

		// When a family started with us is not something anyone should have
		// to type: it is the day the student was added. Shown rather than
		// asked for, so it cannot be left blank or contradicted.
		?>
		<div class="bftd-field">
			<span class="bftd-label">Started with us</span>
			<p class="bftd-readonly"><?php echo esc_html( BFTD_CPT::started_on_label( $post ) ); ?></p>
			<p class="description">Taken from the day this student was added.</p>
		</div>
		<?php

		foreach ( self::student_fields() as $key => $field ) {
			BFTD_Fields::render_field( $post->ID, 'student', $key, $field );
		}
		echo '</div>';

		echo '<div class="bftd-box bftd-grid bftd-track-box" data-track="'
			. esc_attr( (string) BFTD_Fields::get( $post->ID, 'student', 'track', array( 'type' => 'select' ) ) ) . '">';
		foreach ( self::track_fields() as $key => $field ) {
			BFTD_Fields::render_field( $post->ID, 'student', $key, $field );
		}
		echo '</div>';
	}

	/**
	 * Skills marked complete, for the track this student is on.
	 *
	 * Only the track they are on, because a list of two hundred and eighty with
	 * no division is not a list anybody ticks. What they completed in a track
	 * they have left is still counted and still on the report; it is simply not
	 * what a tutor is editing today, and the heading says so.
	 *
	 * A skill recorded on a published session is shown ticked and locked. It is
	 * complete because the record says so, and offering a tick that cannot
	 * change anything would be a control that lies. Unticking it means editing
	 * the session that claimed it, which is where that claim belongs.
	 */
	public static function box_skills_done( $post ) {
		$track = BFTD_Derived::track( $post->ID );

		echo '<div class="bftd-box">';
		// Always posted, so an empty list is an empty list rather than a form
		// that did not carry the field.
		echo '<input type="hidden" name="bftd_skills_done_present" value="1">';

		if ( '' === $track ) {
			echo '<p class="description">Choose a track above and save, and this student\'s skills will be listed here to tick off.</p></div>';
			return;
		}

		$sequence = BFTD_Skills::sequence( $track );
		if ( ! $sequence ) {
			echo '<p class="description">' . esc_html( BFTD_Skills::track_label( $track ) )
				. ' has no numbered skills yet, so there is nothing to tick. Numbers are set on each skill in the library.</p></div>';
			return;
		}

		$ticked    = array_fill_keys( BFTD_Skills::ticked_for_student( $post->ID ), true );
		$from_work = BFTD_Skills::done_for_student( $post->ID );
		$start     = BFTD_Derived::track_start( $post->ID, $track );

		/*
		 * The whole track is listed, not just from the starting number. A tutor
		 * ticking off work needs to reach anything, including something below
		 * where this student began, and a list that quietly omitted it would
		 * look like the skill did not exist. The starting number is said as
		 * context, because it is what the family's report counts from.
		 */
		echo '<p class="description">' . esc_html( BFTD_Skills::track_label( $track ) )
			. '. This student started at skill ' . (int) $start
			. ', which is where their report counts from, and every skill in the track is listed here. '
			. 'A skill already recorded on a published session is ticked and cannot be unticked, '
			. 'because the session is what says so.</p>';

		echo '<ul class="bftd-done-list">';
		foreach ( $sequence as $number => $id ) {
			$group  = BFTD_Skills::all()[ $id ]['group'] ?? 'skill';
			$earned = ! empty( $from_work[ $id ] ) && empty( $ticked[ $id ] );
			$on     = ! empty( $from_work[ $id ] ) || ! empty( $ticked[ $id ] );
			?>
			<li class="bftd-done-row<?php echo $earned ? ' is-earned' : ''; ?>">
				<label>
					<input type="checkbox" name="bftd_skills_done[]" value="<?php echo (int) $id; ?>"
						<?php checked( $on ); ?> <?php disabled( $earned ); ?>>
					<strong><?php echo (int) $number; ?></strong>
					<?php echo esc_html( BFTD_Skills::label( $id ) ); ?>
					<?php if ( 'skill' !== $group ) : ?>
						<em><?php echo esc_html( BFTD_Skills::group_label( $group ) ); ?></em>
					<?php endif; ?>
					<?php if ( $earned ) : ?>
						<span class="bftd-done-why">recorded on a session</span>
						<?php // A disabled box posts nothing, so the value is carried on its own. ?>
						<input type="hidden" name="bftd_skills_done[]" value="<?php echo (int) $id; ?>">
					<?php endif; ?>
				</label>
			</li>
			<?php
		}
		echo '</ul></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Child records                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Which tutors may open this report.
	 *
	 * A chip list over a hidden multi-select, with a search box: the select is
	 * still the thing that posts, so the form works with JavaScript off and
	 * nothing about saving depends on the widget. The chips exist because a
	 * scrolling multi-select hides what is already chosen, and "who can see
	 * this child's assessment" is exactly the question you should never have
	 * to scroll to answer.
	 */
	public static function box_report_staff( $post ) {
		self::nonce( $post->ID );

		$assigned = BFTD_CPT::staff_ids( $post->ID );
		if ( ! $assigned && 'auto-draft' === $post->post_status ) {
			$assigned = array( get_current_user_id() ); // shown before the first save
		}

		$can_assign = BFTD_Roles::can_manage()
			|| (int) $post->post_author === get_current_user_id()
			|| in_array( get_current_user_id(), $assigned, true );

		self::assign_field(
			'bftd_report_staff',
			BFTD_Roles::staff_users(),
			$assigned,
			'Nobody yet. Whoever saves this first is added automatically.',
			'Only the tutors listed here can open this record. Managers, Senior Managers and Administrators always can. Whoever creates it is added straight away, so nobody is locked out of their own work.'
				. ( $can_assign ? '' : '<br><strong>You cannot change this list</strong>, because you are neither assigned to this record nor a manager.' ),
			$can_assign,
			function ( $u ) { return BFTD_Roles::role_name( $u->ID ); }
		);
	}

	/**
	 * The schedule, and what it does to the lesson bank.
	 *
	 * A weekly pattern rather than a list of dates: "Mondays and Wednesdays at
	 * four" is how a family actually books, and it stays true when a term
	 * shifts. Individual departures from the pattern are handled underneath,
	 * on the occurrence itself.
	 */
	public static function box_schedule( $post ) {
		self::nonce( $post->ID );

		$rules  = BFTD_Schedule::rules( $post->ID );
		$bank   = BFTD_Schedule::bank( $post->ID );
		$tutors = BFTD_Roles::staff_users();
		?>
		<div class="bftd-sched" data-student="<?php echo (int) $post->ID; ?>">

			<div class="bftd-bank">
				<?php
				// Keyed rather than ordered: the script updates these by name,
				// so a tile can be moved or a fifth one added without silently
				// putting the wrong number under the wrong word.
				// Ordered as the story runs: bought, then scheduled, then
				// taught, and finally what is left. Remaining lands last
				// because it is the answer the other three add up to.
				$tiles = array(
					array( 'purchased', 'Purchased',    'Set under Student details.' ),
					array( 'booked',    'Booked ahead', 'Scheduled and paid for.' ),
					array( 'used',      'Used',         'Counted from the sessions actually recorded.' ),
					array( 'remaining', 'Remaining',    'Purchased minus used.' ),
				);
				foreach ( $tiles as $t ) : ?>
					<div class="bftd-bank-tile" data-bank="<?php echo esc_attr( $t[0] ); ?>">
						<span class="bftd-bank-n"><?php echo esc_html( (string) $bank[ $t[0] ] ); ?></span>
						<span class="bftd-bank-l"><?php echo esc_html( $t[1] ); ?></span>
						<span class="bftd-bank-h"><?php echo esc_html( $t[2] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php
			// Overbooking is no longer possible: the pattern stops at the
			// paid for boundary. What is worth saying instead is the date the
			// bank runs out, because that is when the family needs asking
			// about the next block. Always rendered so the script can update
			// it the moment the purchased figure changes.
			?>
			<p class="bftd-bank-note" <?php echo $bank['last_date'] ? '' : 'hidden'; ?>>
				The sessions paid for run out after
				<strong class="bftd-runs-out"><?php echo esc_html( $bank['last_date'] ? BFTD_Time::day( $bank['last_date'] ) : '' ); ?></strong>.
				Add more under Student details and the schedule carries on from there.
			</p>

			<div class="notice notice-warning inline bftd-bank-unbooked" <?php echo $bank['unbooked'] ? '' : 'hidden'; ?>><p>
				<strong><span class="bftd-unbooked-n"><?php echo esc_html( (string) $bank['unbooked'] ); ?></span> paid for sessions have nowhere to go.</strong>
				There is no weekly slot for them, so nothing is booked. Add a row below and they will be scheduled.
			</p></div>

			<h4>The weekly pattern</h4>
			<p class="description">
				One row per regular slot. A student who comes three times a week has three rows.
				There is no end date: the pattern runs until the sessions paid for run out, and carries on
				by itself when more are bought.
			</p>

			<table class="widefat bftd-sched-rules">
				<thead><tr>
					<th style="width:150px;">Day</th>
					<th style="width:120px;">Time</th>
					<th style="width:100px;">Minutes</th>
					<th style="width:150px;">Starting</th>
					<th>Tutor</th>
					<th style="width:30px;"></th>
				</tr></thead>
				<tbody>
				<?php
				$rows = $rules ? $rules : array( array() );
				foreach ( array_values( $rows ) as $i => $r ) self::schedule_row( $i, $r, $tutors, $post->ID );
				?>
				</tbody>
			</table>
			<p>
				<button type="button" class="button bftd-sched-add">Add a slot</button>
				<span class="description">Dates below refresh as you type.</span>
			</p>
			<script type="text/template" class="bftd-sched-tpl"><?php self::schedule_row( '__i__', array(), $tutors, $post->ID ); ?></script>

			<p class="description bftd-cap-note">
				A tutor teaches at most <strong><?php echo (int) BFTD_Schedule::daily_cap(); ?> sessions in a day</strong>,
				and <strong>no two of them may overlap</strong>. Back to back is fine; the same half hour twice is not.
				A slot that breaks either rule is not added, and you will be told which one, on what date, and why.
			</p>

			<h4>The next sessions</h4>
			<div class="bftd-occ-wrap"><?php echo BFTD_Schedule::render_occurrence_list( $post->ID ); ?></div>
			<p class="description">
				Recording a session is what uses one from the bank, so a cancellation costs the family nothing.
				<strong>Next session</strong> on the family's home screen is always the first booked date here, so it can never be left stale.
			</p>

			<?php self::reschedule_dialog(); ?>
		</div>
		<?php
	}

	/**
	 * The reschedule picker.
	 *
	 * A month grid rather than a date box, because the question a tutor is
	 * actually answering is "when is this child free", and that is a shape on
	 * a calendar rather than a string. Days already carrying a lesson are
	 * marked, so a clash is visible before it is made rather than discovered
	 * afterwards.
	 *
	 * One dialog serves every row; it is filled in when it opens.
	 */
	private static function reschedule_dialog() {
		?>
		<div class="bftd-modal bftd-resched" hidden>
			<div class="bftd-modal-box" role="dialog" aria-modal="true" aria-labelledby="bftd-resched-title">
				<h2 id="bftd-resched-title">Reschedule this session</h2>
				<p class="bftd-resched-from description"></p>

				<div class="bftd-cal">
					<div class="bftd-cal-head">
						<button type="button" class="button bftd-cal-prev" aria-label="Previous month">&lsaquo;</button>
						<strong class="bftd-cal-label" aria-live="polite"></strong>
						<button type="button" class="button bftd-cal-next" aria-label="Next month">&rsaquo;</button>
					</div>
					<div class="bftd-cal-grid" role="grid"></div>
					<p class="bftd-cal-key">
						<span class="bftd-cal-dot is-booked"></span> booked
						<span class="bftd-cal-dot is-held"></span> taught
						<span class="bftd-cal-dot is-pick"></span> your choice
					</p>
				</div>

				<div class="bftd-resched-when">
					<label>New date
						<input type="date" class="bftd-resched-date">
					</label>
					<label>Time
						<input type="time" class="bftd-resched-time">
					</label>
				</div>

				<label class="bftd-resched-why">Reason, shown in the log and on the missed sessions table
					<input type="text" class="bftd-resched-reason" placeholder="For example: family away, or tutor unwell">
				</label>

				<p class="bftd-resched-warn" hidden></p>

				<div class="bftd-modal-foot">
					<button type="button" class="button button-primary bftd-resched-save">Reschedule it</button>
					<button type="button" class="button bftd-resched-cancel-lesson">Cancel the session instead</button>
					<button type="button" class="button-link bftd-resched-close">Not now</button>
				</div>
			</div>
		</div>
		<?php
	}

	private static function schedule_row( $i, $r, $tutors, $post_id = 0 ) {
		$r = wp_parse_args( $r, array( 'id' => '', 'day' => '1', 'time' => '16:00', 'minutes' => 50, 'from' => '', 'tutor' => 0 ) );
		$n = 'bftd_sched[' . $i . ']';
		?>
		<tr class="bftd-sched-row">
			<td>
				<input type="hidden" name="<?php echo $n; ?>[id]" value="<?php echo esc_attr( $r['id'] ); ?>">
				<select name="<?php echo $n; ?>[day]" class="bftd-s-day">
					<?php foreach ( BFTD_Schedule::weekdays() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $r['day'], (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="time" name="<?php echo $n; ?>[time]" value="<?php echo esc_attr( $r['time'] ); ?>" class="bftd-s-time"></td>
			<td><input type="number" min="15" step="5" name="<?php echo $n; ?>[minutes]" value="<?php echo (int) $r['minutes']; ?>" class="small-text bftd-s-min"></td>
			<td><input type="date" name="<?php echo $n; ?>[from]" value="<?php echo esc_attr( $r['from'] ); ?>" class="bftd-s-from"></td>
			<td><?php self::tutor_picker( $n . '[tutor]', $tutors, $r['tutor'], $post_id ); ?></td>
			<td><button type="button" class="button-link bftd-sched-rm" aria-label="Remove this slot">&times;</button></td>
		</tr>
		<?php
	}

	/**
	 * Who teaches this slot.
	 *
	 * A searchable single choice rather than a plain select, because a list
	 * of every tutor is a scroll once there are more than a handful, and
	 * because the person picking usually knows a name or an email rather than
	 * a position in a list.
	 *
	 * The first option is the default, and it names the person it resolves
	 * to. "Assigned" on its own asks the reader to go and look; "Assigned
	 * (Laurel Sanders)" answers the question on the spot, and keeps answering
	 * it correctly when the student is reassigned, because nothing is
	 * stored.
	 *
	 * A hidden input carries the value, so what posts is a plain field and
	 * saving does not depend on the widget at all.
	 */
	public static function tutor_picker( $name, $tutors, $selected, $post_id = 0 ) {
		$default_id = BFTD_Schedule::effective_tutor( $post_id );
		$default_wh = $default_id ? get_userdata( $default_id ) : null;

		self::person_picker( $name, $tutors, $selected, array(
			'default_label' => $default_wh ? 'Assigned (' . $default_wh->display_name . ')' : 'Assigned',
			'default_note'  => 'Follows the student, so a reassignment carries the slot with it',
			'aria'          => 'Tutor for this slot',
			'clear_label'   => 'Go back to the assigned tutor',
		) );
	}

	/**
	 * Pick one person, by searching for them.
	 *
	 * A plain select is fine for four options and useless for forty, and the
	 * person choosing usually knows a name or an email rather than a position
	 * in a list. So: a search box over a fixed list, with the email and role
	 * shown against each name so two people called Sanders are told apart.
	 *
	 * A hidden input carries the value and it stores a user id, not a name.
	 * Names change, and a report that recorded "L. Sanders" as text would
	 * still say that after a marriage, a correction or a typo.
	 *
	 * Pass a default_label to offer a first option meaning "not a specific
	 * person" (the schedule uses it for "follows whoever is assigned").
	 * Leave it empty and an unset field simply reads as empty.
	 */
	public static function person_picker( $name, $people, $selected, $args = array() ) {
		$args = wp_parse_args( $args, array(
			'default_label' => '',
			'default_note'  => '',
			'placeholder'   => 'Search by name or email',
			'aria'          => 'Person',
			'clear_label'   => 'Clear',
			'id'            => '',
		) );

		$selected = (int) $selected;
		$chosen   = $selected ? get_userdata( $selected ) : null;
		$label    = $chosen ? $chosen->display_name : $args['default_label'];
		?>
		<div class="bftd-pick" data-default="<?php echo esc_attr( $args['default_label'] ); ?>">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo $selected; ?>" class="bftd-s-tutor bftd-pick-v">
			<input type="text" class="bftd-pick-in<?php echo $selected ? ' is-set' : ''; ?>"
				<?php if ( $args['id'] ) : ?>id="<?php echo esc_attr( $args['id'] ); ?>"<?php endif; ?>
				value="<?php echo esc_attr( $label ); ?>"
				placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				autocomplete="off" role="combobox" aria-expanded="false"
				aria-label="<?php echo esc_attr( $args['aria'] ); ?>">
			<button type="button" class="bftd-pick-x" aria-label="<?php echo esc_attr( $args['clear_label'] ); ?>" <?php echo $selected ? '' : 'hidden'; ?>>&times;</button>

			<div class="bftd-pick-list" hidden role="listbox">
				<?php if ( '' !== $args['default_label'] ) : ?>
					<button type="button" class="bftd-pick-opt" data-value="0" data-search="assigned default"
						data-label="<?php echo esc_attr( $args['default_label'] ); ?>">
						<span class="bftd-pick-name"><?php echo esc_html( $args['default_label'] ); ?></span>
						<?php if ( $args['default_note'] ) : ?>
							<span class="bftd-pick-meta"><?php echo esc_html( $args['default_note'] ); ?></span>
						<?php endif; ?>
					</button>
				<?php endif; ?>

				<?php foreach ( $people as $u ) : ?>
					<button type="button" class="bftd-pick-opt" data-value="<?php echo (int) $u->ID; ?>"
						data-label="<?php echo esc_attr( $u->display_name ); ?>"
						data-search="<?php echo esc_attr( strtolower( $u->display_name . ' ' . $u->user_email . ' ' . $u->user_login ) ); ?>">
						<span class="bftd-pick-name"><?php echo esc_html( $u->display_name ); ?></span>
						<span class="bftd-pick-meta"><?php echo esc_html( BFTD_Roles::role_name( $u->ID ) . ' · ' . $u->user_email ); ?></span>
					</button>
				<?php endforeach; ?>
				<p class="bftd-pick-none description" hidden>Nobody matches that.</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Two links: this report as the family will read it, and a finished one
	 * for comparison.
	 *
	 * Both open in a new tab rather than replacing the editor, because the
	 * point is to look at the report and the form side by side.
	 */
	public static function box_preview( $post ) {
		$saved = 'auto-draft' !== $post->post_status;
		?>
		<div class="bftd-box">
			<?php if ( $saved ) : ?>
				<p>
					<a class="button button-primary button-large" style="width:100%;text-align:center"
						href="<?php echo esc_url( BFTD_Preview::url( $post->ID ) ); ?>" target="_blank" rel="noopener">
						<?php echo BFTD_CPT::SESSION === $post->post_type ? 'Preview the progress report' : 'Preview this report'; ?>
					</a>
				</p>
				<p class="description">
					<?php if ( BFTD_CPT::SESSION === $post->post_type ) : ?>
						A session is one entry in the progress report rather than a page of its own, so this opens that
						report with this session in it, even while it is a draft. Save first if you have unsaved changes.
					<?php else : ?>
						Opens in a new tab, laid out exactly as the family sees it, with whatever has been saved so far.
						Save first if you have unsaved changes.
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p class="description">Save this report once and a preview link appears here.</p>
			<?php endif; ?>

			<hr style="margin:14px 0;border:0;border-top:1px solid var(--bftd-line)">

			<p>
				<a href="<?php echo esc_url( BFTD_Preview::sample_url(
					BFTD_CPT::PROGRESS === $post->post_type ? 'progress' : 'diagnostic'
				) ); ?>" target="_blank" rel="noopener">View a sample report</a>
			</p>
			<p class="description">
				A finished <?php echo BFTD_CPT::PROGRESS === $post->post_type ? 'progress report' : 'reading diagnostic'; ?>,
				filled in, so you can see how much to write and how each section reads once it is.
			</p>
		</div>
		<?php
	}

	public static function box_priority( $post ) {
		self::items_box( $post, BFTD_Items::PRIORITY );
	}

	public static function box_review( $post ) {
		self::items_box( $post, BFTD_Items::REVIEW );
	}

	/**
	 * One of the two lists, on whichever screen asked for it.
	 *
	 * On a student record the lists are that student's own. On a report they
	 * are still that student's: the editor here writes the same two meta
	 * keys, so a tutor can add "print the practice sheet" while writing the
	 * diagnostic that prompted it, and the caregiver sees one list however it
	 * was added.
	 *
	 * A report with no student chosen gets a note rather than an editor. An
	 * item typed before there is a student has nowhere to be saved, and a
	 * form that silently drops what somebody typed is worse than one that
	 * says it is not ready yet.
	 */
	private static function items_box( $post, $list ) {
		if ( BFTD_CPT::STUDENT === $post->post_type ) {
			BFTD_Items::render_editor( $post->ID, $list );
			return;
		}

		$student_id = (int) BFTD_CPT::student_id( $post->ID );
		if ( ! $student_id ) {
			echo '<p class="description">Choose a student in the Student box first. These lists belong to the student, so there is nowhere to keep an item until there is one.</p>';
			return;
		}
		if ( ! current_user_can( 'edit_post', $student_id ) ) {
			echo '<p class="description">This student is not one you can edit, so their lists are read only here.</p>';
			return;
		}

		BFTD_Items::render_editor( $student_id, $list, get_the_title( $student_id ) );
	}

	/** Where the subheading is kept. */
	const SUBTITLE_KEY = '_bftd_subtitle';

	/**
	 * A line under the title, naming who taught this.
	 *
	 * Sits where a subheading belongs, directly beneath the title, rather
	 * than in a box further down the page, because that is where it appears
	 * on the family's copy and the two should look like the same thing.
	 *
	 * It is not typed. Who teaches a student is already answered by the
	 * assignment on the student record, and a second place to say it is a
	 * second place to be wrong: cover a lesson, reassign a student, and the
	 * hand-typed line keeps insisting on last year's tutor. So this is filled
	 * from the assignment when a student is chosen, and shown as a line of
	 * text rather than as a box somebody could contradict.
	 *
	 * The value is still *stored* rather than read live, because a report is
	 * a record of a day. A diagnostic Laurel wrote should still say Laurel
	 * after the student moves to another tutor.
	 *
	 * Nothing renders until there is a name. An empty subheading on a report
	 * with no student chosen yet is a blank line where a name will be, and a
	 * blank line reads as something missing.
	 */
	public static function subtitle_field( $post ) {
		if ( ! in_array( $post->post_type, BFTD_Access::report_types(), true ) ) return;

		$value = (string) get_post_meta( $post->ID, self::SUBTITLE_KEY, true );
		?>
		<div class="bftd-subtitle<?php echo '' === $value ? ' is-empty' : ''; ?>">
			<p class="bftd-subtitle-line"><?php echo esc_html( $value ); ?></p>
			<input type="hidden" id="bftd_subtitle" name="bftd_subtitle"
				value="<?php echo esc_attr( $value ); ?>">
		</div>
		<?php
		wp_nonce_field( 'bftd_subtitle_' . $post->ID, 'bftd_subtitle_nonce' );
	}

	/**
	 * What a report's heading should say for a given student.
	 *
	 * Answered by the server rather than assembled in the browser, because
	 * who is assigned lives in post meta the report screen has never loaded.
	 */
	public static function ajax_student_header() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );

		$student_id = isset( $_POST['student_id'] ) ? (int) $_POST['student_id'] : 0;
		if ( ! $student_id || ! BFTD_Access::can_staff_view( $student_id ) ) {
			wp_send_json_error( array( 'message' => 'Not your student.' ), 403 );
		}

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		wp_send_json_success( array(
			'title'    => get_the_title( $student_id ),
			'subtitle' => self::assigned_line( $student_id ),
			// Everything the report asks for that the student record already
			// answers, so none of it has to be typed twice.
			'prefill'  => BFTD_Prefill::values( $student_id, $post_type ),
		) );
	}

	/**
	 * Who is assigned to this report's student, as one line.
	 *
	 * The student is read from what is being saved where there is one, since
	 * on the save that first attaches a report to a student the meta has not
	 * been written yet.
	 */
	public static function tutor_line( $post_id ) {
		$student_id = isset( $_POST['bftd_student'] )
			? (int) $_POST['bftd_student']
			: (int) BFTD_CPT::student_id( $post_id );
		return self::assigned_line( $student_id );
	}

	/** The assigned tutors on one student, as the line the report shows. */
	public static function assigned_line( $student_id ) {
		$student_id = (int) $student_id;
		if ( ! $student_id ) return '';

		$names = array();
		foreach ( BFTD_CPT::staff_ids( $student_id ) as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) $names[] = $u->display_name;
		}
		return $names ? self::and_list( $names ) : '';
	}

	/** "Laurel", "Laurel and Dana", "Laurel, Dana and Priya". */
	public static function and_list( $names ) {
		$names = array_values( array_filter( array_map( 'trim', (array) $names ) ) );
		$n     = count( $names );
		if ( ! $n ) return '';
		if ( 1 === $n ) return $names[0];
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' and ' . $last;
	}

	public static function box_parent( $post ) {
		self::nonce( $post->ID );
		$current = (int) get_post_meta( $post->ID, BFTD_CPT::STUDENT_KEY, true );
		if ( ! $current && isset( $_GET['student'] ) ) $current = (int) $_GET['student'];

		$students = get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<p>
			<label class="bftd-label" for="bftd_student">Which student</label>
			<select id="bftd_student" name="bftd_student" style="width:100%;">
				<option value="0">Choose a student</option>
				<?php foreach ( $students as $s ) : ?>
					<?php if ( ! BFTD_Access::can_staff_view( $s->ID ) ) continue; ?>
					<option value="<?php echo (int) $s->ID; ?>" <?php selected( $current, $s->ID ); ?>><?php echo esc_html( $s->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="description">Everything on this record shows on that student's portal, and nowhere else.</p>
		<?php
		// Said out loud, because a field that fills itself in without warning
		// is unsettling the first time it happens.
		$pulled = BFTD_Prefill::explain();
		if ( $pulled && in_array( $post->post_type, BFTD_Access::report_types(), true ) ) : ?>
			<details class="bftd-pulled">
				<summary>What gets filled in from the student</summary>
				<p class="description">Choosing a student fills in anything here that the student record already answers, so it is never typed twice. Only empty fields are touched, and every one can be changed.</p>
				<ul class="bftd-pulled-list">
					<?php foreach ( $pulled as $label ) : ?><li><?php echo esc_html( $label ); ?></li><?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
		<?php if ( BFTD_CPT::SESSION === $post->post_type && $current ) :
			$report = BFTD_CPT::report_for( $current, BFTD_CPT::PROGRESS ); ?>
			<p class="description"><?php echo $report
				? 'This session joins ' . esc_html( get_the_title( $report ) ) . '.'
				: 'There is no progress report for this student yet. One is created when you save.'; ?></p>
		<?php endif; ?>
		<?php if ( $current ) : ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Admin::HUB_SLUG . '&student=' . (int) $current ) ); ?>">Open the student hub</a></p>
		<?php endif; ?>
		<?php
	}

	/** The generated report builder: every section this post type owns. */
	public static function box_report( $post ) {
		self::nonce( $post->ID );
		$sections = BFTD_Schema::sections_for_post_type( $post->post_type );
		?>
		<div class="bftd-box">
			<div class="bftd-jump">
				<span>Jump to</span>
				<?php foreach ( $sections as $id => $s ) : ?>
					<a href="#bftd-<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $s['label'] ); ?></a>
				<?php endforeach; ?>
			</div>
			<p class="bftd-lede">These are the family's sections, in the order they read them. Anything you leave empty is not shown to them at all, so a report in progress never looks half broken.</p>
			<?php
			/*
			 * Every panel starts open, and the screen remembers what each
			 * person folded away. Starting them closed would hide a report
			 * somebody was halfway through writing, which is a worse first
			 * impression than a long page; one press of Collapse all gets the
			 * short view, and it is still there tomorrow.
			 */
			?>
			<p class="bftd-secall">
				<button type="button" class="button-link bftd-secall-open">Expand all</button>
				<span aria-hidden="true">&middot;</span>
				<button type="button" class="button-link bftd-secall-shut">Collapse all</button>
			</p>
			<?php foreach ( $sections as $id => $s ) : ?>
				<?php BFTD_Fields::render_section( $post->ID, $id ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Lesson                                                              */
	/* ------------------------------------------------------------------ */

	/** What is recorded about one text a child read. */
	public static function text_columns() {
		return array(
			'title' => array( 'type' => 'text', 'label' => 'Title' ),
			'level' => array( 'type' => 'text', 'label' => 'Level', 'help' => 'However this practice writes it: 1, Grade 4, Preprimer. A bare number is shown to the family as "Level 1".' ),
		);
	}

	/**
	 * @param int $post_id The session, so the "Cancelled by" list can be the
	 *                     people actually attached to this child. The save
	 *                     reads the same list: a select is sanitised against
	 *                     its own options, so a list built only at render
	 *                     time would throw away every answer on the way in.
	 */
	public static function session_fields( $post_id = 0 ) {
		return array(
			'session_date'   => array( 'type' => 'date',   'label' => 'Date' ),
			'session_time'   => array( 'type' => 'time',   'label' => 'Time' ),
			// "Attended" rather than "Lesson went ahead", but the stored
			// value stays 'held'. Every lesson already recorded holds that
			// word, and so does the figure the paid-for bank is counted
			// from: renaming the key would quietly reset both.
			'status'         => array( 'type' => 'select', 'label' => 'What happened', 'options' => array(
				'held'        => 'Attended',
				'rescheduled' => 'Rescheduled',
				'missed'      => 'Missed',
			) ),
			// Whose hour this was, and so whose time card it lands on.
			// Whoever creates the session, unless somebody changes it in the
			// Taught by box beside Who can see this. Drawn there by
			// box_taught_by(), not in the session record.
			'delivered_by'   => array(
				'type'  => 'staff',
				'label' => 'Taught by',
				'help'  => 'Whoever created the session, unless changed here. Required to publish: this is who gets paid for it.',
			),
			'notes'          => array( 'type' => 'rich',   'label' => 'Session notes' ),
			'homework'       => array( 'type' => 'rich',   'label' => 'Homework' ),
			'recording_url'  => array( 'type' => 'text',   'label' => 'Recording link', 'help' => 'Available to the family for the number of days set on the student record.' ),
			'samples'        => array( 'type' => 'gallery','label' => 'Work samples' ),

			// Asked for only when the lesson did not happen. The family's
			// missed-lessons table is built from these, so the answers live
			// on the lesson that was missed rather than being retyped into a
			// table beside it that disagrees within a term.
			'missed_by'      => array(
				'type'    => 'select',
				'label'   => 'Cancelled by',
				'options' => self::cancelled_by_options( $post_id ),
				'help'    => 'Required. Who cancelled or moved it.',
			),
			'missed_why'     => array(
				'type'  => 'textarea',
				'label' => 'Why',
				'help'  => 'Required. Shown to the family under this session on their progress report, in these words.',
			),
			'missed_madeup'  => array( 'type' => 'date', 'label' => 'Made up on' ),
		);
	}

	/**
	 * The people this session could have been cancelled by.
	 *
	 * Built from the child's own record, so it is a short list of people the
	 * practice knows rather than two words. A record already holding an
	 * answer that is no longer on the list — a caregiver removed since, or
	 * one of the two words this used to offer — keeps it as an option, or
	 * opening the record would silently blank an answer somebody gave.
	 */
	public static function cancelled_by_options( $post_id ) {
		$options = array( '' => 'Choose someone' );

		$student = $post_id ? BFTD_CPT::student_id( $post_id ) : 0;
		foreach ( BFTD_CPT::people_on( $student ) as $uid => $label ) {
			$options[ (string) $uid ] = $label;
		}

		$have = $post_id ? (string) BFTD_Fields::get( $post_id, 'session', 'missed_by' ) : '';
		if ( '' !== $have && ! isset( $options[ $have ] ) ) {
			$was = BFTD_CPT::cancelled_by_label( $have, $student );
			$options[ $have ] = ( '' !== $was ? $was : 'Recorded earlier' ) . ' (no longer listed)';
		}

		return $options;
	}

	/**
	 * Which lesson this is, stated rather than asked for.
	 *
	 * The number used to be typed. Two tutors sharing a student, or one
	 * coming back after a cancellation, both reached for the same number, and
	 * deleting a lesson left a gap nobody went back to close. It is the
	 * lesson's place in the student's own sequence, so it is worked out from
	 * that sequence and never stored: a new lesson takes the next number by
	 * existing, and deleting one closes the gap by the same arithmetic.
	 */
	private static function lesson_number_line( $post ) {
		$n = BFTD_CPT::lesson_number( $post->ID );
		echo '<div class="bftd-box"><p class="bftd-derived">';
		if ( ! $n ) {
			echo 'This will be numbered once it has a student and a date.';
		} elseif ( 'publish' === $post->post_status ) {
			echo 'Session ' . (int) $n . ' for this student. Counted from the sessions on their record, not typed, so deleting one closes the gap.';
		} else {
			echo 'This will be session ' . (int) $n . ' when it is published. Drafts are not numbered, because the family cannot see them.';
		}
		echo '</p></div>';
	}

	/** The activity rows, as the lesson screen and the save both see them. */
	/** One skill a child reached today, beyond what the activities claim. */
	public static function skill_columns() {
		return array(
			'id'   => array( 'type' => 'skill', 'label' => 'Skill' ),
			'note' => array( 'type' => 'rich',  'label' => 'Notes' ),
		);
	}

	public static function activity_columns() {
		return array(
			'id'      => array( 'type' => 'activity', 'label' => 'Activity' ),
			'note'    => array( 'type' => 'rich',     'label' => 'Notes' ),
			'samples' => array( 'type' => 'gallery',  'label' => 'Work samples' ),
		);
	}

	/**
	 * The lesson screen, in the order a tutor fills it in.
	 *
	 * When and whether it happened, then what was taught, then the activities
	 * covered, then the child's work, then what goes home. The old screen put
	 * homework above the activities and the work samples off at the end,
	 * which is neither the order of the lesson nor the order of the report
	 * it becomes.
	 */
	public static function box_session( $post ) {
		$fields = self::session_fields( $post->ID );
		$draw   = function ( $keys ) use ( $post, $fields ) {
			echo '<div class="bftd-box bftd-grid">';
			foreach ( $keys as $key ) {
				if ( isset( $fields[ $key ] ) ) BFTD_Fields::render_field( $post->ID, 'session', $key, $fields[ $key ] );
			}
			echo '</div>';
		};

		$draw( array( 'session_date', 'session_time', 'status' ) );
		self::missed_block( $post, $fields );
		self::lesson_number_line( $post );
		$draw( array( 'notes' ) );

		echo '<div class="bftd-box"><h4>Activities covered</h4>';
		echo '<p class="description">Chosen from the activity library, so the same activity is the same activity on every report. These build the skills grid on the progress report, which is what makes the spiral visible to a parent. The first session an activity appears on is the one it counts as introduced.</p>';
		BFTD_Fields::render_field( $post->ID, 'session', 'activities', array(
			'type'      => 'rows',
			'label'     => 'Activities',
			'layout'    => 'stack',
			'add_label' => 'Add activity',
			'columns'   => self::activity_columns(),
		) );
		echo '</div>';

		echo '<div class="bftd-box"><h4>Skills reached today</h4>';
		echo '<p class="description">Every activity already carries the skills it builds, and those arrive on the report by themselves. This is for a skill the child got to today that the programme did not promise, which is the sentence a parent remembers.</p>';
		BFTD_Fields::render_field( $post->ID, 'session', 'skills', array(
			'type'      => 'rows',
			'label'     => 'Skills',
			'layout'    => 'stack',
			'add_label' => 'Add skill',
			'columns'   => self::skill_columns(),
		) );
		echo '</div>';

		/*
		 * The texts read, on the lesson rather than on the activity.
		 *
		 * They were kept on the activity, on the reasoning that an activity
		 * always uses the same texts. It does not: the same activity is run
		 * with whatever the child is reading that week, so a text written onto
		 * the activity turned up in the reading log of every child who had ever
		 * done it, on the date of their lesson, whether or not they had read it.
		 * A text belongs to the lesson it was read in.
		 */
		echo '<div class="bftd-box"><h4>Texts read today</h4>';
		echo '<p class="description">Anything read in this session. These build the reading log on the family\'s progress report; the date comes from the session, so it is not asked for again.</p>';
		BFTD_Fields::render_field( $post->ID, 'session', 'texts', array(
			'type'      => 'rows',
			'label'     => 'Texts',
			'add_label' => 'Add text',
			'columns'   => self::text_columns(),
		) );
		echo '</div>';

		$draw( array( 'samples' ) );
		$draw( array( 'homework' ) );
		$draw( array( 'recording_url' ) );

	}

	/**
	 * What a cancelled session has to say for itself.
	 *
	 * Directly under what happened, because it is the same question: a
	 * session that did not go ahead is not finished being recorded until it
	 * says who cancelled it and why. It used to sit at the foot of the
	 * screen, past the activities and the work samples, where it read as an
	 * optional afterthought — and a missed session with nothing under it
	 * reaches the family's report as a blank row they have to ask about.
	 *
	 * Rendered on every session, hidden on one that went ahead. Server
	 * rendered either way, so what is stored is never lost by the box being
	 * out of sight.
	 */
	private static function missed_block( $post, $fields ) {
		/*
		 * Shown or hidden here, from what is stored, rather than left to the
		 * script to work out once the page has loaded.
		 *
		 * It was a class on <body>, added on ready. That is fine until the
		 * script does not run — blocked, erroring earlier in the file, or
		 * simply not reached yet — and then a cancelled session shows a tutor
		 * no way to explain it while the save refuses to publish it, with
		 * nothing on screen connecting the two. The script only has to handle
		 * the status CHANGING; what it is now is already known here.
		 */
		$status = (string) BFTD_Fields::get( $post->ID, 'session', 'status' );
		$on     = ( 'missed' === $status || 'rescheduled' === $status );

		echo '<div class="bftd-box bftd-onlymissed' . ( $on ? ' is-on' : '' ) . '"><h4>Why it was missed</h4>';
		echo '<p class="description">Required before this session can be published. These build the missed sessions table on the family\'s progress report. Nothing here is asked twice: the date comes from the session itself.</p>';
		echo '<div class="bftd-grid">';
		foreach ( array( 'missed_by', 'missed_why', 'missed_madeup' ) as $key ) {
			BFTD_Fields::render_field( $post->ID, 'session', $key, $fields[ $key ] );
		}
		echo '</div>';
		echo '<p class="description bftd-missed-rule">A cancelled session counts against the sessions remaining. A rescheduled one does not, and is settled by management.</p>';
		echo '</div>';
	}

	public static function box_resource( $post ) {
		self::nonce( $post->ID );
		$fields = array(
			'area'    => array( 'type' => 'select', 'label' => 'Where it shows', 'options' => array(
				'sec-home-practice' => 'Resources, suggestions for home',
				'sec-setup'         => 'Setup, getting started',
			) ),
			'lead'    => array( 'type' => 'textarea', 'label' => 'Lead line' ),
			'body'    => array( 'type' => 'rich',     'label' => 'Full text' ),
			'video'   => array( 'type' => 'text',     'label' => 'Video link' ),
			'for_who' => array( 'type' => 'text',     'label' => 'Who it is for', 'help' => 'For example "if your child is still sounding out".' ),
			'tag'     => array( 'type' => 'text',     'label' => 'Tag' ),
			'everyone'=> array( 'type' => 'select',   'label' => 'Give it to', 'options' => array(
				'all'      => 'Every family',
				'assigned' => 'Only the students I assign it to',
			) ),
		);
		echo '<div class="bftd-box bftd-grid">';
		foreach ( $fields as $key => $field ) {
			BFTD_Fields::render_field( $post->ID, 'resource', $key, $field );
		}
		echo '</div>';
	}

	public static function box_log( $post ) {
		BFTD_Audit::render_for_post( $post->ID, 40 );
	}

	/* ------------------------------------------------------------------ */
	/* Save                                                                */
	/* ------------------------------------------------------------------ */

	public static function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( wp_is_post_revision( $post_id ) ) return;
		if ( ! in_array( $post->post_type, BFTD_Roles::post_types(), true ) ) return;
		if ( empty( $_POST['bftd_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_nonce'] ) ), 'bftd_save_' . $post_id ) ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		self::save_fields( $post_id, $post );
	}

	/**
	 * Everything a save actually writes, with the form's own guards done.
	 *
	 * Split out so restoring an autosaved draft goes through this rather than
	 * around it. A second way to write a report is a second place to forget
	 * to sanitise something, and the one that gets forgotten is always the
	 * one nobody looks at.
	 */
	/** The top-level POST keys a form of ours carries its fields under. */
	public static function our_post_keys() {
		return array( 'bftd', 'bftd_rows', 'bftd_sched', 'bftd_visible', 'bftd_items' );
	}

	/**
	 * How many of our own fields a request is carrying.
	 *
	 * Counted as leaves, the way a browser counts them when it serialises a
	 * form, so the number the screen sends and the number that arrives are the
	 * same kind of thing and can be compared.
	 */
	public static function count_posted_fields( $where = null ) {
		if ( null === $where ) $where = $_POST;
		$n = 0;
		foreach ( self::our_post_keys() as $key ) {
			if ( ! isset( $where[ $key ] ) ) continue;
			$n += self::count_leaves( $where[ $key ] );
		}
		return $n;
	}

	private static function count_leaves( $value ) {
		if ( ! is_array( $value ) ) return 1;
		$n = 0;
		foreach ( $value as $v ) $n += self::count_leaves( $v );
		return $n;
	}

	/**
	 * Did the whole form arrive?
	 *
	 * PHP stops reading a request once it has taken max_input_vars variables
	 * and says nothing about it: $_POST simply ends early. Every field past the
	 * cut is then absent, and a save that rewrites each field it knows about
	 * turns absent into empty. The record loses its tail, nobody is told, and
	 * the bigger the record the likelier it is, which is why it looks like
	 * data disappearing from exactly the records that matter most.
	 *
	 * The screen says how many of our fields it sent. If fewer arrived, the
	 * request was cut and nothing should be written from it. More arriving
	 * than were sent is not a truncation, so it is not treated as one.
	 *
	 * No count sent at all means an older screen or a request that is not from
	 * our form, and those are left alone rather than refused: this exists to
	 * catch a silent loss, not to become one.
	 */
	public static function posted_is_complete() {
		if ( ! isset( $_POST['bftd_sent'] ) ) return true;
		$sent = (int) $_POST['bftd_sent'];
		if ( $sent < 1 ) return true;
		return self::count_posted_fields() >= $sent;
	}

	/**
	 * Write down that a save was thrown away, and why.
	 *
	 * An autosave silences routine logging, because a record saved every
	 * twenty-five seconds would be the whole log. A refusal is the opposite of
	 * routine and is the one thing somebody will come looking for when they
	 * say their work vanished, so it is written whatever that setting says.
	 */
	public static function log_refused_save( $post_id ) {
		$was_quiet = BFTD_Audit::$quiet;
		BFTD_Audit::$quiet = false;
		BFTD_Audit::log( 'save_refused', array(
			'post_id' => $post_id,
			'summary' => 'A save was refused because the request arrived incomplete. '
				. 'The server stopped reading it after ' . self::count_posted_fields() . ' of '
				. ( isset( $_POST['bftd_sent'] ) ? (int) $_POST['bftd_sent'] : 0 ) . ' fields, '
				. 'which is the max_input_vars limit on this server. '
				. 'Nothing was written, so nothing was lost.',
		) );
		BFTD_Audit::$quiet = $was_quiet;
	}

	public static function save_fields( $post_id, $post ) {
		$changed = 0;
		$items   = 0;

		// A cut-off request is not a save. Writing what arrived would empty
		// every field past the cut, so nothing is written at all.
		if ( ! self::posted_is_complete() ) {
			self::log_refused_save( $post_id );
			return 0;
		}

		if ( BFTD_CPT::STUDENT === $post->post_type ) {
			self::save_relationships( $post_id );
			$changed += self::save_flat( $post_id, 'student', self::student_fields() );
			$changed += self::save_flat( $post_id, 'student', self::crm_fields() );
			$changed += self::save_flat( $post_id, 'student', self::track_fields() );
			$changed += BFTD_Skills::save_ticks( $post_id );
			// Absent means the form was not carrying the schedule. An empty
			// array means somebody deleted every rule, which is a real thing
			// to do and is respected; the two must not be read as the same.
			if ( isset( $_POST['bftd_sched'] )
				&& BFTD_Schedule::save_rules( $post_id, wp_unslash( $_POST['bftd_sched'] ) ) ) {
				$changed++;
			}
			$changed += self::save_items_for_student( $post_id );
		} elseif ( BFTD_CPT::SESSION === $post->post_type ) {
			self::save_parent( $post_id );
			$changed += self::save_subtitle( $post_id );
			$changed += self::save_flat( $post_id, 'session', self::session_fields( $post_id ) );
			$changed += self::save_rows_field( $post_id, 'session', 'activities', self::activity_columns(), 'Activities covered' );
			$changed += self::save_rows_field( $post_id, 'session', 'skills', self::skill_columns(), 'Skills reached' );
			$changed += self::save_rows_field( $post_id, 'session', 'texts', self::text_columns(), 'Texts read' );
			self::stamp_missed( $post_id );
			self::claim_session( $post_id );
			self::hold_duplicate_draft( $post_id, $post );
			self::hold_unexplained( $post_id, $post );
			self::hold_unclaimed( $post_id, $post );
			self::save_report_staff( $post_id );
			self::attach_session_to_report( $post_id );
		} elseif ( BFTD_CPT::RESOURCE === $post->post_type ) {
			$changed += self::save_flat( $post_id, 'resource', array(
				'area'     => array( 'type' => 'select', 'options' => array( 'sec-home-practice' => '', 'sec-setup' => '' ), 'label' => 'Where it shows' ),
				'lead'     => array( 'type' => 'textarea', 'label' => 'Lead line' ),
				'body'     => array( 'type' => 'rich', 'label' => 'Full text' ),
				'video'    => array( 'type' => 'text', 'label' => 'Video link' ),
				'for_who'  => array( 'type' => 'text', 'label' => 'Who it is for' ),
				'tag'      => array( 'type' => 'text', 'label' => 'Tag' ),
				'everyone' => array( 'type' => 'select', 'options' => array( 'all' => '', 'assigned' => '' ), 'label' => 'Given to' ),
			) );
		} else {
			self::save_parent( $post_id );
			self::save_report_staff( $post_id );
			$changed += self::save_subtitle( $post_id );
			$changed += BFTD_Fields::save_post( $post_id, $post->post_type );
			$items    = self::save_items_for_report( $post_id );
		}

		if ( $changed ) {
			$type = ( BFTD_CPT::SESSION === $post->post_type ) ? 'lesson' : 'report';
			// A family reads this line in an email. A session is "Session 4"
			// to them, not the who-and-when reference its title carries.
			$what = ( BFTD_CPT::SESSION === $post->post_type )
				? 'Session ' . BFTD_CPT::lesson_number( $post_id )
				: $post->post_title;
			BFTD_Change_Notify::flag(
				$post_id,
				$type,
				sprintf( '%s updated on %s', $what, get_the_date( get_option( 'date_format' ), $post_id ) )
			);
		}
		if ( $changed || $items ) {
			set_transient( 'bftd_saved_' . get_current_user_id(), $changed + $items, 60 );
		}
	}

	/**
	 * The two lists, saved from a report screen onto the student they belong
	 * to.
	 *
	 * Deliberately outside the report's own change count. BFTD_Items::save
	 * already logs the edit against the student and flags it there, and an
	 * item counted twice would tell a tutor the report itself has unsent
	 * changes waiting when the only thing that moved was the family's list.
	 * The tutor still gets the saved confirmation, which is what the return
	 * value is for.
	 *
	 * The report's own nonce and capability check have already run. This adds
	 * the one they do not cover: permission to write to the student record,
	 * which is a different post with a different answer.
	 */
	private static function save_items_for_report( $post_id ) {
		$student_id = (int) BFTD_CPT::student_id( $post_id );
		if ( ! $student_id || ! current_user_can( 'edit_post', $student_id ) ) return 0;

		return self::save_items_for_student( $student_id );
	}

	/**
	 * The lists, written only by a form that was carrying them.
	 *
	 * BFTD_Items::save reads an absent 'bftd_items' as an empty list and
	 * writes that, which is right when somebody has cleared every row and
	 * catastrophic when the form never carried the rows at all: every priority
	 * item and every For review item on that student is deleted, silently, by
	 * a save that had nothing to do with them.
	 *
	 * This guard existed on the report branch and not on the student branch.
	 * One of the two was going to be forgotten and it was, so there is one
	 * function now and both branches call it.
	 */
	private static function save_items_for_student( $student_id ) {
		// The same rule every field follows: not carried, not written.
		if ( ! isset( $_POST['bftd_items'] ) ) return 0;
		return BFTD_Items::save( (int) $student_id );
	}

	/** A flat group of fields that is not a schema section (student, lesson). */
	private static function save_flat( $post_id, $group, $fields ) {
		$posted = isset( $_POST['bftd'][ $group ] ) ? (array) wp_unslash( $_POST['bftd'][ $group ] ) : array();

		$before = $after = $labels = array();
		foreach ( $fields as $key => $field ) {
			$labels[ $key ] = isset( $field['label'] ) ? $field['label'] : $key;
			$before[ $key ] = BFTD_Fields::get( $post_id, $group, $key, $field );

			/*
			 * A field the request did not carry is left as it was. Absent means
			 * the form did not have it; empty means somebody cleared it. Every
			 * field here used to be rewritten either way, so a form arriving
			 * without one emptied it.
			 */
			if ( ! array_key_exists( $key, $posted ) ) {
				$after[ $key ] = $before[ $key ];
				continue;
			}

			// What is on the record, so a select can keep a value its option
			// list no longer offers instead of being reset to the first one.
			$field['stored'] = $before[ $key ];
			$after[ $key ]  = BFTD_Fields::sanitize_value( $posted[ $key ], $field );
			update_post_meta( $post_id, BFTD_Schema::meta_key( $group, $key ), $after[ $key ] );
		}

		$diff = BFTD_Audit::diff( $before, $after, $labels );
		if ( ! $diff ) return 0;

		BFTD_Audit::log( BFTD_CPT::SESSION === get_post_type( $post_id ) ? 'session_updated' : 'section_updated', array(
			'post_id' => $post_id,
			'summary' => get_the_title( $post_id ) . ' was edited.',
			'changes' => $diff,
		) );
		return 1;
	}

	/**
	 * Who marked a session missed, and when.
	 *
	 * Recorded, not asked for. A tutor typing their own name and the time
	 * they typed it is filling in two things the software already knows, and
	 * the one situation this exists for — a family and a practice
	 * remembering a cancellation differently months later — is exactly the
	 * situation in which a typed answer is worth nothing.
	 *
	 * Stamped once, when the status first becomes missed or rescheduled, and
	 * left alone afterwards: editing the reason a fortnight later must not
	 * rewrite the record of when it was marked. Cleared if the session goes
	 * back to attended, because there is then nothing that was marked.
	 */
	private static function stamp_missed( $post_id ) {
		$status = (string) BFTD_Fields::get( $post_id, 'session', 'status' );
		$by_key = BFTD_Schema::meta_key( 'session', 'missed_marked_by' );
		$at_key = BFTD_Schema::meta_key( 'session', 'missed_marked_at' );

		if ( 'missed' !== $status && 'rescheduled' !== $status ) {
			delete_post_meta( $post_id, $by_key );
			delete_post_meta( $post_id, $at_key );
			return;
		}

		if ( '' !== (string) get_post_meta( $post_id, $at_key, true ) ) return;

		update_post_meta( $post_id, $by_key, (int) get_current_user_id() );
		update_post_meta( $post_id, $at_key, BFTD_Time::mysql() );
	}

	private static function save_rows_field( $post_id, $group, $key, $columns, $label ) {
		// Not carried means the form did not have this field. An empty set of
		// rows still arrives, because the editor always draws one blank row.
		if ( ! isset( $_POST['bftd_rows'][ $group ][ $key ] ) ) return 0;

		$raw    = (array) wp_unslash( $_POST['bftd_rows'][ $group ][ $key ] );
		$before = get_post_meta( $post_id, BFTD_Schema::meta_key( $group, $key ), true );
		$after  = BFTD_Fields::sanitize_rows( $raw, $columns );
		update_post_meta( $post_id, BFTD_Schema::meta_key( $group, $key ), $after );

		$diff = BFTD_Audit::diff( array( $key => $before ), array( $key => $after ), array( $key => $label ) );
		if ( ! $diff ) return 0;
		BFTD_Audit::log( 'session_updated', array( 'post_id' => $post_id, 'summary' => $label . ' changed.', 'changes' => $diff ) );
		return 1;
	}

	/**
	 * Save the per-report tutor list.
	 *
	 * Whether the meta key has ever existed is captured before anything is
	 * written — that is how a brand new record is told apart from one where
	 * somebody deliberately cleared the field. On a new record the creator is
	 * added automatically; on an existing one an empty list is respected as an
	 * empty list, and only a manager can leave it that way.
	 */
	private static function save_report_staff( $post_id ) {
		$never_set = ! metadata_exists( 'post', $post_id, BFTD_CPT::STAFF_KEY );
		$before    = array_map( 'absint', (array) get_post_meta( $post_id, BFTD_CPT::STAFF_KEY ) );

		// A disabled select posts nothing. Treating that as "unassign
		// everyone" would silently strip the list whenever someone without
		// permission saved the record.
		$can_assign = BFTD_Roles::can_manage()
			|| (int) get_post_field( 'post_author', $post_id ) === get_current_user_id()
			|| in_array( get_current_user_id(), $before, true );
		if ( ! $can_assign && ! $never_set ) return;

		$after = isset( $_POST['bftd_report_staff'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['bftd_report_staff'] ) ) : array();
		$after = array_values( array_unique( array_filter( $after ) ) );

		if ( $never_set ) {
			$creator = get_current_user_id();
			if ( $creator && BFTD_Roles::is_staff( $creator ) && ! in_array( $creator, $after, true ) ) {
				$after[] = $creator;
			}
		}

		delete_post_meta( $post_id, BFTD_CPT::STAFF_KEY );
		foreach ( $after as $uid ) {
			add_post_meta( $post_id, BFTD_CPT::STAFF_KEY, $uid, false );
		}

		foreach ( array_diff( $after, $before ) as $uid ) {
			$u = get_userdata( $uid );
			BFTD_Audit::log( 'staff_assigned', array(
				'post_id' => $post_id,
				'summary' => ( $u ? $u->display_name : $uid ) . ' can now open ' . get_the_title( $post_id ) . '.',
			) );
			if ( $uid !== get_current_user_id() ) self::tell_assigned( $uid, $post_id );
		}
		foreach ( array_diff( $before, $after ) as $uid ) {
			$u = get_userdata( $uid );
			BFTD_Audit::log( 'staff_unassigned', array(
				'post_id' => $post_id,
				'summary' => ( $u ? $u->display_name : $uid ) . ' can no longer open ' . get_the_title( $post_id ) . '.',
			) );
		}
	}

	private static function tell_assigned( $user_id, $post_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) return;

		$student_id = BFTD_CPT::student_id( $post_id );
		BFTD_Notices::add( $user_id, 'report', $student_id, $post_id, '',
			'You have been given ' . get_the_title( $post_id ),
			'Added by ' . wp_get_current_user()->display_name . '.' );

		BFTD_Emails::send( 'staff_assigned', $u->user_email, array(
			'first_name'    => $u->first_name ? $u->first_name : $u->display_name,
			'tutor_name'    => $u->display_name,
			'student_name'  => $student_id ? get_the_title( $student_id ) : get_the_title( $post_id ),
			'dashboard_url' => (string) get_edit_post_link( $post_id, 'raw' ),
		), array( 'post_id' => $post_id, 'student_id' => $student_id ) );
	}

	/**
	 * The subheading, which nobody types.
	 *
	 * It arrives on a hidden field the browser filled from the assignment,
	 * so the one case that has to work without it is a save with no
	 * JavaScript: the name is derived here instead. An empty stored value is
	 * the only one this fills, because a report already carrying a name is a
	 * record of who taught it, not a live view of who is assigned now.
	 */
	private static function save_subtitle( $post_id ) {
		if ( ! isset( $_POST['bftd_subtitle'] ) ) return 0;
		if ( ! isset( $_POST['bftd_subtitle_nonce'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['bftd_subtitle_nonce'] ), 'bftd_subtitle_' . $post_id ) ) {
			return 0;
		}

		$before = (string) get_post_meta( $post_id, self::SUBTITLE_KEY, true );
		$after  = sanitize_text_field( wp_unslash( $_POST['bftd_subtitle'] ) );
		if ( '' === $after ) $after = self::tutor_line( $post_id );
		if ( $before === $after ) return 0;

		if ( '' === $after ) delete_post_meta( $post_id, self::SUBTITLE_KEY );
		else update_post_meta( $post_id, self::SUBTITLE_KEY, $after );

		BFTD_Audit::log( 'subtitle_changed', array(
			'post_id' => $post_id,
			'summary' => 'The subheading became "' . $after . '".',
			'before'  => $before,
			'after'   => $after,
		) );
		return 1;
	}

	private static function save_parent( $post_id ) {
		if ( ! isset( $_POST['bftd_student'] ) ) return;
		$student_id = (int) $_POST['bftd_student'];
		if ( $student_id && ! BFTD_Access::can_staff_view( $student_id ) ) return;
		update_post_meta( $post_id, BFTD_CPT::STUDENT_KEY, $student_id );
	}

	/**
	 * Every published lesson belongs to a progress report. If the student
	 * does not have one yet, make it — a tutor recording their first lesson
	 * should not have to create a container first.
	 *
	 * A DRAFT LESSON DOES NEITHER. Creating the report is not a private act:
	 * the report is published the moment it is made, so a tutor opening a
	 * blank lesson, typing a date and hitting Save Draft would hand the
	 * family a progress screen for a lesson nobody has taught yet. And a
	 * draft that attached itself to an existing report would be counted by
	 * everything reading that report.
	 *
	 * Nothing is detached on the way back down. A published lesson later
	 * returned to draft keeps its link, and every reader filters on status
	 * instead, so the link survives the round trip and the family sees
	 * nothing in the meantime.
	 *
	 * Called from the form save and again on publish, because a lesson can
	 * reach published without this form: Quick Edit, a bulk action, or a
	 * scheduled post going live all get there without posting our nonce.
	 */
	public static function attach_session_to_report( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || BFTD_CPT::SESSION !== $post->post_type ) return;
		if ( 'publish' !== $post->post_status ) return;

		$student_id = (int) get_post_meta( $post_id, BFTD_CPT::STUDENT_KEY, true );
		if ( ! $student_id ) return;

		$report = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		if ( ! $report ) {
			$report = wp_insert_post( array(
				'post_type'   => BFTD_CPT::PROGRESS,
				'post_status' => 'publish',
				'post_title'  => get_the_title( $student_id ) . ', progress report',
			) );
			if ( ! $report || is_wp_error( $report ) ) return;
			update_post_meta( $report, BFTD_CPT::STUDENT_KEY, $student_id );
			BFTD_Audit::log( 'report_created', array(
				'post_id'    => $report,
				'student_id' => $student_id,
				'summary'    => 'A progress report was created automatically for the first session.',
			) );
		}
		update_post_meta( $post_id, BFTD_CPT::REPORT_KEY, (int) $report );
	}

	/**
	 * A cancellation with nothing said about it does not reach a family.
	 *
	 * The browser asks for both before it will submit, but a browser check is
	 * a courtesy: quick edit, bulk edit, a REST call and a tutor with scripts
	 * blocked all go straight past it, and what comes out the other side is a
	 * row on a family's progress report saying their child's session did not
	 * happen and nothing else. That is the row that generates the phone call.
	 *
	 * So the record is held as a draft instead of being refused. Refusing the
	 * save would throw away whatever else was written on the screen, and the
	 * tutor who forgot the reason is the tutor who just typed the notes. A
	 * draft keeps every word, stays on the tutor's list, and is simply not
	 * served to the family until the two answers are there.
	 */
	/**
	 * A tutor publishing their own lesson is the tutor who taught it.
	 *
	 * Asking somebody to name themselves on every record they write is a
	 * field they will get wrong once and then resent, so it fills itself for
	 * the ordinary case and is only a question where the answer is genuinely
	 * unknown. It fills an EMPTY field only: a manager who has named a tutor,
	 * and a tutor who has named a colleague they covered for, both mean it.
	 */
	public static function claim_session( $post_id ) {
		$have = (int) BFTD_Fields::get( $post_id, 'session', 'delivered_by' );
		if ( $have ) return;

		// Whoever created the session taught it, unless the Taught by box
		// says otherwise. Failing that, whoever is saving it.
		$author = (int) get_post_field( 'post_author', $post_id );
		if ( $author && BFTD_Roles::is_staff( $author ) ) {
			BFTD_Fields::set( $post_id, 'session', 'delivered_by', (string) $author );
			return;
		}
		$me = get_current_user_id();
		if ( $me && BFTD_Roles::is_staff( $me ) ) {
			BFTD_Fields::set( $post_id, 'session', 'delivered_by', (string) $me );
		}
	}

	/**
	 * The Taught by box, beside Who can see this.
	 *
	 * The field was defined and required to publish, but never drawn, so a
	 * session nobody had claimed could not be fixed from its own screen. A
	 * new session shows its creator chosen, which is what saving will store.
	 */
	public static function box_taught_by( $post ) {
		$fields = self::session_fields( $post->ID );
		if ( ! isset( $fields['delivered_by'] ) ) return;
		$value = (int) BFTD_Fields::get( $post->ID, 'session', 'delivered_by' );
		if ( ! $value ) {
			$creator = 'auto-draft' === $post->post_status ? get_current_user_id() : (int) $post->post_author;
			if ( $creator && BFTD_Roles::is_staff( $creator ) ) $value = $creator;
		}
		echo '<div class="bftd-box">';
		BFTD_Fields::render_field( $post->ID, 'session', 'delivered_by', $fields['delivered_by'], $value ? (string) $value : '' );
		echo '</div>';
	}

	/**
	 * A published lesson with nobody's name on it.
	 *
	 * Held as a draft, for the same reason an unexplained cancellation is:
	 * refusing the save throws away the write-up, and the person who left
	 * the field empty is the person who just typed the notes. A draft keeps
	 * every word and pays nobody, which is the right way round. An
	 * unattributable lesson on a time card is a question nobody can answer a
	 * fortnight later.
	 */
	public static function hold_unclaimed( $post_id, $post ) {
		if ( 'publish' !== $post->post_status ) return false;
		if ( (int) BFTD_Fields::get( $post_id, 'session', 'delivered_by' ) ) return false;

		remove_action( 'save_post', array( __CLASS__, 'save' ), 10 );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );

		set_transient( 'bftd_held_' . get_current_user_id(), 'unclaimed|who taught it', 60 );
		return true;
	}

	/**
	 * A session already drafted is not drafted twice.
	 *
	 * One draft per session, where a session is a student and a date. Many
	 * draft sessions at once is the point of drafts: a tutor writing up a
	 * fortnight ahead has twenty of them open and wants every one. Two drafts
	 * of the SAME session is the thing that loses work, because the write-up
	 * goes into one and the other is what opens next time, and the tutor sees
	 * an empty form where their notes were.
	 *
	 * Nothing is deleted and nothing typed is thrown away. The newer record is
	 * held at draft and the tutor is told which record already exists, so they
	 * can finish the one that has the work in it. Publishing is what is
	 * refused, the same way an unexplained cancellation is refused, because by
	 * then the duplicate would be on a family's report twice.
	 *
	 * Published records are not touched. Two published sessions on one day is a
	 * child who came twice, which is a real thing and none of this code's
	 * business.
	 */
	public static function hold_duplicate_draft( $post_id, $post ) {
		if ( 'publish' !== $post->post_status ) return false;

		$siblings = BFTD_CPT::sibling_drafts( $post_id );
		if ( ! $siblings ) return false;

		// Unhooked around the write, because this runs on save_post and is
		// about to save a post.
		remove_action( 'save_post', array( __CLASS__, 'save' ), 10 );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );

		set_transient( 'bftd_dupe_' . get_current_user_id(), implode( ',', $siblings ), 60 );
		return true;
	}

	public static function hold_unexplained( $post_id, $post ) {
		if ( 'publish' !== $post->post_status ) return false;

		$status = (string) BFTD_Fields::get( $post_id, 'session', 'status' );
		if ( 'missed' !== $status && 'rescheduled' !== $status ) return false;

		$who = trim( (string) BFTD_Fields::get( $post_id, 'session', 'missed_by' ) );
		$why = trim( wp_strip_all_tags( (string) BFTD_Fields::get( $post_id, 'session', 'missed_why' ) ) );
		if ( '' !== $who && '' !== $why ) return false;

		$missing = array();
		if ( '' === $who ) $missing[] = 'who cancelled it';
		if ( '' === $why ) $missing[] = 'why';

		// Unhooked around the write, because this runs on save_post and is
		// about to save a post.
		remove_action( 'save_post', array( __CLASS__, 'save' ), 10 );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );

		set_transient(
			'bftd_held_' . get_current_user_id(),
			( 'rescheduled' === $status ? 'rescheduled' : 'cancelled' ) . '|' . implode( ' and ', $missing ),
			60
		);
		return true;
	}

	/**
	 * Linking a caregiver or a tutor is logged as its own access event,
	 * because who can see a child's record is a different kind of change from
	 * an edit to the copy.
	 */
	private static function save_relationships( $post_id ) {
		$maps = array(
			array( 'bftd_clients', BFTD_CPT::CLIENT_KEY, 'client_linked', 'client_unlinked', 'Caregiver' ),
			array( 'bftd_staff',   BFTD_CPT::STAFF_KEY,  'staff_assigned', 'staff_unassigned', 'Tutor' ),
		);

		// One person, taken before the lists are written, because naming the
		// client is also giving them access: they are merged into the
		// caregivers below rather than being a second list to keep in step.
		// Without that, designating a client who was not already a caregiver
		// is a billing contact who cannot open the portal, and nothing on
		// the screen says so.
		$primary = 0;
		if ( isset( $_POST['bftd_client_primary'] ) ) {
			$posted  = array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['bftd_client_primary'] ) ) ) );
			$primary = $posted ? (int) $posted[0] : 0;
		} else {
			$primary = BFTD_CPT::primary_client_id( $post_id );
		}

		$was = BFTD_CPT::primary_client_id( $post_id );
		if ( $primary ) {
			update_post_meta( $post_id, BFTD_CPT::PRIMARY_KEY, $primary );
		} else {
			delete_post_meta( $post_id, BFTD_CPT::PRIMARY_KEY );
		}
		if ( $primary !== $was ) {
			$u = $primary ? get_userdata( $primary ) : null;
			BFTD_Audit::log( 'client_linked', array(
				'post_id' => $post_id,
				'summary' => $primary
					? ( $u ? $u->display_name : $primary ) . ' is now the client for ' . get_the_title( $post_id ) . '.'
					: get_the_title( $post_id ) . ' has no client named.',
			) );
		}

		foreach ( $maps as $map ) {
			list( $field, $meta_key, $add_event, $rm_event, $noun ) = $map;

			// A disabled select posts nothing, and treating "nothing posted"
			// as "unassign everyone" would silently strip a student's tutors
			// the first time a plain tutor saved the record.
			if ( BFTD_CPT::STAFF_KEY === $meta_key && ! BFTD_Roles::can_manage() ) continue;

			$before = array_map( 'absint', (array) get_post_meta( $post_id, $meta_key ) );
			$after  = isset( $_POST[ $field ] ) ? array_map( 'absint', (array) wp_unslash( $_POST[ $field ] ) ) : array();
			$after  = array_values( array_unique( array_filter( $after ) ) );

			// The client sees the portal whether or not anybody remembered to
			// tick them below.
			if ( BFTD_CPT::CLIENT_KEY === $meta_key && $primary && ! in_array( $primary, $after, true ) ) {
				$after[] = $primary;
			}

			delete_post_meta( $post_id, $meta_key );
			foreach ( $after as $uid ) {
				add_post_meta( $post_id, $meta_key, $uid );
			}

			foreach ( array_diff( $after, $before ) as $uid ) {
				$u = get_userdata( $uid );
				BFTD_Audit::log( $add_event, array(
					'post_id' => $post_id,
					'summary' => $noun . ' ' . ( $u ? $u->display_name : $uid ) . ' was given access to ' . get_the_title( $post_id ) . '.',
				) );
				self::welcome( $uid, $post_id, $add_event );
			}
			foreach ( array_diff( $before, $after ) as $uid ) {
				$u = get_userdata( $uid );
				BFTD_Audit::log( $rm_event, array(
					'post_id' => $post_id,
					'summary' => $noun . ' ' . ( $u ? $u->display_name : $uid ) . ' no longer has access to ' . get_the_title( $post_id ) . '.',
				) );
			}
		}
	}

	/**
	 * The caregiver's welcome, sent once and only once.
	 *
	 * Called both when a caregiver is linked to a published student and, for
	 * one linked while the record was still a draft, when that record is
	 * published. The flag is what keeps a republish from welcoming somebody
	 * who has been in the portal for months.
	 */
	public static function send_welcome( $user_id, $student_id ) {
		$u = get_userdata( $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) return;

		$key = BFTD_Change_Notify::welcomed_key( $student_id );
		if ( get_user_meta( $user_id, $key, true ) ) return;
		update_user_meta( $user_id, $key, BFTD_Time::mysql() );

		BFTD_Notices::add( $user_id, 'system', $student_id, $student_id, '', 'Your portal is ready',
			'Everything about ' . get_the_title( $student_id ) . ' is in one place.' );
		BFTD_Emails::send( 'client_welcome', $u->user_email, array(
			'first_name'       => $u->first_name ? $u->first_name : $u->display_name,
			'student_name'     => get_the_title( $student_id ),
			'tutor_name'       => wp_get_current_user()->display_name,
			'dashboard_url'    => BFTD_Dashboard::url( $student_id ),
			'set_password_url' => wp_lostpassword_url(),
		), array( 'student_id' => $student_id, 'post_id' => $student_id ) );
	}

	private static function welcome( $user_id, $student_id, $event ) {
		$u = get_userdata( $user_id );
		if ( ! $u || ! is_email( $u->user_email ) ) return;

		$tags = array(
			'first_name'    => $u->first_name ? $u->first_name : $u->display_name,
			'student_name'  => get_the_title( $student_id ),
			'tutor_name'    => wp_get_current_user()->display_name,
			'dashboard_url' => BFTD_Dashboard::url( $student_id ),
			'set_password_url' => wp_lostpassword_url(),
		);

		if ( 'client_linked' === $event ) {
			// "Your portal is ready" is only true once the student record is
			// published. On a draft the caregiver would sign in to nothing,
			// so this waits for the publish transition instead.
			if ( ! BFTD_Change_Notify::is_published( $student_id ) ) return;
			self::send_welcome( $user_id, $student_id );
		} else {
			BFTD_Notices::add( $user_id, 'system', $student_id, $student_id, '', 'You have been assigned ' . get_the_title( $student_id ), '' );
			BFTD_Emails::send( 'staff_assigned', $u->user_email, $tags, array( 'student_id' => $student_id, 'post_id' => $student_id ) );
		}
	}

	public static function flash() {
		// A slot turned away for breaking a tutor's daily ceiling is louder
		// than the save confirmation, because something the person typed is
		// not there any more and they need to know which.
		$wkey  = 'bftd_sched_warn_' . get_current_user_id();
		$warns = get_transient( $wkey );
		if ( $warns ) {
			delete_transient( $wkey );
			echo '<div class="notice notice-warning"><p><strong>Some session slots were not added.</strong></p><ul style="margin:0 0 8px 18px;list-style:disc">';
			foreach ( (array) $warns as $w ) echo '<li>' . esc_html( $w ) . '</li>';
			echo '</ul></div>';
		}

		self::warn_about_duplicate_reports();

		/*
		 * The duplicate draft notice names the other record and links to it,
		 * because "there is already a draft" without saying which one leaves
		 * somebody searching a list of three hundred for it.
		 */
		$dkey = 'bftd_dupe_' . get_current_user_id();
		$dupe = get_transient( $dkey );
		if ( $dupe ) {
			delete_transient( $dkey );
			$ids = array_filter( array_map( 'absint', explode( ',', (string) $dupe ) ) );
			echo '<div class="notice notice-warning"><p><strong>Kept as a draft.</strong> '
				. esc_html( 1 === count( $ids )
					? 'There is already a draft for this student on this date.'
					: 'There are already drafts for this student on this date.' )
				. ' Everything you wrote has been saved. Finish the one that has the work in it, '
				. 'or change the date on this one, and publish then.</p><ul style="margin:0 0 8px 18px;list-style:disc">';
			foreach ( $ids as $id ) {
				$link = get_edit_post_link( $id );
				$when = (string) BFTD_Fields::get( $id, 'session', 'session_date' );
				$label = 'Draft started ' . get_the_date( 'j M Y', $id );
				if ( '' !== $when ) $label .= ', for ' . BFTD_Time::format( 'j M Y', BFTD_Time::stamp( $when, '12:00' ) );
				echo '<li>' . ( $link
					? '<a href="' . esc_url( (string) $link ) . '">' . esc_html( $label ) . '</a>'
					: esc_html( $label ) ) . '</li>';
			}
			echo '</ul></div>';
		}

		$hkey = 'bftd_held_' . get_current_user_id();
		$held = get_transient( $hkey );
		if ( $held ) {
			delete_transient( $hkey );
			list( $what, $missing ) = array_pad( explode( '|', (string) $held, 2 ), 2, '' );
			if ( 'unclaimed' === $what ) {
				// A different sentence, because this one is not about the
				// family. Nothing is withheld from them by it; what is
				// withheld is a payment nobody can attribute.
				echo '<div class="notice notice-warning"><p><strong>Kept as a draft.</strong> '
					. 'This session does not say ' . esc_html( $missing ) . ', so there is nobody to pay for it. '
					. 'Everything you wrote has been saved. Name the tutor under Taught by and publish.</p></div>';
			} else {
				echo '<div class="notice notice-warning"><p><strong>Kept as a draft.</strong> '
					. 'This session says it was ' . esc_html( $what ) . ', and it does not say '
					. esc_html( $missing ) . '. Everything you wrote has been saved. Fill those in and publish, '
					. 'and the family will see it then.</p></div>';
			}
		}

		$key = 'bftd_saved_' . get_current_user_id();
		$n   = get_transient( $key );
		if ( ! $n ) return;
		delete_transient( $key );
		echo '<div class="notice notice-success is-dismissible"><p>Saved. ' . (int) $n . ' ' . esc_html( _n( 'section', 'sections', (int) $n, 'bftd' ) ) . ' changed, and the before and after is in the history below.</p></div>';
	}
}
