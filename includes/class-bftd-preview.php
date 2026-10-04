<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Seeing the report the way a family will.
 *
 * A tutor writing a report is looking at a column of form fields. The family
 * is looking at a designed document. Those are different enough that things
 * which read fine in the editor land badly on the page, and the only way to
 * know is to look.
 *
 * THE ONE RULE HERE: this renders through BFTD_Dashboard::report_body, the
 * same method the portal uses. Not a copy of it, not a print stylesheet over
 * it. A preview built on its own template is worse than no preview, because
 * it agrees with the real thing right up until somebody changes one of them,
 * and then it keeps insisting everything is fine.
 *
 * WHAT IS DIFFERENT, deliberately: a preview shows a draft, shows sections
 * that are currently hidden from the family (marked as such, since that is
 * the thing you would most want to check before publishing), and offers no
 * message composer, because a preview is not a place to start a conversation.
 */
class BFTD_Preview {

	const QUERY = 'bftd_preview';

	/** True while a preview is rendering, so pieces can leave themselves out. */
	private static $active = false;

	public static function init() {
		// Early, before the theme decides what to render, because this is not
		// a page on the site: it is a document with its own shell.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );

		// And one click from a list, which is where somebody asking "what
		// does the family actually see on this one" is standing.
		add_filter( 'post_row_actions', array( __CLASS__, 'row_action' ), 10, 2 );
	}

	/**
	 * "View report" on the list tables.
	 *
	 * Opening a report to press Preview means loading an edit screen, with
	 * its editors and its media library, to answer a question that has
	 * nothing to do with editing. The row already knows which record it is.
	 *
	 * Not offered on an auto-draft, which is a screen somebody opened and has
	 * not saved, so there is nothing to look at. Not offered to somebody who
	 * cannot open the record, because the preview would refuse them anyway
	 * and a link that always fails is worse than no link.
	 */
	public static function row_action( $actions, $post ) {
		if ( ! $post || ! in_array( $post->post_type, BFTD_Access::report_types(), true ) ) return $actions;
		if ( 'auto-draft' === $post->post_status || 'trash' === $post->post_status ) return $actions;
		if ( ! current_user_can( 'edit_post', $post->ID ) && ! BFTD_Access::can_staff_view( $post->ID ) ) return $actions;

		$label = ( BFTD_CPT::SESSION === $post->post_type )
			? 'View in the report'   // a lesson is an entry, not a document
			: 'View report';

		$actions['bftd_view'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s</a>',
			esc_url( self::url( $post->ID ) ),
			esc_html( $label )
		);
		return $actions;
	}

	public static function active() {
		return self::$active;
	}

	/* ------------------------------------------------------------------ */
	/* Links                                                               */
	/* ------------------------------------------------------------------ */

	public static function url( $report_id ) {
		return wp_nonce_url(
			add_query_arg( self::QUERY, (int) $report_id, home_url( '/' ) ),
			'bftd_preview_' . (int) $report_id
		);
	}

	public static function sample_url( $which = 'diagnostic' ) {
		return add_query_arg( self::QUERY, 'sample-' . sanitize_key( $which ), home_url( '/' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function maybe_render() {
		if ( empty( $_GET[ self::QUERY ] ) ) return;
		$what = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) );

		if ( 0 === strpos( $what, 'sample' ) ) {
			self::render_sample( substr( $what, 7 ) );
			exit;
		}

		self::render_report( (int) $what );
		exit;
	}

	private static function render_report( $report_id ) {
		$post = get_post( $report_id );
		if ( ! $post || ! in_array( $post->post_type, BFTD_Access::report_types(), true ) ) {
			self::deny( 'There is no report here.' );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'bftd_preview_' . $report_id ) ) {
			self::deny( 'That preview link has expired. Open the report and press Preview again.' );
		}
		if ( ! current_user_can( 'edit_post', $report_id ) && ! BFTD_Access::can_staff_view( $report_id ) ) {
			self::deny( 'This is not a report you can open.' );
		}

		$student_id = BFTD_CPT::student_id( $report_id );
		$screen     = BFTD_Schema::screen_for_post_type( $post->post_type );

		/*
		 * A lesson previews as the report it belongs to, with itself in it.
		 *
		 * A lesson is not a document a family opens; it is one entry in the
		 * progress report, so previewing it on its own would show a page that
		 * does not exist. What a tutor wants to check is how the lesson they
		 * have just written reads where the family will meet it.
		 *
		 * Including a draft lesson is the whole point. A draft is kept out of
		 * the report everywhere else, deliberately, so without this the
		 * preview would show the report exactly as it is now: without the
		 * lesson being previewed. The lessons are handed in through the same
		 * filter the sample report uses, which is why that filter exists.
		 */
		$lesson = 0;
		if ( BFTD_CPT::SESSION === $post->post_type ) {
			$lesson    = $report_id;
			$report_id = $student_id ? (int) BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS ) : 0;
			$screen    = BFTD_Schema::SCREEN_PROGRESS;

			// Worked out BEFORE the filter goes on, because sessions_in_order
			// asks sessions_for, and a filter that asked it again would call
			// itself for as long as the request lasted.
			$ids = $report_id ? BFTD_CPT::sessions_in_order( $report_id ) : array();
			if ( ! in_array( $lesson, $ids, true ) ) $ids[] = $lesson;

			add_filter( 'bftd_pre_sessions_for', function () use ( $ids ) { return $ids; } );
		}

