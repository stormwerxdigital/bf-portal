<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Statutory holiday pay, for employees.
 *
 * Contractors get none of this. It is an entitlement under the Employment
 * Standards Act and the Act is about employees, which is the whole reason
 * the classification is recorded on a tutor in the first place.
 *
 * The Act sets two things and this file works out both.
 *
 * WHETHER SOMEBODY QUALIFIES. Employed for thirty calendar days, and worked
 * or earned wages on fifteen of the thirty days before the holiday. The
 * second half of that is a higher bar than it looks for work paid by the
 * session: a tutor teaching Tuesdays and Thursdays reaches eight or nine
 * days in a thirty day window and does not qualify. That is the answer, and
 * the screen shows the working rather than a bare no, because the first
 * question anybody asks about a no is how it was arrived at.
 *
 * WHAT THEY ARE OWED. An average day's pay, which is total wages in the
 * thirty calendar days before the holiday divided by the number of days
 * worked in them. Overtime is excluded. Paid vacation, paid sick days and
 * other statutory holiday pay count, both as wages and as days worked.
 *
 * AND IF THEY WORKED IT. Time and a half for hours worked, double time past
 * twelve in the day, on top of the average day's pay. The practice pays by
 * the session rather than by the hour, so a session counts as an hour and a
 * reading diagnostic as two and a half. What falls out of that arithmetic is
 * tidier than it looks: the hourly rate is the entry divided by its hours,
 * so the extra half owed on an entry is half the entry, and the hours only
 * actually matter for deciding where the twelfth hour of the day falls.
 *
 * Nothing here pays anybody. It makes entries, pending, on the time card,
 * for an administrator or senior manager to approve like everything else.
 *
 * This is not tax or payroll advice, and the Act is the authority rather
 * than this file. What it does is apply one reading of it consistently, and
 * show the numbers it used.
 */
class BFTD_Stat_Pay {

	/** The Act's two thresholds. */
	const WINDOW_DAYS = 30;
	const NEEDS_DAYS  = 15;

	/** And the hour past which a worked holiday pays double rather than half again. */
	const DOUBLE_AFTER_HOURS = 1200;   // hundredths of an hour

	/* ------------------------------------------------------------------ */
	/* One person, one holiday                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * The whole answer for one tutor on one holiday, working included.
	 *
	 * Returns the figures rather than a decision, so the screen, the entry
	 * and anybody checking it are all reading the same arithmetic. Nothing
	 * in here writes.
	 */
	public static function working( $tutor_id, $holiday ) {
		$tutor_id = (int) $tutor_id;
		$from     = BFTD_Time::add_days( $holiday, -self::WINDOW_DAYS );
		$to       = BFTD_Time::add_days( $holiday, -1 );

		$out = array(
			'tutor'     => $tutor_id,
			'holiday'   => $holiday,
			'name'      => BFTD_Stat_Holidays::name_for( $holiday ),
			'from'      => $from,
			'to'        => $to,
			'employee'  => ( BFTD_Pay::EMPLOYEE === BFTD_Pay::employment( $tutor_id ) ),
			'started'   => self::started( $tutor_id ),
			'wages'     => 0,
			'days'      => 0,
			'amount'    => 0,
			'worked'    => 0,     // hundredths of an hour on the day itself
			'extra'     => 0,     // the premium for having worked it
			'undecided' => 0,     // entries in the window nobody has ruled on
			'qualifies' => false,
			'why'       => '',
		);

		if ( ! $out['employee'] ) {
			$out['why'] = 'A contractor. Statutory holiday pay is an entitlement of employees.';
			return $out;
		}

		// Thirty calendar days of service. An unknown start date is not a
		// no: it is an unanswered question, and paying or refusing on the
		// strength of a blank field is worse than saying the field is blank.
		if ( '' === $out['started'] ) {
			$out['why'] = 'No start date on this tutor\'s profile, so the thirty day test cannot be applied.';
			return $out;
		}
		if ( $out['started'] > $from ) {
			$out['why'] = 'Employed since ' . BFTD_Time::day( $out['started'], 'j M Y' )
				. ', which is less than thirty days before the holiday.';
			return $out;
		}

		$window = self::window( $tutor_id, $from, $to );
		$out['wages']     = $window['wages'];
		$out['days']      = count( $window['days'] );
		$out['undecided'] = $window['undecided'];

		if ( $out['days'] < self::NEEDS_DAYS ) {
			$out['why'] = 'Worked or earned wages on ' . $out['days'] . ' of the thirty days before the holiday. '
				. 'The Act asks for ' . self::NEEDS_DAYS . '.';
			return $out;
		}

		$out['qualifies'] = true;
		$out['amount']    = (int) round( $out['wages'] / max( 1, $out['days'] ) );
		$out['why']       = 'Worked ' . $out['days'] . ' of the thirty days, earning '
			. BFTD_Pay::money( $out['wages'] ) . '.';

		// And whether they taught on the day itself.
		$day = self::on_the_day( $tutor_id, $holiday );
		$out['worked'] = $day['hours'];
		$out['extra']  = $day['extra'];

		return $out;
	}

