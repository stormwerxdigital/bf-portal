<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One permanent, queryable record of everything that happens: content edits
 * with a field-level before and after, conversation activity, every email the
 * system sends or a tutor chooses to send, logins, and access changes.
 *
 * It is a single table rather than one per concern, because the questions
 * people actually ask cut across categories — "what happened on Jot's account
 * last week", "who changed this score and what was it before", "did that
 * parent ever get the email". Each row carries a category so any one of those
 * views is a filter, not a separate system.
 *
 * Rows survive the deletion of the post they refer to, on purpose: the record
 * of what was sent to a family is not something a later cleanup should erase.
 */
class BFTD_Audit {

	const DB_VERSION        = 1;
	const OPTION_DB_VERSION = 'bftd_audit_db_version';
	const PAGE_SLUG         = 'bftd-activity-log';

	/* Categories -------------------------------------------------------- */
	const CAT_CONTENT = 'content';
	const CAT_MESSAGE = 'message';
	const CAT_NOTIFY  = 'notify';
	const CAT_ACCESS  = 'access';
	const CAT_ADMIN   = 'admin';

	public static function categories() {
		return array(
			self::CAT_CONTENT => 'Report content',
			self::CAT_MESSAGE => 'Conversations',
			self::CAT_NOTIFY  => 'Notifications sent',
			self::CAT_ACCESS  => 'Sign in and access',
			self::CAT_ADMIN   => 'Admin actions',
		);
	}

