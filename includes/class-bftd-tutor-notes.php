<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tutor notes: an internal report, session by session (Karl, 1.194).
 *
 * For tutors, managers, senior managers and administrators only; a family
 * never sees it (the screen needs the staff capability, and a tutor sees only
 * students they can open). One student at a time, newest session first, with
 * a date range, a quick range, days of the week, a time of day, what happened
 * and who taught it to narrow it down.
 *
 * Each session shows, in Karl's order: the activities by name (no
 * descriptions), what the tutor wrote (the words only, no images), the
 * attendance, the stories and texts read, and the morphology matrices. In the
 * boxes the family report uses: a purple band and small tiles.
 */
class BFTD_Tutor_Notes {

	const SLUG = 'bftd-tutor-notes';

	/** What happened to a session, in the words the session screen uses. */
	public static function statuses() {
		return array( 'held' => 'Attended', 'late' => 'Late start', 'rescheduled' => 'Rescheduled', 'missed' => 'Missed', 'noshow' => 'No show', 'pending' => 'Pending' );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
	}

	public static function menu() {
		add_submenu_page( BFTD_Admin::MENU_SLUG, 'Tutor notes', 'Tutor notes', BFTD_Roles::STAFF_CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function url( $student_id ) {
		return admin_url( 'admin.php?page=' . self::SLUG . '&student=' . (int) $student_id );
	}

	/* ------------------------------------------------------------------ */
	/* What is asked for                                                   */
	/* ------------------------------------------------------------------ */

	/** The filters, from the address bar, cleaned. */
	public static function filters( $q, $today ) {
		$date = function ( $v ) { $v = (string) $v; return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
		$time = function ( $v ) { $v = (string) $v; return preg_match( '/^\d{2}:\d{2}$/', $v ) ? $v : ''; };
		$f = array(
			'student' => absint( $q['student'] ?? 0 ),
			'range'   => in_array( $q['range'] ?? '', array( '7', '30', '90', 'month', 'term', 'all', 'custom' ), true ) ? $q['range'] : 'all',
			'from'    => $date( $q['from'] ?? '' ),
			'to'      => $date( $q['to'] ?? '' ),
			'days'    => array_values( array_intersect( array_map( 'strval', (array) ( $q['days'] ?? array() ) ), array( '1', '2', '3', '4', '5', '6', '7' ) ) ),
			'tfrom'   => $time( $q['tfrom'] ?? '' ),
			'tto'     => $time( $q['tto'] ?? '' ),
			'status'  => array_values( array_intersect( array_map( 'strval', (array) ( $q['status'] ?? array() ) ), array_keys( self::statuses() ) ) ),
			'tutor'   => absint( $q['tutor'] ?? 0 ),
			// Only sessions with something not entered (1.199).
			'gaps'    => empty( $q['gaps'] ) ? 0 : 1,
		);
		// A quick range sets the dates; typing a date makes it a custom range.
		$back = array( '7' => 6, '30' => 29, '90' => 89 );
		if ( isset( $back[ $f['range'] ] ) ) {
			$f['from'] = gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) - $back[ $f['range'] ] * 86400 );
			$f['to']   = $today;
		} elseif ( 'month' === $f['range'] ) {
			$f['from'] = substr( $today, 0, 8 ) . '01';
			$f['to']   = $today;
		} elseif ( 'all' === $f['range'] && ( $f['from'] || $f['to'] ) ) {
			$f['range'] = 'custom';
		}
		if ( $f['from'] && $f['to'] && $f['from'] > $f['to'] ) { $t = $f['from']; $f['from'] = $f['to']; $f['to'] = $t; }
		return $f;
	}

	/** Does one session pass the filters? */
	public static function matches( $s, $f ) {
		if ( $f['from'] && ( '' === $s['date'] || $s['date'] < $f['from'] ) ) return false;
		if ( $f['to'] && ( '' === $s['date'] || $s['date'] > $f['to'] ) ) return false;
		if ( $f['days'] && ( '' === $s['date'] || ! in_array( (string) gmdate( 'N', strtotime( $s['date'] . ' 12:00:00 UTC' ) ), $f['days'], true ) ) ) return false;
		if ( $f['tfrom'] && ( '' === $s['time'] || $s['time'] < $f['tfrom'] ) ) return false;
		if ( $f['tto'] && ( '' === $s['time'] || $s['time'] > $f['tto'] ) ) return false;
		if ( $f['status'] && ! in_array( $s['status'], $f['status'], true ) ) return false;
		if ( $f['tutor'] && (int) $s['tutor'] !== $f['tutor'] ) return false;
		if ( ! empty( $f['gaps'] ) && empty( $s['missing'] ) ) return false;
		return true;
	}

	/**
	 * What this report tracks that was not entered for a session, so the gap
	 * is seen rather than the box quietly left out (Karl, 1.199).
	 *
	 * Every session needs a date, a time, a tutor and what happened. One that
	 * went ahead (attended, or a late start) needs its activities, the
	 * tutor's notes, what was read and the matrices; a late start, why. A
	 * missed or rescheduled one needs who cancelled it and why, and is not
	 * asked for activities or reading, because there were none.
	 *
	 * @return array key => what is missing, in words
	 */
	public static function missing( $e ) {
		$out = array();
		if ( '' === $e['date'] ) $out['date'] = 'No date';
		if ( '' === $e['time'] ) $out['time'] = 'No time';
		if ( ! $e['tutor'] ) $out['tutor'] = 'No tutor';
		if ( ! isset( self::statuses()[ $e['status'] ] ) ) $out['status'] = 'What happened was not recorded';
		if ( in_array( $e['status'], array( 'held', 'late' ), true ) ) {
			$noted = '' !== $e['notes'];
			foreach ( $e['activities'] as $a ) if ( '' !== $a['note'] ) $noted = true;
			if ( ! $e['activities'] ) $out['activities'] = 'No activities';
			if ( ! $noted ) $out['notes'] = 'No tutor notes';
			if ( ! $e['texts'] ) $out['texts'] = 'No stories or texts read';
			if ( ! $e['matrices'] ) $out['matrices'] = 'No morphology matrices';
		}
		if ( 'late' === $e['status'] && '' === $e['why'] ) $out['why'] = 'No reason for the late start';
		if ( in_array( $e['status'], array( 'missed', 'rescheduled' ), true ) ) {
			if ( '' === $e['missed_by'] ) $out['missed_by'] = 'Who cancelled was not recorded';
			if ( '' === $e['missed_why'] ) $out['missed_why'] = 'No reason given';
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The sessions                                                        */
	/* ------------------------------------------------------------------ */

	/** Rich text as plain words: no images, no markup, line breaks kept. */
	public static function words( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<(img|figure|video|audio|iframe|picture)\b[^>]*>(.*?</\1>)?#is', '', $html );
		$html = preg_replace( '#<\s*(br|/p|/li|/h[1-6]|/div|/blockquote)\b[^>]*>#i', "\n", $html );
		$html = preg_replace( '#<\s*li\b[^>]*>#i', '• ', $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = implode( "\n", array_map( 'trim', explode( "\n", $text ) ) );   // an image taken out leaves no gap
		return trim( preg_replace( "/\n\n+/", "\n", $text ) );
	}

	/** One session, as the report shows it. */
	public static function entry( $session_id ) {
		$get    = function ( $k ) use ( $session_id ) { return BFTD_Fields::get( $session_id, 'session', $k ); };
		$status = (string) $get( 'status' );
		$acts   = array();
		foreach ( BFTD_Activities::for_session( $session_id ) as $a ) {
			$acts[] = array( 'name' => (string) $a['name'], 'note' => self::words( $a['note'] ?? '' ) );
		}
		$texts = array();
		foreach ( (array) $get( 'texts' ) as $row ) {
			$t = is_array( $row ) ? trim( (string) ( $row['title'] ?? '' ) ) : '';
			if ( '' !== $t ) $texts[] = array( 'title' => $t, 'level' => BFTD_Charts::text_level_label( $row['level'] ?? '' ) );
		}
		$matrices = array();
		foreach ( (array) $get( 'matrices' ) as $row ) {
			$m = is_array( $row ) ? trim( (string) ( $row['matrix'] ?? '' ) ) : '';
			if ( '' !== $m ) $matrices[] = $m;
		}
		$by = (int) $get( 'delivered_by' );
		if ( ! $by ) $by = (int) get_post_field( 'post_author', $session_id );
		$e = array(
			'id'        => (int) $session_id,
			'number'    => 'publish' === get_post_status( $session_id ) ? (int) BFTD_CPT::lesson_number( $session_id ) : 0,
			'draft'     => 'publish' !== get_post_status( $session_id ),
			'date'      => (string) $get( 'session_date' ),
			'time'      => (string) $get( 'session_time' ),
			'tutor'     => $by,
			'status'    => $status,
			'why'       => self::words( $get( 'status_why' ) ),
			'missed_by' => '' !== (string) $get( 'missed_by' ) ? BFTD_CPT::cancelled_by_label( (string) $get( 'missed_by' ), BFTD_CPT::student_id( $session_id ) ) : '',
			'missed_why' => self::words( $get( 'missed_why' ) ),
			'madeup'    => in_array( $status, array( 'missed', 'rescheduled' ), true ) ? BFTD_MetaBoxes::madeup_how( $session_id ) : '',
			'madeup_on' => (string) $get( 'missed_madeup' ),
			'activities' => $acts,
			'notes'     => self::words( $get( 'notes' ) ),
			'texts'     => $texts,
			'matrices'  => $matrices,
		);
		$e['missing'] = self::missing( $e );
		return $e;
	}

	/** Every session of a student that passes the filters, newest first. */
	public static function entries( $student_id, $f ) {
		$ids = BFTD_CPT::sessions_of( array( $student_id ), true );
		$out = array();
		foreach ( (array) ( $ids[ $student_id ] ?? array() ) as $sid ) {
			$e = self::entry( $sid );
			if ( self::matches( $e, $f ) ) $out[] = $e;
		}
		usort( $out, function ( $a, $b ) { return strcmp( $b['date'] . $b['time'], $a['date'] . $a['time'] ); } );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The screen                                                          */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! BFTD_Roles::is_staff() ) return;
		$me       = get_current_user_id();
		$students = array();
		foreach ( (array) BFTD_Access::visible_student_ids( $me ) as $sid ) {
			$students[ (int) $sid ] = (string) get_post_field( 'post_title', $sid );
		}
		natcasesort( $students );
		$f = self::filters( wp_unslash( $_GET ), BFTD_Time::today() );   // phpcs:ignore WordPress.Security.NonceVerification -- a report, read only
		$sid = isset( $students[ $f['student'] ] ) && BFTD_Access::can_staff_view( $f['student'], $me ) ? $f['student'] : 0;
		?>
		<div class="wrap bftd-wrap bftd-tn">
			<h1>Tutor notes</h1>
			<p class="bftd-lede">Every session, as the tutor recorded it. For staff only: families never see this page.</p>

			<form method="get" class="bftd-tn-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<label>Student
					<select name="student" onchange="this.form.submit()">
						<option value="0">Choose a student</option>
						<?php foreach ( $students as $id => $name ) : ?>
							<option value="<?php echo (int) $id; ?>"<?php selected( $sid, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<?php if ( $sid ) : ?>
					<label>Showing
						<select name="range">
							<?php foreach ( array( 'all' => 'All time', '7' => 'The last 7 days', '30' => 'The last 30 days', '90' => 'The last 90 days', 'month' => 'This month', 'custom' => 'Between these dates' ) as $k => $l ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>"<?php selected( $f['range'], $k ); ?>><?php echo esc_html( $l ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label>From <input type="date" name="from" value="<?php echo esc_attr( $f['from'] ); ?>"></label>
					<label>To <input type="date" name="to" value="<?php echo esc_attr( $f['to'] ); ?>"></label>
					<fieldset><legend>Days</legend>
						<?php foreach ( BFTD_Schedule::weekdays() as $n => $day ) : ?>
							<label class="bftd-tn-chip"><input type="checkbox" name="days[]" value="<?php echo esc_attr( $n ); ?>"<?php checked( in_array( (string) $n, $f['days'], true ) ); ?>> <?php echo esc_html( substr( $day, 0, 3 ) ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<fieldset class="bftd-tn-time"><legend>Time of day</legend>
						<label>from <input type="time" name="tfrom" value="<?php echo esc_attr( $f['tfrom'] ); ?>"></label>
						<label>until <input type="time" name="tto" value="<?php echo esc_attr( $f['tto'] ); ?>"></label>
					</fieldset>
					<fieldset><legend>What happened</legend>
						<?php foreach ( self::statuses() as $k => $l ) : ?>
							<label class="bftd-tn-chip"><input type="checkbox" name="status[]" value="<?php echo esc_attr( $k ); ?>"<?php checked( in_array( $k, $f['status'], true ) ); ?>> <?php echo esc_html( $l ); ?></label>
						<?php endforeach; ?>
					</fieldset>
					<label>Taught by
						<select name="tutor"><option value="0">Anyone</option>
							<?php foreach ( BFTD_Roles::staff_users() as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>"<?php selected( $f['tutor'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label class="bftd-tn-chip"><input type="checkbox" name="gaps" value="1"<?php checked( $f['gaps'] ); ?>> Only sessions with something not entered</label>
					<p><button class="button button-primary">Show</button>
						<a class="button-link" href="<?php echo esc_url( self::url( $sid ) ); ?>">Clear</a></p>
				<?php endif; ?>
			</form>

			<?php
			if ( ! $sid ) {
				echo '<p class="description">Choose a student to read their sessions.</p></div>';
				return;
			}
			$list     = self::entries( $sid, $f );
			$say      = count( $list ) . ' ' . ( 1 === count( $list ) ? 'session' : 'sessions' );
			$filtered = 'all' !== $f['range'] || $f['days'] || $f['tfrom'] || $f['tto'] || $f['status'] || $f['tutor'] || $f['gaps'];
			$gaps     = count( array_filter( $list, function ( $e ) { return ! empty( $e['missing'] ); } ) );
			?>
			<p class="bftd-tn-count"><strong><?php echo esc_html( $say ); ?></strong><?php echo esc_html( $filtered ? ' match these filters.' : ', all time.' ); ?>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $sid ) ); ?>">Open <?php echo esc_html( $students[ $sid ] ); ?></a></p>
			<?php if ( $gaps ) : ?>
				<p class="bftd-tn-gapsum"><span class="bftd-tn-flag">Not entered</span> <?php echo esc_html( $gaps . ' of these ' . count( $list ) . ' ' . ( 1 === count( $list ) ? 'session has' : 'sessions have' ) . ' something not entered.' ); ?></p>
			<?php endif; ?>

			<?php if ( ! $list ) : ?>
				<p class="bftd-tn-empty"><?php echo esc_html( $filtered
					? 'No sessions match these filters. Widen the range, or clear a filter.'
					: 'No sessions recorded for ' . $students[ $sid ] . ' yet.' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $list as $e ) self::card( $e ); ?>
		</div>
		<?php
	}

	/**
	 * One session, in the report's boxes. Anything tracked here that was not
	 * entered is flagged where it would be, "Not entered", rather than its
	 * box being left out (Karl, 1.199).
	 */
	public static function card( $e ) {
		$st    = self::statuses();
		$miss  = $e['missing'] ?? array();
		$who   = $e['tutor'] ? get_userdata( $e['tutor'] ) : null;
		$went  = in_array( $e['status'], array( 'held', 'late' ), true );
		$head  = array();
		$head[] = $e['date'] ? BFTD_Time::day( $e['date'], 'D j M Y' ) : '';
		if ( $e['time'] ) $head[] = BFTD_Schedule::pretty_time( $e['time'] );
		if ( $who ) $head[] = $who->display_name;
		$gap = function ( $key ) use ( $miss ) {
			if ( isset( $miss[ $key ] ) ) echo '<div class="bftd-tn-missing"><span class="bftd-tn-flag">Not entered</span> ' . esc_html( $miss[ $key ] ) . '</div>';
		};
		?>
		<section class="bftd-tn-card<?php echo $miss ? ' has-gaps' : ''; ?>" id="session-<?php echo (int) $e['id']; ?>">
			<header class="bftd-tn-head">
				<h2><?php echo esc_html( $e['number'] ? 'Session ' . $e['number'] : ( $e['draft'] ? 'Session (draft)' : 'Session' ) ); ?></h2>
				<span><?php echo esc_html( implode( ' · ', array_filter( $head ) ) ); ?></span>
				<?php if ( isset( $st[ $e['status'] ] ) ) : ?><span class="bftd-tn-pill is-<?php echo esc_attr( $e['status'] ); ?>"><?php echo esc_html( $st[ $e['status'] ] ); ?></span><?php endif; ?>
				<?php foreach ( array( 'date', 'time', 'tutor' ) as $k ) : if ( isset( $miss[ $k ] ) ) : ?>
					<span class="bftd-tn-flag"><?php echo esc_html( $miss[ $k ] ); ?></span>
				<?php endif; endforeach; ?>
				<a href="<?php echo esc_url( (string) get_edit_post_link( $e['id'] ) ); ?>">Open the session</a>
			</header>
			<div class="bftd-tn-body">
				<?php if ( $e['activities'] || $went ) : ?>
					<h3>Activities</h3>
					<?php if ( $e['activities'] ) : ?>
						<div class="bftd-tn-tiles">
							<?php foreach ( $e['activities'] as $a ) : ?><span class="bftd-tn-tile"><?php echo esc_html( $a['name'] ); ?></span><?php endforeach; ?>
						</div>
					<?php endif; $gap( 'activities' ); ?>
				<?php endif; ?>

				<?php
				$notes = array();
				if ( '' !== $e['notes'] ) $notes[] = array( '', $e['notes'] );
				foreach ( $e['activities'] as $a ) if ( '' !== $a['note'] ) $notes[] = array( $a['name'], $a['note'] );
				?>
				<?php if ( $notes || isset( $miss['notes'] ) ) : ?>
					<h3>Tutor notes</h3>
					<?php foreach ( $notes as $n ) : ?>
						<div class="bftd-tn-note"><?php if ( '' !== $n[0] ) echo '<strong>' . esc_html( $n[0] ) . '</strong>'; ?>
							<p><?php echo nl2br( esc_html( $n[1] ) ); ?></p></div>
					<?php endforeach; $gap( 'notes' ); ?>
				<?php endif; ?>

				<h3>Attendance</h3>
				<?php if ( isset( $miss['status'] ) ) : $gap( 'status' ); else : ?>
					<div class="bftd-tn-att">
						<span class="bftd-tn-pill is-<?php echo esc_attr( $e['status'] ); ?>"><?php echo esc_html( $st[ $e['status'] ] ); ?></span>
						<?php if ( '' !== $e['why'] ) echo '<span>' . esc_html( $e['why'] ) . '</span>'; ?>
						<?php if ( '' !== $e['missed_by'] ) echo '<span>Cancelled by ' . esc_html( $e['missed_by'] ) . '</span>'; ?>
						<?php if ( '' !== $e['missed_why'] ) echo '<span>' . esc_html( $e['missed_why'] ) . '</span>'; ?>
						<?php if ( 'date' === $e['madeup'] && $e['madeup_on'] ) echo '<span>Made up on ' . esc_html( BFTD_Time::day( $e['madeup_on'], 'j M Y' ) ) . '</span>'; ?>
						<?php if ( 'tba' === $e['madeup'] ) echo '<span>Made up: to be arranged</span>'; ?>
						<?php if ( 'extended' === $e['madeup'] ) echo '<span>Made up by extending sessions</span>'; ?>
						<?php foreach ( array( 'why', 'missed_by', 'missed_why' ) as $k ) : if ( isset( $miss[ $k ] ) ) : ?>
							<span class="bftd-tn-flag">Not entered: <?php echo esc_html( lcfirst( $miss[ $k ] ) ); ?></span>
						<?php endif; endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $e['texts'] || $went ) : ?>
					<h3>What we read</h3>
					<?php if ( $e['texts'] ) : ?>
						<div class="bftd-tn-tiles">
							<?php foreach ( $e['texts'] as $t ) : ?>
								<span class="bftd-tn-tile"><strong><?php echo esc_html( $t['title'] ); ?></strong><?php if ( '' !== $t['level'] ) echo '<em>' . esc_html( $t['level'] ) . '</em>'; ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; $gap( 'texts' ); ?>
				<?php endif; ?>

				<?php if ( $e['matrices'] || $went ) : ?>
					<h3>Morphology matrices studied</h3>
					<?php if ( $e['matrices'] ) : ?>
						<div class="bftd-tn-tiles">
							<?php foreach ( $e['matrices'] as $m ) : ?><span class="bftd-tn-tile"><?php echo esc_html( $m ); ?></span><?php endforeach; ?>
						</div>
					<?php endif; $gap( 'matrices' ); ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

}
