<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The oversight report. Managers and above, nobody else.
 *
 * Every other screen in here is built for the person doing the work. This one
 * is built for the person answering for it, and it asks a different question:
 * not "what happened in this session" but "did the session get written up at
 * all, and when".
 *
 * A tutoring practice runs on records that are written while the hour is
 * still in somebody's head. A session written up a fortnight late is a
 * session written from memory, and a scheduled hour with nothing against it
 * is an hour nobody can say happened — which is the one a family will ring
 * about, and the one a practice cannot answer.
 *
 * So the report measures the paperwork against the schedule, not against
 * itself. The schedule on a student's record says a session was due; the
 * session records say what was written. Everything here is the difference.
 *
 * Nothing on it is a judgement. It is a list of hours, each with a date and
 * a name, that somebody can go and look at.
 */
class BFTD_Oversight {

	const SLUG = 'bftd-oversight';

	/** How the report reads a write-up's timeliness. */
	const ON_TIME_DAYS = 0;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
	}

	public static function menu() {
		// Managers and above. A tutor must not be able to reach a screen
		// that grades them against their colleagues, and the capability is
		// what enforces that rather than the menu being hidden: a hidden row
		// is still a URL.
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			'Oversight',
			'Oversight',
			BFTD_Roles::MANAGE_CAP,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* The window being looked at                                          */
	/* ------------------------------------------------------------------ */

	public static function periods() {
		return array(
			'30'    => 'Last 30 days',
			'month' => 'This month',
			'last'  => 'Last month',
			'90'    => 'Last 90 days',
		);
	}

	/** from, to and a label, as plain Y-m-d. Never past today. */
	public static function window( $which = '30' ) {
		$today = BFTD_Time::today();
		$names = self::periods();
		$which = isset( $names[ $which ] ) ? $which : '30';

		switch ( $which ) {
			case 'month':
				$from = substr( $today, 0, 7 ) . '-01';
				$to   = $today;
				break;
			case 'last':
				$first = substr( $today, 0, 7 ) . '-01';
				$from  = BFTD_Time::format( 'Y-m-01', BFTD_Time::stamp( BFTD_Time::add_days( $first, -1 ) ) );
				$to    = BFTD_Time::add_days( $first, -1 );
				break;
			case '90':
				$from = BFTD_Time::add_days( $today, -90 );
				$to   = $today;
				break;
			default:
				$from = BFTD_Time::add_days( $today, -30 );
				$to   = $today;
		}
		return array( 'key' => $which, 'from' => $from, 'to' => $to, 'label' => $names[ $which ] );
	}

	/* ------------------------------------------------------------------ */
	/* The screen                                                          */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! BFTD_Roles::can_manage() ) {
			echo '<div class="wrap"><p>This report is for managers.</p></div>';
			return;
		}

		$win    = self::window( isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : '30' );
		$tutor  = isset( $_GET['tutor'] ) ? (int) $_GET['tutor'] : 0;
		$groups = self::report( $win, $tutor );
		?>
		<div class="wrap bftd-wrap bftd-stu bftd-ov">
			<h1 class="wp-heading-inline">Oversight</h1>
			<hr class="wp-header-end">

			<p class="bftd-lede">
				Sessions due against sessions written up, for every active student.
				A session counts as on time when it was written up on the day it happened.
				<?php echo esc_html( BFTD_Time::day( $win['from'], 'j M Y' ) . ' to ' . BFTD_Time::day( $win['to'], 'j M Y' ) . '.' ); ?>
			</p>

			<form class="bftd-stu-bar" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<select name="period" aria-label="Period">
					<?php foreach ( self::periods() as $k => $label ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $win['key'], $k, false ), esc_html( $label ) );
					} ?>
				</select>
				<?php
				$mine = BFTD_Access::visible_student_ids( get_current_user_id() );
				$all  = BFTD_CPT::staffed_tutor_ids( $mine );
				if ( $all ) {
					echo '<select name="tutor" aria-label="Tutor"><option value="">Every tutor</option>';
					foreach ( $all as $uid ) {
						$u = get_userdata( $uid );
						if ( ! $u ) continue;
						printf( '<option value="%d"%s>%s</option>', (int) $uid, selected( $tutor, (int) $uid, false ), esc_html( $u->display_name ) );
					}
					echo '</select>';
				}
				?>
				<button type="submit" class="button">Show</button>
			</form>

			<?php if ( ! $groups ) : ?>
				<div class="bftd-stu-empty">
					<p><strong>Nothing to report.</strong></p>
					<p class="description">No active students fall in this period.</p>
				</div>
			<?php else : ?>
				<?php foreach ( $groups as $uid => $g ) self::section( $uid, $g, $win ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function section( $uid, $g, $win ) {
		$behind = $g['nothing'] + $g['late'] + $g['drafts'];
		?>
		<section class="bftd-stu-sec<?php echo $uid ? '' : ' is-none'; ?>">
			<header class="bftd-stu-h">
				<h2><?php echo esc_html( $g['name'] ); ?></h2>
				<span class="bftd-stu-n"><?php
					echo esc_html( $g['on_time'] . ' of ' . $g['due'] . ' written up on the day' );
				?></span>
			</header>

			<div class="bftd-ov-tiles">
				<?php
				// Nothing recorded first, and on its own. A late write-up is
				// paperwork; an hour with no record at all is nobody being
				// able to say whether a child was taught.
				self::tile( $g['nothing'], 'nothing recorded', 'bad' );
				self::tile( $g['late'], 'written up late', 'warn' );
				self::tile( $g['drafts'], 'left as drafts', 'warn' );
				self::tile( $g['cancelled'], 'cancelled', 'plain' );
				self::tile( $g['moved'], 'rescheduled', 'plain' );
				self::tile( $g['flagged'], 'moved twice in a month', $g['flagged'] ? 'bad' : 'plain' );
				?>
			</div>

			<?php if ( ! $behind && ! $g['cancelled'] && ! $g['moved'] ) : ?>
				<p class="bftd-ov-clear">Everything due in this period is written up on the day it happened.</p>
			<?php else : ?>
				<table class="bftd-stu-t bftd-ov-t">
					<thead>
						<tr>
							<th>Student</th>
							<th>Nothing recorded</th>
							<th>Written up late</th>
							<th>Drafts</th>
							<th>Cancelled</th>
							<th>Rescheduled</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $g['rows'] as $row ) self::row( $row ); ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function tile( $n, $word, $kind ) {
		?>
		<div class="bftd-ov-tile is-<?php echo esc_attr( $n ? $kind : 'quiet' ); ?>">
			<b><?php echo (int) $n; ?></b>
			<span><?php echo esc_html( $word ); ?></span>
		</div>
		<?php
	}

	private static function row( $row ) {
		// A student with nothing to say about them is not a row. The report
		// is a list of hours to go and look at, and a screen of clean rows
		// is one nobody reads to the bottom.
		$quiet = ! $row['nothing'] && ! $row['late'] && ! $row['drafts'] && ! $row['cancelled'] && ! $row['moved'];
		if ( $quiet ) return;

		$hub = admin_url( 'admin.php?page=' . BFTD_Admin::HUB_SLUG . '&student=' . (int) $row['student'] );
		?>
		<tr>
			<td class="bftd-stu-name">
				<a href="<?php echo esc_url( $hub ); ?>"><?php echo esc_html( $row['name'] ); ?></a>
				<?php foreach ( $row['crowded'] as $c ) : ?>
					<span class="bftd-ov-flag"><?php echo esc_html( 'Moved ' . (int) $c['times'] . ' times in ' . $c['label'] ); ?></span>
				<?php endforeach; ?>
			</td>
			<td data-l="Nothing recorded"><?php self::dates( $row['nothing'], 'bad' ); ?></td>
			<td data-l="Written up late"><?php self::late_dates( $row['late'] ); ?></td>
			<td data-l="Drafts"><?php self::dates( $row['drafts'], 'warn' ); ?></td>
			<td data-l="Cancelled"><?php self::dates( $row['cancelled'], 'plain' ); ?></td>
			<td data-l="Rescheduled"><?php self::dates( $row['moved'], 'plain' ); ?></td>
		</tr>
		<?php
	}

	/** The dates themselves, because a manager goes and looks at an hour. */
	private static function dates( $list, $kind ) {
		if ( ! $list ) { echo '<span class="bftd-none">&mdash;</span>'; return; }
		echo '<span class="bftd-ov-dates">';
		foreach ( $list as $one ) {
			$label = BFTD_Time::day( $one['date'], 'j M' );
			if ( ! empty( $one['session'] ) ) {
				echo '<a class="bftd-ov-d is-' . esc_attr( $kind ) . '" href="'
					. esc_url( (string) get_edit_post_link( $one['session'] ) ) . '">' . esc_html( $label ) . '</a>';
			} else {
				echo '<span class="bftd-ov-d is-' . esc_attr( $kind ) . '">' . esc_html( $label ) . '</span>';
			}
		}
		echo '</span>';
	}

	private static function late_dates( $list ) {
		if ( ! $list ) { echo '<span class="bftd-none">&mdash;</span>'; return; }
		echo '<span class="bftd-ov-dates">';
		foreach ( $list as $one ) {
			echo '<a class="bftd-ov-d is-warn" href="' . esc_url( (string) get_edit_post_link( $one['session'] ) ) . '">'
				. esc_html( BFTD_Time::day( $one['date'], 'j M' ) )
				. ' <b>+' . (int) $one['days'] . 'd</b></a>';
		}
		echo '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* What the paperwork looks like against the schedule                  */
	/* ------------------------------------------------------------------ */

	/**
	 * One student's record against their own schedule, over a window.
	 *
	 * Returns the counts and, more usefully, the hours themselves: a manager
	 * needs a date and a name to go and look at, not a percentage.
	 *
	 * @param array $sessions The student's session ids, already fetched in
	 *                        bulk by the caller. Asked for per student this
	 *                        would be a query per row.
	 */
	public static function student_row( $student_id, $sessions, $win ) {
		$out = array(
			'student'  => (int) $student_id,
			'due'      => 0,
			'on_time'  => 0,
			'late'     => array(),
			'nothing'  => array(),
			'drafts'   => array(),
			'moved'    => array(),
			'cancelled'=> array(),
			'crowded'  => array(),
		);

		// What was written, by the day it was for. Two sessions on one day is
		// a catch-up beside the regular slot, so the day holds a list.
		$by_date = array();
		foreach ( $sessions as $sid ) {
			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			if ( '' === $date ) continue;
			$by_date[ $date ][] = (int) $sid;
		}

		foreach ( $sessions as $sid ) {
			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			if ( '' === $date || $date < $win['from'] || $date > $win['to'] ) continue;

			$status = (string) BFTD_Fields::get( $sid, 'session', 'status' );
			$live   = ( 'publish' === get_post_status( $sid ) );

			if ( ! $live ) {
				// Started and never finished. The family sees nothing.
				$out['drafts'][] = array( 'session' => (int) $sid, 'date' => $date );
				continue;
			}

			if ( 'rescheduled' === $status ) $out['moved'][] = array( 'session' => (int) $sid, 'date' => $date );
			if ( 'missed' === $status )      $out['cancelled'][] = array( 'session' => (int) $sid, 'date' => $date );

			$days = self::days_late( $sid, $date );
			if ( $days > self::ON_TIME_DAYS ) {
				$out['late'][] = array( 'session' => (int) $sid, 'date' => $date, 'days' => $days );
			} else {
				$out['on_time']++;
			}
		}

		// The schedule's side of it: every slot that was due in the window
		// and has nothing written against its day at all.
		foreach ( (array) BFTD_Schedule::occurrences( $student_id, $win['from'], $win['to'], 400 ) as $slot ) {
			if ( $slot['date'] < $win['from'] || $slot['date'] > $win['to'] ) continue;
			if ( in_array( $slot['status'], array( 'cancelled', 'moved' ), true ) ) continue;

			$out['due']++;
			if ( empty( $by_date[ $slot['date'] ] ) ) {
				$out['nothing'][] = array( 'date' => $slot['date'], 'time' => $slot['time'] );
			}
		}

		$out['crowded'] = self::crowded( $out['moved'] );
		return $out;
	}

	/**
	 * How many days after the session it was written up.
	 *
	 * The record's own creation date, which is the day somebody sat down and
	 * made it. Not the modified date: a tutor who fixes a typo six weeks
	 * later has not written the session up six weeks late, and a report that
	 * said so would be a report nobody trusted twice.
	 */
	public static function days_late( $session_id, $session_date ) {
		$made = get_post_field( 'post_date', $session_id );
		$made = $made ? substr( (string) $made, 0, 10 ) : '';
		if ( '' === $made || '' === $session_date ) return 0;
		if ( $made <= $session_date ) return 0;   // written up on the day, or ahead of it

		$a = BFTD_Time::stamp( $session_date, '12:00' );
		$b = BFTD_Time::stamp( $made, '12:00' );
		if ( ! $a || ! $b ) return 0;
		return (int) round( ( $b - $a ) / DAY_IN_SECONDS );
	}

	/**
	 * More than one move inside one calendar month.
	 *
	 * A running total says nothing about this: six moves across a year is a
	 * family with a busy year, and two inside one month is a fortnight with
	 * no reading in it. The month is named, because a month is something a
	 * person can place and act on.
	 */
	public static function crowded( $moved ) {
		$by = array();
		foreach ( (array) $moved as $m ) {
			if ( empty( $m['date'] ) ) continue;
			$key = substr( $m['date'], 0, 7 );
			$by[ $key ] = isset( $by[ $key ] ) ? $by[ $key ] + 1 : 1;
		}

		$out = array();
		foreach ( $by as $key => $n ) {
			if ( $n < 2 ) continue;
			$ts = BFTD_Time::stamp( $key . '-01' );
			$out[] = array(
				'month' => $key,
				'label' => $ts ? BFTD_Time::format( 'F Y', $ts ) : $key,
				'times' => (int) $n,
			);
		}
		usort( $out, function ( $a, $b ) { return strcmp( $b['month'], $a['month'] ); } );
		return $out;
	}

	/**
	 * The whole report, tutor by tutor.
	 *
	 * Active students only, because the schedule is what this is measured
	 * against and a past student has none. Every fetch is in bulk: the
	 * students once, their sessions once, the people once.
	 */
	public static function report( $win, $only_tutor = 0 ) {
		$ids = BFTD_Students::student_ids( array(
			'q' => '', 'tutor' => (int) $only_tutor, 'client' => 0, 'status' => 'active',
		) );
		if ( ! $ids ) return array();

		$sessions = BFTD_CPT::sessions_of( $ids, true );

		$rows = array();
		foreach ( $ids as $id ) {
			$row = self::student_row( $id, isset( $sessions[ $id ] ) ? $sessions[ $id ] : array(), $win );
			$row['name']   = get_the_title( $id );
			$row['tutors'] = BFTD_CPT::staff_ids( $id );
			$rows[ $id ]   = $row;
		}

		// By tutor, the way the practice is run. A child with two tutors is
		// under both: either of them may be the one who has not written up.
		$groups = array();
		foreach ( $rows as $row ) {
			$where = $row['tutors'] ? $row['tutors'] : array( 0 );
			foreach ( $where as $uid ) {
				$uid = (int) $uid;
				if ( ! isset( $groups[ $uid ] ) ) {
					$u = $uid ? get_userdata( $uid ) : false;
					$groups[ $uid ] = array(
						'name'  => $u ? $u->display_name : 'Nobody assigned',
						'rows'  => array(),
						'due'   => 0, 'on_time' => 0, 'late' => 0,
						'nothing' => 0, 'drafts' => 0, 'moved' => 0, 'cancelled' => 0, 'flagged' => 0,
					);
				}
				$groups[ $uid ]['rows'][]   = $row;
				$groups[ $uid ]['due']     += $row['due'];
				$groups[ $uid ]['on_time'] += $row['on_time'];
				$groups[ $uid ]['late']    += count( $row['late'] );
				$groups[ $uid ]['nothing'] += count( $row['nothing'] );
				$groups[ $uid ]['drafts']  += count( $row['drafts'] );
				$groups[ $uid ]['moved']   += count( $row['moved'] );
				$groups[ $uid ]['cancelled'] += count( $row['cancelled'] );
				$groups[ $uid ]['flagged'] += count( $row['crowded'] );
			}
		}

		$unassigned = isset( $groups[0] ) ? array( 0 => $groups[0] ) : array();
		unset( $groups[0] );
		uasort( $groups, function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );

		return $groups + $unassigned;
	}
}
