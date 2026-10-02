<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Lessons: the schedule, the bank, and what "next lesson" actually is.
 *
 * THE MODEL, because two things sound like they mean the same and do not.
 * A schedule BOOKS lessons. Recording one USES it. Those are separate events
 * and separate numbers:
 *
 *   Purchased   what the family paid for.
 *   Used        lessons actually taught, counted from the lesson records
 *               themselves. Never typed in, so it cannot drift from reality.
 *   Booked      future occurrences the schedule has reserved but nobody has
 *               taught yet.
 *   Remaining   purchased minus used.
 *
 * Collapsing booked into used would mean a family that cancels on Monday has
 * paid for a lesson nobody gave. Collapsing used into booked would mean a
 * lesson taught off-schedule never counts. Keeping them apart is what makes
 * a cancellation cost nothing and a squeezed-in extra lesson still count.
 *
 * OCCURRENCES ARE GENERATED, NOT STORED. A rule of "Mondays and Wednesdays at
 * four" plus a start date is enough to produce every date on demand. Writing
 * a year of them into the database would be a hundred and fifty rows that go
 * stale the instant somebody moves the regular slot. What IS stored is the
 * short list of deliberate departures from the pattern: this one is cancelled,
 * that one moved to Thursday.
 *
 * The generator is filtered, so the GoHighLevel calendar can later add or
 * replace occurrences without any of this changing shape.
 */
class BFTD_Schedule {

	const RULES_KEY  = '_bftd_schedule_rules';
	const EXCEPT_KEY = '_bftd_schedule_exceptions';
	const OFFSET_KEY = '_bftd_lessons_used_before';

	/** How far ahead the generator will ever look. */
	const HORIZON_DAYS = 400;

	public static function init() {
		add_action( 'wp_ajax_bftd_schedule_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_bftd_schedule_except', array( __CLASS__, 'ajax_exception' ) );
		add_action( 'wp_ajax_bftd_schedule_month', array( __CLASS__, 'ajax_month' ) );
	}

