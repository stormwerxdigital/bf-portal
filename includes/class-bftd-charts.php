<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The drawn parts of a progress report.
 *
 * Nothing here is typed in. Every figure comes from a record somebody already
 * keeps — a published diagnostic, a lesson, an activity — so no picture on the
 * report can disagree with the table under it, and nobody maintains the same
 * fact twice.
 *
 * THE JOURNEY is one chart, drawn large, of reading grade level. It is the one
 * number a family asks about, and what it has to show is not a value but a
 * DISTANCE: where the child started, where they are, and the reading level of
 * their own school grade, which is what they are aiming at. A Grade 8 reading
 * at preprimer is not aiming at "better". The gap between those two is the
 * subject of the picture, first as how far there is to go and later as how
 * much of it has been closed.
 *
 * The other five measures are the summary cards further down the page, read
 * off the diagnostic itself. They are counted in five different units and were
 * briefly drawn as five more small charts; what that produced was five pictures
 * a parent scanned past to reach the one they came for.
 *
 * The x axis is the assessments in order, evenly spaced, not a time line. A
 * practice reassesses at activity 70 and activity 140, so the gaps are months
 * of teaching rather than a measured interval, and spacing them by date would
 * say something about rate that nobody measured. The dashed run to the target
 * carries no date at all, because nobody has promised one.
 *
 * THE SPIRAL is a grid rather than a chart, because what it shows is not a
 * magnitude. Every row is a skill, every column a lesson, and the point is the
 * pattern: skills come back long after they were first reached. That reads as
 * a shape, and a shape wants a grid.
 *
 * Everything here refuses to draw when there is nothing to draw. A line
 * through one point is a dot, and an empty frame on a family's report reads as
 * a broken page rather than as a programme that has just started.
 */
class BFTD_Charts {

	/* ------------------------------------------------------------------ */
	/* Where they are, and where they are going                            */
	/* ------------------------------------------------------------------ */

	/* The big chart, in its own units. */
	const JW = 720;
	const JH = 300;
	const JL = 96;
	const JR = 664;
	const JT = 34;
	const JB = 232;

	/**
	 * Reading grade level, from where they started to the grade they are in.
	 *
	 * Drawn large and on its own because it is the one number a family asks
	 * about, and because the thing it has to show is not a value but a
	 * DISTANCE: how far there is to go, and later how much of that has been
	 * closed. A small panel among five others cannot carry that.
	 *
	 * Solid to where they are now, dashed on to the target. The dashed part is
	 * not a prediction and does not claim a date; it is the gap, drawn so a
	 * parent can see it.
	 */
	public static function journey( $report_id ) {
		$levels = BFTD_Schema::reading_levels();
		return self::journey_chart(
			BFTD_Derived::grade_journey( $report_id ),
			array(
				'subject' => 'reading grade level',
				'column'  => 'Reading level',
				'floor'   => reset( $levels ),
				'bottom'  => 1,
				'aim'     => 'the reading level of their own school grade',
			)
		);
	}

	/**
	 * The same chart, for words a minute.
	 *
	 * Its own chart rather than a second line on the reading level one. The
	 * two are counted in units nobody can compare — a level and a rate — so
	 * one pair of axes would mean either a second y scale, which is the
	 * single most misread thing in charting, or indexing both to percentages
	 * of themselves, which throws away the figure a parent came to read.
	 */
	public static function wpm( $report_id ) {
		return self::journey_chart(
			BFTD_Derived::fluency_journey( $report_id ),
			array(
				'subject' => 'words read correctly per minute',
				'column'  => 'Words correct per minute',
				'floor'   => '0 wpm',
				'bottom'  => 0,
				'aim'     => 'the end of year benchmark for their own school grade',
			)
		);
	}