		self::$active = true;

		$student = $student_id ? get_the_title( $student_id ) : 'No student chosen yet';
		$hidden  = self::hidden_sections( $report_id, $screen );

		ob_start();
		BFTD_Dashboard::report_body( $report_id, $student_id, $screen );
		$body = ob_get_clean();

		self::$active = false;
		if ( $lesson ) remove_all_filters( 'bftd_pre_sessions_for' );

		self::page(
			get_the_title( $report_id ) ? get_the_title( $report_id ) : $student,
			$body,
			array(
				'student' => $student,
				'status'  => 'publish' === $post->post_status ? 'Published' : ucfirst( $post->post_status ),
				'hidden'  => $hidden,
				'note'    => $lesson
					? 'This is the progress report with this session in it. A session still in draft is shown here and nowhere else, so you can read it before the family can.'
					: '',
				// A preview shows the record. On a draft that is what the tutor
				// is working on, because a draft autosaves for real. On a
				// published report it is what the family can see, and anything
				// typed since is being held to one side rather than written, so
				// the preview cannot show it. Saying nothing here would leave
				// somebody deciding their edits had been lost.
				'unsaved' => ( class_exists( 'BFTD_Autosave' ) && BFTD_Autosave::unsaved( $post ) )
					? 'You have changes that are not saved yet. This preview shows the saved version, which is the one the family can open.'
					: '',
				'edit'    => get_edit_post_link( $lesson ? $lesson : $report_id, 'raw' ),
			)
		);
	}

	private static function render_sample( $which ) {
		if ( ! is_user_logged_in() ) self::deny( 'Please sign in to see the sample report.' );

		$sample = BFTD_Sample::get( $which );
		if ( ! $sample ) self::deny( 'There is no sample of that kind.' );

		self::$active = true;
		// The lessons a progress report is built from ride along in the same
		// fixture, keyed by their own ids, and the filter below stands them
		// in where the database would normally answer.
		$fields = $sample['fields'];
		$lessons = isset( $sample['sessions'] ) ? $sample['sessions'] : array();
		$posts   = $lessons;

		// A progress report also pulls from a reading diagnostic, which is a
		// second record, so the fixture carries that one alongside the lessons
		// under the same "other posts" key.
		if ( ! empty( $sample['posts'] ) ) $posts += (array) $sample['posts'];
		if ( $posts ) $fields['__posts'] = $posts;

		BFTD_Fields::use_fixture( $fields );
		BFTD_Items::use_fixture( isset( $sample['items'] ) ? $sample['items'] : array() );

		// The tables built from lessons, activities and diagnostics. Without
		// these a sample progress report shows its written sections and four
		// empty spaces where most of the report is.
		BFTD_Derived::use_fixture( isset( $sample['derived'] ) ? $sample['derived'] : array() );

		// The activity library the sample's lessons point at, so they name
		// their activities the way a real lesson does: track, number, name.
		BFTD_Activities::use_fixture( isset( $sample['activities'] ) ? $sample['activities'] : array() );

		// And the skills those activities build, so the skills named on each
		// session, the summary above them and the spiral are all read off the
		// sample's own library by the real code rather than written out.
		BFTD_Skills::use_fixture( isset( $sample['skills_library'] ) ? $sample['skills_library'] : array() );

		$ids = array_keys( $lessons );
		add_filter( 'bftd_pre_sessions_for', function () use ( $ids ) { return $ids; } );

		ob_start();
		BFTD_Report_View::render( 0, 0, $sample['screen'], $sample['student'], $sample );
		$body = ob_get_clean();

		BFTD_Fields::clear_fixture();
		BFTD_Items::clear_fixture();
		BFTD_Derived::clear_fixture();
		BFTD_Activities::clear_fixture();
		BFTD_Skills::clear_fixture();
		remove_all_filters( 'bftd_pre_sessions_for' );
		self::$active = false;

		self::page( $sample['title'], $body, array(
			'student' => $sample['student'],
			'sample'  => $sample['note'],
		) );
	}

	/** Sections a family cannot currently see, which is what a preview is for. */
	private static function hidden_sections( $report_id, $screen ) {
		$out = array();
		foreach ( BFTD_Schema::sections_for_screen( $screen ) as $id => $section ) {
			if ( ! empty( $section['sessions'] ) ) continue;
			if ( BFTD_Fields::is_visible( $report_id, $id ) ) continue;
			if ( ! BFTD_Fields::section_has_content( $report_id, $id ) ) continue;
			$out[] = $section['label'];
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The page around it                                                  */
	/* ------------------------------------------------------------------ */

	private static function page( $title, $body, $meta = array() ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html class="no-js" <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title . ' · preview' ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="<?php echo esc_url( BFTD_Report_View::fonts_url() ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( BFTD_URL . 'assets/css/bftd-report.css?v=' . BFTD_VERSION ); ?>">
	<?php echo BFTD_Accessibility::head_tags(); // Nothing unless this person has accessibility mode on. ?>
	<style>
		body{margin:0;background:#EDE6EA;font-family:'Source Sans 3',system-ui,sans-serif}
		.bfp-bar{position:sticky;top:0;z-index:40;background:#241E2A;color:#fff;padding:11px 20px;
			display:flex;align-items:center;gap:14px;flex-wrap:wrap;font-size:13.5px}
		.bfp-bar b{font-family:'Raleway',sans-serif;font-weight:700;letter-spacing:.02em}
		.bfp-tag{background:rgba(255,255,255,.14);border-radius:12px;padding:3px 10px}
		.bfp-bar a{color:#fff;text-decoration:underline}
		.bfp-bar .bfp-right{margin-left:auto;display:flex;gap:14px;align-items:center}
		.bfp-warn{background:#B85C2E;color:#fff;padding:10px 20px;font-size:13.5px}
		.bfp-note{background:#70567F;color:#fff;padding:10px 20px;font-size:13.5px}
		.bfp-wrap{max-width:1120px;margin:0 auto;padding:26px 18px 60px}
		@media print{.bfp-bar,.bfp-warn,.bfp-note{display:none}.bfp-wrap{padding:0;max-width:none}body{background:#fff}}
	</style>
</head>
<body<?php echo BFTD_Accessibility::body_attr(); ?>>
	<div class="bfp-bar">
		<b>Preview</b>
		<?php if ( ! empty( $meta['student'] ) ) : ?>
			<span class="bfp-tag"><?php echo esc_html( $meta['student'] ); ?></span>
		<?php endif; ?>
		<?php if ( ! empty( $meta['status'] ) ) : ?>
			<span class="bfp-tag"><?php echo esc_html( $meta['status'] ); ?></span>
		<?php endif; ?>
		<span>This is what the family sees. Nothing here can be edited or posted.</span>
		<span class="bfp-right">
			<a href="#" onclick="window.print();return false">Print</a>
			<?php if ( ! empty( $meta['edit'] ) ) : ?>
				<a href="<?php echo esc_url( $meta['edit'] ); ?>">Back to editing</a>
			<?php endif; ?>
			<?php if ( BFTD_Roles::is_staff() ) : // This page has no admin bar, so the switch is here instead. ?>
				<a class="bfp-a11y" href="<?php echo esc_url( BFTD_Accessibility::toggle_url() ); ?>"><?php
					echo BFTD_Accessibility::is_on() ? 'Accessibility mode: on' : 'Accessibility mode';
				?></a>
			<?php endif; ?>
			<a href="#" onclick="window.close();return false">Close</a>
		</span>
	</div>

	<?php if ( ! empty( $meta['note'] ) ) : ?>
		<div class="bfp-note"><?php echo esc_html( $meta['note'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $meta['sample'] ) ) : ?>
		<div class="bfp-warn"><?php echo esc_html( $meta['sample'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $meta['unsaved'] ) ) : ?>
		<div class="bfp-warn"><?php echo esc_html( $meta['unsaved'] ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $meta['hidden'] ) ) : ?>
		<div class="bfp-warn">
			<strong>Hidden from the family:</strong>
			<?php echo esc_html( implode( ', ', $meta['hidden'] ) ); ?>.
			These are written but switched off, so they are not shown below either.
		</div>
	<?php endif; ?>

	<div class="bfp-wrap">
		<?php echo $body; // Already escaped by the renderer, which brings its own .bf-report wrapper. ?>
	</div>

	<script src="<?php echo esc_url( BFTD_URL . 'assets/js/bftd-report.js?v=' . BFTD_VERSION ); ?>"></script>
</body>
</html>
		<?php
	}

	private static function deny( $why ) {
		status_header( 403 );
		nocache_headers();
		wp_die( esc_html( $why ), 'Preview', array( 'response' => 403, 'back_link' => true ) );
	}
}