	public static function weekdays() {
		return array(
			'1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday',
			'5' => 'Friday', '6' => 'Saturday', '7' => 'Sunday',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rules                                                               */
	/* ------------------------------------------------------------------ */

	public static function rules( $student_id ) {
		$rules = get_post_meta( $student_id, self::RULES_KEY, true );
		return is_array( $rules ) ? $rules : array();
	}

	public static function save_rules( $student_id, $raw ) {
		$out = array();
		foreach ( (array) $raw as $row ) {
			if ( ! is_array( $row ) ) continue;

			$day = isset( $row['day'] ) ? (string) absint( $row['day'] ) : '';
			if ( ! array_key_exists( $day, self::weekdays() ) ) continue;

			$time = isset( $row['time'] ) ? sanitize_text_field( $row['time'] ) : '';
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) continue;

			$out[] = array(
				'id'       => isset( $row['id'] ) && $row['id'] ? sanitize_text_field( $row['id'] ) : 'r' . wp_generate_password( 8, false, false ),
				'day'      => $day,
				'time'     => $time,
				'minutes'  => max( 15, (int) ( isset( $row['minutes'] ) ? $row['minutes'] : 50 ) ),
				'from'     => self::clean_date( isset( $row['from'] ) ? $row['from'] : '' ),
				'tutor'    => absint( isset( $row['tutor'] ) ? $row['tutor'] : 0 ),
			);
		}

		// Nobody teaches more than a day holds. Slots that would break the
		// ceiling are turned away rather than quietly accepted, and the
		// person saving is told which one and why.
		$split = self::split_by_cap( $student_id, $out );
		$out   = $split['kept'];
		if ( $split['rejected'] ) {
			$said = array();
			foreach ( $split['rejected'] as $r ) $said[] = self::explain_rejection( $r );
			set_transient( 'bftd_sched_warn_' . get_current_user_id(), $said, 60 );
		}

		$before = self::rules( $student_id );
		update_post_meta( $student_id, self::RULES_KEY, $out );

		if ( maybe_serialize( $before ) !== maybe_serialize( $out ) ) {
			BFTD_Audit::log( 'schedule_changed', array(
				'post_id'    => $student_id,
				'student_id' => $student_id,
				'summary'    => 'The session schedule changed: ' . ( $out ? self::describe( $out ) : 'no regular slots' ) . '.',
			) );
			return true;
		}
		return false;
	}

	private static function clean_date( $d ) {
		$d = sanitize_text_field( (string) $d );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	}

	/** "Mondays and Wednesdays at 4:00 PM" — for logs and for the family. */
	public static function describe( $rules ) {
		if ( ! $rules ) return '';
		$days = self::weekdays();
		$bits = array();
		foreach ( $rules as $r ) {
			$bits[] = $days[ $r['day'] ] . 's at ' . self::pretty_time( $r['time'] );
		}
		$bits = array_unique( $bits );
		if ( 1 === count( $bits ) ) return $bits[0];
		$last = array_pop( $bits );
		return implode( ', ', $bits ) . ' and ' . $last;
	}

	public static function pretty_time( $hhmm ) {
		// "Four o'clock" is a time of day, not a moment in history, so it is
		// formatted without a date and without a timezone. Putting it through
		// a timestamp is what turns a four o'clock lesson into an eight in
		// the morning one the day the site stops being on UTC.
		return BFTD_Time::time_of_day( $hhmm );
	}

	/* ------------------------------------------------------------------ */
	/* Exceptions                                                          */
	/* ------------------------------------------------------------------ */

	public static function exceptions( $student_id ) {
		$x = get_post_meta( $student_id, self::EXCEPT_KEY, true );
		return is_array( $x ) ? $x : array();
	}

	/**
	 * Cancel or move one occurrence. Keyed by the original date and time, so
	 * a rule change never orphans an exception into the wrong slot.
	 */
	public static function set_exception( $student_id, $key, $action, $to_date = '', $to_time = '', $reason = '' ) {
		$all = self::exceptions( $student_id );

		// A move is a booking like any other, so it meets the same two rules:
		// the day has a ceiling, and the tutor cannot be in two places at
		// once. The clash is checked first because it is the absolute one.
		if ( 'move' === $action ) {
			$to = self::clean_date( $to_date );
			if ( $to ) {
				$slot  = self::occurrence_at( $student_id, $key );
				$tutor = $slot ? (int) $slot['tutor'] : 0;
				$time  = preg_match( '/^\d{2}:\d{2}$/', (string) $to_time ) ? $to_time : ( $slot ? $slot['time'] : '' );
				$mins  = $slot ? (int) $slot['minutes'] : 50;

				if ( $tutor && $time ) {
					$u    = get_userdata( $tutor );
					$who  = $u ? $u->display_name : 'That tutor';
					$when = BFTD_Time::day( $to );

					$clash = self::clash_at( $tutor, $to, $time, $mins, $student_id );
					if ( $clash ) {
						$with = '';
						if ( ! empty( $clash['student'] ) ) {
							$title = get_the_title( $clash['student'] );
							if ( $title ) $with = ' with ' . $title;
						}
						return new WP_Error( 'bftd_clash', sprintf(
							'%s is already teaching a session%s at %s on %s, and a tutor cannot take two at once. Pick another time or another date.',
							$who, $with, self::pretty_time( $clash['time'] ), $when
						) );
					}

					if ( self::day_is_full( $tutor, $to, $student_id ) ) {
						return new WP_Error( 'bftd_day_full', sprintf(
							'%s already has %d sessions on %s, which is a full day. Pick another date, or hand this session to another tutor.',
							$who, self::daily_cap(), $when
						) );
					}
				}
			}
		}

		if ( 'clear' === $action ) {
			unset( $all[ $key ] );
		} else {
			$all[ $key ] = array(
				'action'  => 'move' === $action ? 'move' : 'cancel',
				'date'    => self::clean_date( $to_date ),
				'time'    => preg_match( '/^\d{2}:\d{2}$/', (string) $to_time ) ? $to_time : '',
				'reason'  => sanitize_text_field( $reason ),
				'by'      => get_current_user_id(),
				'at'      => BFTD_Time::mysql(),
			);
		}

		update_post_meta( $student_id, self::EXCEPT_KEY, $all );

		BFTD_Audit::log( 'lesson_rescheduled', array(
			'post_id'    => $student_id,
			'student_id' => $student_id,
			'summary'    => 'clear' === $action
				? 'A change to the ' . $key . ' session was undone.'
				: ( 'move' === $action
					? 'The ' . $key . ' session was rescheduled to ' . $to_date . ' ' . $to_time . '.'
					: 'The ' . $key . ' session was cancelled.' ),
		) );

		return true;
	}

	/** The occurrence behind a key, with its tutor and how long it runs. */
	public static function occurrence_at( $student_id, $key ) {
		foreach ( self::occurrences( $student_id, BFTD_Time::add_days( BFTD_Time::today(), -365 ), '', 800 ) as $o ) {
			if ( $o['key'] === $key ) return $o;
		}
		return null;
	}

	/** Which tutor owns the occurrence behind this key. */
	public static function tutor_for_key( $student_id, $key ) {
		$o = self::occurrence_at( $student_id, $key );
		return $o ? (int) $o['tutor'] : 0;
	}


	/* ------------------------------------------------------------------ */
	/* How much a tutor is carrying                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Two separate rules, and they fail in different ways.
	 *
	 * A DAY HAS A CEILING. Ten lessons is a long day; past it the day stops
	 * being teachable and a booking over the top is a promise the agency
	 * cannot keep.
	 *
	 * A TUTOR CANNOT BE IN TWO PLACES AT ONCE. This one is absolute and has
	 * nothing to do with how many lessons the day holds: the second lesson at
	 * four o'clock is impossible whether it is the tutor's second of the day
	 * or their tenth. It is also the one a schedule breaks by accident, since
	 * nobody types a clash in on purpose — it happens when a new family is
	 * offered a slot that another child already has.
	 *
	 * Both are checked when a schedule is saved and when a lesson is moved,
	 * which are the only two ways a tutor's day gains a booking.
	 */
	const DAILY_CAP = 10;

	/** How far ahead the rules are enforced. Past this, capacity is guesswork. */
	const CAP_WINDOW_DAYS = 120;

	public static function daily_cap() {
		return max( 1, (int) apply_filters( 'bftd_tutor_daily_cap', self::DAILY_CAP ) );
	}

	/** Every student who has any schedule at all. */
	public static function scheduled_students() {
		$ids = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::RULES_KEY,
			'no_found_rows'  => true,
		) );
		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Who is actually teaching a slot.
	 *
	 * A slot normally says "whoever is assigned", which is the right default:
	 * it follows the student's tutor without anybody having to keep two
	 * places in step. Naming somebody on the row overrides it, which is how a
	 * sick day, a holiday or a temporary tutor is handled without disturbing
	 * who the student belongs to.
	 *
	 * Resolving it matters for more than display. A slot left on the default
	 * still fills an hour of somebody's Tuesday, so if it were treated as
	 * having no tutor it would escape both the daily ceiling and the clash
	 * check, and the default is what most slots use.
	 */
	public static function effective_tutor( $student_id, $rule_tutor = 0 ) {
		$rule_tutor = (int) $rule_tutor;
		if ( $rule_tutor ) return $rule_tutor;

		$staff = BFTD_CPT::staff_ids( $student_id );
		return $staff ? (int) $staff[0] : 0;
	}