	private static function journey_chart( $j, $about ) {
		if ( ! $j ) return '';

		$points = $j['points'];
		$target = $j['target'];
		$n      = count( $points );
		$last   = $points[ $n - 1 ];
		$first  = $points[0];

		// The scale runs from the bottom of the levels to the target, or to
		// the child if they have already passed it. It does not run to Grade 8
		// for a Grade 3: three quarters of the chart would be empty space
		// above a target they have already been told is theirs.
		$top    = max( $target['rank'], $last['rank'], $first['rank'] );
		$bottom = (int) $about['bottom'];
		if ( $top <= $bottom ) $top = $bottom + 1;

		// The target sits one step in from the right, so the dashed gap is
		// visible even when the latest assessment is the only point.
		$slots = max( 1, $n );
		$x = function ( $i ) use ( $slots ) {
			$t = ( $slots > 1 ) ? $i / $slots : 0;
			return round( self::JL + $t * ( self::JR - self::JL ), 1 );
		};
		$xt = self::JR;
		$y  = function ( $rank ) use ( $top, $bottom ) {
			$t = ( $rank - $bottom ) / ( $top - $bottom );
			$t = max( 0, min( 1, $t ) );
			return round( self::JB - $t * ( self::JB - self::JT ), 1 );
		};

		$reached = $last['rank'] >= $target['rank'];

		$floor  = $about['floor'];

		ob_start();
		?>
		<div class="chartwrap">
			<svg class="chart journey" viewBox="0 0 <?php echo self::JW; ?> <?php echo self::JH; ?>"
				role="img" aria-label="<?php echo esc_attr( self::journey_alt( $points, $target, $reached, $about ) ); ?>">

				<?php
				/*
				 * Two gridlines and two labels: where they started and where
				 * they are going. Every level in between is a line a parent
				 * has to read past to find the two that matter.
				 */
				?>
				<g stroke="var(--chart-grid)" stroke-width="1">
					<line x1="<?php echo self::JL; ?>" y1="<?php echo $y( $bottom ); ?>"
						x2="<?php echo self::JR; ?>" y2="<?php echo $y( $bottom ); ?>"></line>
					<line x1="<?php echo self::JL; ?>" y1="<?php echo $y( $target['rank'] ); ?>"
						x2="<?php echo self::JR; ?>" y2="<?php echo $y( $target['rank'] ); ?>"
						stroke-dasharray="6 5"></line>
				</g>
				<?php
				/*
				 * The bottom of the scale, named once.
				 *
				 * Left off when a labelled point is sitting on it, which is the
				 * common case at the start of a programme: a child reading at
				 * preprimer had "Preprimer" printed twice, once as the axis and
				 * once as their score, a few pixels apart.
				 *
				 * The target's LEVEL is not named here. It is named on its own
				 * marker, where a parent is already looking; what sits down
				 * here under the marker is the word Target, on the same line
				 * as Initial.
				 */
				?>
				<?php if ( $last['rank'] !== $bottom && $first['rank'] !== $bottom ) : ?>
					<g class="journey-floor" text-anchor="end" fill="var(--chart-ink)">
						<text x="<?php echo self::JL - 12; ?>" y="<?php echo $y( $bottom ) + 4; ?>"><?php
							echo esc_html( $floor );
						?></text>
					</g>
				<?php endif; ?>

				<?php
				// Where they are now, on to where they are going. Dashed,
				// because nothing here says when.
				?>
				<line x1="<?php echo $x( $n - 1 ); ?>" y1="<?php echo $y( $last['rank'] ); ?>"
					x2="<?php echo $xt; ?>" y2="<?php echo $y( $target['rank'] ); ?>"
					stroke="var(--chart-goal)" stroke-width="2.5" stroke-dasharray="7 6"
					stroke-linecap="round"></line>

				<?php if ( $n > 1 ) : ?>
					<polyline fill="none" stroke="var(--chart-series)" stroke-width="3"
						stroke-linecap="round" stroke-linejoin="round"
						points="<?php
							$pts = array();
							foreach ( $points as $i => $p ) $pts[] = $x( $i ) . ',' . $y( $p['rank'] );
							echo esc_attr( implode( ' ', $pts ) );
						?>"></polyline>
				<?php endif; ?>

				<g class="pts">
					<?php foreach ( $points as $i => $p ) : ?>
						<circle cx="<?php echo $x( $i ); ?>" cy="<?php echo $y( $p['rank'] ); ?>"
							r="<?php echo ( $i === $n - 1 ) ? '8' : '5.5'; ?>"
							fill="var(--chart-series)" stroke="var(--card)" stroke-width="2.5"
							data-lab="<?php echo esc_attr( $p['when'] . ( $p['ts'] ? ', ' . BFTD_Time::format( 'j M Y', $p['ts'] ) : '' ) ); ?>"
							data-val="<?php echo esc_attr( $p['level'] ); ?>"></circle>
					<?php endforeach; ?>
				</g>

				<?php // The target, hollow, because it has not happened. ?>
				<circle cx="<?php echo $xt; ?>" cy="<?php echo $y( $target['rank'] ); ?>" r="7"
					fill="var(--card)" stroke="var(--chart-goal)" stroke-width="3"></circle>

				<?php
				/*
				 * Three labels and no more: where they started, where they are,
				 * where they are going. A number on every point would turn the
				 * line back into the table underneath it — but leaving the
				 * START unlabelled loses half the story, because "how far they
				 * have come" cannot be read off a line with only its far end
				 * named.
				 *
				 * Dropped when the two would sit on top of each other, which is
				 * the case where they say the same thing anyway.
				 */
				$apart = abs( $y( $first['rank'] ) - $y( $last['rank'] ) ) > 22 || abs( $x( 0 ) - $x( $n - 1 ) ) > 90;
				?>
				<?php if ( $n > 1 && $apart ) : ?>
					<text x="<?php echo $x( 0 ); ?>" y="<?php echo max( 16, $y( $first['rank'] ) - 15 ); ?>"
						text-anchor="start" fill="var(--chart-ink)"><?php echo esc_html( $first['level'] ); ?></text>
				<?php endif; ?>
				<text x="<?php echo $x( $n - 1 ); ?>" y="<?php echo max( 16, $y( $last['rank'] ) - 15 ); ?>"
					text-anchor="middle" fill="var(--chart-series)"
					style="font-weight:700"><?php echo esc_html( $last['level'] ); ?></text>
				<?php
				/*
				 * The level they are aiming at, above the marker, exactly
				 * where the level they started at sits above the first point.
				 * The word "Target" is not here: it is the axis label under
				 * the marker, on the line with Initial, so the two ends of the
				 * chart are written the same way round — what it is, above;
				 * which reading it is, below.
				 *
				 * Left off once they have reached it. The last reading is then
				 * at the same height and nearly the same place, so the two
				 * labels printed over each other — and they said the same
				 * word, because a reading that reached Grade 8 IS Grade 8. The
				 * point's own label names the level; the axis says it was
				 * reached.
				 */
				?>
				<?php if ( ! $reached ) : ?>
					<text x="<?php echo $xt; ?>" y="<?php echo max( 17, $y( $target['rank'] ) - 15 ); ?>"
						text-anchor="end" fill="var(--chart-goal)" class="journey-g"><?php
						echo esc_html( $target['level'] );
					?></text>
				<?php endif; ?>

				<line x1="<?php echo self::JL; ?>" y1="<?php echo self::JB; ?>"
					x2="<?php echo self::JR; ?>" y2="<?php echo self::JB; ?>"
					stroke="var(--chart-grid)" stroke-width="1"></line>

				<?php
				/*
				 * The axis: every reading by name, then the target.
				 *
				 * The gap between the last reading and the target shrinks with
				 * every assessment added, because they share the width. Four
				 * readings and a reached target put "Interim" and "Target
				 * reached" close enough to touch, and a sixth put them on top
				 * of each other. So the last tick gives way — the two are
				 * describing the same moment when the target has been reached,
				 * and reaching it is the news. The reading's own name is in
				 * the table underneath either way.
				 */
				$goal_text = $reached ? 'Target reached' : 'Target';

				// The axis is set in the monospaced face, so its width is
				// countable rather than guessable: one character is almost
				// exactly 7.2px at this size.
				$w        = function ( $t ) { return 7.2 * strlen( (string) $t ); };
				$last_end = $x( $n - 1 ) + ( 0 === $n - 1 ? $w( $last['when'] ) : $w( $last['when'] ) / 2 );
				$crowded  = $last_end + 8 > $xt - $w( $goal_text );
				?>
				<g fill="var(--chart-ink)">
					<?php foreach ( $points as $i => $p ) : ?>
						<?php if ( $crowded && $i === $n - 1 ) continue; ?>
						<?php
						// The two ends of the journey are named in the colours
						// the cards use for where a child is weakest and where
						// they are aiming: the first reading in the focus tone,
						// the target in the strength tone. The readings between
						// them are just readings and stay in the ordinary ink,
						// or the axis turns into a traffic light.
						?>
						<text x="<?php echo $x( $i ); ?>" y="<?php echo self::JB + 22; ?>"
							<?php echo ( 0 === $i ) ? 'class="jt-start"' : ''; ?>
							text-anchor="<?php echo 0 === $i ? 'start' : 'middle'; ?>"><?php
							echo esc_html( $p['when'] );
						?></text>
					<?php endforeach; ?>

					<?php
					// Anchored to the end, because the marker sits at the
					// right-hand edge of the plot and a centred word there
					// hangs off the side of the chart.
					?>
					<text x="<?php echo $xt; ?>" y="<?php echo self::JB + 22; ?>"
						text-anchor="end" class="jt-goal"><?php
						echo esc_html( $goal_text );
					?></text>
				</g>
			</svg>
			<div class="tip" hidden></div>
		</div>

		<div class="legend">
			<i><span class="key"></span>Where <?php echo esc_html( $reached ? 'they got to' : 'they are now' ); ?></i>
			<i><span class="key goal"></span><?php echo esc_html( ucfirst( $about['aim'] ) ); ?></i>
		</div>

		<?php echo self::journey_table( $points, $target, $about ); ?>
		<?php
		return ob_get_clean();
	}

