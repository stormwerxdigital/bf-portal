<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A report, rendered as the prototype draws it.
 *
 * This emits the prototype's markup and class names, not a reinterpretation
 * of them, and it is paired with bftd-report.css, which is the prototype's
 * own stylesheet lifted whole. The two were separated once before, by hand,
 * a piece at a time, and the result quietly lost the hero, the collapsible
 * sections, the to-do cards and the summary strip. Keeping the markup and the
 * stylesheet identical to the design is what stops that happening again.
 *
 * Everything a tutor types reaches here through BFTD_Schema, so the admin and
 * the report stay in step by construction. Where the design shows a figure
 * nobody types, such as the number of lessons recorded, it is counted rather
 * than asked for.
 */
class BFTD_Report_View {

	/** Chevron, used by every collapsible thing in the design. */
	const CHEV = '<span class="chev"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></span>';

	/**
	 * @param array $extra Facts a real record carries in its own tables and a
	 *                     sample has to state: the counts across the top, and
	 *                     the line under the heading.
	 */
	public static function render( $report, $student_id, $screen, $heading = '', $extra = array() ) {
		self::$extra = is_array( $extra ) ? $extra : array();

		// The wrapper is emitted here, by the renderer, and nowhere else.
		//
		// Every selector in bftd-report.css is prefixed .bf-report, so this
		// element is not decoration: without it the stylesheet matches nothing
		// and the report renders as raw browser defaults. It lived in the
		// preview template for three versions, which is exactly why the family
		// portal shipped unstyled while every preview looked right. A renderer
		// that carries its own scope cannot be called somewhere that forgot it.
		//
		// data-theme is pinned to light. The design has a dark mode, but the
		// portal around it does not, and a dark report inside a light page
		// reads as a fault rather than a preference. It also keeps the preview
		// honest: the preview claims to be what the family sees, so it must not
		// follow a setting the family's own screen ignores.
		echo '<div class="bf-report" data-theme="light">';

		if ( BFTD_Schema::SCREEN_REPORT === $screen ) {
			self::diagnostic( $report, $student_id, $heading );
		} else {
			self::progress( $report, $student_id, $heading );
		}

		echo '</div>';

		self::$extra = array();
	}

	/**
	 * The fonts the report is drawn in, in one place.
	 *
	 * The report, the preview and the back end all ask for this same URL. When
	 * they each kept their own list they drifted: the editor lost the alphabet
	 * face the report used, so the letters a tutor typed were not the letters a
	 * parent saw.
	 */
	public static function fonts_url() {
		return 'https://fonts.googleapis.com/css2'
			. '?family=Raleway:wght@400;600;700;800'
			. '&family=Source+Sans+3:ital,wght@0,400;0,600;0,700;1,400'
			. '&family=IBM+Plex+Mono:wght@400;500'
			. '&family=Didact+Gothic'
			. '&display=swap';
	}

	private static $extra = array();

	/* ================================================================== */
	/* Reading diagnostic                                                 */
	/* ================================================================== */

	private static function diagnostic( $report, $student_id, $heading ) {
		$sections = BFTD_Schema::sections_for_screen( BFTD_Schema::SCREEN_REPORT );
		$name     = self::student_name( $student_id, $heading );
		$v        = BFTD_Fields::get_section( $report, 'sec-assessment-overview' );
		$tutor    = self::tutor_name( $report, $student_id, $v );

		/* ---- hero ---- */
		?>
		<div class="hero-report">
			<p class="eyebrow">Reading diagnostic assessment<?php
				// Which of the four it is. Calling a mid-programme reassessment
				// a baseline is telling a family this is where their child
				// started when it is where they got to.
				$kind = BFTD_Schema::assessment_kind_label(
					isset( $v['kind'] ) ? $v['kind'] : BFTD_Schema::KIND_DEFAULT
				);
				// Written the way the milestone cards on the progress report
				// write it. Lowercasing it here made the same assessment
				// "initial" on one screen and "Initial" on the other, which
				// reads as two different things.
				echo $kind ? ' &middot; ' . esc_html( $kind ) : '';
			?></p>
			<h1><?php echo esc_html( $name ); ?></h1>
			<div class="sub"><?php echo esc_html( self::hero_line( $report, $student_id, $v ) ); ?></div>
			<p class="lede">Six areas of reading and writing, so we can see exactly where the gaps are.
				The cards below are the summary. Everything under them is the full report, section by
				section, exactly as <?php echo esc_html( $tutor ? $tutor : 'your tutor' ); ?> wrote it.</p>
			<div class="hero-actions">
				<button type="button" class="btn btn-primary" onclick="window.print()">Print or save as PDF</button>
				<?php $call = BFTD_Settings::help_bar_link(); ?>
				<?php if ( $call ) : ?>
					<a class="btn btn-ghost" href="<?php echo esc_url( $call ); ?>">Book your 15-minute review call</a>
				<?php endif; ?>
			</div>
		</div>
		<?php

		/* ---- what the family has to do about this report ---- */
		self::items_cards( $student_id, 'Reading diagnostic' );

		/* ---- read this first ---- */
		?>
		<div class="banner" style="margin:18px 0">
			<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="8"/><path d="M10 6.5v4M10 13.6v.1"/></svg>
			<span><b>Read this part first.</b> These numbers describe where <?php echo esc_html( self::first_name( $name ) ); ?>
				is starting from, not what they are capable of. Every one of these six areas responds to
				teaching, and several of them move quickly.</span>
		</div>
		<?php

		/* ---- the overview, open ---- */
		if ( BFTD_Fields::section_has_content( $report, 'sec-assessment-overview' ) ) {
			echo '<div id="dxoverview" style="margin-bottom:24px">';
			self::docsec( 'sec-assessment-overview', 'Summary', 'Assessment overview',
				self::body_html( $report, 'sec-assessment-overview', $sections['sec-assessment-overview'] ), true,
				self::thread_for( $report, 'sec-assessment-overview', $sections['sec-assessment-overview'] ) );
			echo '</div>';
		}

		/* ---- the six cards ---- */
		$cards = self::skill_cards( $report, $sections );
		if ( $cards ) {
			?>
			<h2 style="margin-bottom:6px">Reading skill assessments</h2>
			<p class="lede dim" style="max-width:64ch;margin-bottom:14px">Each card is one of the six areas.
				Tap to see what it measures, how <?php echo esc_html( self::first_name( $name ) ); ?> did, and what we will do about it.</p>
			<div class="skills"><?php echo $cards; ?></div>
			<?php
		}

		/* ---- the full report ---- */
		?>
		<h2 style="margin:30px 0 12px" id="dxdoc">Assessment details</h2>
		<p class="lede dim" style="max-width:64ch;margin-bottom:14px">Every area in full, then strengths and growth
			areas, the recommended approach, the instructional plan and the conclusion.</p>
		<div id="dxplan">
			<?php
			foreach ( $sections as $id => $section ) {
				if ( 'sec-assessment-overview' === $id ) continue;
				if ( ! BFTD_Fields::is_visible( $report, $id ) ) continue;
				if ( ! BFTD_Fields::section_has_content( $report, $id ) ) continue;
				self::docsec( $id, isset( $section['eyebrow'] ) ? $section['eyebrow'] : '', $section['label'],
					self::body_html( $report, $id, $section ), false,
					self::thread_for( $report, $id, $section ) );
			}
			?>
		</div>
		<?php
	}