	/** "16:30" as minutes past midnight, which is the only shape overlaps compare in. */
	public static function minutes_of( $hhmm ) {
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', (string) $hhmm, $m ) ) return 0;
		return ( (int) $m[1] * 60 ) + (int) $m[2];
	}

	/**
	 * date => the lessons a tutor already has that day, each as a start and
	 * an end in minutes.
	 *
	 * Counting was enough for the ceiling; the clash rule needs to know when
	 * each lesson runs, so this returns the intervals and the count is taken
	 * from them. Cancelled lessons hold no time, and a lesson moved away
	 * holds its time on the date it moved to, not the one it left.
	 */
	public static function tutor_bookings( $tutor_id, $from, $to, $ignore_student = 0 ) {
		$tutor_id = (int) $tutor_id;
		if ( ! $tutor_id ) return array();

		static $cache = array();
		$ck = $tutor_id . '|' . $from . '|' . $to . '|' . (int) $ignore_student;
		if ( isset( $cache[ $ck ] ) ) return $cache[ $ck ];

		$out = array();
		foreach ( self::scheduled_students() as $sid ) {
			if ( $sid === (int) $ignore_student ) continue;
			foreach ( self::occurrences( $sid, $from, $to, 400 ) as $o ) {
				if ( (int) $o['tutor'] !== $tutor_id ) continue;
				if ( ! in_array( $o['status'], array( 'booked', 'held' ), true ) ) continue;
				$out[ $o['date'] ][] = self::interval( $o['time'], $o['minutes'], $sid );
			}
		}

		$cache[ $ck ] = $out;
		return $out;
	}

	private static function interval( $time, $minutes, $student_id = 0 ) {
		$start = self::minutes_of( $time );
		return array(
			'start'   => $start,
			'end'     => $start + max( 5, (int) $minutes ),
			'time'    => $time,
			'student' => (int) $student_id,
		);
	}

	/** date => how many lessons this tutor has. The ceiling's half of the check. */
	public static function tutor_load( $tutor_id, $from, $to, $ignore_student = 0 ) {
		$out = array();
		foreach ( self::tutor_bookings( $tutor_id, $from, $to, $ignore_student ) as $date => $slots ) {
			$out[ $date ] = count( $slots );
		}
		return $out;
	}

	/** True when adding one more lesson that day would break the ceiling. */
	public static function day_is_full( $tutor_id, $date, $ignore_student = 0 ) {
		if ( ! (int) $tutor_id ) return false;
		$load = self::tutor_load( $tutor_id, $date, $date, $ignore_student );
		$has  = isset( $load[ $date ] ) ? $load[ $date ] : 0;
		return $has >= self::daily_cap();
	}

	/**
	 * Do two lessons run over each other?
	 *
	 * Back to back is not a clash: a lesson ending at 16:50 and one starting
	 * at 16:50 are a normal afternoon, so the comparison is strict at both
	 * ends.
	 */
	private static function intervals_clash( $a, $b ) {
		return ( $a['start'] < $b['end'] ) && ( $b['start'] < $a['end'] );
	}

	/**
	 * The lesson a proposed slot would run over, or false if it is clear.
	 *
	 * $extra carries slots that are not saved yet — the other rows of the
	 * schedule being saved right now — so two new slots at the same time are
	 * caught in the same pass as a clash with somebody else's lesson.
	 */
	public static function clash_at( $tutor_id, $date, $time, $minutes, $ignore_student = 0, $extra = array() ) {
		if ( ! (int) $tutor_id ) return false;

		$mine  = self::interval( $time, $minutes );
		$books = self::tutor_bookings( $tutor_id, $date, $date, $ignore_student );
		$day   = isset( $books[ $date ] ) ? $books[ $date ] : array();

		if ( isset( $extra[ $date ] ) ) $day = array_merge( $day, $extra[ $date ] );

		foreach ( $day as $slot ) {
			if ( self::intervals_clash( $mine, $slot ) ) return $slot;
		}
		return false;
	}

	/**
	 * Split a proposed set of rules into the ones that fit and the ones that
	 * break a rule.
	 *
	 * Rules are taken in the order they were entered, so the slots already on
	 * the screen keep their places and it is the new one that is turned away.
	 * A slot with no tutor picked has nobody to overload and nobody to clash
	 * with, so it always fits.
	 */
	public static function split_by_cap( $student_id, $rules ) {
		$cap  = self::daily_cap();
		$from = BFTD_Time::today();
		$to   = BFTD_Time::add_days( $from, self::CAP_WINDOW_DAYS );

		$exceptions = self::exceptions( $student_id );
		$taught     = self::taught_dates( $student_id );

		$books    = array();   // tutor => date => intervals, as accepted so far
		$kept     = array();
		$rejected = array();

		foreach ( $rules as $rule ) {
			// Works from rules that have not been saved, so it resolves the
			// default itself rather than reading it off an occurrence.
			$tutor = self::effective_tutor( $student_id, isset( $rule['tutor'] ) ? $rule['tutor'] : 0 );

			// Only a student with no tutor at all has nobody to overload.
			if ( ! $tutor ) { $kept[] = $rule; continue; }

			if ( ! isset( $books[ $tutor ] ) ) {
				$books[ $tutor ] = self::tutor_bookings( $tutor, $from, $to, $student_id );
			}

			$adding = array();
			$why    = '';
			$where  = '';
			$other  = null;

			foreach ( self::generate( array( $rule ), $exceptions, $taught, $from, $to, 400 ) as $o ) {
				if ( ! in_array( $o['status'], array( 'booked', 'held' ), true ) ) continue;

				$d   = $o['date'];
				$day = array_merge(
					isset( $books[ $tutor ][ $d ] ) ? $books[ $tutor ][ $d ] : array(),
					isset( $adding[ $d ] ) ? $adding[ $d ] : array()
				);

				// Two places at once is impossible, so it is checked first.
				$mine = self::interval( $o['time'], $o['minutes'], $student_id );
				foreach ( $day as $slot ) {
					if ( self::intervals_clash( $mine, $slot ) ) { $why = 'clash'; $where = $d; $other = $slot; break 2; }
				}

				if ( count( $day ) + 1 > $cap ) { $why = 'full'; $where = $d; break; }

				$adding[ $d ][] = $mine;
			}

			if ( $why ) {
				$rejected[] = array( 'rule' => $rule, 'date' => $where, 'tutor' => $tutor, 'why' => $why, 'other' => $other );
				continue;
			}

			foreach ( $adding as $d => $slots ) {
				$books[ $tutor ][ $d ] = array_merge(
					isset( $books[ $tutor ][ $d ] ) ? $books[ $tutor ][ $d ] : array(),
					$slots
				);
			}
			$kept[] = $rule;
		}

		return array( 'kept' => $kept, 'rejected' => $rejected );
	}

