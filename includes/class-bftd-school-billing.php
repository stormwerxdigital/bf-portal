<?php
/**
 * Schools that pay (Karl, 1.218: "most Schools pay monthly, after services
 * have been delivered. 1 school currently pays in advance. Whereas regular
 * students pay 1-3 months in advance").
 *
 * Each school on the list has a billing setting:
 *
 *   - not billed to the school (families pay, as always; the default);
 *   - monthly, after sessions, on a billing day (1 to 28, or the last day);
 *   - in advance, sessions drawn down.
 *
 * The School on a student's History is the child's own school, so a family
 * can still pay privately for a child whose school pays for others. Each
 * student says who pays (Paid by: Family or Their school), and a school's
 * billing applies only to the students it pays for. That works both ways:
 * a student can move from one to the other without touching the school.
 *
 * A student paid for by a monthly school has Sessions approved in place of
 * Sessions purchased: the same number, the same forecast of Attending to,
 * the same drawdown. One paid for in advance draws down per student, as a
 * family does. No shows count either way (Karl: "Yes, billed").
 *
 * Monthly schools get a statement for each billing period, from the day
 * after one billing day to the next: each student the school pays for, the
 * sessions given, the rate and the total. Printed or exported; nothing is
 * sent anywhere.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_School_Billing {

	/** A session given, as the drawdown counts it (BFTD_Schedule::used_counts). */
	const BILLED = array( 'held', 'late', 'noshow', 'missed' );

	public static function init() {
		add_action( 'admin_post_bftd_school_statement_csv', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_bftd_school_statement_print', array( __CLASS__, 'printable' ) );
	}

	public static function modes() {
		return array(
			''        => 'Not billed to the school',
			'monthly' => 'Monthly, after sessions',
			'advance' => 'In advance, sessions drawn down',
		);
	}

	/** A school's billing: mode, day ('1' to '28' or 'last'), rate (string, may be empty). */
	public static function of_school( $school ) {
		$s    = is_array( $school ) ? $school : ( class_exists( 'BFTD_Schools' ) ? BFTD_Schools::get( (int) $school ) : null );
		$mode = $s ? (string) ( $s['billing'] ?? '' ) : '';
		if ( ! isset( self::modes()[ $mode ] ) ) $mode = '';
		$day = $s ? (string) ( $s['bill_day'] ?? '' ) : '';
		if ( 'last' !== $day && ( (int) $day < 1 || (int) $day > 28 ) ) $day = 'last';
		return array( 'mode' => $mode, 'day' => 'last' === $day ? 'last' : (string) (int) $day, 'rate' => $s ? (string) ( $s['rate'] ?? '' ) : '' );
	}

	/** Clean what the Schools table sent for one school's billing. */
	public static function clean( $mode, $day, $rate ) {
		$mode = (string) $mode;
		if ( ! isset( self::modes()[ $mode ] ) ) $mode = '';
		$day  = (string) $day;
		if ( 'last' !== $day ) $day = ( (int) $day >= 1 && (int) $day <= 28 ) ? (string) (int) $day : 'last';
		$rate = trim( str_replace( array( '$', ',' ), '', (string) $rate ) );
		$rate = ( '' === $rate || ! is_numeric( $rate ) || (float) $rate < 0 ) ? '' : number_format( (float) $rate, 2, '.', '' );
		return array( 'billing' => $mode, 'bill_day' => $day, 'rate' => $rate );
	}

	/** Paid by on the student: 'school' or 'family'. */
	public static function paid_by( $student_id ) {
		return 'school' === (string) BFTD_Fields::get( (int) $student_id, 'student', 'paid_by' ) ? 'school' : 'family';
	}

	/**
	 * The school paying for this student, with its billing, or null when the
	 * family pays (Paid by Family, no school linked, or a school not billed).
	 */
	public static function payer( $student_id ) {
		if ( 'school' !== self::paid_by( $student_id ) || ! class_exists( 'BFTD_Schools' ) ) return null;
		$s = BFTD_Schools::get( BFTD_Schools::of_student( (int) $student_id ) );
		if ( ! $s ) return null;
		$b = self::of_school( $s );
		return '' === $b['mode'] ? null : array_merge( $s, $b );
	}

	/** 'monthly', 'advance' or '' (the family pays). */
	public static function mode_for( $student_id ) {
		$p = self::payer( $student_id );
		return $p ? $p['mode'] : '';
	}

	/** What the student's session count is called: approved for a monthly school, purchased otherwise. */
	public static function bank_label( $student_id ) {
		return 'monthly' === self::mode_for( $student_id ) ? 'Sessions approved' : 'Sessions purchased';
	}

	/** One line under Paid by saying what it means for this student. */
	public static function said( $student_id ) {
		$wants = 'school' === self::paid_by( $student_id );
		$sid   = class_exists( 'BFTD_Schools' ) ? BFTD_Schools::of_student( (int) $student_id ) : 0;
		$s     = $sid ? BFTD_Schools::get( $sid ) : null;
		if ( ! $wants ) return 'The family pays in advance.';
		if ( ! $s ) return 'Link their School under History first; until then the family pays.';
		$b = self::of_school( $s );
		if ( '' === $b['mode'] ) return $s['name'] . ' is not set up to be billed (Brilliant Futures, Schools); until it is, the family pays.';
		if ( 'advance' === $b['mode'] ) return $s['name'] . ' pays in advance; sessions are drawn down from Sessions purchased.';
		return $s['name'] . ' is billed monthly after sessions, on ' . self::day_words( $b['day'] ) . '.';
	}

	public static function day_words( $day ) {
		if ( 'last' === $day ) return 'the last day of the month';
		$n = (int) $day;
		$suffix = ( $n % 100 >= 11 && $n % 100 <= 13 ) ? 'th' : ( array( 'th', 'st', 'nd', 'rd' )[ $n % 10 ] ?? 'th' );
		return 'the ' . $n . $suffix;
	}

	/* ------------------------------------------------------------------ */
	/* Billing periods                                                     */
	/* ------------------------------------------------------------------ */

	/** The billing date in a month (Y-m-d) for a billing day. */
	public static function bill_date( $year, $month, $day ) {
		$t    = gmmktime( 12, 0, 0, (int) $month, 1, (int) $year );
		$last = (int) gmdate( 't', $t );
		$d    = 'last' === $day ? $last : min( (int) $day, $last );
		return gmdate( 'Y-m-', $t ) . sprintf( '%02d', $d );
	}

	/** The period that ends on the billing date in a month: array( from, to ). */
	public static function period_ending( $year, $month, $day ) {
		$to   = self::bill_date( $year, $month, $day );
		$prev = gmmktime( 12, 0, 0, (int) $month - 1, 1, (int) $year );
		$from = gmdate( 'Y-m-d', strtotime( self::bill_date( gmdate( 'Y', $prev ), gmdate( 'n', $prev ), $day ) . ' 12:00:00 UTC' ) + DAY_IN_SECONDS );
		return array( $from, $to );
	}

	/**
	 * The period a day falls in, then the ones before it: newest first,
	 * $n of them. Each is array( from, to, open ), open while it has not
	 * reached its billing date.
	 */
	public static function periods( $day, $today, $n = 12 ) {
		$t = strtotime( $today . ' 12:00:00 UTC' );
		$y = (int) gmdate( 'Y', $t ); $m = (int) gmdate( 'n', $t );
		// The period holding today ends this month, or next month when this
		// month's billing date has passed.
		if ( self::bill_date( $y, $m, $day ) < $today ) { $m++; if ( $m > 12 ) { $m = 1; $y++; } }
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			list( $from, $to ) = self::period_ending( $y, $m, $day );
			$out[] = array( $from, $to, $to >= $today );
			$m--; if ( $m < 1 ) { $m = 12; $y--; }
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Statements                                                          */
	/* ------------------------------------------------------------------ */

	/** Students linked to a school whose Paid by is Their school. */
	public static function paid_students( $school_id ) {
		$out = array();
		foreach ( BFTD_Schools::students_at( (int) $school_id ) as $sid ) {
			if ( 'school' === self::paid_by( $sid ) ) $out[] = (int) $sid;
		}
		return $out;
	}

	/**
	 * What a school owes for a period, from published sessions dated in it.
	 *
	 * @return array( rows => list of array( id, name, attended, noshow, missed, sessions, rate, total, no_rate ), sessions, total, missing_rate )
	 */
	public static function statement( $school_id, $from, $to ) {
		$s     = BFTD_Schools::get( (int) $school_id );
		$b     = self::of_school( $s );
		$ids   = self::paid_students( $school_id );
		$rows  = array();
		$all   = 0; $sum = 0.0; $no_rate = 0;
		$bysid = $ids ? BFTD_CPT::sessions_of( $ids ) : array();
		foreach ( $ids as $sid ) {
			$c = array( 'attended' => 0, 'noshow' => 0, 'missed' => 0 );
			foreach ( (array) ( $bysid[ $sid ] ?? array() ) as $sess ) {
				$d = (string) BFTD_Fields::get( $sess, 'session', 'session_date' );
				if ( '' === $d || $d < $from || $d > $to ) continue;
				$st = (string) BFTD_Fields::get( $sess, 'session', 'status' );
				if ( ! in_array( $st, self::BILLED, true ) ) continue;
				if ( 'noshow' === $st ) $c['noshow']++;
				elseif ( 'missed' === $st ) $c['missed']++;
				else $c['attended']++;
			}
			$n    = $c['attended'] + $c['noshow'] + $c['missed'];
			$own  = trim( (string) BFTD_Fields::get( $sid, 'student', 'rate' ) );
			$rate = '' !== $own ? (float) $own : ( '' !== $b['rate'] ? (float) $b['rate'] : null );
			if ( $n && null === $rate ) $no_rate++;
			$total = null === $rate ? 0.0 : round( $n * $rate, 2 );
			$rows[] = array_merge( $c, array(
				'id' => $sid, 'name' => (string) get_the_title( $sid ), 'sessions' => $n,
				'rate' => $rate, 'total' => $total, 'no_rate' => null === $rate,
			) );
			$all += $n; $sum += $total;
		}
		usort( $rows, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
		return array( 'rows' => $rows, 'sessions' => $all, 'total' => round( $sum, 2 ), 'missing_rate' => $no_rate );
	}

	public static function money( $n ) {
		return null === $n ? '' : '$' . number_format( (float) $n, 2 );
	}

	/** The schools billed monthly, id => name. */
	public static function monthly_schools() {
		$out = array();
		foreach ( BFTD_Schools::all() as $id => $s ) {
			if ( 'monthly' === self::of_school( $s )['mode'] ) $out[ (int) $id ] = $s['name'];
		}
		return $out;
	}

	/** The period asked for (its billing date), or the newest closed one. */
	private static function chosen( $school_id, $end ) {
		$b = self::of_school( BFTD_Schools::get( $school_id ) );
		$list = self::periods( $b['day'], BFTD_Time::today(), 13 );
		foreach ( $list as $p ) if ( $p[1] === $end ) return $p;
		foreach ( $list as $p ) if ( ! $p[2] ) return $p;
		return $list[0];
	}

	/** The Statements section of the Schools page. */
	public static function render_section() {
		$schools = self::monthly_schools();
		?>
		<h2 id="statements">Monthly statements</h2>
		<?php if ( ! $schools ) : ?>
			<p class="description">No school is billed monthly yet. Set a school's Billing to Monthly, after sessions, in the table above, and its statements show here.</p>
			<?php return; endif;
		$pick = absint( $_GET['statement'] ?? 0 );
		if ( ! isset( $schools[ $pick ] ) ) $pick = (int) array_key_first( $schools );
		$b    = self::of_school( BFTD_Schools::get( $pick ) );
		$p    = self::chosen( $pick, sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ) );
		$st   = self::statement( $pick, $p[0], $p[1] );
		?>
		<p class="description">Each student the school pays for (Paid by: Their school), with the published sessions dated in the period. Attended includes late starts; no shows and missed sessions are billed too, as they draw down sessions. A student's own Rate per session is used first, then the school's.</p>
		<form method="get" class="bftd-stmt-pick">
			<input type="hidden" name="page" value="<?php echo esc_attr( BFTD_Schools::SLUG ); ?>">
			<label for="bftd-stmt-school">School</label>
			<select id="bftd-stmt-school" name="statement" onchange="this.form.period.value='';this.form.submit()">
				<?php foreach ( $schools as $id => $name ) : ?><option value="<?php echo (int) $id; ?>" <?php selected( $id, $pick ); ?>><?php echo esc_html( $name ); ?></option><?php endforeach; ?>
			</select>
			<label for="bftd-stmt-period">Billing period</label>
			<select id="bftd-stmt-period" name="period">
				<?php foreach ( self::periods( $b['day'], BFTD_Time::today(), 13 ) as $pp ) : ?>
					<option value="<?php echo esc_attr( $pp[1] ); ?>" <?php selected( $pp[1], $p[1] ); ?>><?php echo esc_html( self::period_words( $pp ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button">Show</button>
		</form>
		<?php self::table( $pick, $p, $st ); ?>
		<p class="bftd-stmt-actions">
			<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( self::action_url( 'print', $pick, $p[1] ) ); ?>">Print</a>
			<a class="button" href="<?php echo esc_url( self::action_url( 'csv', $pick, $p[1] ) ); ?>">Export CSV</a>
		</p>
		<?php
	}

	public static function period_words( $p ) {
		return BFTD_Time::day( $p[0], 'j M Y' ) . ' to ' . BFTD_Time::day( $p[1], 'j M Y' ) . ( $p[2] ? ' (still open)' : '' );
	}

	private static function action_url( $what, $school, $end ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'bftd_school_statement_' . $what, 'school' => (int) $school, 'period' => $end ), admin_url( 'admin-post.php' ) ), 'bftd_school_statement' );
	}

	/** The statement table, shared by the page and the printable copy. */
	public static function table( $school_id, $p, $st ) {
		$s = BFTD_Schools::get( $school_id );
		?>
		<table class="widefat striped bftd-stmt">
			<caption><?php echo esc_html( $s['name'] . ', ' . self::period_words( $p ) ); ?></caption>
			<thead><tr><th scope="col">Student</th><th scope="col">Attended</th><th scope="col">No shows</th><th scope="col">Missed</th><th scope="col">Sessions billed</th><th scope="col">Rate</th><th scope="col">Total</th></tr></thead>
			<tbody>
			<?php if ( ! $st['rows'] ) : ?><tr><td colspan="7">No students are paid for by this school. Set a student's Paid by to Their school under Student details.</td></tr><?php endif; ?>
			<?php foreach ( $st['rows'] as $r ) : ?>
				<tr><th scope="row"><?php echo esc_html( $r['name'] ); ?></th><td><?php echo (int) $r['attended']; ?></td><td><?php echo (int) $r['noshow']; ?></td><td><?php echo (int) $r['missed']; ?></td>
					<td><?php echo (int) $r['sessions']; ?></td><td><?php echo $r['no_rate'] ? 'No rate set' : esc_html( self::money( $r['rate'] ) ); ?></td><td><?php echo esc_html( self::money( $r['total'] ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot><tr><th scope="row">Total</th><td colspan="3"></td><td><?php echo (int) $st['sessions']; ?></td><td></td><td><?php echo esc_html( self::money( $st['total'] ) ); ?></td></tr></tfoot>
		</table>
		<?php if ( $st['missing_rate'] ) : ?>
			<p class="bftd-appt-err" role="alert"><?php echo (int) $st['missing_rate']; ?> student<?php echo 1 === (int) $st['missing_rate'] ? ' has' : 's have'; ?> sessions but no rate. Set the school's Rate per session, or the student's.</p>
		<?php endif;
	}

	private static function guard() {
		check_admin_referer( 'bftd_school_statement' );
		if ( ! BFTD_Schools::can_edit() ) wp_die( 'Only senior managers and administrators can see school statements.', 403 );
		$id = absint( $_GET['school'] ?? 0 );
		if ( ! BFTD_Schools::get( $id ) ) wp_die( 'That school is not on the list.', 404 );
		$p = self::chosen( $id, sanitize_text_field( wp_unslash( $_GET['period'] ?? '' ) ) );
		return array( $id, $p, self::statement( $id, $p[0], $p[1] ) );
	}

	/** The rows of a CSV statement, header first. */
	public static function csv_rows( $school_id, $p, $st ) {
		$s   = BFTD_Schools::get( $school_id );
		$out = array( array( 'School', 'From', 'To', 'Student', 'Attended', 'No shows', 'Missed', 'Sessions billed', 'Rate', 'Total' ) );
		foreach ( $st['rows'] as $r ) {
			$out[] = array( $s['name'], $p[0], $p[1], $r['name'], $r['attended'], $r['noshow'], $r['missed'], $r['sessions'],
				$r['no_rate'] ? '' : number_format( (float) $r['rate'], 2, '.', '' ), number_format( (float) $r['total'], 2, '.', '' ) );
		}
		$out[] = array( $s['name'], $p[0], $p[1], 'Total', '', '', '', $st['sessions'], '', number_format( (float) $st['total'], 2, '.', '' ) );
		return $out;
	}

	public static function download() {
		list( $id, $p, $st ) = self::guard();
		$s = BFTD_Schools::get( $id );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $s['name'] . ' statement ' . $p[0] . ' to ' . $p[1] ) . '.csv"' );
		$fh = fopen( 'php://output', 'w' );
		foreach ( self::csv_rows( $id, $p, $st ) as $row ) {
			// A cell starting with = + - @ is a formula to a spreadsheet; a quote in front keeps it text.
			fputcsv( $fh, array_map( function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v; }, $row ) );
		}
		fclose( $fh );
		exit;
	}

	public static function printable() {
		list( $id, $p, $st ) = self::guard();
		$s = BFTD_Schools::get( $id );
		nocache_headers();
		?><!doctype html><html lang="en"><head><meta charset="utf-8"><title><?php echo esc_html( $s['name'] . ' statement' ); ?></title>
		<style>body{font:14px/1.5 system-ui,sans-serif;color:#1d2327;margin:32px}h1{font-size:20px;margin:0 0 4px}p{margin:0 0 16px}
		table{border-collapse:collapse;width:100%}caption{text-align:left;font-weight:600;margin-bottom:8px}th,td{border:1px solid #c3c4c7;padding:6px 10px;text-align:left}
		thead th{background:#f6f7f7}tfoot th,tfoot td{font-weight:600}.bftd-appt-err{color:#b32d2e;margin-top:12px}@media print{body{margin:0}}</style></head>
		<body><h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1><p>Statement for <?php echo esc_html( $s['name'] ); ?>, <?php echo esc_html( self::period_words( $p ) ); ?></p>
		<?php self::table( $id, $p, $st ); ?>
		<script>window.print();</script></body></html><?php
		exit;
	}
}