	/* ================================================================== */
	/* Progress report                                                    */
	/* ================================================================== */

	private static function progress( $report, $student_id, $heading ) {
		$sections = BFTD_Schema::sections_for_screen( BFTD_Schema::SCREEN_PROGRESS );
		$name     = self::student_name( $student_id, $heading );

		self::items_cards( $student_id, 'Progress report' );

		/* ---- page head ---- */
		?>
		<div class="page-head">
			<p class="eyebrow"><?php echo esc_html( self::progress_line( $report, $student_id, $name ) ); ?></p>
			<h1>Progress report</h1>
			<p class="lede dim">A living record, updated after every session. Everything below is the full report:
				the summary, every session, every activity explanation and every piece of work.</p>
		</div>
		<?php

		self::kpis( $report, $student_id );
		self::jump( $report, $sections );

		/* ---- the dashboard cards ---- */
		echo '<div id="dash">';

		// Attendance first. It is the shortest card and the one that can change
		// what a family does this week; a gap of three weeks matters more,
		// today, than a reading level that moves over a term.
		self::chart_card( $report, 'sec-attendance', $sections, 'Attendance', 'attend' );

		/*
		 * The two measures in one card, behind tabs.
		 *
		 * They were two full height charts stacked, and a parent scrolled past
		 * a screen and a half of one to reach the other. They are drawn the
		 * same way, from the same three assessments, and nobody reads both at
		 * once — so they share a card and a set of milestone cards, and a tap
		 * swaps which is showing.
		 */
		self::chart_tabs( $report, $name, $sections, array(
			'overview' => array( 'sec-progress-overview', 'Reading level' ),
			'wpm'      => array( 'sec-fluency', 'Words correct per minute' ),
		) );

		self::chart_card( $report, 'sec-activity-map', $sections, 'Skills we\'re building', 'cover' );

		echo '</div>';

		/* ---- where those measures came from ---- */
		self::diagnostic_pull( $report, $student_id, $name );

		/* ---- the written sections ---- */
		$rest = array();
		foreach ( $sections as $id => $section ) {
			if ( in_array( $id, array( 'sec-progress-overview', 'sec-fluency', 'sec-activity-map', 'sec-texts-read', 'sec-attendance' ), true ) ) continue;
			if ( ! empty( $section['sessions'] ) ) continue;
			if ( ! BFTD_Fields::is_visible( $report, $id ) ) continue;
			if ( ! BFTD_Fields::section_has_content( $report, $id ) ) continue;
			$rest[ $id ] = $section;
		}

		if ( $rest ) {
			echo '<h2 style="margin:26px 0 12px" id="docs">Report sections</h2>';
			$first = true;
			foreach ( $rest as $id => $section ) {
				self::docsec( $id, isset( $section['eyebrow'] ) ? $section['eyebrow'] : '', $section['label'],
					self::body_html( $report, $id, $section ), $first,
					self::thread_for( $report, $id, $section ) );
				$first = false;
			}
		}

		/* ---- what has been read, then the sessions it was read in ---- */

		// Moved down here from the cards at the top. It is a list of books
		// rather than a measure, and it reads as the preface to the sessions
		// underneath it: this is what we read, and here is every session we
		// read it in.
		echo '<div id="texts-block">';
		self::chart_card( $report, 'sec-texts-read', $sections, 'What we have read so far', 'texts' );
		echo '</div>';

		$sessions = BFTD_CPT::sessions_for( $report );
		if ( $sessions ) {
			// The same purple band the cards above it wear. It was a bare
			// heading on the page background, so the run of cards appeared to
			// stop and the sessions read as something left over underneath
			// rather than as the last section of the report.
			echo '<div class="card chart-card stream-band"><div class="chart-head"><h2 id="stream">Sessions</h2></div></div>';
			echo '<div id="stream-list">';
			BFTD_Dashboard::render_sessions( $report, $student_id );
			echo '</div>';
		}
	}

