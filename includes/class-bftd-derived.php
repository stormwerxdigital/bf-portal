<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The parts of a progress report nobody types.
 *
 * Four tables on that report used to be typed twice. A lesson was marked
 * missed on the lesson, and then the same cancellation was typed again into a
 * table on the report. A text was listed on the activity that uses it, and
 * then typed again into the reading log. The skills an activity teaches were
 * written into the activity, and the grid underneath was built from activity
 * names instead. Every one of those pairs drifted, and the copy a family read
 * was always the stale one, because it was the one nobody was looking at while
 * they worked.
 *
 * So they are derived here, from the records that already hold the answer, and
 * the second copy is gone. Nothing in this class stores anything: every method
 * is a question asked of the lessons, and the answer changes the moment a
 * lesson does.
 *
 * WHY ONE CLASS. Each of these is read in three places — the family's report,
 * the tutor's screen, and the pill that says whether a section has anything in
 * it. Three call sites is exactly how many it takes for two definitions to
 * appear, so there is one.
 */
class BFTD_Derived {

	/**
	 * A stand-in set of answers, for the sample reports.
	 *
	 * The samples exist so a family or a new tutor can see a finished report
	 * without one having been written, and these four tables are most of what
	 * a finished progress report is. They have no records behind them, so they
	 * are answered from a fixture instead — the same way BFTD_Fields already
	 * answers a sample's stored fields.
	 *
	 * Never set on a real request. The preview switches it on around the
	 * sample and clears it immediately after.
	 */
	private static $fixture = null;

	public static function use_fixture( $data ) {
		self::$fixture = is_array( $data ) ? $data : null;
	}

	public static function clear_fixture() {
		self::$fixture = null;
	}

	/**
	 * A fixture answers only for the keys it actually carries.
	 *
	 * It used to answer for all of them: a mounted fixture missing a key
	 * returned an empty table rather than letting the real derivation run. The
	 * reading log is built from the lessons now, and the sample carries those
	 * lessons, so the sample was in the one position where the real derivation
	 * would have worked — and the blanket empty was what stopped it. The sample
	 * report showed no reading log at all, which is the opposite of what a
	 * sample is for.
	 *
	 * So: key present, use it; key absent, derive it like any other report. The
	 * sample supplies what it cannot build and builds what it can, and the
	 * parts it builds are the real code rather than a drawing of it.
	 */
	private static function fixture( $key ) {
		if ( null === self::$fixture ) return null;
		return array_key_exists( $key, self::$fixture ) ? self::$fixture[ $key ] : null;
	}

	/**
	 * Which progress-report sections are built rather than filled in.
	 *
	 * The pill on a tutor's screen reads stored fields to decide whether a
	 * section is empty. These sections have none, so it called every one of
	 * them empty and told a tutor their family could not see a table the
	 * family could plainly see. It asks here instead.
	 */
	public static function sections() {
		return array(
			'sec-texts-read', 'sec-activity-map',
			'sec-progress-overview', 'sec-sessions', 'sec-attendance',
			'sec-fluency',
		);
	}

	public static function is_derived( $section_id ) {
		return in_array( $section_id, self::sections(), true );
	}