	private static function journey_alt( $points, $target, $reached, $about ) {
		$first = $points[0];
		$last  = $points[ count( $points ) - 1 ];
		$alt   = sprintf(
			'Line chart of %s. %s at the %s assessment%s.',
			$about['subject'], $first['level'], strtolower( $first['when'] ),
			( count( $points ) > 1 ) ? ', ' . $last['level'] . ' at the ' . strtolower( $last['when'] ) : ''
		);
		return $alt . ' The target is ' . $target['level'] . ', which is '
			. ( $reached ? 'reached.' : $about['aim'] . '.' );
	}

	private static function journey_table( $points, $target, $about ) {
		ob_start();
		?>
		<details class="chart-data">
			<summary>See these readings as a table</summary>
			<div class="tablewrap">
				<table>
					<thead><tr><th>Assessment</th><th>Date</th><th><?php echo esc_html( $about['column'] ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $points as $p ) : ?>
							<tr>
								<td><?php echo esc_html( $p['when'] ); ?></td>
								<td><?php echo esc_html( $p['ts'] ? BFTD_Time::format( 'j M Y', $p['ts'] ) : '' ); ?></td>
								<td><?php echo esc_html( $p['level'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php
						// The last row is not another reading, it is where the
						// readings are going. Marked, so a reader scanning the
						// column does not take it for one more measurement —
						// and in the same tone the chart above gives it.
						?>
						<tr class="row-goal">
							<td>Target</td>
							<td></td>
							<td><?php echo esc_html( $target['level'] ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</details>
		<?php
		return ob_get_clean();
	}

	/**
	 * Initial, middle, final, as three cards above the chart.
	 *
	 * The shape of a programme rather than a list of what has happened, so a
	 * milestone still to come is shown too: it is the half of the picture that
	 * says where the family is heading. An interim assessment is not one of
	 * the three and is not here; it is still a point on the chart.
	 */
	public static function milestones( $report_id ) {
		$all = BFTD_Derived::milestones( $report_id );
		if ( ! $all ) return '';

		ob_start();
		?>
		<ol class="miles">
			<?php foreach ( $all as $one ) : ?>
				<li class="mile<?php echo $one['done'] ? ' is-done' : ''; ?>">
					<span class="mile-n"><?php echo esc_html( $one['label'] ); ?></span>
					<span class="mile-w"><?php
						echo esc_html( $one['done']
							? ( $one['ts'] ? BFTD_Time::format( 'j M Y', $one['ts'] ) : 'Done' )
							: 'To come' );
					?></span>
				</li>
			<?php endforeach; ?>
		</ol>
		<?php
		return ob_get_clean();
	}

	/**
	 * The report's icons, in one place.
	 *
	 * Line drawings on a 24 box, stroked rather than filled, inheriting the
	 * colour of whatever they sit in. Kept together so a second copy of the
	 * tick cannot end up a different weight from the first, and so the set
	 * stays small: an icon beside every figure is decoration, and decoration
	 * is what a parent learns to stop reading.
	 */
	/**
	 * How a text's level reads on a family's report.
	 *
	 * The field is free text on purpose: a practice writes "Grade 4" for one
	 * book, "Preprimer" for another and "1" for a levelled reader, and none of
	 * those belong in a fixed list. But a bare "1" on its own beside a title
	 * is not a level, it is a digit — a parent reads it as a count of
	 * something. So a number is given the word that makes it a level, and
	 * anything already written in words is left exactly as the tutor typed it.
	 *
	 * Done at render rather than on save: what somebody typed stays what they
	 * typed, and changing our minds about the wording does not mean going back
	 * through a year of records.
	 */
	public static function text_level_label( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) return '';

		// A bare number, with or without a decimal: 1, 2.5, 12.
		return preg_match( '/^\d+(\.\d+)?$/', $raw ) ? 'Level ' . $raw : $raw;
	}

	/** "May", "May and June", "May, June and July". */
	private static function sentence_list( $items ) {
		$items = array_values( array_filter( $items ) );
		$n     = count( $items );
		if ( ! $n ) return '';
		if ( 1 === $n ) return $items[0];
		$last = array_pop( $items );
		return implode( ', ', $items ) . ' and ' . $last;
	}

	public static function icon( $name ) {
		$paths = array(
			'tick'   => '<circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.6 2.6L16 9.6"/>',
			'moved'  => '<path d="M3.5 9A8.5 8.5 0 0 1 18 5.5M20.5 15A8.5 8.5 0 0 1 6 18.5"/><path d="M18 2v4h-4M6 22v-4h4"/>',
			'cross'  => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
			'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.4l3.4 2"/>',
			'book'   => '<path d="M4 4.5A1.5 1.5 0 0 1 5.5 3H19v18H5.5A1.5 1.5 0 0 1 4 19.5z"/><path d="M8 3v18"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) return '';
		// Not "ic": a resource link already owns that class and it is a
		// 26px pale tile, not a line drawing. Two features, one name, and
		// the one further down the stylesheet wins.
		return '<svg class="bfic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/* ------------------------------------------------------------------ */
	/* Schedule and attendance                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Four figures and, when it matters, a warning.
	 *
	 * Everything on this panel could be counted off the session list further
	 * down the page, and no parent was going to. The one that changes what a
	 * family does is the gap since the last session, and a list sorted newest
	 * first is the one arrangement in which a gap is invisible: the most
	 * recent session sits at the top looking current however old it is.
	 *
	 * The tone matters here. This is the part of a report that can read as a
	 * telling-off, so the figures are stated plainly and the line about one a
	 * week is a standing fact about how reading is learned rather than an
	 * accusation. It is only raised to a warning when there is a real gap.
	 */
	public static function attendance( $report_id ) {
		$a = BFTD_Derived::attendance( $report_id );
		if ( ! $a ) return '';

		$gap   = isset( $a['days_since'] ) ? (int) $a['days_since'] : null;
		$weeks = (int) $a['weeks_missed'];
		$busy  = isset( $a['busy_months'] ) ? (array) $a['busy_months'] : array();

		// Plain words for the gap. "0 days" is a number a person has to turn
		// back into a sentence, and the sentence is "today".
		if ( null === $gap )   $since = array( '—', 'no dated session yet' );
		elseif ( 0 === $gap )  $since = array( 'Today', 'last session' );
		elseif ( 1 === $gap )  $since = array( 'Yesterday', 'last session' );
		else                   $since = array( (string) $gap, 'days since the last session' );

		$tiles = array(
			array( (string) (int) $a['held'], 1 === (int) $a['held'] ? 'session attended' : 'sessions attended', '', 'tick' ),
			array( (string) (int) $a['rescheduled'], 'rescheduled', $busy ? 'warn' : '', 'moved' ),
			array( (string) (int) $a['missed'], 'missed', (int) $a['missed'] ? 'warn' : '', 'cross' ),
			array( $since[0], $since[1], $weeks ? 'warn' : '', 'clock' ),
		);

		ob_start();
		?>
		<div class="attend">
			<?php foreach ( $tiles as $t ) : ?>
				<div class="attend-t<?php echo $t[2] ? ' is-' . esc_attr( $t[2] ) : ''; ?>">
					<span class="attend-i" aria-hidden="true"><?php echo self::icon( $t[3] ); ?></span>
					<span class="attend-n"><?php echo esc_html( $t[0] ); ?></span>
					<span class="attend-l"><?php echo esc_html( $t[1] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		/*
		 * Every session that did not go ahead, one row each, with the reason
		 * in the tutor's own words underneath it.
		 *
		 * It was a six column table further down the report. A table is the
		 * right shape for figures a reader compares down a column, and none
		 * of these are: a family reads one of these rows, about the day they
		 * remember, and what they want from it is the reason and who recorded
		 * it. So the reason gets a line of its own rather than a cell it has
		 * to be squeezed into, and the audit detail sits under it quietly.
		 */
		$miss_rows = BFTD_Derived::missed( $report_id );
		?>
		<?php if ( $miss_rows ) : ?>
			<ul class="miss">
				<?php foreach ( $miss_rows as $m ) :
					$moved = ( 'rescheduled' === $m['status'] );
					$who   = isset( $m['who_name'] ) ? (string) $m['who_name'] : '';
					$stamp = '';
					if ( ! empty( $m['at'] ) ) {
						$ts    = BFTD_Time::from_mysql( $m['at'] );
						$stamp = $ts ? BFTD_Time::format( 'j M Y, g:i a', $ts ) : '';
					}
					$by = '';
					if ( ! empty( $m['by'] ) ) {
						$u  = get_userdata( (int) $m['by'] );
						$by = $u ? $u->display_name : '';
					}
					?>
					<li class="miss-r">
						<span class="miss-ic<?php echo $moved ? ' moved' : ''; ?>" aria-hidden="true"><?php
							echo self::icon( $moved ? 'moved' : 'cross' );
						?></span>
						<span class="miss-b">
							<span class="miss-h">
								<b><?php echo esc_html( $m['ts'] ? BFTD_Time::format( 'j M Y', $m['ts'] ) : '' ); ?></b>
								<span class="miss-tag<?php echo $moved ? ' moved' : ''; ?>"><?php
									echo esc_html( BFTD_Derived::word( 'status', $m['status'] ) );
								?></span>
								<?php if ( $m['number'] ) : ?>
									<span class="miss-n"><?php echo esc_html( 'Session ' . (int) $m['number'] ); ?></span>
								<?php endif; ?>
							</span>
							<?php if ( '' !== trim( (string) $m['reason'] ) ) : ?>
								<span class="miss-why"><?php echo esc_html( $m['reason'] ); ?></span>
							<?php endif; ?>
							<span class="miss-meta"><?php
								$bits = array();
								if ( '' !== $who ) $bits[] = 'Cancelled by ' . $who;
								if ( '' !== $by )  $bits[] = 'marked by ' . $by;
								if ( '' !== $stamp ) $bits[] = 'on ' . $stamp;
								if ( ! empty( $m['made_up'] ) ) $bits[] = 'made up on ' . BFTD_Time::day( $m['made_up'], 'j M Y' );
								// From the status, not from a box. A cancellation
								// spends the hour that was held; a reschedule is
								// the same session on another day.
								$bits[] = $moved
									? 'moved rather than used'
									: 'counts against the sessions remaining';
								echo esc_html( implode( ' · ', $bits ) );
							?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php
		/*
		 * Moved more than once in a month.
		 *
		 * The total says nothing about this: six moves across a year is a
		 * family with a busy year, and two inside one month is a fortnight
		 * with no reading in it. Named by month, because a family can place
		 * the month and act on it, where a running total is just a number
		 * going up.
		 */
		?>
		<?php if ( $busy ) : ?>
			<p class="attend-gap">
				<?php
				$names = array();
				foreach ( $busy as $m ) $names[] = $m['label'] . ' (' . (int) $m['times'] . ')';
				echo esc_html( sprintf(
					'Sessions were moved more than once in %s. Reading is built by coming back to it weekly, so two moves in a month costs more than the two dates.',
					self::sentence_list( $names )
				) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( $weeks ) : ?>
			<p class="attend-gap">
				<?php echo esc_html( sprintf(
					'There have been no sessions for %d weeks. Reading is built by coming back to it, so a gap costs more than the sessions in it.',
					$weeks
				) ); ?>
			</p>
		<?php endif; ?>

		<p class="attend-rule">Students must attend 1 session per week to make progress.</p>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* The reading log                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Every text read, from the activities the lessons used.
	 *
	 * A text is written down once, on the activity that uses it. Reading the
	 * log off those activities is what stops the log and the programme saying
	 * different things about the same lesson.
	 */
	public static function texts( $report_id ) {
		$rows = BFTD_Derived::texts( $report_id );
		if ( ! $rows ) return '';

		/*
		 * A shelf of books rather than four columns of a table.
		 *
		 * The table was the right shape for the wrong thing. A parent does not
		 * read down a column of titles comparing them; they scan for the one
		 * they recognise, and what they want beside it is how hard it was and
		 * when. So each text is a card with a spine down its side, and the
		 * level sits on the spine where a reader is already looking.
		 *
		 * Title, level, date. "Times read" went: it was the count of sessions
		 * a book appeared in, which is a fact about the programme rather than
		 * about the reading, and it is on the session records for anyone who
		 * wants it.
		 */
		ob_start();
		?>
		<ul class="shelf">
			<?php foreach ( $rows as $r ) :
				$ts = $r['date'] ? BFTD_Time::stamp( $r['date'] ) : 0;
				?>
				<li class="bk">
					<span class="bk-ic" aria-hidden="true"><?php echo self::icon( 'book' ); ?></span>
					<span class="bk-b">
						<span class="bk-t"><?php echo esc_html( $r['title'] ); ?></span>
						<span class="bk-m">
							<?php $lv = self::text_level_label( $r['level'] ); ?>
							<?php if ( '' !== $lv ) : ?>
								<span class="bk-lv"><?php echo esc_html( $lv ); ?></span>
							<?php endif; ?>
							<?php if ( $ts ) : ?>
								<span class="bk-d"><?php echo esc_html( BFTD_Time::format( 'j M Y', $ts ) ); ?></span>
							<?php endif; ?>
						</span>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* The spiral: skills against lessons                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * A grid of skills against lessons.
	 *
	 * It used to be a grid of ACTIVITIES against lessons, and that could not
	 * show the thing it exists to show. Two activities that build the same
	 * skill drew two rows, so a skill first reached in week two and practised
	 * again in week nine looked like two unrelated things done once each. The
	 * spiral was in the records and the picture hid it.
	 *
	 * Now the rows are skills, gathered from the activities each lesson used
	 * and from anything the tutor recorded on the lesson itself, and the
	 * pattern a parent is being shown is the pattern that is actually there.
	 */
	/**
	 * How many sessions before the spiral grid is worth drawing.
	 *
	 * Below this the grid is one or two columns: a shape with no spacing in
	 * it, which is the only thing the grid exists to show.
	 */
	const SPIRAL_MIN = 4;

	/**
	 * Every skill this child has reached, as a card each.
	 *
	 * The question a parent opens a progress report to answer is "what has my
	 * child learned", and until now the only answer on the page was a grid of
	 * coloured squares they had to decode first. This says it in words: the
	 * skill, the session it was first reached in, and how often it has come
	 * back since — which is the spiral, stated rather than drawn.
	 *
	 * Built from the same BFTD_Derived::skills payload the grid is built from,
	 * so the two cannot tell a parent different things about the same term.
	 */
	/** How many rows show before the rest are folded away. */
	const SKILL_ROWS = 10;

	/**
	 * What is left, and what is behind them.
	 *
	 * The list used to be the whole library against what had been reached,
	 * which was right when there was one library. There are two tracks now,
	 * each numbered from one, and a student is on one of them at a time and
	 * moves between them. So:
	 *
	 * WHAT IS LEFT comes from the track they are on now, from the number they
	 * started that track at. A child who joined Tracks 2 and 3 at skill ten is
	 * not behind on the nine before it; they were never on them.
	 *
	 * WHAT IS DONE is every track, because a skill learned in Track 1 is still
	 * learned after they move. Dropping it when they move would make a report
	 * say a child had gone backwards.
	 *
	 * WORDWALL IS NOT IN WHAT IS LEFT. Those are words read on sight and they
	 * are optional, so counting them as outstanding would put a number on a
	 * family's report that nobody intends to finish. Done ones still show,
	 * because the child did them.
	 *
	 * And the other track is mentioned rather than listed. A parent reading
	 * Track 1 does not need a hundred and forty things they have not started;
	 * they need to know the next part exists and roughly when it opens.
	 */
	private static function skill_list( $grid, $student_id = 0 ) {
		$lessons = $grid['lessons'];
		$rows    = $grid['rows'];
		$names   = $grid['names'];
		if ( ! $rows ) return '';

		$n = count( $lessons );

		/*
		 * The whole library, not just what has been reached.
		 *
		 * A list of five skills says a child has learned five things. The same
		 * five against the twenty two this practice teaches says where they
		 * are, which is the question a parent is actually asking — and it
		 * shows the ones still ahead, which is the rest of the answer.
		 *
		 * Reached first, in the order they were reached, then the rest in the
		 * library's own order. Putting the library first would open the list
		 * on a run of things nobody has taught yet.
		 */
		$out  = array();
		$seen = array();
		foreach ( $rows as $key => $marks ) {
			ksort( $marks );
			$idx   = array_keys( $marks );
			$first = $idx[0];
			$seen[ (string) $key ] = true;
			$out[] = array(
				'name'    => $names[ $key ],
				'reached' => true,
				'first'   => $first,
				'when'    => isset( $lessons[ $first ]['label'] ) ? $lessons[ $first ]['label'] : '',
				'times'   => count( $idx ),
				'marks'   => $marks,
			);
		}

		$have_skills = class_exists( 'BFTD_Skills' ) && class_exists( 'BFTD_Derived' );
		$track       = ( $have_skills && $student_id ) ? BFTD_Derived::track( $student_id ) : '';

		/*
		 * Skills completed outside this report's own sessions: ticked on the
		 * student, or reached in a session belonging to an earlier report, in
		 * any track. They are history, so they are listed as done with no
		 * session beside them, because this report is not where it happened.
		 */
		$ahead  = array();
		$behind = array();
		if ( $have_skills && $student_id ) {
			$done_ids = BFTD_Skills::done_for_student( $student_id );
			foreach ( BFTD_Skills::all() as $id => $row ) {
				if ( isset( $seen[ (string) $id ] ) ) continue;
				if ( ! empty( $done_ids[ $id ] ) ) {
					$behind[] = array(
						'name'    => $row['number'] ? $row['number'] . '. ' . $row['name'] : $row['name'],
						'reached' => true,
						'earlier' => true,
					);
				}
			}
		}

		if ( $have_skills && '' !== $track ) {
			// What is left: this track, from where they started it, skills
			// rather than Wordwall, and not already done.
			$start    = $student_id ? BFTD_Derived::track_start( $student_id, $track ) : 1;
			$done_ids = $student_id ? BFTD_Skills::done_for_student( $student_id ) : array();
			foreach ( BFTD_Skills::sequence( $track ) as $number => $id ) {
				if ( $number < $start ) continue;
				if ( isset( $seen[ (string) $id ] ) ) continue;
				if ( ! empty( $done_ids[ $id ] ) ) continue;
				$row = BFTD_Skills::all()[ $id ];
				if ( 'skill' !== ( $row['group'] ?? 'skill' ) ) continue;   // Wordwall is optional
				$ahead[] = array(
					'name'    => $number . '. ' . $row['name'],
					'reached' => false,
				);
			}
		} elseif ( $have_skills ) {
			/*
			 * No track chosen yet, so there is no sequence to count against and
			 * nothing is claimed about what is outstanding. The report says what
			 * has been reached and stops there, which is true, rather than
			 * counting a child against a programme nobody has put them on.
			 */
			$ahead = array();
		}

		$out = array_merge( $out, $behind, $ahead );

		$total  = count( $out );
		$folded = max( 0, $total - self::SKILL_ROWS );

		ob_start();
		?>
		<?php
		// No count line above the list: Karl removed it. The section shows
		// the skills and nothing else.
		?>
		<?php
		/*
		 * The other track, said in one line rather than listed.
		 *
		 * A parent working through Track 1 does not want a hundred and forty
		 * things their child has not begun. They want to know the next part
		 * exists and roughly how far off it is, and the honest answer to that is
		 * how much of this track is left, which is a number this page already
		 * knows. No promise about dates, because nothing here knows how fast a
		 * child will go.
		 */
		$left = count( $ahead );
		if ( $have_skills && '' !== $track && $left && count( BFTD_Skills::tracks() ) > 1 ) :
			$others = array();
			foreach ( BFTD_Skills::tracks() as $k => $label ) {
				if ( $k !== $track ) $others[] = $label;
			}
			if ( $others ) :
			?>
			<p class="skl-next"><?php echo esc_html( sprintf(
				'%s %s after the %d remaining %s in %s.',
				implode( ' and ', $others ),
				1 === count( $others ) ? 'comes next,' : 'come next,',
				$left,
				1 === $left ? 'skill' : 'skills',
				BFTD_Skills::track_label( $track )
			) ); ?></p>
			<?php endif;
		endif;
		?>

		<?php
		/*
		 * Every row ships visible and the script folds the tail away, so a
		 * report with scripts blocked, or printed, is the whole library rather
		 * than ten rows and a button that does nothing.
		 */
		?>
		<ul class="skl" data-show="<?php echo (int) self::SKILL_ROWS; ?>">
			<?php foreach ( $out as $i => $c ) : ?>
				<li class="skl-r<?php echo empty( $c['reached'] ) ? ' is-ahead' : ' is-on'; ?><?php
					echo ( $i >= self::SKILL_ROWS ) ? ' skl-more' : '';
				?>">
					<?php
					/*
					 * A checkmark for a skill reached, on its own with no
					 * bullet (Karl asked for this). An empty bullet for one
					 * still ahead. Labelled for a screen reader because a
					 * shape is not a word.
					 */
					?>
					<?php if ( ! empty( $c['reached'] ) ) : ?>
						<span class="skl-mark is-done" aria-hidden="true"><svg viewBox="0 0 16 16" width="16" height="16" focusable="false"><path d="M2.5 8.5l3.5 3.5 7.5-8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
						<span class="screen-reader-text">Done. </span>
					<?php else : ?>
						<span class="skl-mark" aria-hidden="true"></span>
					<?php endif; ?>
					<span class="skl-n"><?php echo esc_html( $c['name'] ); ?></span>
					<?php // Nothing to the right of the name: Karl removed that column. ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $folded ) : ?>
			<button type="button" class="skl-all" aria-expanded="true"
				data-more="Show all skills"
				data-less="Show fewer"><?php
				echo esc_html( 'Show all skills' );
			?></button>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}

	public static function coverage( $report_id ) {
		$grid = BFTD_Derived::skills( $report_id );
		if ( ! $grid ) return '';

		$lessons = $grid['lessons'];
		$rows    = $grid['rows'];
		$names   = $grid['names'];
		$n       = count( $lessons );

		ob_start();

		/*
		 * The list first, the grid after — and the grid only once it has
		 * something to show.
		 *
		 * A spiral needs several sessions before it looks like one. On a first
		 * report the grid was a single column of squares beside a column of
		 * names: all of the furniture of a chart and none of the picture, on
		 * the report a family reads most carefully because it is their first.
		 *
		 * So what is always here is the plain answer to what a parent is
		 * actually asking — what has my child learned — and the spiral appears
		 * underneath it once there are enough sessions for the spacing to be
		 * visible.
		 */
		// Guarded, because a fixture may render this section without the whole
		// plugin loaded, and a chart is not the place to insist on it.
		$student = class_exists( 'BFTD_CPT' ) ? (int) BFTD_CPT::student_id( $report_id ) : 0;
		echo self::skill_list( $grid, $student );
		if ( $n < self::SPIRAL_MIN ) return ob_get_clean();
		?>
		<div class="howto">
			<span><b>How to read this:</b> each row is one skill and each column is one session.
			The orange square is the session it was first reached; every pale square after it is a
			session it came back in.</span>
		</div>

		<div class="cov">
			<div class="cov-scroll">
				<table class="covtab">
					<thead>
						<tr>
							<th class="cn">Skill</th>
							<?php foreach ( $lessons as $i => $lesson ) : ?>
								<th title="<?php echo esc_attr( $lesson['label'] ); ?>"><span><?php echo (int) ( $i + 1 ); ?></span></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $key => $marks ) : ?>
							<tr>
								<th class="cn"><?php echo esc_html( $names[ $key ] ); ?></th>
								<?php foreach ( $lessons as $i => $lesson ) :
									$mark = isset( $marks[ $i ] ) ? $marks[ $i ] : '';
									?>
									<td>
										<?php if ( 'new' === $mark ) : ?>
											<i class="cell new" title="<?php
												echo esc_attr( 'First reached in session ' . ( $i + 1 ) . ', ' . $lesson['label'] );
											?>">N</i>
										<?php elseif ( 'again' === $mark ) : ?>
											<i class="cell" title="<?php
												echo esc_attr( 'Practised again in session ' . ( $i + 1 ) . ', ' . $lesson['label'] );
											?>"></i>
										<?php endif; ?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<p class="cov-key">
				<span><i class="cell new">N</i> first time it was reached</span>
				<span><i class="cell"></i> practised again</span>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