	/**
	 * The diagnostic, on the progress report.
	 *
	 * Where the six measures on the chart above came from. A parent reading a
	 * progress report should not have to remember which screen the assessment
	 * was on, and a tutor should not be retyping the overview into a second
	 * place, so the overview a family reads here is the overview on the
	 * diagnostic itself, the cards are the same six cards, and the link goes
	 * to the full report rather than to a copy of it.
	 *
	 * The most recent published diagnostic, because that is the one that
	 * describes where the child is now. The earlier ones are still on the
	 * chart, which is where a history belongs.
	 */
	private static function diagnostic_pull( $report, $student_id, $name ) {
		// The same assessment the chart above ends on. Both ask for the last
		// diagnostic by the date it was taken; picking "the newest record"
		// here instead let the cards describe one assessment while the line
		// beside them finished on another.
		$dx = ! empty( self::$extra['diagnostic'] )
			? (int) self::$extra['diagnostic']
			: ( $student_id ? BFTD_CPT::current_diagnostic( $student_id ) : 0 );
		if ( ! $dx ) return;

		$sections = BFTD_Schema::sections_for_screen( BFTD_Schema::SCREEN_REPORT );
		/*
		 * Where the full diagnostic can be reached, which depends on who is
		 * looking.
		 *
		 * A PREVIEW is not the portal. A tutor pressing Preview is signed in
		 * as staff, and staff arriving at /portal/ without naming a family are
		 * handed the family chooser — so the portal link, followed from a
		 * preview, showed a tutor a search box instead of the assessment they
		 * had clicked. The preview links to the diagnostic's own preview,
		 * which is the page that actually answers what they asked for.
		 *
		 * On the PORTAL the portal link is right, and it now carries the
		 * family through for a staff member reading over their shoulder.
		 *
		 * A SAMPLE has no student and so no portal screen of its own; it names
		 * the other sample instead.
		 */
		if ( ! empty( self::$extra['diagnostic_link'] ) ) {
			$link = (string) self::$extra['diagnostic_link'];
		} elseif ( class_exists( 'BFTD_Preview' ) && BFTD_Preview::active() ) {
			$link = BFTD_Preview::url( $dx );
		} else {
			$link = $student_id ? BFTD_Dashboard::url( $student_id, 'sec-assessment-overview' ) : '';
		}

		$cards = self::skill_cards( $dx, $sections, $link ? $link : null );
		if ( ! $cards ) return;

		$on   = (string) BFTD_Fields::get( $dx, 'sec-assessment-overview', 'assessed_on' );
		$when = $on ? BFTD_Time::day( $on, 'j F Y' ) : '';

		// Which of the four it is, and when it was taken. "The assessment
		// these cards are taken from" described the cards; a family wants to
		// know which reading this is, because a middle assessment and the
		// baseline are not the same news.
		$kind = BFTD_Schema::assessment_kind_label(
			(string) BFTD_Fields::get( $dx, 'sec-assessment-overview', 'kind' )
		);
		if ( '' === $kind ) $kind = BFTD_Schema::assessment_kind_label( BFTD_Schema::KIND_DEFAULT );

		echo '<div id="diagnostic" style="margin:26px 0 0">';
		echo '<h2 style="margin-bottom:6px">Last reading assessment</h2>';
		echo '<p class="lede dim" style="max-width:64ch;margin-bottom:14px">'
			// The heading gives the noun, so the line gives the two facts:
			// which of the four this was, and the day it was taken.
			. esc_html( $kind . ( $when ? ' taken on ' . $when : '' ) . '.' )
			. '</p>';

		/*
		 * The written overview is not repeated here.
		 *
		 * It is the summary of the diagnostic, in full, and it is already the
		 * first thing on the diagnostic itself — which the button at the foot
		 * of this block opens. Carried onto the progress report it was a
		 * closed accordion headed "Assessment overview" sitting between the
		 * heading and the cards: a reader either ignored it or opened it and
		 * read three paragraphs about an assessment before reaching the
		 * report they came for.
		 *
		 * The six cards are the part worth pulling across, because they are
		 * the figures the charts above are drawn from.
		 */
		echo '<h3 style="margin:18px 0 10px">Reading skill assessments</h3>';
		echo '<div class="skills">' . $cards . '</div>';

		if ( $link ) {
			// The button ends this block, and the next card starts hard
			// against it. The gap under it has to be the gap BETWEEN sections
			// rather than the gap between a paragraph and the thing above it.
			echo '<p class="note" style="margin:14px 0 30px"><a class="btn btn-ghost" href="' . esc_url( $link ) . '">'
				. esc_html( 'Read ' . self::first_name( $name ) . '\'s full reading diagnostic' ) . '</a></p>';
		}
		echo '</div>';
	}

	/* ================================================================== */
	/* Pieces                                                             */
	/* ================================================================== */

	/**
	 * The thread descriptor for a section, or null where it carries none.
	 *
	 * Which sections take a conversation is a schema question, answered in
	 * one place, so the two report screens cannot end up disagreeing about
	 * where a family may ask something.
	 */
	private static function thread_for( $report, $id, $section ) {
		if ( empty( $section['thread'] ) ) return null;
		return array(
			'post'    => $report,
			'section' => $id,
			'label'   => isset( $section['label'] ) ? $section['label'] : 'this section',
		);
	}

	/** A collapsible document section, exactly as the design draws one. */
	public static function docsec( $id, $eyebrow, $title, $body, $open = false, $thread = null ) {
		?>
		<section class="docsec<?php echo $open ? ' is-open' : ''; ?>" id="<?php echo esc_attr( $id ); ?>">
			<button class="docsec-h" type="button" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>">
				<span class="docsec-t"><?php
					if ( $eyebrow ) echo '<span class="eyebrow">' . esc_html( $eyebrow ) . '</span>';
					echo esc_html( $title );
				?></span>
				<?php echo self::CHEV; ?>
			</button>
			<div class="acc"><div class="acc-in"><div class="docsec-b doc-body">
				<?php echo $body; ?>
				<?php if ( null !== $thread ) self::thread( $thread['post'], $thread['section'], $thread['label'] ); ?>
			</div></div></div>
		</section>
		<?php
	}

	/**
	 * The conversation that belongs to one section.
	 *
	 * A family's question lives where they asked it. Putting every question
	 * in one inbox at the bottom of the report would mean a parent reading
	 * about spelling has to remember which paragraph they were on by the
	 * time they reach the box, and a tutor answering has to reconstruct it.
	 *
	 * The composer is drawn in a preview too, because a report without it
	 * does not look like the report a family gets. It is inert there, and
	 * the script checks the flag before it will post anything.
	 */
	public static function thread( $post_id, $section_id, $label ) {
		$preview = class_exists( 'BFTD_Preview' ) && BFTD_Preview::active();
		$msgs    = self::thread_messages( $post_id, $section_id );
		?>
		<div class="sec-thread" data-post="<?php echo (int) $post_id; ?>"
			data-section="<?php echo esc_attr( $section_id ); ?>"
			<?php if ( $preview ) echo 'data-preview="1"'; ?>>
			<div class="st-head">
				<span class="st-ico" aria-hidden="true">
					<svg viewBox="0 0 20 20"><path d="M17 11.5a2.5 2.5 0 01-2.5 2.5H7l-4 3v-3H4.5A2.5 2.5 0 012 11.5v-6A2.5 2.5 0 014.5 3h10A2.5 2.5 0 0117 5.5z"/><path d="M6.5 7.2h7M6.5 10h4.5"/></svg>
				</span>
				<h3>Questions about this section</h3>
				<?php if ( $msgs ) : ?>
					<span class="st-n"><?php echo (int) count( $msgs ); ?> <?php
						echo esc_html( 1 === count( $msgs ) ? 'message' : 'messages' );
					?></span>
				<?php endif; ?>
			</div>

			<?php if ( ! $msgs ) : ?>
				<p class="ai-empty">No one has asked about this yet. Anything you write here goes straight to your tutor and stays attached to this section.</p>
			<?php else : foreach ( $msgs as $m ) : ?>
				<div class="msg<?php echo empty( $m['mine'] ) ? '' : ' mine'; ?>">
					<span class="who"><?php echo esc_html( $m['initials'] ); ?></span>
					<span class="msg-b">
						<span class="msg-h"><b><?php echo esc_html( $m['name'] ); ?></b>
							<time<?php if ( $m['title'] ) echo ' title="' . esc_attr( $m['title'] ) . '"'; ?>><?php echo esc_html( $m['when'] ); ?></time></span>
						<div class="msg-t"><?php echo wp_kses_post( wpautop( $m['body'] ) ); ?></div>
					</span>
				</div>
			<?php endforeach; endif; ?>

			<div class="rt">
				<div class="rt-bar">
					<button type="button" data-cmd="bold" title="Bold"><b>B</b></button>
					<button type="button" data-cmd="italic" title="Italic"><i>I</i></button>
					<button type="button" data-cmd="insertUnorderedList" title="Bullet list">&#8226;</button>
					<button type="button" data-cmd="createLink" title="Add a link">Link</button>
					<span class="rt-sep"></span>
					<button type="button" class="rt-attach" title="Attach a file">
						<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M10.5 5.5l-4.6 4.6a1.8 1.8 0 002.5 2.5l5-5a3.1 3.1 0 00-4.4-4.4l-5 5a4.4 4.4 0 006.2 6.2l4.2-4.2"/></svg> Attach</button>
				</div>
				<div class="rt-in" contenteditable="<?php echo $preview ? 'false' : 'true'; ?>" role="textbox"
					aria-label="Write a message" data-ph="<?php echo esc_attr( 'Ask about ' . $label ); ?>"></div>
				<div class="rt-files" hidden></div>
				<div class="rt-foot">
					<span class="rt-hint"><?php echo esc_html( $preview
						? 'This is a preview, so nothing can be posted from here.'
						: 'Paste a screenshot, drag files in, or attach. Up to 5.' ); ?></span>
					<button class="btn btn-primary btn-sm" type="button"<?php disabled( $preview, true ); ?>>Post</button>
				</div>
				<input type="file" class="rt-file" multiple hidden accept="image/*,.pdf,.doc,.docx,.txt,.heic">
			</div>
		</div>
		<?php
	}