	/**
	 * Every event this log understands. The label is what a human reads on
	 * the log screen; the category groups it. Anything logged with an event
	 * not listed here still records fine and simply shows its raw key, so a
	 * new feature can log before this list is updated.
	 */
	public static function events() {
		return array(
			// content
			'section_updated'      => array( self::CAT_CONTENT, 'Report section edited' ),
			// Not an edit. A save that was thrown away because the request
			// arrived incomplete, recorded so that "it did not save" has an
			// answer rather than a shrug.
			'save_refused'         => array( self::CAT_CONTENT, 'Save refused, request incomplete' ),
			'section_published'    => array( self::CAT_CONTENT, 'Section published to the family' ),
			'section_hidden'       => array( self::CAT_CONTENT, 'Section hidden from the family' ),
			'report_created'       => array( self::CAT_CONTENT, 'Report created' ),
			'report_published'     => array( self::CAT_CONTENT, 'Report published' ),
			'report_unpublished'   => array( self::CAT_CONTENT, 'Report unpublished' ),
			'session_created'      => array( self::CAT_CONTENT, 'Session recorded' ),
			'session_updated'      => array( self::CAT_CONTENT, 'Session edited' ),
			'session_deleted'      => array( self::CAT_CONTENT, 'Session deleted' ),
			'resource_assigned'    => array( self::CAT_CONTENT, 'Resource assigned to a student' ),
			'resource_unassigned'  => array( self::CAT_CONTENT, 'Resource removed from a student' ),
			'schedule_changed'     => array( self::CAT_CONTENT, 'Session schedule changed' ),
			'lesson_rescheduled'   => array( self::CAT_CONTENT, 'Session moved or cancelled' ),
			'recording_added'      => array( self::CAT_CONTENT, 'Session recording added' ),
			'recording_expired'    => array( self::CAT_CONTENT, 'Session recording expired' ),
			// items
			'item_added'           => array( self::CAT_CONTENT, 'Priority or review item added' ),
			'item_edited'          => array( self::CAT_CONTENT, 'Priority or review item edited' ),
			'item_removed'         => array( self::CAT_CONTENT, 'Priority or review item removed' ),
			'item_checked'         => array( self::CAT_MESSAGE, 'Item checked off by the family' ),
			'item_confirmed'       => array( self::CAT_ADMIN,   'Item confirmed complete by staff' ),
			'item_reopened'        => array( self::CAT_ADMIN,   'Item reopened by staff' ),
			// conversations
			'thread_started'       => array( self::CAT_MESSAGE, 'Conversation started' ),
			'message_posted'       => array( self::CAT_MESSAGE, 'Message posted' ),
			'message_edited'       => array( self::CAT_MESSAGE, 'Message edited' ),
			'message_deleted'      => array( self::CAT_MESSAGE, 'Message deleted' ),
			'attachment_added'     => array( self::CAT_MESSAGE, 'File attached to a message' ),
			'thread_read'          => array( self::CAT_MESSAGE, 'Conversation read' ),
			// notifications
			'email_sent'           => array( self::CAT_NOTIFY,  'Email sent' ),
			'email_failed'         => array( self::CAT_NOTIFY,  'Email failed to send' ),
			'email_suppressed'     => array( self::CAT_NOTIFY,  'Email withheld by a send rule' ),
			'change_flagged'       => array( self::CAT_NOTIFY,  'Change flagged for review' ),
			'change_dismissed'     => array( self::CAT_NOTIFY,  'Change dismissed without emailing' ),
			'notice_created'       => array( self::CAT_NOTIFY,  'In-app notification created' ),
			// access
			'user_login'           => array( self::CAT_ACCESS,  'Signed in' ),
			'user_logout'          => array( self::CAT_ACCESS,  'Signed out' ),
			'login_failed'         => array( self::CAT_ACCESS,  'Failed sign in' ),
			'client_linked'        => array( self::CAT_ACCESS,  'Caregiver linked to a student' ),
			'client_unlinked'      => array( self::CAT_ACCESS,  'Caregiver unlinked from a student' ),
			'portal_viewed'        => array( self::CAT_ACCESS,  'Family portal opened by staff' ),
			'staff_assigned'       => array( self::CAT_ACCESS,  'Tutor assigned' ),
			'staff_unassigned'     => array( self::CAT_ACCESS,  'Tutor unassigned' ),
			// admin
			'template_updated'     => array( self::CAT_ADMIN,   'Email template edited' ),
			'template_restored'    => array( self::CAT_ADMIN,   'Email template restored to default' ),
			'rule_updated'         => array( self::CAT_ADMIN,   'Send rule changed' ),
			'settings_updated'     => array( self::CAT_ADMIN,   'Settings changed' ),
		);
	}

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bftd_activity_log';
	}

	public static function install() {
		global $wpdb;
		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			category VARCHAR(24) NOT NULL,
			event_type VARCHAR(64) NOT NULL,
			student_id BIGINT UNSIGNED NULL,
			post_id BIGINT UNSIGNED NULL,
			post_type VARCHAR(32) NULL,
			section_id VARCHAR(64) NULL,
			actor_id BIGINT UNSIGNED NULL,
			actor_name VARCHAR(191) NULL,
			actor_ip VARCHAR(45) NULL,
			summary TEXT NULL,
			changes LONGTEXT NULL,
			recipients TEXT NULL,
			PRIMARY KEY  (id),
			KEY category (category),
			KEY event_type (event_type),
			KEY student_id (student_id),
			KEY post_id (post_id),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) $collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
	}

	public static function maybe_install() {
		if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < self::DB_VERSION ) self::install();
	}

	private static function client_ip() {
		// REMOTE_ADDR only. Forwarded-for headers are attacker-controlled and
		// this value is shown to staff as fact, so it must not be spoofable.
		return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ), 0, 45 ) : '';
	}

	/**
	 * Record one event.
	 *
	 * $args: student_id, post_id, section_id, summary, changes (array of
	 * field => [from, to]), recipients (array of email addresses), actor_id
	 * (defaults to the current user, 0 for a system action).
	 */
	/**
	 * While this is on, nothing is written to the log.
	 *
	 * An autosave of a draft writes the record every twenty-five seconds. Each
	 * of those through the log would bury the entries a person actually made
	 * under a few hundred rows saying the same thing, and the log is read to
	 * answer "who changed this and when", which it could no longer do.
	 *
	 * Nothing is lost that a family could see: a draft reaches nobody, and the
	 * publish, and every edit after it, are logged as they always were.
	 */
	public static $quiet = false;

	public static function log( $event_type, $args = array() ) {
		global $wpdb;

		if ( self::$quiet ) return;

		$events   = self::events();
		$category = isset( $events[ $event_type ] ) ? $events[ $event_type ][0] : self::CAT_ADMIN;

		$post_id    = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$student_id = isset( $args['student_id'] ) ? (int) $args['student_id'] : ( $post_id ? BFTD_CPT::student_id( $post_id ) : 0 );

		$actor_id = array_key_exists( 'actor_id', $args ) ? (int) $args['actor_id'] : get_current_user_id();
		$actor    = $actor_id ? get_userdata( $actor_id ) : null;

		$changes = isset( $args['changes'] ) && $args['changes'] ? wp_json_encode( $args['changes'] ) : null;
		$rcpts   = isset( $args['recipients'] ) ? $args['recipients'] : array();
		if ( is_array( $rcpts ) ) $rcpts = implode( ', ', array_filter( $rcpts ) );

		$wpdb->insert( self::table_name(), array(
			'created_at' => BFTD_Time::mysql(),
			'category'   => $category,
			'event_type' => $event_type,
			'student_id' => $student_id ?: null,
			'post_id'    => $post_id ?: null,
			'post_type'  => $post_id ? get_post_type( $post_id ) : null,
			'section_id' => isset( $args['section_id'] ) ? substr( (string) $args['section_id'], 0, 64 ) : null,
			'actor_id'   => $actor_id ?: null,
			'actor_name' => $actor ? $actor->display_name : 'System',
			'actor_ip'   => self::client_ip(),
			'summary'    => isset( $args['summary'] ) ? (string) $args['summary'] : '',
			'changes'    => $changes,
			'recipients' => $rcpts ?: null,
		), array( '%s','%s','%s','%d','%d','%s','%s','%d','%s','%s','%s','%s','%s' ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Compare two field maps and return only what actually changed, in a shape
	 * the log screen can render as a readable before and after. Values are
	 * truncated here, not at render, so the row never grows unbounded.
	 */
	public static function diff( $before, $after, $labels = array() ) {
		$out = array();
		$keys = array_unique( array_merge( array_keys( (array) $before ), array_keys( (array) $after ) ) );
		foreach ( $keys as $k ) {
			$from = isset( $before[ $k ] ) ? $before[ $k ] : '';
			$to   = isset( $after[ $k ] ) ? $after[ $k ] : '';
			if ( maybe_serialize( $from ) === maybe_serialize( $to ) ) continue;
			$out[ $k ] = array(
				'label' => isset( $labels[ $k ] ) ? $labels[ $k ] : $k,
				'from'  => self::flatten( $from ),
				'to'    => self::flatten( $to ),
			);
		}
		return $out;
	}

	private static function flatten( $v ) {
		if ( is_array( $v ) ) {
			$parts = array();
			foreach ( $v as $row ) {
				$parts[] = is_array( $row ) ? implode( ' / ', array_map( 'strval', $row ) ) : (string) $row;
			}
			$v = implode( ' | ', $parts );
		}
		$v = wp_strip_all_tags( (string) $v );
		$v = trim( preg_replace( '/\s+/', ' ', $v ) );
		return mb_substr( $v, 0, 400 );
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                             */
	/* ------------------------------------------------------------------ */

	private static function build_where( $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		foreach ( array( 'category' => '%s', 'event_type' => '%s' ) as $col => $fmt ) {
			if ( ! empty( $args[ $col ] ) ) { $where[] = "$col = $fmt"; $params[] = $args[ $col ]; }
		}
		foreach ( array( 'student_id', 'post_id', 'actor_id' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) { $where[] = "$col = %d"; $params[] = (int) $args[ $col ]; }
		}
		if ( ! empty( $args['section_id'] ) ) { $where[] = 'section_id = %s'; $params[] = $args['section_id']; }
		if ( isset( $args['student__in'] ) ) {
			$ids = array_map( 'absint', (array) $args['student__in'] );
			if ( ! $ids ) $ids = array( 0 );
			$where[] = 'student_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			foreach ( $ids as $id ) $params[] = $id;
		}
		if ( ! empty( $args['since'] ) ) { $where[] = 'created_at >= %s'; $params[] = $args['since']; }
		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(summary LIKE %s OR actor_name LIKE %s OR recipients LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like; $params[] = $like; $params[] = $like;
		}
		return array( implode( ' AND ', $where ), $params );
	}

	public static function get_entries( $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::build_where( $args );

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
		$paged    = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$params[] = $per_page;
		$params[] = ( $paged - 1 ) * $per_page;

		$sql = 'SELECT * FROM ' . self::table_name() . " WHERE $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count_entries( $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::build_where( $args );
		$sql = 'SELECT COUNT(*) FROM ' . self::table_name() . " WHERE $where";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	public static function event_label( $key ) {
		$events = self::events();
		return isset( $events[ $key ] ) ? $events[ $key ][1] : $key;
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			'Activity Log',
			'Activity Log',
			// Who did what, across every family. A tutor manager runs the
			// teaching; reading the whole practice's audit trail is the layer
			// under that, and it is where a client's name sits beside every
			// staff member who has opened their child's record.
			BFTD_Roles::ADMIN_CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/** Renders the log filtered to one record — used as a panel on every edit screen. */
	public static function render_for_post( $post_id, $limit = 40 ) {
		$entries = self::get_entries( array( 'post_id' => $post_id, 'per_page' => $limit ) );
		echo '<p class="description">Everything that has happened on this record, newest first. The full history, including notifications, is under Activity Log.</p>';
		self::render_table( $entries, false );
	}

	/** Renders the log filtered to one student, across all of their records. */
	public static function render_for_student( $student_id, $limit = 60 ) {
		$entries = self::get_entries( array( 'student_id' => $student_id, 'per_page' => $limit ) );
		self::render_table( $entries, true );
	}

	private static function render_table( $entries, $show_item ) {
		if ( ! $entries ) {
			echo '<p class="description">Nothing logged yet.</p>';
			return;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo '<table class="widefat striped bftd-log"><thead><tr>';
		echo '<th style="width:150px;">When</th><th style="width:140px;">Who</th><th style="width:200px;">What</th>';
		if ( $show_item ) echo '<th style="width:160px;">Where</th>';
		echo '<th>Detail</th></tr></thead><tbody>';
		foreach ( $entries as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( mysql2date( $fmt, $row->created_at ) ) . '</td>';
			echo '<td>' . esc_html( $row->actor_name ? $row->actor_name : 'System' ) . '</td>';
			echo '<td>' . esc_html( self::event_label( $row->event_type ) ) . '</td>';
			if ( $show_item ) {
				$p = $row->post_id ? get_post( $row->post_id ) : null;
				echo '<td>' . ( $p ? '<a href="' . esc_url( (string) get_edit_post_link( $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a>' : '&mdash;' ) . '</td>';
			}
			echo '<td>' . self::detail_html( $row ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The summary line, plus a field-by-field before and after where one was
	 * recorded. Everything is escaped here; nothing in the log is ever trusted
	 * as markup, whoever wrote it.
	 */
	private static function detail_html( $row ) {
		$html = $row->summary ? '<span>' . esc_html( $row->summary ) . '</span>' : '';

		if ( $row->recipients ) {
			$html .= '<br><span class="bftd-log-to">Sent to ' . esc_html( $row->recipients ) . '</span>';
		}

		$changes = $row->changes ? json_decode( $row->changes, true ) : null;
		if ( is_array( $changes ) && $changes ) {
			$html .= '<details class="bftd-log-diff"><summary>' . esc_html( count( $changes ) ) . ' ' . esc_html( _n( 'field changed', 'fields changed', count( $changes ), 'bftd' ) ) . '</summary><dl>';
			foreach ( $changes as $c ) {
				$label = isset( $c['label'] ) ? $c['label'] : '';
				$from  = isset( $c['from'] ) ? $c['from'] : '';
				$to    = isset( $c['to'] ) ? $c['to'] : '';
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>';
				$html .= '<span class="was">' . ( '' === $from ? '<em>empty</em>' : esc_html( $from ) ) . '</span>';
				$html .= '<span class="arrow" aria-hidden="true">&rarr;</span>';
				$html .= '<span class="now">' . ( '' === $to ? '<em>empty</em>' : esc_html( $to ) ) . '</span>';
				$html .= '</dd>';
			}
			$html .= '</dl></details>';
		}
		return $html ? $html : '&mdash;';
	}

	public static function render_page() {
		if ( ! BFTD_Roles::is_staff() ) return;

		// A tutor sees the history of their own students. A manager sees
		// everything, including the entries that belong to no student at all
		// — settings changes, template edits, sign ins. Scoping this at the
		// query rather than hiding the screen means a tutor still has the
		// record they need for their own work.
		$scoped = ! BFTD_Roles::can_manage();

		$cat     = isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '';
		$event   = isset( $_GET['event_type'] ) ? sanitize_key( wp_unslash( $_GET['event_type'] ) ) : '';
		$student = isset( $_GET['student_id'] ) ? (int) $_GET['student_id'] : 0;
		$actor   = isset( $_GET['actor_id'] ) ? (int) $_GET['actor_id'] : 0;
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$args = array(
			'category' => $cat, 'event_type' => $event, 'student_id' => $student,
			'actor_id' => $actor, 'search' => $search, 'paged' => $paged, 'per_page' => 50,
		);

		$visible = BFTD_Access::visible_student_ids( get_current_user_id() );
		if ( $scoped ) $args['student__in'] = $visible;

		$entries = self::get_entries( $args );
		$total   = self::count_entries( $args );
		$pages   = max( 1, (int) ceil( $total / 50 ) );

		$students = $scoped
			? array_filter( array_map( 'get_post', $visible ) )
			: get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<div class="wrap bftd-wrap">
			<h1>Activity Log</h1>
			<p class="bftd-lede">
				<?php if ( $scoped ) : ?>
					Every change, conversation and notification on your students, newest first. Nothing here is ever edited or removed, including when the record it refers to is deleted. A Tutor Manager sees the whole practice.
				<?php else : ?>
					Every change to a report, every conversation, every notification, and every sign in, across the whole practice. Nothing here is ever edited or removed, including when the record it refers to is deleted.
				<?php endif; ?>
			</p>

			<form method="get" class="bftd-log-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<select name="category" onchange="this.form.submit()">
					<option value="">Everything</option>
					<?php foreach ( self::categories() as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cat, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="event_type" onchange="this.form.submit()">
					<option value="">Any event</option>
					<?php foreach ( self::events() as $k => $meta ) : ?>
						<?php if ( $cat && $meta[0] !== $cat ) continue; ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $event, $k ); ?>><?php echo esc_html( $meta[1] ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="student_id" onchange="this.form.submit()">
					<option value="0">Any student</option>
					<?php foreach ( $students as $p ) : ?>
						<option value="<?php echo (int) $p->ID; ?>" <?php selected( $student, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search detail, person or address">
				<button class="button">Filter</button>
				<?php if ( $cat || $event || $student || $search || $actor ) : ?>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">Clear</a>
				<?php endif; ?>
			</form>

			<p class="bftd-log-count"><?php echo esc_html( number_format_i18n( $total ) ); ?> entries</p>
			<?php self::render_table( $entries, true ); ?>

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
}