	/** Say plainly which slot was turned away and why. */
	public static function explain_rejection( $r ) {
		$days = self::weekdays();
		$u    = get_userdata( $r['tutor'] );
		$who  = $u ? $u->display_name : 'That tutor';
		$day  = isset( $days[ $r['rule']['day'] ] ) ? $days[ $r['rule']['day'] ] . 's' : 'That slot';
		$time = self::pretty_time( $r['rule']['time'] );
		$date = BFTD_Time::day( $r['date'] );

		if ( isset( $r['why'] ) && 'clash' === $r['why'] ) {
			$with = '';
			if ( ! empty( $r['other']['student'] ) ) {
				$title = get_the_title( $r['other']['student'] );
				if ( $title ) $with = ' with ' . $title;
			}
			return sprintf(
				'%s at %s was not added: it runs over a session%s that %s is already teaching at %s on %s. A tutor cannot take two sessions at once, so pick another time or another tutor.',
				$day, $time, $with, $who,
				self::pretty_time( isset( $r['other']['time'] ) ? $r['other']['time'] : '' ),
				$date
			);
		}

		return sprintf(
			'%s at %s was not added: %s already has %d sessions on %s, which is the most anyone teaches in a day. Pick another tutor or another day.',
			$day, $time, $who, self::daily_cap(), $date
		);
	}

	/* ------------------------------------------------------------------ */
	/* Occurrences                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Every scheduled lesson between two dates, in order, with what happened
	 * to each already folded in.
	 *
	 * Each occurrence: key, date, time, minutes, tutor, status, session_id.
	 * Status is one of held, cancelled, moved, booked.
	 */
	public static function occurrences( $student_id, $from = '', $to = '', $limit = 200 ) {
		$rules = self::rules( $student_id );
		if ( ! $rules ) return array();

		$from = $from ? $from : BFTD_Time::today();
		$to   = $to ? $to : BFTD_Time::add_days( $from, self::HORIZON_DAYS );

		$out = self::generate( $rules, self::exceptions( $student_id ), self::taught_dates( $student_id ), $from, $to, $limit );

		/**
		 * Filter the generated occurrences.
		 *
		 * The seam an external calendar plugs into: GoHighLevel can add its
		 * own bookings, or replace the lot, without anything else here
		 * changing shape.
		 */
		$out = apply_filters( 'bftd_schedule_occurrences', $out, $student_id, $from, $to );

		// A slot left on "whoever is assigned" resolves to that person here,
		// once, so the calendar, the clash check and the daily ceiling all
		// read the same tutor off the same occurrence.
		foreach ( $out as $i => $o ) {
			$out[ $i ]['tutor_named'] = (int) $o['tutor'];
			$out[ $i ]['tutor']       = self::effective_tutor( $student_id, $o['tutor'] );
		}

		return self::within_bank( $student_id, $out );
	}

	/**
	 * A weekly pattern has no end date. What ends it is running out of paid
	 * for lessons, so that is what ends it here.
	 *
	 * Before this, a rule ran to a four hundred day horizon and a family who
	 * bought eight lessons had fifty seven of them booked, which is not a
	 * schedule, it is a guess. Now the pattern produces exactly as many
	 * lessons ahead as have been paid for and stops. Buy ten more and ten
	 * more appear, with no second field to keep in step and no way for the
	 * two to disagree.
	 *
	 * Lessons already taught are history and are never trimmed. Cancelled and
	 * moved markers stay too, because they explain what happened to a date
	 * somebody is looking at.
	 */
	private static function within_bank( $student_id, $occurrences ) {
		$left = self::unspent( $student_id );
		$today = BFTD_Time::today();

		$out  = array();
		$kept = 0;

		foreach ( $occurrences as $o ) {
			$future_booking = ( 'booked' === $o['status'] && $o['date'] >= $today );

			if ( ! $future_booking ) { $out[] = $o; continue; }
			if ( $kept >= $left ) continue;

			$kept++;
			$out[] = $o;
		}

		return $out;
	}

	/**
	 * Lessons paid for and not yet taught.
	 *
	 * Deliberately does not call bank(), because bank() counts booked
	 * lessons and booked lessons are now decided by this number. Working it
	 * out from the two facts it depends on keeps that from becoming a circle.
	 */
	private static function unspent( $student_id ) {
		$purchased = ( null === self::$assume_purchased )
			? (int) BFTD_Fields::get( $student_id, 'student', 'lessons_bank' )
			: (int) self::$assume_purchased;
		$offset    = (int) get_post_meta( $student_id, self::OFFSET_KEY, true );
		$used      = self::used_count( $student_id ) + $offset;
		return max( 0, $purchased - $used );
	}