	/** Does a derived section have anything to show for this report? */
	public static function has( $section_id, $report_id ) {
		switch ( $section_id ) {
			case 'sec-fluency':
				return (bool) self::fluency_journey( $report_id );
			case 'sec-texts-read':
				return (bool) self::texts( $report_id );
			case 'sec-activity-map':
				return (bool) self::skills( $report_id );
			case 'sec-progress-overview':
				// The journey alone is worth the section: a chart showing where a
				// child is and where they are going is a report before there are
				// two assessments to draw a line between.
				return (bool) self::grade_journey( $report_id ) || (bool) self::milestones( $report_id );
			case 'sec-attendance':
				return (bool) self::attendance( $report_id );
			case 'sec-sessions':
				// "Every lesson" is the lessons. It is not a section anybody
				// fills in, and it was being called empty on reports with a
				// term's worth of them underneath.
				return (bool) BFTD_CPT::sessions_for( $report_id );
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Missed lessons                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Every lesson that did not go ahead, from the lessons themselves.
	 *
	 * Rescheduled and missed are both here, and they are not the same thing
	 * to a family: one was moved and one was lost. The row says which, so the
	 * table can too.
	 *
	 * Oldest first, the way the rest of the report reads.
	 */
	public static function missed( $report_id ) {
		$pre = self::fixture( 'missed' );
		if ( null !== $pre ) return (array) $pre;

		$out = array();
		$n   = 0;
		foreach ( (array) BFTD_CPT::sessions_in_order( $report_id ) as $sid ) {
			// The session's place in the sequence, counted off the same walk
			// rather than asked of BFTD_CPT per row. That method starts from
			// the session and finds its way back to the report, which is two
			// lookups a row for a number this loop is already holding, and
			// which answers nothing at all on a sample.
			$n++;

			$status = (string) BFTD_Fields::get( $sid, 'session', 'status' );
			if ( 'missed' !== $status && 'rescheduled' !== $status ) continue;

			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			$out[] = array(
				'id'      => (int) $sid,
				'status'  => $status,
				'date'    => $date,
				'ts'      => $date ? BFTD_Time::stamp( $date ) : 0,
				'who'     => (string) BFTD_Fields::get( $sid, 'session', 'missed_by' ),
				// Resolved here rather than at the drawing, which would make
				// every screen that prints a missed row reach for the student
				// and agree about how a name is written.
				'who_name' => BFTD_CPT::cancelled_by_label(
					(string) BFTD_Fields::get( $sid, 'session', 'missed_by' ),
					BFTD_CPT::student_id( $sid )
				),
				'reason'  => (string) BFTD_Fields::get( $sid, 'session', 'missed_why' ),
				// Not a box anybody answers. A cancellation spends the hour
				// and a reschedule moves it, so the status already says this
				// and a second field could only disagree with it.
				'counted' => ( 'missed' === $status ) ? 'yes' : 'no',
				'made_up' => (string) BFTD_Fields::get( $sid, 'session', 'missed_madeup' ),

				// Who marked it, and when they did. Stamped on save rather
				// than typed, so it is a record of what happened rather than
				// of what somebody remembered when they got round to it.
				'by'      => (int) BFTD_Fields::get( $sid, 'session', 'missed_marked_by' ),
				'at'      => (string) BFTD_Fields::get( $sid, 'session', 'missed_marked_at' ),
				'number'  => $n,
			);
		}
		return $out;
	}

	/**
	 * The same journey, in words a minute.
	 *
	 * Reading level and reading speed are the two figures this practice moves,
	 * and they move at different rates: a child can gain thirty words a minute
	 * inside one reading level, which the level chart cannot show at all. So
	 * there are two charts, each on its own scale, rather than one chart with
	 * two y axes — which is the same figure told twice in units nobody can
	 * compare.
	 *
	 * The target is the benchmark for the grade the child is in, not the band
	 * a tutor chose on the diagnostic. See BFTD_Schema::grade_target_wcpm.
	 */
	public static function fluency_journey( $report_id ) {
		$pre = self::fixture( 'fluency' );
		if ( null !== $pre ) return (array) $pre;

		$student = BFTD_CPT::student_id( $report_id );
		if ( ! $student ) return array();

		$target = BFTD_Schema::grade_target_wcpm(
			BFTD_Fields::get( $student, 'student', 'grade' )
		);
		if ( ! $target ) return array();

		$points = array();
		foreach ( BFTD_CPT::diagnostics_for( $student ) as $one ) {
			/*
			 * The figure the card reports, read the way the card reads it.
			 *
			 * Correct words per minute is DERIVED — the passage reading less
			 * its errors, or the Kindergarten word list where that was used
			 * instead — so there is nothing stored under it. Asking
			 * BFTD_Fields::get for the bare key returns an empty box, every
			 * point is dropped as untimed, and the chart does not draw at all.
			 * It did not, on the first real report it met, and nothing
			 * errored: the section simply was not there.
			 *
			 * get_section walks the schema and applies the derivation, which
			 * is how every other reader of this figure gets it.
			 */
			$dx   = BFTD_Fields::get_section( $one['id'], 'dxsec-4' );
			$wcpm = isset( $dx['score'] ) ? (int) $dx['score'] : 0;
			if ( $wcpm <= 0 ) continue;   // not timed on this one

			$kind = self::kind_of( $one['id'] );
			$points[] = array(
				'report' => $one['id'],
				'kind'   => $kind,
				'when'   => BFTD_Schema::assessment_kind_label( $kind ),
				'on'     => $one['on'],
				'ts'     => $one['ts'],
				'rank'   => $wcpm,
				'level'  => $wcpm . ' wpm',
			);
		}
		if ( ! $points ) return array();

		return array(
			'points' => $points,
			'target' => array( 'rank' => $target, 'level' => $target . ' wpm' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Attendance                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * How the programme is actually being attended.
	 *
	 * Counted off the session records, never stored, for the same reason
	 * everything else here is: a figure a tutor has to keep in step by hand is
	 * a figure that is wrong by the second month.
	 *
	 * The one that matters is the gap. A child who attends every week moves;
	 * a child who attends fortnightly spends half of each session getting back
	 * to where they were. A family cannot act on that unless somebody says it,
	 * and nobody was: the report showed what had happened and never that
	 * nothing had happened lately.
	 *
	 * "Since" is measured from the last session ATTENDED, not the last one
	 * recorded, because a run of cancellations is precisely the case worth
	 * showing. Sessions dated in the future are not attendance yet and are
	 * left out of every figure here.
	 */
	public static function attendance( $report_id ) {
		$pre = self::fixture( 'attendance' );
		if ( null !== $pre ) return (array) $pre;

		// Midnight today, in the practice's own timezone, so "days since" is
		// whole calendar days rather than a number of hours rounded down, and
		// a session recorded for today counts as attended today.
		$now  = BFTD_Time::stamp( BFTD_Time::today() );
		$held = 0;
		$resc = 0;
		$miss = 0;
		$last = 0;
		$first = 0;

		// Reschedules by calendar month.
		//
		// The total is not the thing: six moves across a year is a family
		// with a busy year, and two moves inside one month is a fortnight
		// with no reading in it. A programme built on coming back weekly
		// cannot survive the second, and the report said nothing about it
		// because a running total cannot show when something happened.
		$by_month = array();

		foreach ( (array) BFTD_CPT::sessions_in_order( $report_id ) as $sid ) {
			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			$ts   = $date ? BFTD_Time::stamp( $date ) : 0;
			if ( $ts && $ts > $now ) continue;

			$status = (string) BFTD_Fields::get( $sid, 'session', 'status' );
			if ( 'rescheduled' === $status ) {
				$resc++;
				if ( $ts ) {
					$key = BFTD_Time::format( 'Y-m', $ts );
					$by_month[ $key ] = isset( $by_month[ $key ] ) ? $by_month[ $key ] + 1 : 1;
				}
				continue;
			}
			if ( 'missed' === $status )      { $miss++; continue; }

			$held++;
			if ( $ts ) {
				if ( ! $first || $ts < $first ) $first = $ts;
				if ( $ts > $last ) $last = $ts;
			}
		}

		if ( ! $held && ! $resc && ! $miss ) return array();

		$days = $last ? (int) floor( ( $now - $last ) / DAY_IN_SECONDS ) : 0;

		return array(
			'held'        => $held,
			'rescheduled' => $resc,
			'missed'      => $miss,
			'last_ts'     => $last,
			'first_ts'    => $first,
			'days_since'  => $last ? $days : null,

			// Whole weeks with no session, and only once the gap is longer
			// than a week. One a week is the schedule, so seven days is on
			// time rather than a warning: an alarm that is always on is not
			// an alarm, and a family told off for attending weekly stops
			// reading the section.
			'weeks_missed' => ( $last && $days > 7 ) ? (int) floor( $days / 7 ) : 0,

			// Every month, and the ones that went past one. More than one move
			// in a month is the pattern worth naming; one is a life happening.
			'resched_months' => $by_month,
			'busy_months'    => self::crowded_months( $by_month ),
		);
	}

	/**
	 * The months a session was moved more than once, newest first.
	 *
	 * One move in a month is a life happening. Two is a fortnight with no
	 * reading in it, which costs a child more than the two dates: a skill met
	 * once is recognised and a skill met again a week later is owned, and the
	 * second half of that does not happen.
	 */
	private static function crowded_months( $by_month ) {
		$out = array();
		foreach ( $by_month as $key => $n ) {
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

	/** The words a family reads for a stored answer. One list, three readers. */
	public static function words() {
		return array(
			'status'  => array( 'missed' => 'Missed', 'rescheduled' => 'Rescheduled' ),
			// 'who' is a person now, so the words for it live with the
			// people: see BFTD_CPT::cancelled_by_label(), which still answers
			// for the two options this used to offer.
			'counted' => array( 'yes' => 'Yes', 'no' => 'No' ),
		);
	}

	public static function word( $group, $key ) {
		$all = self::words();
		return isset( $all[ $group ][ $key ] ) ? $all[ $group ][ $key ] : '';
	}

	/* ------------------------------------------------------------------ */
	/* The reading log                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Every text read, from the lessons that read them.
	 *
	 * They used to be kept on the ACTIVITY, on the reasoning that an activity
	 * always uses the same texts. It does not: the same activity is run with
	 * whatever the child is reading that week, so a text written onto an
	 * activity turned up in the reading log of every child who had ever done
	 * it, dated to their lesson, whether or not they had read it. A text
	 * belongs to the lesson it was read in, and that is where it is asked for.
	 *
	 * The same text read three times is one line with the first date on it,
	 * not three lines. A parent reading "Frog and Toad, Frog and Toad, Frog
	 * and Toad" learns nothing except that the table is generated.
	 */
	public static function texts( $report_id ) {
		$pre = self::fixture( 'texts' );
		if ( null !== $pre ) return (array) $pre;

		$out = array();
		foreach ( (array) BFTD_CPT::sessions_in_order( $report_id ) as $sid ) {
			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );

			foreach ( (array) BFTD_Fields::get( $sid, 'session', 'texts' ) as $row ) {
				if ( ! is_array( $row ) ) continue;

				// A row with a level and no title is somebody halfway through
				// typing. It is not a book, and a blank line on a family's
				// reading log reads as a fault.
				$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
				if ( '' === $title ) continue;

				$key = strtolower( $title );
				if ( isset( $out[ $key ] ) ) {
					$out[ $key ]['times']++;
					continue;
				}
				$out[ $key ] = array(
					'title' => $title,
					'level' => isset( $row['level'] ) ? trim( (string) $row['level'] ) : '',
					'date'  => $date,
					'times' => 1,
				);
			}
		}
		return array_values( $out );
	}

	/* ------------------------------------------------------------------ */
	/* The spiral                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * The skills grid: what was reached, in which lesson, and which came back.
	 *
	 * Built from skills rather than from activities. The grid is there to show
	 * a parent the spiral — that a thing taught in week two is still being
	 * practised in week nine — and a row per activity could not show that,
	 * because the second activity to teach the same skill drew a second row
	 * instead of a second mark on the first. The spiral was in the data and
	 * the picture hid it.
	 *
	 * Returns lessons (the columns) and rows (skill => lesson index => 'new'
	 * or 'again'), so the drawing has no rule of its own to get wrong.
	 */
	/* ------------------------------------------------------------------ */
	/* Where a student is in the programme                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * The track a student is on, or '' when nobody has placed them yet.
	 *
	 * Deliberately not defaulted to Track 1. A student with no track is a real
	 * state, the one every new record starts in, and a report that silently
	 * counted them against a programme nobody put them on would be confidently
	 * wrong about a child.
	 */
	public static function track( $student_id ) {
		$pre = self::fixture( 'track' );
		if ( null !== $pre ) return (string) $pre;

		$track = (string) BFTD_Fields::get( $student_id, 'student', 'track', array( 'type' => 'select' ) );
		return isset( BFTD_Skills::tracks()[ $track ] ) ? $track : '';
	}

	/**
	 * The skill number a student began a track at. One is the default.
	 *
	 * Each track keeps its own, because somebody joining Tracks 2 and 3 part
	 * way through starts where they joined, and going back to Track 1 should
	 * not forget where they began it.
	 */
	public static function track_start( $student_id, $track ) {
		$pre = self::fixture( 'track_start' );
		if ( null !== $pre ) return (int) $pre;

		if ( ! isset( BFTD_Skills::tracks()[ $track ] ) ) return 1;
		$n = (int) BFTD_Fields::get( $student_id, 'student', 'start_' . $track, array( 'type' => 'number' ) );
		return $n > 0 ? $n : 1;
	}

	public static function skills( $report_id ) {
		$pre = self::fixture( 'skills' );
		if ( null !== $pre ) return (array) $pre;

		$lessons = array();
		foreach ( (array) BFTD_CPT::sessions_in_order( $report_id ) as $sid ) {
			$reached = BFTD_Skills::for_session( $sid );
			if ( ! $reached ) continue;   // a lesson that reached nothing is not a column
			$date = (string) BFTD_Fields::get( $sid, 'session', 'session_date' );
			$ts   = $date ? BFTD_Time::stamp( $date ) : 0;
			$lessons[] = array(
				'id'      => (int) $sid,
				// Never the session's own title. That is a reference the admin
				// uses to tell two records apart, and it now reads "Kaine M
				// · 14 Sep 2026" — a date the column already carries and
				// a name the parent reading it already knows.
				'label'   => $ts ? BFTD_Time::format( 'j M Y', $ts ) : 'Session ' . BFTD_CPT::lesson_number( $sid ),
				'skills'  => $reached,
			);
		}
		if ( ! $lessons ) return array();

		/*
		 * In the order they were first reached, which is the order the child
		 * met them. Alphabetical would scatter the diagonal that makes a
		 * spiral look like one, and two skills first reached in the same
		 * lesson keep the order the tutor recorded them in rather than being
		 * shuffled into alphabetical order inside that column.
		 *
		 * So the rows are built in first-seen order to begin with and never
		 * sorted afterwards: a sort that has to be stable is a sort that will
		 * one day not be.
		 */
		$rows  = array();
		$first = array();
		$name  = array();
		foreach ( $lessons as $i => $lesson ) {
			foreach ( $lesson['skills'] as $sid => $skill ) {
				if ( ! isset( $rows[ $sid ] ) ) {
					$rows[ $sid ]  = array();
					$first[ $sid ] = $i;
					$name[ $sid ]  = $skill['name'];
				}
				$rows[ $sid ][ $i ] = ( $i === $first[ $sid ] ) ? 'new' : 'again';
			}
		}

		return array( 'lessons' => $lessons, 'rows' => $rows, 'names' => $name );
	}

	/* ------------------------------------------------------------------ */
	/* The shape of a programme, and the journey through it                */
	/* ------------------------------------------------------------------ */

	/**
	 * Initial, middle, final: done, or still to come.
	 *
	 * The three cards above the chart. They are the shape of a programme
	 * rather than a list of what has happened, so all three are always
	 * returned. One that has not been done yet is the useful half of the
	 * picture, because it is what the family is heading towards.
	 *
	 * An interim assessment is not one of the three and does not appear here.
	 * It is still a real reading and still a point on the chart.
	 */
	public static function milestones( $report_id ) {
		$pre = self::fixture( 'milestones' );
		if ( null !== $pre ) return (array) $pre;

		$student = BFTD_CPT::student_id( $report_id );
		$done    = array();

		if ( $student ) {
			foreach ( BFTD_CPT::diagnostics_for( $student ) as $one ) {
				$kind = self::kind_of( $one['id'] );
				// The first of each kind. A practice that recorded two finals
				// is describing one ending, and the earlier is the one that
				// marks when it happened.
				if ( ! isset( $done[ $kind ] ) ) $done[ $kind ] = $one;
			}
		}

		$out = array();
		foreach ( BFTD_Schema::milestone_kinds() as $kind ) {
			$out[ $kind ] = array(
				'kind'  => $kind,
				'label' => BFTD_Schema::assessment_kind_label( $kind ),
				'done'  => isset( $done[ $kind ] ),
				'id'    => isset( $done[ $kind ] ) ? $done[ $kind ]['id'] : 0,
				'on'    => isset( $done[ $kind ] ) ? $done[ $kind ]['on'] : '',
				'ts'    => isset( $done[ $kind ] ) ? $done[ $kind ]['ts'] : 0,
			);
		}
		return $out;
	}

	/** Which of the four an assessment is. Anything unrecognised is an initial. */
	public static function kind_of( $assessment_id ) {
		$kind = (string) BFTD_Fields::get( $assessment_id, 'sec-assessment-overview', 'kind' );
		return BFTD_Schema::assessment_kind_label( $kind ) ? $kind : BFTD_Schema::KIND_DEFAULT;
	}

	/**
	 * Reading grade level, from where they started to where they are going.
	 *
	 * The one measure a family asks about, drawn large: every assessment so
	 * far, and then the target, which is the reading level of the child's own
	 * school grade. A Grade 8 reading at preprimer is not aiming at "better",
	 * they are aiming at Grade 8, and the distance between those two is what
	 * the chart exists to show. First as how far there is to go, and later as
	 * how far they have come.
	 *
	 * Nothing is returned where there is no target, because a chart whose
	 * whole point is the distance to somewhere cannot be drawn without the
	 * somewhere. That happens when a student record has no grade on it, and
	 * saying nothing is better than inventing one.
	 */
	public static function grade_journey( $report_id ) {
		$pre = self::fixture( 'journey' );
		if ( null !== $pre ) return (array) $pre;

		$student = BFTD_CPT::student_id( $report_id );
		if ( ! $student ) return array();

		$target_level = BFTD_Schema::grade_target_level(
			BFTD_Fields::get( $student, 'student', 'grade' )
		);
		if ( '' === $target_level ) return array();

		$levels = BFTD_Schema::reading_levels();
		$points = array();
		foreach ( BFTD_CPT::diagnostics_for( $student ) as $one ) {
			$score = BFTD_Fields::get( $one['id'], 'dxsec-3', 'score' );
			$rank  = BFTD_Schema::reading_level_rank( $score );
			if ( ! $rank ) continue;   // not assessed on this one

			$kind = self::kind_of( $one['id'] );
			$points[] = array(
				'report' => $one['id'],
				'kind'   => $kind,
				'when'   => BFTD_Schema::assessment_kind_label( $kind ),
				'on'     => $one['on'],
				'ts'     => $one['ts'],
				'rank'   => $rank,
				'level'  => isset( $levels[ $score ] ) ? $levels[ $score ] : (string) $score,
			);
		}
		if ( ! $points ) return array();

		return array(
			'points' => $points,
			'target' => array(
				'rank'  => BFTD_Schema::reading_level_rank( $target_level ),
				'level' => isset( $levels[ $target_level ] ) ? $levels[ $target_level ] : $target_level,
			),
		);
	}
}
