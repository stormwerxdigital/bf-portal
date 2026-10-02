<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Time cards. One period, one tutor at a time.
 *
 * The screen answers two questions and tries not to answer any others. For
 * an approver: what has this tutor done this fortnight, and what am I saying
 * yes to. For a tutor: what have I got coming, and has anybody looked at it
 * yet.
 *
 * A tutor sees their own card and nothing else — not a filter set to them, a
 * different screen with a different question behind it. The rows are the
 * same rows; what is missing is every other person's, and every control that
 * decides money.
 *
 * Nothing here calculates a deduction. What it produces is what the practice
 * has agreed it owes, which is the number the payroll side starts from.
 */
class BFTD_Timecards {

	const SLUG = 'bftd-timecards';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'admin_init', array( __CLASS__, 'handle_cra_ack' ) );
		add_action( 'admin_post_bftd_pay_decide', array( __CLASS__, 'handle_decide' ) );
		add_action( 'admin_post_bftd_stat_run', array( __CLASS__, 'handle_stat_run' ) );
	}

	public static function menu() {
		// Staff, because a tutor has their own card here. What separates an
		// approver from a tutor is checked inside, on every row and on every
		// action, rather than at the door.
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			'Time Cards',
			'Time Cards',
			BFTD_Roles::STAFF_CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* What is being looked at                                             */
	/* ------------------------------------------------------------------ */

	public static function asked() {
		$period = isset( $_GET['period'] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : '';
		if ( ! BFTD_Pay_Period::valid( $period ) ) $period = BFTD_Pay_Period::current();

		return array(
			'period' => $period,
			'tutor'  => isset( $_GET['tutor'] ) ? (int) $_GET['tutor'] : 0,
			'sample' => ! empty( $_GET['sample'] ),
		);
	}

	/**
	 * Whose cards this viewer may see.
	 *
	 * An approver sees everybody. Anybody else sees themselves, and the
	 * answer is their own id rather than a filter they could edit out of the
	 * query string.
	 */
	public static function visible_tutors( $viewer = 0 ) {
		$viewer = $viewer ? (int) $viewer : get_current_user_id();
		if ( ! BFTD_Pay::can_approve( $viewer ) ) return array( $viewer );

		$all = array();
		foreach ( get_users( array( 'fields' => array( 'ID' ) ) ) as $u ) {
			if ( BFTD_Pay_Profile::is_paid_staff( $u->ID ) ) $all[] = (int) $u->ID;
		}
		return $all;
	}

	/**
	 * Whose sections to draw for a period.
	 *
	 * Everybody on the payroll, so a tutor with a quiet fortnight still gets
	 * a card saying so, PLUS anybody who has an entry in the period even if
	 * they are not on it. The second half is not tidiness: without it, a
	 * lesson published against somebody whose profile was never filled in
	 * would be money on the ledger that no screen ever shows, which is the
	 * worst failure this screen can have.
	 */
	public static function sections_for( $period ) {
		$mine = self::visible_tutors();
		if ( ! BFTD_Pay::can_approve() ) return $mine;

		foreach ( BFTD_Pay::entries_in( $period ) as $id ) {
			$uid = (int) BFTD_Pay::get( $id, 'tutor' );
			if ( $uid && ! in_array( $uid, $mine, true ) ) $mine[] = $uid;
		}
		return $mine;
	}

	/**
	 * The period's entries, grouped by tutor, in the order they were worked.
	 *
	 * Tutors with nothing in the period are kept, with an empty list, because
	 * "Laurel has nothing this fortnight" is an answer somebody came here for
	 * and a missing section is not it.
	 */
	public static function cards( $period, $only_tutor = 0 ) {
		$mine = self::sections_for( $period );
		if ( $only_tutor ) $mine = array_values( array_intersect( $mine, array( (int) $only_tutor ) ) );
		if ( ! $mine ) return array();

		$by = array();
		foreach ( $mine as $uid ) $by[ $uid ] = array();

		foreach ( BFTD_Pay::entries_in( $period ) as $id ) {
			$row = BFTD_Pay::row( $id );
			if ( ! isset( $by[ $row['tutor'] ] ) ) continue;
			$by[ $row['tutor'] ][] = $row;
		}

		// By the day the work happened, then by the time of day, so a card
		// reads down the fortnight the way the fortnight happened.
		foreach ( $by as $uid => $rows ) {
			usort( $rows, function ( $a, $b ) {
				if ( $a['on'] !== $b['on'] ) return strcmp( $a['on'], $b['on'] );
				return strcmp( $a['at'], $b['at'] );
			} );
			$by[ $uid ] = $rows;
		}

		// Named, so the sections come out in an order a person expects.
		uksort( $by, function ( $a, $b ) {
			$ua = get_userdata( $a ); $ub = get_userdata( $b );
			return strcasecmp( $ua ? $ua->display_name : '', $ub ? $ub->display_name : '' );
		} );

		return $by;
	}

	/* ------------------------------------------------------------------ */
	/* Deciding                                                            */
	/* ------------------------------------------------------------------ */

	public static function handle_decide() {
		check_admin_referer( 'bftd_pay_decide' );

		if ( ! BFTD_Pay::can_approve() ) {
			wp_die( 'Only an administrator or senior manager can decide what is paid.', '', array( 'response' => 403 ) );
		}

		$to  = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$ids = isset( $_POST['entry'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['entry'] ) ) : array();

		$done = 0;
		foreach ( array_filter( $ids ) as $id ) {
			if ( BFTD_Pay::decide( $id, $to ) ) $done++;
		}

		$back = isset( $_POST['back'] ) ? wp_unslash( $_POST['back'] ) : '';
		$back = $back ? wp_validate_redirect( $back, admin_url( 'admin.php?page=' . self::SLUG ) )
					  : admin_url( 'admin.php?page=' . self::SLUG );

		wp_safe_redirect( add_query_arg( array( 'decided' => $done, 'as' => $to ), $back ) );
		exit;
	}

	/**
	 * Work out the statutory holidays in a period.
	 *
	 * By hand, from a button, rather than on a schedule. There is a
	 * judgement in this one that somebody should be looking at when it is
	 * made, and a calculation that runs itself at two in the morning is a
	 * calculation nobody reads.
	 */
	public static function handle_stat_run() {
		check_admin_referer( 'bftd_stat_run' );

		if ( ! BFTD_Pay::can_approve() ) {
			wp_die( 'Only an administrator or senior manager can work out statutory holiday pay.', '', array( 'response' => 403 ) );
		}

		$period = isset( $_POST['period'] ) ? sanitize_text_field( wp_unslash( $_POST['period'] ) ) : '';
		if ( ! BFTD_Pay_Period::valid( $period ) ) $period = BFTD_Pay_Period::current();

		$made = BFTD_Stat_Pay::run( $period, self::sections_for( $period ) );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::SLUG, 'period' => $period, 'stat' => (int) $made['paid'], 'no' => (int) $made['refused'] ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* The screen                                                          */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* The yearly reminder to go and read the CRA tables again             */
	/* ------------------------------------------------------------------ */

	/** Where the acknowledgement for one year is kept. */
	const CRA_ACK = 'bftd_cra_checked_year';

	/**
	 * A reminder, every January, to check this year's CRA figures.
	 *
	 * The rates change on the first of January: the CPP and CPP2 ceilings and
	 * exemption, the EI maximum and rate, and the federal and BC tax brackets
	 * and credits. Nothing in the plugin can tell that it is now working from
	 * last year's numbers, because last year's numbers are arithmetically fine
	 * and simply wrong. A payroll run on them is wrong in somebody's favour and
	 * has to be unpicked by hand afterwards.
	 *
	 * So the reminder is the first thing on the screen from the 1st of January,
	 * for whoever approves pay, and it stays until one of them says they have
	 * looked. The acknowledgement is stored as the year it was given for, so
	 * next January it comes back by itself rather than needing anybody to
	 * remember that it should.
	 */
	public static function cra_reminder() {
		if ( ! BFTD_Pay::can_approve() ) return;

		$year = (int) substr( BFTD_Time::today(), 0, 4 );
		if ( (int) get_option( self::CRA_ACK, 0 ) >= $year ) return;

		$url = wp_nonce_url(
			add_query_arg( array( 'bftd_cra_ack' => $year ), self::base_url() ),
			'bftd_cra_ack_' . $year
		);
		?>
		<div class="notice notice-warning bftd-cra-notice">
			<p><strong>Check this year's CRA figures before the first pay run of <?php echo (int) $year; ?>.</strong></p>
			<p>
				The CPP and CPP2 ceilings, the basic exemption, the EI rate and
				maximum, and the federal and British Columbia tax brackets and
				credits all change on the 1st of January. The plugin cannot tell
				that it is working from last year's numbers, so a pay run made
				before they are updated will be wrong, and wrong in a way that
				has to be corrected by hand afterwards.
			</p>
			<p>
				Compare a payslip against the CRA's own payroll deductions
				calculator before paying anybody from it. This plugin applies
				one reading of the rules; the CRA is the authority.
			</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>">I have checked <?php echo (int) $year; ?>'s figures</a></p>
		</div>
		<?php
	}

	/** Record that somebody has checked a year, and say so. */
	public static function handle_cra_ack() {
		if ( empty( $_GET['bftd_cra_ack'] ) ) return;

		$year = (int) $_GET['bftd_cra_ack'];
		if ( ! BFTD_Pay::can_approve() ) return;
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'bftd_cra_ack_' . $year ) ) return;

		update_option( self::CRA_ACK, $year );

		if ( class_exists( 'BFTD_Audit' ) ) {
			BFTD_Audit::log( 'settings_updated', array(
				'summary' => wp_get_current_user()->display_name . ' confirmed the ' . $year . ' CRA figures have been checked.',
			) );
		}

		wp_safe_redirect( add_query_arg( array( 'bftd_said' => 'cra' ), self::base_url() ) );
		exit;
	}

	/** This screen, with nothing else on the query string. */
	private static function base_url() {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	public static function render() {
		if ( ! BFTD_Roles::is_staff() ) return;

		$a      = self::asked();
		$boss   = BFTD_Pay::can_approve();

		if ( $a['sample'] ) { self::render_sample( $a, $boss ); return; }

		$cards  = self::cards( $a['period'], $a['tutor'] );
		?>
		<div class="wrap bftd-wrap bftd-tc">
			<h1 class="wp-heading-inline"><?php echo $boss ? 'Time Cards' : 'My Time Card'; ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( add_query_arg(
				array( 'page' => self::SLUG, 'period' => $a['period'], 'sample' => 1 ), admin_url( 'admin.php' ) ) ); ?>">See a worked example</a>
			<hr class="wp-header-end">

			<?php self::cra_reminder(); ?>
			<?php self::said(); ?>
			<?php self::bar( $a, $boss ); ?>
			<?php self::stat_panel( $a, $boss ); ?>

			<?php if ( ! $cards ) : ?>
				<div class="bftd-tc-empty">
					<p><strong>Nothing here.</strong></p>
					<p class="description">Published sessions and reading diagnostics arrive on this screen by themselves. A draft never does.</p>
				</div>
			<?php else : ?>
				<?php foreach ( $cards as $uid => $rows ) self::card( $uid, $rows, $a, $boss ); ?>
			<?php endif; ?>

			<p class="bftd-tc-foot description">
				A session reaches a card once, whatever happens to it afterwards. One
				moved three times is one hour of somebody's working life, and is paid once.
			</p>
		</div>
		<?php
	}

	/**
	 * The screen, with a fortnight that did not happen on it.
	 *
	 * For showing somebody how this works. A real period is a poor teacher:
	 * it is mostly one kind of row, and the three that need explaining are
	 * the ones that turn up in the week nobody is being trained.
	 *
	 * Nothing here can be acted on and nothing here is in the ledger. The
	 * banner says so at the top, and there is no form around the rows, so
	 * there is nothing to tick and nothing to press even for somebody who
	 * could approve the real thing.
	 */
	public static function render_sample( $a, $boss ) {
		$rows = BFTD_Pay::sample( $a['period'] );
		$back = add_query_arg( array( 'page' => self::SLUG, 'period' => $a['period'] ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap bftd-wrap bftd-tc bftd-tc-is-sample">
			<h1 class="wp-heading-inline">A worked example</h1>
			<a class="page-title-action" href="<?php echo esc_url( $back ); ?>">Back to the real ones</a>
			<hr class="wp-header-end">

			<div class="bftd-tc-sample-note">
				<p><strong>Nobody is paid for anything on this page.</strong>
				These six rows are made up. They are here because one fortnight of real
				work rarely contains all the things this screen can show, and the rows
				worth explaining are the ones that turn up least often.</p>
			</div>

			<?php self::card( 0, $rows, $a, $boss, array( 'name' => 'A tutor', 'kind' => BFTD_Pay::EMPLOYEE ) ); ?>

			<div class="bftd-tc-key">
				<h2>What each row means</h2>
				<dl>
					<dt>Session</dt>
					<dd>A session that went ahead and was published. It arrives here by itself, at the rate on that tutor's profile at the moment it was published.</dd>
					<dt>Reading diagnostic</dt>
					<dd>The same, at the diagnostic rate. It is dated by the assessment, not by the day the write-up was finished.</dd>
					<dt>Rescheduled session &middot; Cancelled session</dt>
					<dd>The tutor held the hour, so it is entered and paid at the session rate, but somebody has to say yes first. These are the two rows marked <em>needs a decision</em>, and they are the reason this screen exists.</dd>
					<dt>Pending</dt>
					<dd>On the card and counted separately, not in what is owed. Nothing is owed until it is approved.</dd>
					<dt>Approved</dt>
					<dd>Agreed and counted. The amount is frozen: a later change to the tutor's rate, or to the session itself, does not move it.</dd>
					<dt>Declined</dt>
					<dd>Decided against, struck through, counted as nothing. It stays on the card so the decision is visible rather than the row simply disappearing.</dd>
					<dt>Paid</dt>
					<dd>Money has moved. It cannot be decided again. A mistake in a period that has been paid is corrected in an open one, not edited here.</dd>
				</dl>
				<p class="description">
					A session reaches a card once and once only, whatever happens to it afterwards.
					One moved three times is still one hour of somebody's working life.
					A draft never reaches a card at all.
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * The statutory holidays in this period, and who is owed one.
	 *
	 * Only drawn when the period actually contains one, which is nine
	 * fortnights in twenty-four. It shows the working for everybody rather
	 * than only for those who qualify, because the first question anybody
	 * asks about a tutor who was not paid a stat is why not, and the answer
	 * is arithmetic that should be on the screen rather than in somebody's
	 * head.
	 */
	private static function stat_panel( $a, $boss ) {
		$days = BFTD_Stat_Holidays::between(
			BFTD_Pay_Period::start( $a['period'] ),
			BFTD_Pay_Period::end( $a['period'] )
		);
		if ( ! $days ) return;

		$who = self::sections_for( $a['period'] );
		if ( $a['tutor'] ) $who = array_values( array_intersect( $who, array( (int) $a['tutor'] ) ) );
		if ( ! $who ) return;
		?>
		<section class="bftd-tc-stat">
			<?php foreach ( $days as $ymd => $name ) : ?>
				<header class="bftd-tc-stat-h">
					<h2><?php echo esc_html( $name ); ?></h2>
					<span class="bftd-tc-stat-d"><?php echo esc_html( BFTD_Time::day( $ymd, 'l j F Y' ) ); ?></span>
				</header>
				<table class="bftd-tc-t bftd-tc-stat-t">
					<thead>
						<tr><th>Tutor</th><th>Days worked in the thirty before</th><th class="bftd-tc-r">An average day</th><th>Owed</th></tr>
					</thead>
					<tbody>
						<?php foreach ( $who as $uid ) :
							$w    = BFTD_Stat_Pay::working( $uid, $ymd );
							$user = get_userdata( $uid );
							?>
							<tr class="<?php echo $w['qualifies'] ? 'is-yes' : 'is-no'; ?>">
								<td><?php echo esc_html( $user ? $user->display_name : 'Unknown' ); ?></td>
								<td>
									<?php echo esc_html( $w['why'] ); ?>
									<?php if ( $w['undecided'] ) : ?>
										<span class="bftd-tc-flag"><?php echo esc_html( $w['undecided'] ); ?>
											still undecided in that window, which would change this</span>
									<?php endif; ?>
									<?php if ( $w['worked'] > 0 ) : ?>
										<span class="bftd-tc-flag">taught <?php echo esc_html( BFTD_Stat_Pay::hours( $w['worked'] ) ); ?> on the day</span>
									<?php endif; ?>
								</td>
								<td class="bftd-tc-r"><?php echo $w['qualifies'] ? esc_html( BFTD_Pay::money( $w['amount'] ) ) : '<span class="bftd-none">&ndash;</span>'; ?></td>
								<td><?php
									if ( ! $w['qualifies'] ) { echo '<span class="bftd-none">Nothing</span>'; }
									else {
										echo esc_html( BFTD_Pay::money( $w['amount'] + $w['extra'] ) );
										if ( $w['extra'] > 0 ) echo ' <span class="bftd-tc-flag">including ' . esc_html( BFTD_Pay::money( $w['extra'] ) ) . ' for working it</span>';
									}
								?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<?php if ( $boss ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bftd-tc-acts">
					<input type="hidden" name="action" value="bftd_stat_run">
					<input type="hidden" name="period" value="<?php echo esc_attr( $a['period'] ); ?>">
					<?php wp_nonce_field( 'bftd_stat_run' ); ?>
					<button class="button button-primary">Put these on the time cards</button>
					<span class="description">Safe to press twice. Nobody is paid a holiday more than once, and anything already approved is left alone.</span>
				</form>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function said() {
		if ( isset( $_GET['bftd_said'] ) && 'cra' === $_GET['bftd_said'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( 'Noted. The reminder will come back on the 1st of January.' )
				. '</p></div>';
		}
		if ( isset( $_GET['stat'] ) ) {
			$n  = (int) $_GET['stat'];
			$no = isset( $_GET['no'] ) ? (int) $_GET['no'] : 0;
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html( $n . ' statutory holiday ' . ( 1 === $n ? 'entry' : 'entries' ) . ' on the cards, waiting on a decision.' )
				. ( $no ? esc_html( ' ' . $no . ' ' . ( 1 === $no ? 'person did' : 'people did' ) . ' not qualify.' ) : '' )
				. '</p></div>';
		}
		if ( ! isset( $_GET['decided'] ) ) return;
		$n  = (int) $_GET['decided'];
		$as = isset( $_GET['as'] ) ? sanitize_key( wp_unslash( $_GET['as'] ) ) : '';
		$w  = array( BFTD_Pay::APPROVED => 'approved', BFTD_Pay::DECLINED => 'declined', BFTD_Pay::PENDING => 'put back' );
		if ( ! isset( $w[ $as ] ) ) return;
		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html( $n . ' ' . ( 1 === $n ? 'entry' : 'entries' ) . ' ' . $w[ $as ] . '.' )
			. '</p></div>';
	}

	private static function bar( $a, $boss ) {
		$prev = BFTD_Pay_Period::previous( $a['period'] );
		$next = BFTD_Pay_Period::next( $a['period'] );
		$url  = function ( $args ) {
			return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
		};
		?>
		<div class="bftd-tc-bar">
			<div class="bftd-tc-when">
				<a class="button bftd-tc-step" href="<?php echo esc_url( $url( array( 'period' => $prev, 'tutor' => $a['tutor'] ) ) ); ?>" aria-label="The period before this one">&lsaquo;</a>
				<form method="get" class="bftd-tc-pick">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
					<?php if ( $a['tutor'] ) : ?><input type="hidden" name="tutor" value="<?php echo (int) $a['tutor']; ?>"><?php endif; ?>
					<label class="screen-reader-text" for="bftd-tc-period">Pay period</label>
					<select name="period" id="bftd-tc-period" onchange="this.form.submit()">
						<?php foreach ( BFTD_Pay_Period::around( $a['period'] ) as $key ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $a['period'], $key ); ?>>
								<?php echo esc_html( BFTD_Pay_Period::short_label( $key ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<noscript><button class="button">Show</button></noscript>
				</form>
				<a class="button bftd-tc-step" href="<?php echo esc_url( $url( array( 'period' => $next, 'tutor' => $a['tutor'] ) ) ); ?>" aria-label="The period after this one">&rsaquo;</a>
			</div>
			<p class="bftd-tc-paid description">
				Paid on <?php echo esc_html( BFTD_Time::day( BFTD_Pay_Period::pay_date( $a['period'] ), 'j F Y' ) ); ?>.
			</p>
			<?php if ( $boss && $a['tutor'] ) : ?>
				<a class="button bftd-tc-all" href="<?php echo esc_url( $url( array( 'period' => $a['period'] ) ) ); ?>">Everybody</a>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function card( $uid, $rows, $a, $boss, $as = null ) {
		$sample = is_array( $as );
		$who    = $sample ? null : get_userdata( $uid );
		$name   = $sample ? $as['name'] : ( $who ? $who->display_name : 'Unknown' );
		$t      = BFTD_Pay::totals( $rows );
		$kind   = $sample ? $as['kind'] : BFTD_Pay::employment( $uid );
		$url   = add_query_arg( array( 'page' => self::SLUG, 'period' => $a['period'], 'tutor' => $uid ), admin_url( 'admin.php' ) );
		$back  = add_query_arg( array( 'page' => self::SLUG, 'period' => $a['period'] ) + ( $a['tutor'] ? array( 'tutor' => $a['tutor'] ) : array() ), admin_url( 'admin.php' ) );
		?>
		<section class="bftd-tc-sec">
			<header class="bftd-tc-h">
				<h2><?php echo esc_html( $name ); ?></h2>
				<span class="bftd-pill is-<?php echo esc_attr( $kind ); ?>"><?php echo esc_html( BFTD_Pay::employments()[ $kind ] ); ?></span>
				<span class="bftd-tc-sum">
					<b><?php echo esc_html( BFTD_Pay::money( $t['due'] ) ); ?></b> approved
					<?php if ( $t['waiting'] ) : ?>
						<span class="bftd-tc-wait"><?php echo esc_html( BFTD_Pay::money( $t['pending'] ) ); ?> waiting on a decision</span>
					<?php endif; ?>
				</span>
				<?php if ( $boss && ! $a['tutor'] && ! $sample ) : ?>
					<a class="bftd-tc-only" href="<?php echo esc_url( $url ); ?>">Only <?php echo esc_html( $name ); ?></a>
				<?php endif; ?>
			</header>

			<?php if ( ! $rows ) : ?>
				<p class="bftd-tc-none">Nothing in this period.</p>
			<?php elseif ( $sample ) : ?>
				<table class="bftd-tc-t">
					<colgroup>
						<col class="w-when"><col class="w-what"><col class="w-who"><col class="w-amt"><col class="w-st">
					</colgroup>
					<thead>
						<tr><th>When</th><th>What</th><th>Student</th><th class="bftd-tc-r">Amount</th><th>State</th></tr>
					</thead>
					<tbody><?php foreach ( $rows as $r ) self::row( $r, false ); ?></tbody>
				</table>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bftd_pay_decide">
				<input type="hidden" name="back" value="<?php echo esc_attr( $back ); ?>">
				<?php wp_nonce_field( 'bftd_pay_decide' ); ?>

				<table class="bftd-tc-t">
					<colgroup>
						<?php if ( $boss ) : ?><col class="w-tick"><?php endif; ?>
						<col class="w-when"><col class="w-what"><col class="w-who"><col class="w-amt"><col class="w-st">
					</colgroup>
					<thead>
						<tr>
							<?php if ( $boss ) : ?><th class="bftd-tc-c"><span class="screen-reader-text">Choose</span></th><?php endif; ?>
							<th>When</th>
							<th>What</th>
							<th>Student</th>
							<th class="bftd-tc-r">Amount</th>
							<th>State</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) self::row( $r, $boss ); ?>
					</tbody>
				</table>

				<?php if ( $boss && $t['waiting'] ) : ?>
					<p class="bftd-tc-acts">
						<button class="button button-primary" name="to" value="<?php echo esc_attr( BFTD_Pay::APPROVED ); ?>">Approve the ticked</button>
						<button class="button" name="to" value="<?php echo esc_attr( BFTD_Pay::DECLINED ); ?>">Decline the ticked</button>
					</p>
				<?php elseif ( $boss ) : ?>
					<p class="bftd-tc-acts">
						<button class="button" name="to" value="<?php echo esc_attr( BFTD_Pay::PENDING ); ?>">Put the ticked back to pending</button>
					</p>
				<?php endif; ?>
			</form>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function row( $r, $boss ) {
		$flag  = BFTD_Pay::needs_a_decision( $r['kind'] );
		$title = $r['source'] ? get_the_title( $r['source'] ) : '';
		?>
		<tr class="bftd-tc-row is-<?php echo esc_attr( $r['state'] ); ?><?php echo $flag ? ' is-flagged' : ''; ?>">
			<?php if ( $boss ) : ?>
				<td class="bftd-tc-c">
					<?php if ( BFTD_Pay::PAID !== $r['state'] ) : ?>
						<input type="checkbox" name="entry[]" value="<?php echo (int) $r['id']; ?>"
							aria-label="<?php echo esc_attr( BFTD_Pay::kind_label( $r['kind'] ) . ' on ' . BFTD_Time::day( $r['on'], 'j M' ) ); ?>">
					<?php endif; ?>
				</td>
			<?php endif; ?>
			<td class="bftd-tc-when">
				<?php echo esc_html( BFTD_Time::day( $r['on'], 'j M Y' ) ); ?>
				<?php if ( $r['at'] ) : ?><span class="bftd-tc-at"><?php echo esc_html( BFTD_Schedule::pretty_time( $r['at'] ) ); ?></span><?php endif; ?>
			</td>
			<td>
				<?php if ( $r['source'] && current_user_can( 'edit_post', $r['source'] ) ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $r['source'] ) ); ?>"><?php echo esc_html( BFTD_Pay::kind_label( $r['kind'] ) ); ?></a>
				<?php else : ?>
					<?php echo esc_html( BFTD_Pay::kind_label( $r['kind'] ) ); ?>
				<?php endif; ?>
				<?php if ( $flag ) : ?>
					<span class="bftd-tc-flag">needs a decision</span>
				<?php endif; ?>
				<?php if ( $r['changed'] ) : ?>
					<span class="bftd-tc-flag">the record changed after this was decided</span>
				<?php endif; ?>
			</td>
			<td><?php
				// A made-up row carries its own name, because there is no
				// record behind it to ask.
				if ( isset( $r['who'] ) ) echo esc_html( $r['who'] );
				elseif ( $r['student'] ) echo esc_html( get_the_title( $r['student'] ) );
				else echo '<span class="bftd-none">&ndash;</span>';
			?></td>
			<td class="bftd-tc-r"><?php echo esc_html( BFTD_Pay::money( $r['amount'] ) ); ?></td>
			<td><span class="bftd-pill is-<?php echo esc_attr( $r['state'] ); ?>"><?php echo esc_html( ucfirst( $r['state'] ) ); ?></span></td>
		</tr>
		<?php
	}
}