	/**
	 * One section's messages, flattened to what the markup needs.
	 *
	 * A sample report supplies them directly; a real one reads the thread.
	 * Both arrive here in the same shape, so the markup above has one path
	 * through it rather than a branch per source.
	 */
	private static function thread_messages( $post_id, $section_id ) {
		if ( isset( self::$extra['threads'][ $section_id ] ) ) {
			$out = array();
			foreach ( (array) self::$extra['threads'][ $section_id ] as $m ) {
				$out[] = array(
					'name'     => isset( $m['name'] ) ? $m['name'] : 'Someone',
					'initials' => self::initials( isset( $m['name'] ) ? $m['name'] : '' ),
					'when'     => isset( $m['when'] ) ? $m['when'] : '',
					'title'    => isset( $m['title'] ) ? $m['title'] : '',
					'body'     => isset( $m['body'] ) ? $m['body'] : '',
					'mine'     => ! empty( $m['mine'] ),
				);
			}
			return $out;
		}

		if ( ! class_exists( 'BFTD_Threads' ) || ! $post_id ) return array();
		$thread = BFTD_Threads::find_thread( $post_id, $section_id );
		if ( ! $thread ) return array();

		$out = array();
		foreach ( BFTD_Threads::messages( $thread->comment_ID ) as $m ) {
			$u  = $m->user_id ? get_userdata( $m->user_id ) : null;
			$ts = strtotime( $m->comment_date_gmt . ' UTC' );
			$out[] = array(
				'name'     => $u ? $u->display_name : 'Someone',
				'initials' => self::initials( $u ? $u->display_name : '' ),
				'when'     => $ts ? human_time_diff( $ts ) . ' ago' : '',
				'title'    => $ts ? BFTD_Time::format( 'l, j F Y \a\t g:i A', $ts ) : '',
				'body'     => $m->comment_content,
				'mine'     => $u && (int) $u->ID === get_current_user_id(),
			);
		}
		return $out;
	}