	/**
	 * What was earned, and on how many separate days, in the thirty before.
	 *
	 * Days are counted as distinct dates rather than as entries, because two
	 * lessons on a Tuesday are one day worked. Only entries somebody has
	 * agreed to are counted; the number of undecided ones is reported
	 * alongside, because approving one of them changes both figures and a
	 * calculation run mid-fortnight comes out low with nothing saying why.
	 */
	public static function window( $tutor_id, $from, $to ) {
		$wages = 0;
		$days  = array();
		$open  = 0;

		foreach ( BFTD_Pay::entries_between( $from, $to, $tutor_id ) as $id ) {
			$row = BFTD_Pay::row( $id );
			if ( BFTD_Pay::PENDING === $row['state'] ) { $open++; continue; }
			if ( BFTD_Pay::DECLINED === $row['state'] ) continue;

			// A statutory holiday already paid counts as a day worked and as
			// wages, which is what the Act says and is also why two holidays
			// close together do not each come out lower than the last.
			$wages += $row['amount'];
			$days[ $row['on'] ] = true;
		}

		return array( 'wages' => $wages, 'days' => array_keys( $days ), 'undecided' => $open );
	}

	/**
	 * Hours taught on the holiday itself, and the premium they earn.
	 *
	 * Time and a half up to twelve hours and double time past it. The tutor
	 * already has the ordinary entry for each piece of work, so what is owed
	 * on top is half of it below the twelfth hour and all of it above.
	 */
	public static function on_the_day( $tutor_id, $holiday ) {
		$hours = 0;
		$extra = 0;

		foreach ( BFTD_Pay::entries_between( $holiday, $holiday, $tutor_id ) as $id ) {
			$row = BFTD_Pay::row( $id );
			if ( ! in_array( $row['kind'], array( BFTD_Pay::TAUGHT, BFTD_Pay::DIAGNOSTIC ), true ) ) continue;
			if ( BFTD_Pay::DECLINED === $row['state'] ) continue;

			$len = BFTD_Pay::hours_for( $row['kind'] );
			if ( $len <= 0 ) continue;

			// Where this piece of work sits against the twelfth hour of the
			// day. Split, because one long day can straddle it.
			$before = max( 0, min( $len, self::DOUBLE_AFTER_HOURS - $hours ) );
			$after  = $len - $before;

			$per_hour = $row['amount'] / $len;            // cents per hundredth-hour
			$extra   += (int) round( $per_hour * ( ( $before * 0.5 ) + ( $after * 1.0 ) ) );
			$hours   += $len;
		}

		return array( 'hours' => $hours, 'extra' => $extra );
	}

	/** When this person started, as they told the practice. */
	public static function started( $tutor_id ) {
		$raw = trim( (string) get_user_meta( (int) $tutor_id, BFTD_Pay::META_STARTED, true ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
	}

	/* ------------------------------------------------------------------ */
	/* Putting it on the card                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Work out every statutory holiday in a period, for everybody.
	 *
	 * Run by hand from the time card screen rather than on a schedule. A
	 * calculation that runs itself is a calculation nobody reads, and this
	 * one has a judgement in it that somebody should be looking at when it
	 * is made.
	 *
	 * Safe to run twice. An entry is keyed to the holiday and the person, so
	 * a second run updates what is still pending and leaves alone anything
	 * already decided.
	 */
	public static function run( $period, $tutors ) {
		$made = array( 'paid' => 0, 'refused' => 0, 'skipped' => 0 );
		if ( ! BFTD_Pay_Period::valid( $period ) ) return $made;

		$days = BFTD_Stat_Holidays::between(
			BFTD_Pay_Period::start( $period ),
			BFTD_Pay_Period::end( $period )
		);
		if ( ! $days ) return $made;

		foreach ( $days as $ymd => $name ) {
			foreach ( array_map( 'absint', (array) $tutors ) as $uid ) {
				if ( ! $uid ) continue;
				$w = self::working( $uid, $ymd );

				if ( ! $w['qualifies'] ) {
					$made['refused']++;
					continue;
				}

				self::put( $uid, $ymd, BFTD_Pay::STAT, $w['amount'], $w['why'] );
				$made['paid']++;

				if ( $w['extra'] > 0 ) {
					self::put(
						$uid, $ymd, BFTD_Pay::STAT_EXTRA, $w['extra'],
						'Taught ' . self::hours( $w['worked'] ) . ' on the holiday. '
							. 'Time and a half, and double time past twelve hours.'
					);
					$made['paid']++;
				}
			}
		}

		return $made;
	}

	/** One entry, made or brought up to date. Never a second one. */
	private static function put( $tutor_id, $ymd, $kind, $amount, $note ) {
		$ref   = ( BFTD_Pay::STAT_EXTRA === $kind ? 'statx:' : 'stat:' ) . $ymd . ':' . (int) $tutor_id;
		$facts = array(
			'ref'     => $ref,
			'source'  => 0,
			'tutor'   => (int) $tutor_id,
			'student' => 0,
			'kind'    => $kind,
			'on'      => $ymd,
			'at'      => '',
			'rate'    => (int) $amount,
			'amount'  => (int) $amount,
			'note'    => $note,
		);
		return BFTD_Pay::put( $facts );
	}

	/**
	 * Hundredths of an hour, as a person would say them.
	 *
	 * Kept in integers to the last moment. Dividing first and asking whether
	 * the result is whole means comparing a float to an integer, which is
	 * how "1 hours" gets onto a screen.
	 */
	public static function hours( $hundredths ) {
		$h = (int) $hundredths;
		$n = ( 0 === $h % 100 )
			? (string) intdiv( $h, 100 )
			: rtrim( rtrim( number_format( $h / 100, 2, '.', '' ), '0' ), '.' );
		return $n . ' ' . ( 100 === $h ? 'hour' : 'hours' );
	}
}