	/**
	 * A figure typed into the purchased box but not saved yet.
	 *
	 * How many lessons are booked now depends on how many are paid for, so a
	 * preview of "what if they buy twenty" has to reach all the way down into
	 * the generator. Threading an extra argument through every caller of
	 * occurrences() to serve one preview would be worse than saying plainly,
	 * here, that a figure is being assumed for the length of one call.
	 */
	private static $assume_purchased = null;

	public static function assuming_purchased( $purchased, $fn ) {
		$before = self::$assume_purchased;
		self::$assume_purchased = ( null === $purchased ) ? null : max( 0, (int) $purchased );
		try {
			return call_user_func( $fn );
		} finally {
			self::$assume_purchased = $before;
		}
	}

	/**
	 * The generator itself, over rules handed to it rather than rules on a
	 * student. Keeping it separate is what lets the cap check ask "what would
	 * this rule produce" before anything is saved.
	 */
	public static function generate( $rules, $exceptions, $taught, $from, $to, $limit = 200 ) {
		// This loop is deliberately left in UTC. Nothing here is shown to
		// anybody: date strings go in and date strings come out, and both
		// ends of the round trip use the same frame, so a Y-m-d always
		// survives it unchanged. Every value that reaches a screen is
		// formatted through BFTD_Time instead.
		$out    = array();
		$cursor = strtotime( $from . ' 12:00:00 UTC' );
		$end    = strtotime( $to . ' 12:00:00 UTC' );

		while ( $cursor <= $end && count( $out ) < $limit ) {
			$date = gmdate( 'Y-m-d', $cursor );
			$dow  = (string) gmdate( 'N', $cursor );

			foreach ( $rules as $rule ) {
				if ( $rule['day'] !== $dow ) continue;
				if ( $rule['from'] && $date < $rule['from'] ) continue;

				$key  = $date . ' ' . $rule['time'];
				$slot = array(
					'key'     => $key,
					'date'    => $date,
					'time'    => $rule['time'],
					'minutes' => $rule['minutes'],
					'tutor'   => $rule['tutor'],
					'status'  => 'booked',
					'session' => 0,
					'reason'  => '',
				);

				if ( isset( $exceptions[ $key ] ) ) {
					$x = $exceptions[ $key ];
					$slot['reason'] = $x['reason'];
					if ( 'cancel' === $x['action'] ) {
						$slot['status'] = 'cancelled';
					} else {
						$slot['status'] = 'moved';
						$slot['moved_to_date'] = $x['date'];
						$slot['moved_to_time'] = $x['time'];
						// The lesson still happens, on its new date.
						if ( $x['date'] ) {
							$out[] = array_merge( $slot, array(
								'key'    => $x['date'] . ' ' . ( $x['time'] ? $x['time'] : $rule['time'] ),
								'date'   => $x['date'],
								'time'   => $x['time'] ? $x['time'] : $rule['time'],
								'status' => 'booked',
								'from_key' => $key,
							) );
						}
					}
				}

				if ( isset( $taught[ $slot['date'] ] ) ) {
					$slot['status']  = 'held';
					$slot['session'] = $taught[ $slot['date'] ];
				}

				$out[] = $slot;
			}

			$cursor = strtotime( '+1 day', $cursor );
		}

		usort( $out, function ( $a, $b ) {
			return strcmp( $a['date'] . $a['time'], $b['date'] . $b['time'] );
		} );

		return $out;
	}

	/**
	 * Every lesson actually recorded as attended, as post ids.
	 *
	 * Published only, because a draft lesson is a tutor's notes in progress
	 * and must not spend a lesson the family has paid for. Attended only,
	 * because a missed or rescheduled lesson is precisely the one that
	 * should not.
	 */
	public static function taught_sessions( $student_id ) {
		$report = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		if ( ! $report ) return array();

		$out = array();
		foreach ( BFTD_CPT::sessions_for( $report ) as $sid ) {
			$date   = BFTD_Fields::get( $sid, 'session', 'session_date' );
			$status = BFTD_Fields::get( $sid, 'session', 'status' );
			if ( ! $date || 'held' !== $status ) continue;
			$out[ (int) $sid ] = (string) $date;
		}
		return $out;
	}

	/**
	 * How many lessons have been used.
	 *
	 * Counted per lesson RECORD, not per date. taught_dates() below is keyed
	 * by date because the schedule needs to ask "was this slot taught", and
	 * counting that array was the used figure until somebody put two lessons
	 * on one day: a catch-up beside the regular slot, or a double session
	 * before a holiday. Two lessons were taught, one was charged, and the
	 * discrepancy only ever shows up as a family running out later than the
	 * bank says they should.
	 *
	 */
	public static function taught_count( $student_id ) {
		return count( self::taught_sessions( $student_id ) );
	}

	/**
	 * What the bank spends: lessons taught, and lessons cancelled.
	 *
	 * A CANCELLED SESSION COUNTS. The tutor held the hour and nobody else
	 * could have it, which is what the family is buying. There used to be a
	 * "counts against allowance" box on every cancellation, answered by
	 * whoever happened to write it up, and a practice cannot run a policy
	 * that is a different answer on each record.
	 *
	 * A RESCHEDULED ONE DOES NOT. It is the same session on another day, and
	 * it is charged when it happens — charging both would charge twice.
	 *
	 * Where a cancellation should be let off, that is management's to decide
	 * on the student's record, by the purchased figure or the offset, rather
	 * than the tutor's to decide on the session.
	 *
	 * Kept apart from taught_count(), which still means what it says: how
	 * many lessons the child actually had.
	 */
	public static function used_count( $student_id ) {
		$all = self::used_counts( array( $student_id ) );
		return isset( $all[ (int) $student_id ] ) ? (int) $all[ (int) $student_id ] : 0;
	}