	/** "Priya Singh" becomes "PS". */
	private static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$out   = '';
		foreach ( array_slice( $parts, 0, 2 ) as $p ) {
			if ( '' !== $p ) $out .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		}
		return '' === $out ? '?' : $out;
	}

	/** One of the dashboard cards, with the purple header the design gives it. */
	private static function chart_card( $report, $id, $sections, $title, $anchor = '' ) {
		if ( ! isset( $sections[ $id ] ) ) return;
		if ( ! BFTD_Fields::is_visible( $report, $id ) ) return;

		$body = self::body_html( $report, $id, $sections[ $id ] );
		if ( '' === trim( wp_strip_all_tags( $body ) ) && false === strpos( $body, '<svg' ) && false === strpos( $body, '<table' ) ) return;

		// $head_intro is what the tabbed card calls this, and for three
		// versions this function asked for that name instead of its own: the
		// intro a tutor wrote under a card heading was never drawn, and every
		// card raised a notice saying so.
		$intro = (string) BFTD_Fields::get( $report, $id, 'intro' );
		?>
		<div class="card chart-card" style="margin-bottom:18px"<?php echo $anchor ? ' id="' . esc_attr( $anchor ) . '"' : ''; ?>>
			<div class="chart-head">
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( $intro ) : ?><p><?php echo esc_html( $intro ); ?></p><?php endif; ?>
			</div>
			<div class="chart-body"><?php echo $body; ?></div>
		</div>
		<?php
	}

	/**
	 * Two charts, one card, a tab each.
	 *
	 * They are the same picture drawn from the same three assessments, in two
	 * units nobody can compare, and a parent reads one at a time. Stacked they
	 * cost a screen and a half of scrolling to get from the first to the
	 * second.
	 *
	 * Both panels ship visible and the script hides the second one, so a
	 * report opened with scripts blocked, or printed, is both charts down the
	 * page rather than one chart and a row of dead buttons. The milestone
	 * cards are drawn once, above the tabs, because they belong to the
	 * assessments rather than to either measure.
	 */
	private static function chart_tabs( $report, $name, $sections, $tabs ) {
		$live = array();
		foreach ( $tabs as $anchor => $meta ) {
			list( $id, $label ) = $meta;
			if ( ! isset( $sections[ $id ] ) ) continue;
			if ( ! BFTD_Fields::is_visible( $report, $id ) ) continue;

			$body = self::body_html( $report, $id, $sections[ $id ] );
			if ( '' === trim( wp_strip_all_tags( $body ) ) && false === strpos( $body, '<svg' ) ) continue;
			$live[ $anchor ] = array( 'id' => $id, 'label' => $label, 'body' => $body );
		}
		if ( ! $live ) return;

		// One of them on its own is not worth a tab strip, but it still wants
		// the milestone cards and a heading, so the card is drawn here either
		// way rather than handed back to chart_card.
		$one_only = ( 1 === count( $live ) );
		$first    = key( $live );

		// Each measure's explainer belongs to that measure. Hoisting one of
		// them into a shared card head put "the reading level of his own
		// grade" above the words-a-minute chart, describing a chart that was
		// not on screen. With one chart there is no other measure to be wrong
		// about, so it stays in the head where it reads better.
		$head_intro = $one_only ? (string) BFTD_Fields::get( $report, $live[ $first ]['id'], 'intro' ) : '';
		?>
		<div class="card chart-card<?php echo $one_only ? '' : ' chart-tabs'; ?>" id="<?php echo esc_attr( $first ); ?>">
			<div class="chart-head">
				<h2><?php
					// With two of them the card is about the child's reading;
					// with one it is about that measure, because a heading
					// that promises two charts over a single chart is a
					// heading describing a page that is not there.
					echo esc_html( $one_only
						? $live[ $first ]['label']
						: self::first_name( $name ) . '\'s reading' );
				?></h2>
				<?php if ( $head_intro ) : ?><p><?php echo esc_html( $head_intro ); ?></p><?php endif; ?>
			</div>
			<div class="chart-body">
				<?php
				// The milestone cards, once. Both charts are drawn from these
				// three readings, so a set above each was the same three cards
				// twice in one card.
				echo BFTD_Charts::milestones( $report );
				?>
				<?php if ( ! $one_only ) : ?>
				<div class="tabstrip" role="tablist">
					<?php foreach ( $live as $anchor => $one ) : ?>
						<button type="button" role="tab" class="tabbtn<?php echo $anchor === $first ? ' is-on' : ''; ?>"
							id="tab-<?php echo esc_attr( $anchor ); ?>"
							aria-controls="panel-<?php echo esc_attr( $anchor ); ?>"
							aria-selected="<?php echo $anchor === $first ? 'true' : 'false'; ?>"><?php
							echo esc_html( $one['label'] );
						?></button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<?php foreach ( $live as $anchor => $one ) : ?>
					<div class="tabpanel" role="tabpanel" id="panel-<?php echo esc_attr( $anchor ); ?>"
						aria-labelledby="tab-<?php echo esc_attr( $anchor ); ?>">
						<h3 class="tabpanel-h"><?php echo esc_html( $one['label'] ); ?></h3>
						<?php $lede = $one_only ? '' : (string) BFTD_Fields::get( $report, $one['id'], 'intro' ); ?>
						<?php if ( '' !== $lede ) : ?>
							<p class="tabpanel-l"><?php echo esc_html( $lede ); ?></p>
						<?php endif; ?>
						<?php echo $one['body']; ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/** The six summary cards. Returns markup so the caller can skip the heading. */
	private static function skill_cards( $report, $sections, $base = '' ) {
		// null means there is nowhere to send a reader. An empty string means
		// the full section is further down this same page.
		$linked = ( null !== $base );
		$tone = array(
			'strength'   => array( 'sage',  'A strength' ),
			'developing' => array( 'purple','Developing' ),
			'priority'   => array( 'clay',  'Focus area' ),
		);

		ob_start();
		foreach ( $sections as $id => $section ) {
			if ( empty( $section['card'] ) ) continue;
			$v = BFTD_Fields::get_section( $report, $id );

			// What this section measured, worked out by the schema rather
			// than here. The chart of these same six scores over time reads
			// the identical rule, which is the only way the line and the card
			// under it can be made to agree.
			$m = BFTD_Schema::measure( $id, $v );
			if ( null === $m ) continue;

			$bench   = $m['bench'];
			$measure = $m['value'];

			// Where a section has a fixed benchmark, the bar is worked out
			// from the score against it rather than typed. A percentage a
			// person maintains by hand is a percentage that disagrees with
			// the number beside it the first time either changes.
			if ( $bench > 0 ) {
				$ratio = $measure / $bench;
				$pct   = max( 0, min( 100, round( $ratio * 100 ) ) );
				$tint  = self::meter_tone( $ratio );
			} else {
				$status = ( isset( $v['status'] ) && isset( $tone[ $v['status'] ] ) ) ? $v['status'] : 'developing';
				$pct    = ( isset( $v['meter_pct'] ) && '' !== $v['meter_pct'] ) ? max( 0, min( 100, (float) $v['meter_pct'] ) ) : 0;
				$tint   = $tone[ $status ][0];
			}

			// Whether this card may state a verdict at all.
			//
			// A section that still asks a tutor where the result sits shows
			// what they chose. A section that has had that question taken away
			// must not fall back to one: "Developing" appearing on a card
			// because a band has no published norm is the card deciding
			// something about a child that nobody decided, and it is the exact
			// judgement those sections had removed on purpose.
			$may_judge = isset( $section['fields']['status'] );

			// A score out of a total is a fraction, and a slash is how one is
			// written. "25 of 26" reads as a sentence about a quantity; 25/26
			// reads as the thing the tutor counted.
			$of   = ! empty( $v['score_of'] ) ? '/ ' . $v['score_of'] : ( ! empty( $v['unit'] ) ? $v['unit'] : '' );
			if ( ! $of && ! empty( $section['unit'] ) ) $of = $section['unit'];

			// A chosen score is stored as its key. The family reads the words
			// beside that key, never the key itself.
			$shown = $m['shown'];
			$word = preg_match( '/^[\d.]+$/', trim( $shown ) ) ? '' : ' is-word';

			// The right hand line is the goal where there is one, and the
			// tutor's own marker otherwise.
			$meta = ! empty( $v['baseline'] ) ? $v['baseline'] : '';

			// "Goal 10" is arithmetic, and on a scale of named levels it is
			// arithmetic about nothing a parent can picture. A section whose
			// score is a level says what its scale runs between instead.
			if ( ! $meta && ! empty( $section['meter_note'] ) ) $meta = $section['meter_note'];

			// A chosen goal is named beside the bar, because "Goal 151" on its
			// own tells a parent nothing about whose 151 it is.
			if ( ! $meta && ! empty( $section['benchmark_field'] )
				&& ! empty( $section['benchmark_lookup'] ) && 'fluency_norm' === $section['benchmark_lookup'] ) {
				$chosen = isset( $v[ $section['benchmark_field'] ] ) ? $v[ $section['benchmark_field'] ] : '';
				$norms  = BFTD_Schema::fluency_norms();
				if ( isset( $norms[ $chosen ] ) ) {
					$meta = ( null === $norms[ $chosen ]['wcpm'] )
						? $norms[ $chosen ]['label'] . ', no published norm'
						: $norms[ $chosen ]['label'] . ' norm, ' . $norms[ $chosen ]['wcpm'] . ' ' . $section['unit'];
				}
			}

			if ( ! $meta && $bench > 0 ) {
				$meta = 'Goal ' . rtrim( rtrim( number_format( $bench, 2, '.', '' ), '0' ), '.' );
				if ( ! empty( $section['unit'] ) ) $meta .= ' ' . $section['unit'];
			}
			if ( ! $meta ) $meta = 'Tap for detail';
			?>
			<div class="skill" data-section="<?php echo esc_attr( $id ); ?>">
				<button class="skill-head" type="button" aria-expanded="false">
					<span class="skill-name"><?php echo esc_html( $section['label'] ); ?></span>
					<span class="skill-sub"><?php echo esc_html( isset( $section['summary'] ) ? $section['summary'] : '' ); ?></span>
					<span class="skill-fig">
						<span class="num<?php echo $word; ?>"><?php echo esc_html( $shown ); ?></span>
						<?php if ( $of ) : ?><span class="of"><?php echo esc_html( $of ); ?></span><?php endif; ?>
					</span>
					<?php if ( $pct ) : ?>
						<span class="track"><span class="fill <?php echo esc_attr( $tint ); ?>" style="width:<?php echo esc_attr( $pct ); ?>%"></span></span>
					<?php endif; ?>
					<span class="skill-status">
						<?php if ( $bench <= 0 && $may_judge ) : ?>
							<span class="pill pill-<?php echo esc_attr( $tint ); ?>"><span class="dot"></span><?php echo esc_html( $tone[ $status ][1] ); ?></span>
						<?php endif; ?>
						<span class="meta"><?php echo esc_html( $meta ); ?></span>
					</span>
				</button>
				<div class="acc"><div class="acc-in"><div class="acc-pad">
					<?php if ( ! empty( $v['card_note'] ) ) : ?>
						<p class="note"><?php echo esc_html( $v['card_note'] ); ?></p>
					<?php endif; ?>
					<?php
					/*
					 * On the diagnostic the full section is further down the
					 * same page; on a progress report it is on another screen
					 * entirely, and a bare fragment there scrolls to nothing —
					 * which is what six of these did until somebody opened the
					 * page and clicked one. Where there is nowhere to send a
					 * reader, the card says nothing rather than offering a
					 * link that goes nowhere.
					 */
					?>
					<?php if ( $linked ) : ?>
						<p class="note" style="margin-top:9px"><a href="<?php
							echo esc_url( $base . '#' . $id );
						?>">Read the full section</a></p>
					<?php endif; ?>
				</div></div></div>
			</div>
			<?php
		}
		return ob_get_clean();
	}

	/**
	 * What colour a measured score is.
	 *
	 * Three bands, and the top one is reaching the benchmark rather than
	 * beating it, because a child at 40 letters a minute is done with
	 * alphabet practice and one at 55 is no more done than that.
	 *
	 * Colour is never the only signal: the fraction and the goal are both
	 * printed beside the bar, which is what a parent who cannot tell clay
	 * from sage actually reads.
	 */
	private static function meter_tone( $ratio ) {
		if ( $ratio >= 1 )   return 'sage';    // at or past the goal
		if ( $ratio >= 0.5 ) return 'purple';  // halfway and climbing
		return 'clay';                          // a focus area
	}

	/**
	 * The four figures across the top of a progress report.
	 *
	 * Counted rather than typed. A tutor asked to keep "lessons recorded" up
	 * to date by hand would be maintaining a number the database already
	 * knows, and it would be wrong within a fortnight.
	 */
	private static function kpis( $report, $student_id ) {
		if ( ! empty( self::$extra['kpis'] ) ) {
			self::kpi_strip( self::$extra['kpis'] );
			return;
		}

		$sessions = BFTD_CPT::sessions_for( $report );
		$lessons  = count( $sessions );

		// Counted by what the activity IS, not by what it was typed as. The
		// same activity under three spellings used to read as three, and a
		// family was told their child had covered more of the programme than
		// they had.
		$activities = array();
		foreach ( $sessions as $sid ) {
			foreach ( BFTD_Activities::for_session( $sid ) as $a ) {
				$activities[ $a['key'] ] = true;
			}
		}
		$done = count( $activities );

		// Skills and activities are two different counts and this tile used to
		// print the second under the first's name. A child can reach one skill
		// through three activities, or three skills in one activity; saying
		// "eighteen skills introduced" because eighteen activities were run is
		// telling a family something nobody measured.
		$grid   = BFTD_Derived::skills( $report );
		$skills = empty( $grid['rows'] ) ? 0 : count( $grid['rows'] );

		// The reading log, read off the activities the lessons used, the same
		// way the table further down the page is.
		$texts = BFTD_Derived::texts( $report );
		$top   = '';
		foreach ( $texts as $t ) {
			// "Level 1", not "1", the same as every other place a text's
			// level is shown.
			if ( ! empty( $t['level'] ) ) $top = BFTD_Charts::text_level_label( $t['level'] );
		}

		/*
		 * Skills are a set length; activities are not.
		 *
		 * There is a fixed list of things this practice claims to teach, so a
		 * child is somewhere along it and "5 of 20" is the useful shape. The
		 * activity library is the ways of teaching them, and no child is meant
		 * to go through all of it — a tile reading "2 of 280" told a family
		 * their child was less than one per cent of the way through a
		 * programme nobody completes. So that one is a plain count of what has
		 * been covered.
		 */
		$skills_total = BFTD_Skills::total();

		$tiles = array();
		if ( $lessons ) $tiles[] = array( $lessons, 'sessions recorded' );
		if ( $skills )  $tiles[] = array( self::of( $skills, $skills_total ), 'skills we\'re building' );
		if ( $texts )   $tiles[] = array( count( $texts ), $top ? 'texts read, up to ' . $top : 'texts read' );
		if ( $done )    $tiles[] = array( $done, 1 === $done ? 'activity completed' : 'activities completed' );
		self::kpi_strip( $tiles );
	}

	/**
	 * "5" on its own, or "5" with a quiet "/20" after it.
	 *
	 * The total is only worth printing when there is one: a practice that has
	 * not filled its library in yet would otherwise be telling families their
	 * child had reached five skills out of none.
	 */
	private static function of( $done, $total ) {
		$done = (int) $done;
		if ( $total <= 0 || $total < $done ) return (string) $done;
		return $done . '<span style="font-size:15px;color:var(--muted)">/' . (int) $total . '</span>';
	}

	private static function kpi_strip( $tiles ) {
		if ( ! $tiles ) return;
		?>
		<div class="kpis" style="margin-bottom:18px">
			<?php foreach ( $tiles as $t ) : ?>
				<div class="kpit"><div class="n"><?php echo wp_kses_post( (string) $t[0] ); ?></div><div class="l"><?php echo esc_html( $t[1] ); ?></div></div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Jump links, built from what the report actually contains.
	 *
	 * Listed by hand in the prototype; here they are generated, so a report
	 * with no reading log does not offer to jump to one.
	 */
	private static function jump( $report, $sections ) {
		$links = array();
		// In the order they appear on the page. A jump strip that lists them
		// in a different order is a map of somewhere else.
		if ( isset( $sections['sec-attendance'] ) && BFTD_Fields::section_has_content( $report, 'sec-attendance' ) ) $links['attend'] = 'Attendance';
		if ( isset( $sections['sec-progress-overview'] ) && BFTD_Fields::section_has_content( $report, 'sec-progress-overview' ) ) $links['overview'] = 'Reading level';
		if ( isset( $sections['sec-activity-map'] ) && BFTD_Fields::section_has_content( $report, 'sec-activity-map' ) ) $links['cover'] = 'Skills we\'re building';
		if ( isset( $sections['sec-texts-read'] ) && BFTD_Fields::section_has_content( $report, 'sec-texts-read' ) ) $links['texts'] = 'What we have read so far';
		$links['docs'] = 'Report sections';
		$sessions = BFTD_CPT::sessions_for( $report );
		if ( $sessions ) $links['stream'] = 'All ' . count( $sessions ) . ' sessions';
		if ( count( $links ) < 2 ) return;
		?>
		<div class="jump" style="margin-bottom:18px">
			<?php foreach ( $links as $id => $label ) : ?>
				<button type="button" data-jump="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></button>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Priority items and For review, as the design's banner cards.
	 *
	 * Read only here: a report is a place to read what is being asked of you.
	 * Ticking happens on the portal itself, where the request came from.
	 */
	/**
	 * The two to-do cards, in the one order they are ever drawn.
	 *
	 * Priority items come before For review on every report. One is what has
	 * to happen before the next lesson and the other is what to look over
	 * when there is time, so a family reading top to bottom should meet the
	 * urgent list first. Rendering them from here rather than from each
	 * screen is what stops the order being a thing two call sites have to
	 * agree about.
	 */
	private static function items_cards( $student_id, $scope ) {
		self::items_card( $student_id, BFTD_Items::PRIORITY, $scope );
		self::items_card( $student_id, BFTD_Items::REVIEW, $scope );
	}

	private static function items_card( $student_id, $list, $scope ) {
		$cfg   = BFTD_Items::config( $list );
		$items = BFTD_Items::open_items( $student_id, $list, '' );
		if ( ! $cfg || ! $items ) return;

		$todo = 0;
		foreach ( $items as $i ) { if ( empty( $i['checked'] ) ) $todo++; }
		$done = count( $items ) - $todo;
		$sage = ( BFTD_Items::REVIEW === $list );
		?>
		<section class="pi-card<?php echo $sage ? ' sage' : ''; ?> is-open" data-kind="<?php echo esc_attr( $list ); ?>">
			<button class="pi-head" type="button" aria-expanded="true">
				<span class="pi-h-t">
					<span class="eyebrow<?php echo $sage ? ' sage' : ' clay'; ?>"><?php echo esc_html( $cfg['eyebrow'] ); ?></span>
					<span class="pi-title"><?php echo esc_html( $cfg['title'] ); ?><span class="scope-chip"><?php echo esc_html( $scope ); ?></span></span>
				</span>
				<span class="pi-badge">
					<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.4"/><path d="M8 5v3.4M8 10.8v.1"/></svg>
					<?php if ( $todo ) : ?><b><?php echo (int) $todo; ?></b> to do<?php else : ?><?php echo esc_html( $cfg['done'] ); ?><?php endif; ?>
				</span>
				<span class="pi-count"><?php echo (int) $done; ?> / <?php echo count( $items ); ?></span>
				<span class="pi-chev"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M4 6l4 4 4-4"/></svg></span>
			</button>
			<div class="acc"><div class="acc-in"><div class="pi-body">
				<div class="pi-list">
					<?php foreach ( $items as $item ) :
						$checked = ! empty( $item['checked'] );
						$detail  = isset( $item['detail'] ) ? trim( (string) $item['detail'] ) : '';
						?>
						<div class="pi-item<?php echo $checked ? ' is-done' : ''; ?>">
							<div class="pi-row">
								<span class="pi-check"><svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.4l3 3 6-6.6"/></svg></span>
								<div class="pi-main">
									<div class="pi-text"><?php echo esc_html( $item['text'] ); ?></div>
									<?php if ( $detail ) :
										// The design shows a truncated first line as the control
										// and keeps the full note behind it, so a list of ten
										// points still reads as a list rather than as an essay.
										$peek = function_exists( 'wp_trim_words' )
											? wp_trim_words( $detail, 12, '&hellip;' )
											: $detail;
										?>
										<button class="pi-det" type="button" aria-expanded="false">
											<svg class="fbc" viewBox="0 0 12 12" aria-hidden="true"><path d="M4 2l4 4-4 4"/></svg>
											<span><?php echo wp_kses_post( $peek ); ?></span>
										</button>
										<div class="acc"><div class="acc-in">
											<div class="pi-det-b doc-body"><?php echo esc_html( $detail ); ?></div>
										</div></div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div></div></div>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Content                                                             */
	/* ------------------------------------------------------------------ */

	/** A section's fields, as the document body the design expects. */
	private static function body_html( $report, $id, $section ) {
		$values = BFTD_Fields::get_section( $report, $id );

		ob_start();

		// The headline finding leads the section it belongs to rather than
		// sitting in a row at the bottom of it, which is where a label and
		// value list would put it.
		if ( isset( $values['headline'] ) && BFTD_Fields::has_value( $values['headline'] ) ) {
			echo '<p class="headline">' . esc_html( $values['headline'] ) . '</p>';
		}

		foreach ( $section['fields'] as $key => $field ) {
			if ( 'headline' === $key ) continue;
			$type = isset( $field['type'] ) ? $field['type'] : 'text';

			// Two tables in this section are drawn rather than written: one
			// from figures entered elsewhere, one from rules that do not
			// change. Neither has a stored value to test for emptiness.
			if ( 'score_table' === $type ) {
				echo BFTD_Schema::score_table_html( $id, $values, $key );
				continue;
			}
			if ( 'figures' === $type ) {
				echo BFTD_Schema::figures_html( $id, $values, $key );
				continue;
			}
			if ( 'criteria' === $type ) {
				// A tick against every rule says somebody marked this and
				// found nothing worth mentioning. On a section that was never
				// scored it would be saying that about a task nobody did, so
				// the table waits for the score the way every other field in
				// this report waits for its value.
				if ( BFTD_Fields::has_value( isset( $values['score'] ) ? $values['score'] : '' ) ) {
					self::criteria_table( $field, isset( $values[ $key ] ) ? $values[ $key ] : array() );
				}
				continue;
			}

			$value = isset( $values[ $key ] ) ? $values[ $key ] : '';
			if ( ! BFTD_Fields::has_value( $value ) ) continue;
			if ( ! empty( $field['card'] ) ) continue;                 // drawn elsewhere
			if ( ! empty( $section['chart'] ) && 'readings' === $key ) continue;
			if ( ! empty( $field['lede'] ) ) continue;                 // the card header carries it

			// A section's own headings belong to the section, not to the
			// writing under them. A tutor typing into Notes should not be
			// able to delete the word Notes, and the standing wording should
			// not have to carry its own title through every rewrite.
			if ( ! empty( $field['heading'] ) ) {
				echo '<h3>' . esc_html( $field['heading'] ) . '</h3>';
			}
			BFTD_Dashboard::report_field( $key, $field, $value );
		}
		if ( ! empty( $section['chart'] ) ) echo BFTD_Dashboard::report_chart( $section['chart'], $report, $id );
		return ob_get_clean();
	}

	/**
	 * How the task was marked, and whether anything needs saying about it.
	 *
	 * The rules are fixed and the notes are not, so a row with nothing
	 * written against it shows a tick rather than an empty cell. A blank
	 * where a sentence might have been reads as something missing; a tick
	 * says a person looked and there was nothing to report.
	 *
	 * The tick carries a label only a screen reader hears. On the page it is
	 * a tick and nothing else, because a column of the same three words
	 * repeated down a table is noise a sighted reader has to look past. Read
	 * aloud, though, an unlabelled tick is silence, so the words are still
	 * there for anybody who needs them.
	 *
	 * A rule can name its own wording for a blank instead, and the one about
	 * reversals does. A tick against a rule about mistakes is ambiguous: it
	 * could mean there were none, or that nobody checked. "No reversals"
	 * says which, and that is a finding a parent should be able to read.
	 */
	private static function criteria_table( $field, $notes ) {
		$notes = is_array( $notes ) ? $notes : array();
		?>
		<div class="crit">
			<?php foreach ( $field['groups'] as $group ) : ?>
				<div class="crit-head crit-<?php echo esc_attr( $group['tone'] ); ?>"><?php echo esc_html( $group['label'] ); ?></div>
				<?php foreach ( $group['rows'] as $rid => $rule ) :
					$label = is_array( $rule ) ? $rule['label'] : $rule;
					$blank = is_array( $rule ) && isset( $rule['empty'] ) ? $rule['empty'] : '';
					$note  = isset( $notes[ $rid ] ) ? trim( (string) $notes[ $rid ] ) : '';
					?>
					<div class="crit-row">
						<div class="crit-rule"><?php echo esc_html( $label ); ?></div>
						<div class="crit-note<?php echo ( '' === $note && '' === $blank ) ? ' is-clear' : ''; ?>">
							<?php if ( '' !== $note ) : ?>
								<?php echo esc_html( $note ); ?>
							<?php elseif ( '' !== $blank ) : ?>
								<?php echo esc_html( $blank ); ?>
							<?php else : ?>
								<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M3.5 8.4l3 3 6-6.6"/></svg><span class="crit-said">Nothing to report</span>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<?php if ( ! empty( $field['footer'] ) ) : ?>
				<div class="crit-foot">
					<b><?php echo esc_html( $field['footer']['label'] ); ?></b>
					<span class="abc"><?php echo esc_html( $field['footer']['value'] ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Small facts                                                         */
	/* ------------------------------------------------------------------ */

	private static function student_name( $student_id, $heading ) {
		if ( $heading ) return $heading;
		$n = $student_id ? get_the_title( $student_id ) : '';
		return $n ? $n : 'Your child';
	}

	private static function first_name( $name ) {
		$parts = preg_split( '/\s+/', trim( $name ) );
		return $parts ? $parts[0] : $name;
	}

	private static function tutor_name( $report, $student_id, $v ) {
		if ( ! empty( $v['assessed_by'] ) ) {
			$u = get_userdata( (int) $v['assessed_by'] );
			if ( $u ) return $u->display_name;
		}
		$sub = (string) get_post_meta( $report, BFTD_MetaBoxes::SUBTITLE_KEY, true );
		if ( $sub ) return $sub;
		$staff = $student_id ? BFTD_CPT::staff_ids( $student_id ) : array();
		if ( $staff ) {
			$u = get_userdata( $staff[0] );
			if ( $u ) return $u->display_name;
		}
		return '';
	}

	/** "11 August 2026 · age 13 · Grade 8 · Chewelah School District · assessed by Candice Paluck" */
	private static function hero_line( $report, $student_id, $v ) {
		$bits = array();
		if ( ! empty( $v['assessed_on'] ) ) $bits[] = BFTD_Time::day( $v['assessed_on'], get_option( 'date_format' ) ? get_option( 'date_format' ) : 'j F Y' );

		if ( $student_id ) {
			$age = self::age_at( (string) BFTD_Fields::get( $student_id, 'student', 'birth_date' ),
				! empty( $v['assessed_on'] ) ? $v['assessed_on'] : '' );
			if ( $age ) $bits[] = 'age ' . $age;
		}
		if ( ! empty( $v['grade'] ) ) $bits[] = $v['grade'];
		if ( $student_id ) {
			$school = (string) BFTD_Fields::get( $student_id, 'student', 'school' );
			if ( $school ) $bits[] = $school;
		}
		$tutor = self::tutor_name( $report, $student_id, $v );
		if ( $tutor ) $bits[] = 'assessed by ' . $tutor;

		return implode( ' · ', $bits );
	}

	/** "Jot Singh · Grade 3 · started 5 July 2026 · tutor Laurel Sanders" */
	private static function progress_line( $report, $student_id, $name ) {
		if ( ! empty( self::$extra['eyebrow'] ) ) return self::$extra['eyebrow'];

		$bits = array( $name );

		if ( $student_id ) {
			$grade = (string) BFTD_Fields::get( $student_id, 'student', 'grade' );
			$opts  = BFTD_MetaBoxes::grade_options();
			if ( '' !== $grade ) $bits[] = isset( $opts[ $grade ] ) ? $opts[ $grade ] : $grade;

			$started = BFTD_CPT::started_on( $student_id );
			if ( $started ) $bits[] = 'started ' . BFTD_Time::day( $started, 'j F Y' );
		}

		$tutor = self::tutor_name( $report, $student_id, array() );
		if ( $tutor ) $bits[] = 'tutor ' . $tutor;

		return implode( ' · ', $bits );
	}

	private static function age_at( $birth, $on = '' ) {
		if ( ! $birth ) return 0;
		$b = BFTD_Time::stamp( $birth, '12:00' );
		$t = $on ? BFTD_Time::stamp( $on, '12:00' ) : time();
		if ( ! $b || ! $t || $t < $b ) return 0;
		$age = (int) BFTD_Time::format( 'Y', $t ) - (int) BFTD_Time::format( 'Y', $b );
		if ( (int) BFTD_Time::format( 'nd', $t ) < (int) BFTD_Time::format( 'nd', $b ) ) $age--;
		return max( 0, $age );
	}
}
