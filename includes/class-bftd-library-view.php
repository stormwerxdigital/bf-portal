<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reading the skills and activities library without editing it.
 *
 * Tutors read the library: they need an activity's description and the
 * skills behind it while planning a session, and they must not change what
 * every other tutor's students are taught. Tutor managers and above write it.
 *
 * So a tutor gets the list screens (edit_bftd_skills and edit_bftd_activities,
 * nothing more, see BFTD_Roles::library_read_caps) and this page, which shows
 * one record and nothing that saves. Everyone who can edit a record is sent to
 * its edit screen instead, so there is one place each person goes.
 */
class BFTD_Library_View {

	const SLUG = 'bftd-library-item';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		// Out of the menu after WordPress has checked access, the same way as
		// the student overview. Removed during admin_menu, the page has no
		// parent when WordPress looks for one and refuses everybody.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'hide_row' ), 0 );
		// The library's own row stays lit while one of its records is read.
		add_filter( 'submenu_file', array( __CLASS__, 'current_row' ), 20 );
		add_filter( 'list_table_primary_column', array( __CLASS__, 'reader_primary' ), 10, 2 );
	}

	/** The two types this page reads, each with the class that knows it. */
	public static function types() {
		return array( 'bftd_skill' => 'BFTD_Skills', 'bftd_activity' => 'BFTD_Activities' );
	}

	public static function menu() {
		add_submenu_page( BFTD_Admin::MENU_SLUG, 'Library', 'Library', BFTD_Roles::STAFF_CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function hide_row() {
		remove_submenu_page( BFTD_Admin::MENU_SLUG, self::SLUG );
	}

	public static function current_row( $submenu_file ) {
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) return $submenu_file;
		$type = get_post_type( isset( $_GET['item'] ) ? (int) $_GET['item'] : 0 );
		return isset( self::types()[ $type ] ) ? 'edit.php?post_type=' . $type : $submenu_file;
	}

	public static function url( $id ) {
		return admin_url( 'admin.php?page=' . self::SLUG . '&item=' . (int) $id );
	}

	/** Where a name in the library goes for this person: its editor if they may edit it, this page if not. */
	public static function link_for( $id ) {
		$edit = current_user_can( 'edit_post', (int) $id ) ? (string) get_edit_post_link( (int) $id, 'raw' ) : '';
		return '' !== $edit ? $edit : self::url( $id );
	}

	/**
	 * The list's columns for somebody who reads rather than writes.
	 *
	 * WordPress prints the name of a record you cannot edit as plain text, so
	 * a tutor had a list of names that led nowhere. The name is a column of
	 * our own here, linked to this page. The checkbox goes too, because there
	 * is no bulk action a reader may take.
	 */
	public static function reader_columns( $cols, $type ) {
		if ( self::writes( $type ) ) return $cols;
		$out = array();
		foreach ( $cols as $k => $v ) {
			if ( 'cb' === $k ) continue;
			if ( 'title' === $k ) { $out['bftd_read'] = $v; continue; }
			$out[ $k ] = $v;
		}
		return $out;
	}

	/** The linked name carries the row, as the title does for an editor. */
	public static function reader_primary( $default, $screen ) {
		foreach ( array_keys( self::types() ) as $type ) {
			if ( 'edit-' . $type === $screen && ! self::writes( $type ) ) return 'bftd_read';
		}
		return $default;
	}

	public static function read_cell( $post_id ) {
		printf(
			'<strong><a class="row-title" href="%s">%s</a></strong>',
			esc_url( self::url( $post_id ) ),
			esc_html( get_the_title( $post_id ) )
		);
	}

	/** May this person write this library, not just read it? */
	public static function writes( $type ) {
		$obj = get_post_type_object( $type );
		return $obj && current_user_can( $obj->cap->edit_others_posts );
	}

	/** Bulk actions are writing. A reader gets none. */
	public static function reader_bulk( $actions ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$type   = $screen ? (string) $screen->post_type : '';
		return ( isset( self::types()[ $type ] ) && ! self::writes( $type ) ) ? array() : $actions;
	}

	public static function render() {
		$id   = isset( $_GET['item'] ) ? (int) $_GET['item'] : 0;
		$post = $id ? get_post( $id ) : null;
		$type = $post ? $post->post_type : '';

		if ( ! $post || ! isset( self::types()[ $type ] ) || 'trash' === $post->post_status || ! BFTD_Roles::is_staff() ) {
			wp_die( 'That is not in the library.', 404 );
		}

		$cls   = self::types()[ $type ];
		$noun  = ( 'bftd_skill' === $type ) ? 'Skill' : 'Activity';
		$list  = admin_url( 'edit.php?post_type=' . $type );
		$track = call_user_func( array( $cls, 'track_label' ), call_user_func( array( $cls, 'track_of' ), $id ) );
		$num   = ( 'bftd_skill' === $type )
			? (int) BFTD_Skills::number_of( $id )
			: (int) get_post_meta( $id, BFTD_Activities::NUMBER_KEY, true );
		$body  = (string) $post->post_content;
		?>
		<div class="wrap bftd-wrap bftd-libview">
			<h1 class="wp-heading-inline"><?php echo esc_html( $noun . ': ' . get_the_title( $id ) ); ?></h1>
			<?php if ( current_user_can( 'edit_post', $id ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>">Edit</a>
			<?php endif; ?>
			<hr class="wp-header-end">

			<p class="bftd-libview-back"><a href="<?php echo esc_url( $list ); ?>"><?php echo esc_html( 'bftd_skill' === $type ? 'All skills' : 'All activities' ); ?></a></p>

			<dl class="bftd-libview-facts">
				<?php if ( '' !== $track ) : ?><div><dt>Track</dt><dd><?php echo esc_html( $track ); ?></dd></div><?php endif; ?>
				<div><dt>Number</dt><dd><?php echo $num ? (int) $num : '<span class="bftd-none">Not numbered</span>'; ?></dd></div>
				<?php if ( 'bftd_skill' === $type ) :
					$groups = BFTD_Skills::groups(); $g = BFTD_Skills::group_of( $id ); ?>
					<div><dt>Group</dt><dd><?php echo esc_html( isset( $groups[ $g ] ) ? $groups[ $g ] : $g ); ?></dd></div>
				<?php endif; ?>
				<?php if ( 'publish' !== $post->post_status ) : ?>
					<div><dt>Record</dt><dd><span class="bftd-pill is-past">Draft</span></dd></div>
				<?php endif; ?>
			</dl>

			<?php if ( 'bftd_activity' === $type ) :
				$skills = BFTD_Activities::skills_of( $id ); ?>
				<h2>Skills it teaches</h2>
				<?php if ( $skills ) : ?>
					<ul class="bftd-libview-skills">
						<?php foreach ( $skills as $sid ) : ?>
							<li><a href="<?php echo esc_url( self::link_for( $sid ) ); ?>"><?php echo esc_html( BFTD_Skills::numbered_label( $sid ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="bftd-none">None attached yet.</p>
				<?php endif; ?>
			<?php endif; ?>

			<h2>Description</h2>
			<div class="bftd-libview-body">
				<?php echo '' !== trim( $body ) ? wp_kses_post( wpautop( $body ) ) : '<p class="bftd-none">Nothing written yet.</p>'; ?>
			</div>
		</div>
		<?php
	}
}