	/**
	 * The same figure for a whole screen of students, in one query.
	 *
	 * A management list of a thousand children cannot ask this a thousand
	 * times: used_count() alone is a report lookup, a session lookup and two
	 * meta reads per session, which is a page that takes a minute and a
	 * database that notices.
	 *
	 * One rule, not two. used_count() above is this method with one id in it,
	 * rather than a second implementation that agrees with this one today —
	 * a bulk counter that drifts from the single one is a bank that says a
	 * different number on the list than on the record.
	 *
	 * The shape matches sessions_for(): published sessions carrying this
	 * child's id. A session with no date has not happened, cancelled or
	 * otherwise, so it is not counted either way.
	 */
	public static function used_counts( $student_ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $student_ids ) ) ) );
		$out = array();
		foreach ( $ids as $id ) $out[ $id ] = 0;
		if ( ! $ids ) return $out;

		// One fetch, shared with the management screens, so there is one place
		// that knows how to ask for a set of students' sessions in bulk and
		// only the policy below lives here.
		foreach ( BFTD_CPT::sessions_of( $ids ) as $owner => $sessions ) {
			foreach ( $sessions as $sid ) {
				// Undated is not a session yet, cancelled or otherwise.
				if ( ! BFTD_Fields::get( $sid, 'session', 'session_date' ) ) continue;

				$status = (string) BFTD_Fields::get( $sid, 'session', 'status' );
				if ( 'held' !== $status && 'missed' !== $status ) continue;

				$out[ $owner ]++;
			}
		}
		return $out;
	}

	/**
	 * Published cancellations. Published only, for the same reason attended
	 * is: a draft is a tutor's notes in progress and must not spend an hour
	 * the family has paid for before anybody has decided it did.
	 */
	public static function cancelled_count( $student_id ) {
		$report = BFTD_CPT::report_for( $student_id, BFTD_CPT::PROGRESS );
		if ( ! $report ) return 0;

		$n = 0;
		foreach ( BFTD_CPT::sessions_for( $report ) as $sid ) {
			if ( ! BFTD_Fields::get( $sid, 'session', 'session_date' ) ) continue;
			if ( 'missed' !== BFTD_Fields::get( $sid, 'session', 'status' ) ) continue;
			$n++;
		}
		return $n;
	}

	/**
	 * date => session post id, for marking a scheduled slot as taught.
	 *
	 * Where two lessons fall on one day the later record wins the slot, which
	 * is all this is for: the slot either was taught or was not. Anything
	 * counting lessons wants taught_count().
	 */
	public static function taught_dates( $student_id ) {
		$out = array();
		foreach ( self::taught_sessions( $student_id ) as $sid => $date ) {
			$out[ $date ] = (int) $sid;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* The bank                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Purchased, used, booked, remaining.
	 *
	 * Used is counted from the lesson records rather than typed in, so it can
	 * never disagree with the record of what was taught. The one typed number
	 * is an offset for a student who joined before any of this existed and
	 * already had lessons behind them.
	 */
	/**
	 * @param int|null $purchased_override The figure currently typed into the
	 *                 purchased box, which has not been saved yet. Passing it
	 *                 is what lets the tiles answer while somebody is still
	 *                 typing, instead of after they press update.
	 */
	public static function bank( $student_id, $purchased_override = null ) {
		$purchased = ( null === $purchased_override )
			? (int) BFTD_Fields::get( $student_id, 'student', 'lessons_bank' )
			: max( 0, (int) $purchased_override );
		$offset    = (int) get_post_meta( $student_id, self::OFFSET_KEY, true );
		$used      = self::used_count( $student_id ) + $offset;

		$remaining = max( 0, $purchased - $used );

		// The schedule now stops at the paid for boundary, so booked can
		// never exceed remaining and there is no overbooking to warn about.
		// What is worth knowing instead is the date the bank runs dry, which
		// is when the family needs to be asked about the next block.
		$booked = 0;
		$last   = '';
		$slots  = self::assuming_purchased( $purchased_override, function () use ( $student_id ) {
			return self::occurrences( $student_id, BFTD_Time::today() );
		} );
		foreach ( $slots as $o ) {
			if ( 'booked' !== $o['status'] ) continue;
			$booked++;
			$last = $o['date'];
		}

		return array(
			'purchased'  => $purchased,
			'offset'     => $offset,
			'used'       => $used,
			'booked'     => $booked,
			'remaining'  => $remaining,
			'last_date'  => $last,
			'last_label' => $last ? BFTD_Time::day( $last ) : '',
			// Paid for but with no slot to fall on, because the weekly
			// pattern does not reach that far or there is no pattern at all.
			'unbooked'   => max( 0, $remaining - $booked ),
		);
	}

	/**
	 * The next lesson that has not happened yet. This is what the family sees;
	 * nobody types it, so it cannot be left stale after a reschedule.
	 */
	public static function next_lesson( $student_id ) {
		$today = BFTD_Time::today();
		$now   = BFTD_Time::clock();

		foreach ( self::occurrences( $student_id, $today, '', 60 ) as $o ) {
			if ( 'booked' !== $o['status'] ) continue;
			if ( $o['date'] === $today && $o['time'] < $now ) continue;
			return $o;
		}
		return null;
	}

	/** "Tomorrow, 4:00 PM" — the line on the family home screen. */
	public static function next_lesson_label( $student_id ) {
		$next = self::next_lesson( $student_id );
		if ( ! $next ) return '';

		$today    = BFTD_Time::today();
		$tomorrow = BFTD_Time::add_days( $today, 1 );

		if ( $next['date'] === $today )    $day = 'Today';
		elseif ( $next['date'] === $tomorrow ) $day = 'Tomorrow';
		else $day = BFTD_Time::day( $next['date'], 'l j F' );

		return $day . ', ' . self::pretty_time( $next['time'] );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	private static function guard( $student_id ) {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		$student_id = (int) $student_id;
		if ( ! $student_id || ! current_user_can( 'edit_post', $student_id ) ) {
			wp_send_json_error( array( 'message' => 'Not your student.' ), 403 );
		}
		return $student_id;
	}

	/** Regenerate the preview while the rules are still being typed. */
	public static function ajax_preview() {
		$student_id = self::guard( isset( $_POST['student_id'] ) ? $_POST['student_id'] : 0 );

		// Preview from the posted rules, not the saved ones, so the list
		// updates before anybody presses save.
		$posted = isset( $_POST['rules'] ) ? json_decode( wp_unslash( $_POST['rules'] ), true ) : array();
		$saved  = self::rules( $student_id );

		// The purchased figure may also be mid-edit in the box above, so the
		// preview is told what it currently says rather than reading the
		// saved value and contradicting the screen.
		$purchased = isset( $_POST['purchased'] ) && '' !== $_POST['purchased']
			? (int) $_POST['purchased']
			: null;

		update_post_meta( $student_id, self::RULES_KEY, self::sanitize_preview( $posted ) );
		$html = self::render_occurrence_list( $student_id, 14, $purchased );
		$bank = self::bank( $student_id, $purchased );
		update_post_meta( $student_id, self::RULES_KEY, $saved ); // put it back

		wp_send_json_success( array( 'html' => $html, 'bank' => $bank ) );
	}

	private static function sanitize_preview( $raw ) {
		$out = array();
		foreach ( (array) $raw as $row ) {
			$day  = isset( $row['day'] ) ? (string) absint( $row['day'] ) : '';
			$time = isset( $row['time'] ) ? sanitize_text_field( $row['time'] ) : '';
			if ( ! array_key_exists( $day, self::weekdays() ) ) continue;
			if ( ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) continue;
			$out[] = array(
				'id'      => 'preview',
				'day'     => $day,
				'time'    => $time,
				'minutes' => max( 15, (int) ( isset( $row['minutes'] ) ? $row['minutes'] : 50 ) ),
				'from'    => self::clean_date( isset( $row['from'] ) ? $row['from'] : '' ),
				'tutor'   => absint( isset( $row['tutor'] ) ? $row['tutor'] : 0 ),
			);
		}
		return $out;
	}

	public static function ajax_exception() {
		$student_id = self::guard( isset( $_POST['student_id'] ) ? $_POST['student_id'] : 0 );

		$key    = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$action = isset( $_POST['what'] ) ? sanitize_key( wp_unslash( $_POST['what'] ) ) : '';
		if ( ! $key || ! in_array( $action, array( 'cancel', 'move', 'clear' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Nothing to change there.' ), 400 );
		}

		$done = self::set_exception(
			$student_id, $key, $action,
			isset( $_POST['date'] ) ? wp_unslash( $_POST['date'] ) : '',
			isset( $_POST['time'] ) ? wp_unslash( $_POST['time'] ) : '',
			isset( $_POST['reason'] ) ? wp_unslash( $_POST['reason'] ) : ''
		);

		if ( is_wp_error( $done ) ) {
			wp_send_json_error( array( 'message' => $done->get_error_message() ), 409 );
		}

		$purchased = isset( $_POST['purchased'] ) && '' !== $_POST['purchased']
			? (int) $_POST['purchased']
			: null;

		wp_send_json_success( array(
			'html' => self::render_occurrence_list( $student_id, 14, $purchased ),
			'bank' => self::bank( $student_id, $purchased ),
			'next' => self::next_lesson_label( $student_id ),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* The month grid                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * One month, as a grid, with what is already happening on each day.
	 *
	 * Built for the reschedule picker. Seeing the existing pattern while
	 * choosing a new date is the whole point: a tutor moving Tuesday needs to
	 * know Wednesday is already taken, and a date typed into a box tells them
	 * nothing.
	 *
	 * Weeks start on Monday, because the schedule is described in weekdays and
	 * a Monday-start grid puts a Monday-and-Wednesday pattern in the same two
	 * columns every row.
	 */
	/** The last day of a month, so "next month" can be reached from it. */
	private static function last_of_month( $ym ) {
		$days = (int) BFTD_Time::format( 't', BFTD_Time::stamp( $ym . '-01', '12:00' ) );
		return $ym . '-' . str_pad( (string) $days, 2, '0', STR_PAD_LEFT );
	}

	public static function month( $student_id, $ym = '' ) {
		$ym = preg_match( '/^\d{4}-\d{2}$/', (string) $ym ) ? $ym : BFTD_Time::format( 'Y-m' );

		$first = strtotime( $ym . '-01' );
		$days  = (int) gmdate( 't', $first );
		$last  = $ym . '-' . str_pad( (string) $days, 2, '0', STR_PAD_LEFT );

		// Occurrences for the month, keyed by date.
		$byDate = array();
		foreach ( self::occurrences( $student_id, $ym . '-01', $last, 300 ) as $o ) {
			$byDate[ $o['date'] ][] = $o;
		}

		// Leading blanks so the first of the month lands under its weekday.
		$lead = ( (int) gmdate( 'N', $first ) ) - 1;

		$cells = array();
		for ( $i = 0; $i < $lead; $i++ ) $cells[] = null;

		// How full the tutor's own day already is, so a clash shows on the
		// grid rather than being discovered when the move is refused.
		$tutor = 0;
		foreach ( self::rules( $student_id ) as $r ) {
			if ( ! empty( $r['tutor'] ) ) { $tutor = (int) $r['tutor']; break; }
		}
		$books = $tutor ? self::tutor_bookings( $tutor, $ym . '-01', $last, $student_id ) : array();
		$cap   = self::daily_cap();

		$today = BFTD_Time::today();
		for ( $d = 1; $d <= $days; $d++ ) {
			$date = $ym . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$on   = isset( $byDate[ $date ] ) ? $byDate[ $date ] : array();

			$status = '';
			foreach ( $on as $o ) {
				// A day showing "taught" outranks one showing "booked".
				if ( 'held' === $o['status'] ) { $status = 'held'; break; }
				if ( 'booked' === $o['status'] ) $status = 'booked';
				elseif ( ! $status ) $status = $o['status'];
			}

			$times = array();
			foreach ( $on as $o ) {
				if ( in_array( $o['status'], array( 'cancelled', 'moved' ), true ) ) continue;
				$times[] = self::pretty_time( $o['time'] );
			}

			$slots = isset( $books[ $date ] ) ? $books[ $date ] : array();
			$busy  = count( $slots );

			// The minutes the tutor is already teaching that day, so the
			// dialog can say "that time is taken" while the time is being
			// picked rather than only once it is saved.
			$taken = array();
			foreach ( $slots as $sl ) {
				$taken[] = array( 'start' => $sl['start'], 'end' => $sl['end'], 'time' => self::pretty_time( $sl['time'] ) );
			}

			$cells[] = array(
				'date'   => $date,
				'day'    => $d,
				'status' => $status,
				'times'  => $times,
				'past'   => $date < $today,
				'today'  => $date === $today,
				'busy'   => $busy,
				'full'   => $busy >= $cap,
				'taken'  => $taken,
			);
		}

		while ( count( $cells ) % 7 ) $cells[] = null;

		return array(
			'ym'    => $ym,
			'label' => BFTD_Time::day( $ym . '-01', 'F Y' ),
			'prev'  => BFTD_Time::format( 'Y-m', BFTD_Time::stamp( $ym . '-01', '12:00' ) - 15 * DAY_IN_SECONDS ),
			'next'  => BFTD_Time::format( 'Y-m', BFTD_Time::stamp( self::last_of_month( $ym ), '12:00' ) + 15 * DAY_IN_SECONDS ),
			'cells' => $cells,
			'cap'   => $cap,
			'mins'  => self::move_minutes( $student_id ),
		);
	}

	/** How long this student's lessons run, for the clash preview. */
	private static function move_minutes( $student_id ) {
		foreach ( self::rules( $student_id ) as $r ) {
			if ( ! empty( $r['minutes'] ) ) return (int) $r['minutes'];
		}
		return 50;
	}

	public static function ajax_month() {
		$student_id = self::guard( isset( $_POST['student_id'] ) ? $_POST['student_id'] : 0 );
		$ym = isset( $_POST['ym'] ) ? sanitize_text_field( wp_unslash( $_POST['ym'] ) ) : '';
		wp_send_json_success( self::month( $student_id, $ym ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render_occurrence_list( $student_id, $limit = 14, $assume_purchased = null ) {
		if ( null !== $assume_purchased ) {
			return self::assuming_purchased( $assume_purchased, function () use ( $student_id, $limit ) {
				return self::render_occurrence_list( $student_id, $limit );
			} );
		}

		$list = array_slice( self::occurrences( $student_id, BFTD_Time::today(), '', 120 ), 0, $limit );

		ob_start();
		if ( ! $list ) {
			echo '<p class="description">No sessions scheduled. Add a weekly slot above and the dates appear here.</p>';
			return ob_get_clean();
		}

		echo '<ul class="bftd-occ">';
		foreach ( $list as $o ) {
			$cls = 'is-' . $o['status'];
			echo '<li class="bftd-occ-row ' . esc_attr( $cls ) . '" data-key="' . esc_attr( $o['key'] ) . '">';
			echo '<span class="bftd-occ-date">' . esc_html( BFTD_Time::day( $o['date'], 'D j M' ) ) . '</span>';
			echo '<span class="bftd-occ-time">' . esc_html( self::pretty_time( $o['time'] ) ) . '</span>';

			$labels = array(
				'held'      => 'Taught',
				'cancelled' => 'Cancelled',
				'moved'     => 'Rescheduled',
				'booked'    => 'Booked',
			);
			echo '<span class="bftd-occ-status">' . esc_html( $labels[ $o['status'] ] ) . '</span>';

			if ( $o['reason'] ) echo '<span class="bftd-occ-why">' . esc_html( $o['reason'] ) . '</span>';

			echo '<span class="bftd-occ-do">';
			if ( 'held' === $o['status'] && $o['session'] ) {
				echo '<a href="' . esc_url( (string) get_edit_post_link( $o['session'] ) ) . '">Open the session</a>';
			} elseif ( 'booked' === $o['status'] ) {
				echo '<button type="button" class="button-link bftd-occ-move">Reschedule</button>';
				echo '<button type="button" class="button-link bftd-occ-cancel">Cancel</button>';
			} else {
				echo '<button type="button" class="button-link bftd-occ-undo">Undo</button>';
			}
			echo '</span></li>';
		}
		echo '</ul>';

		return ob_get_clean();
	}
}
