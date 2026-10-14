<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The single source of truth for how the admin maps onto the parent-facing
 * dashboard.
 *
 * Every field group a tutor fills in on the back end declares, here, exactly
 * which front-end screen and section it renders into, whether parents can see
 * it, and whether it carries a conversation thread. The admin screens, the
 * front-end renderer, the conversation binding, the "changes detected" bar,
 * the audit log and the notification system all read this one registry — so
 * "the admin maps to the front end" is true by construction, not by anyone
 * remembering to update two places.
 *
 * Adding a section to the parent dashboard is a single entry here plus the
 * fields it declares. Nothing else needs to know about it.
 *
 * Field types:
 *   text      one line
 *   textarea  plain multi-line
 *   rich      wp_editor, wp_kses_post on save and again at render
 *   number    numeric, stored as a string, rendered blank when empty
 *   select    one of options[]
 *   date      Y-m-d
 *   media     attachment ID
 *   gallery   array of attachment IDs
 *   rows      repeatable table; columns[] describes each cell
 *   pills     one of options[], rendered as a pill row in the admin
 *
 * A field the tutor leaves empty is never rendered on the parent dashboard.
 * That rule lives in one place (BFTD_Fields::has_value) and applies to every
 * field type, so a half-filled report never shows a parent an empty row.
 */
class BFTD_Schema {

	/** The diagnostic's opening paragraph, as it read before it was editable. */
	const DIAGNOSTIC_INTRO = 'Six areas of reading and writing, so we can see exactly where the gaps are. The cards below are the summary. Everything under them is the full report, section by section, exactly as {tutor} wrote it.';

	const SCREEN_HOME      = 'home';
	const SCREEN_PROGRESS  = 'progress';
	const SCREEN_REPORT    = 'report';
	const SCREEN_RESOURCES = 'resources';
	const SCREEN_SETUP     = 'setup';

	/** Cached, built once per request. */
	private static $sections = null;

	public static function screens() {
		return array(
			self::SCREEN_HOME      => array( 'label' => 'Home',              'post_type' => 'bftd_student' ),
			self::SCREEN_PROGRESS  => array( 'label' => 'Progress report',   'post_type' => 'bftd_progress' ),
			self::SCREEN_REPORT    => array( 'label' => 'Reading diagnostic','post_type' => 'bftd_assessment' ),
			self::SCREEN_RESOURCES => array( 'label' => 'Resources',         'post_type' => 'bftd_resource' ),
			self::SCREEN_SETUP     => array( 'label' => 'Setup',             'post_type' => 'bftd_resource' ),
		);
	}

	/**
	 * The practice's standing wording, and where it appears.
	 *
	 * These paragraphs are the same promise on every report Brilliant Futures
	 * sends. They are shipped with the plugin, changed portal-wide under
	 * Settings, and can still be overridden on one report where a particular
	 * child needs something said differently.
	 *
	 * The list is here rather than in the settings screen because the schema
	 * is what says a field exists. A settings screen that kept its own list
	 * would offer an editor for wording no report reads, the first time a
	 * section was renamed.
	 */
	public static function standing_text() {
		$out = array();
		foreach ( self::sections() as $sid => $section ) {
			foreach ( $section['fields'] as $key => $field ) {
				if ( ! isset( $field['shipped'] ) ) continue;
				$out[ $sid . '.' . $key ] = array(
					'section' => $sid,
					'field'   => $key,
					'label'   => $section['label'] . ': ' . $field['label'],
					'heading' => isset( $field['heading'] ) ? $field['heading'] : '',
					'shipped' => $field['shipped'],
				);
			}
		}
		return $out;
	}

	/**
	 * What a piece of standing wording currently says.
	 *
	 * The practice's own version where somebody has set one, and what the
	 * plugin shipped otherwise. Read through here rather than from the option
	 * directly, so a setting nobody has touched still produces a paragraph
	 * instead of a blank.
	 */
	public static function standing_default( $section_id, $field_key ) {
		$shipped = '';
		$section = self::section( $section_id );
		if ( isset( $section['fields'][ $field_key ]['shipped'] ) ) {
			$shipped = $section['fields'][ $field_key ]['shipped'];
		}
		if ( ! class_exists( 'BFTD_Settings' ) ) return $shipped;

		$ours = BFTD_Settings::standing_text( $section_id . '.' . $field_key );
		return ( '' !== trim( (string) $ours ) ) ? $ours : $shipped;
	}

	/**
	 * The standing explanation of the handwriting task.
	 *
	 * Kept as a method rather than inline so the one place it is written is
	 * findable, and so a future change to the practice's wording is a change
	 * to this and nothing else.
	 */
	public static function alphabet_intro() {
		return '<p>As part of the assessment, the student completed a one minute handwriting task where they were asked to print the lowercase alphabet in order from a to z as quickly and accurately as possible. Simple as it looks, this task reveals how easily the brain can access and produce letters, an ability closely connected to reading fluency, comprehension and overall literacy development.</p>'
			. '<p>When letter formation is automatic, with muscle memory handling each stroke consistently and correctly, it stops competing for cognitive resources, freeing the brain for reading, spelling, writing and comprehension. When it is not, the effects go beyond slow handwriting. Slow, effortful letter production makes reading, spelling and writing harder too.</p>'
			. '<p>In fact, there is a direct correlation between oral reading fluency and printing 40 lowercase letters of the alphabet (a to z plus a to n) in one minute.</p>'
			. '<p>For a more detailed explanation: <a href="https://bftutoring.com/reading-assessments/" target="_blank" rel="noopener">BFTutoring Reading Assessment</a></p>'
			. '<p>Goal of the one minute timing: 40 lowercase letters per minute, a to z plus a to n.</p>'
			. '<p>Students are unassisted by their tutor but are free to recite the alphabet to themselves. While monitoring letter formation, the tutor also assesses posture and pencil grip, which can affect ease of handwriting over an extended period.</p>';
	}

	/**
	 * The standing answer on how the method builds handwriting automaticity.
	 *
	 * Says "the student" throughout for the same reason the introduction
	 * does: it is the same paragraph on a seven year old girl's report and a
	 * forty year old man's.
	 */
	/**
	 * Hearing sounds in words, the standing explanation.
	 *
	 * Practice-wide wording, held at the same level as the alphabet's: it
	 * describes what the task measures and why it matters, which is the same
	 * on every report the practice sends. Editable by a senior manager, and
	 * portal-wide from Settings, never one tutor's to reword on a Tuesday.
	 */
	public static function segmenting_intro() {
		return '<p>During this step of the diagnostic, the student completed a phoneme segmentation task. This activity measures how well a student can hear and break spoken words into their individual sounds. The word cat, for example, separates into three sounds: /k/ /a/ /t/. Being able to hear and say those sounds separately is a critical skill that supports both reading and spelling.</p>'
			. '<p>Phoneme segmentation matters because it lets students blend sounds to read unfamiliar words, and break words apart to spell them accurately. When this skill is weak, children may guess at words instead of decoding them, or make frequent spelling errors because they cannot identify all of the sounds in a word.</p>'
			. '<p>This assessment tells us whether a student can hear and work with sounds independently of letters. Strengthening it often leads to more confident, accurate and fluent reading.</p>';
	}

	public static function segmenting_method() {
		return '<p>Students who struggle with this skill benefit greatly from targeted instruction. Being able to clearly hear and differentiate the individual sounds in spoken words is foundational to becoming a confident, accurate and fluent reader, and it is a skill we can absolutely help a child build.</p>'
			. '<p>Instead of relying on memorisation, which can feel overwhelming given how complex and unpredictable English appears, this approach teaches students to break words into individual sounds and apply practical, flexible strategies for both reading and writing. Those tools give a clear, logical path forward and remove the guesswork from decoding and spelling. Because multiple skills are integrated into each activity, students often make faster, more confident progress.</p>'
			. '<p>Short term memory holds only a few seconds of sound at a time, which is why hearing sounds in sequence has to become effortless rather than deliberate.</p>';
	}

	/**
	 * Reading grade level, the standing explanation and answer.
	 *
	 * Practice-wide wording again. The introduction says what the assessment
	 * is for; the levels table under it says how the result is read, and that
	 * is fixed, so neither is one tutor's to reword.
	 */
	public static function wordreading_intro() {
		return '<p>The San Diego Quick Assessment is used to determine a student&#8217;s approximate reading grade level, which then sets the grade level of the passage used in the oral reading fluency step.</p>';
	}

	public static function wordreading_method() {
		return '<p>It raises word reading fluency, and with it reading grade level, by rapidly strengthening the skills with the greatest effect on real reading: accurate decoding, flexible word solving, comprehension and written language. Rather than moving slowly through levels or rule based sequences, students learn one way of thinking about words and apply it to everything they read.</p>';
	}

	/**
	 * The ten reading levels, in order, and the order is the point.
	 *
	 * One list, used three ways: the choices a tutor picks from, the labels
	 * the report prints, and the position each one holds, which is what the
	 * bar on the card is worked out from. Keeping them as three lists is how
	 * a level ends up meaning one thing on the card and another in the table.
	 */
	public static function reading_levels() {
		$levels = array( 'preprimer' => 'Preprimer', 'primer' => 'Primer' );
		for ( $g = 1; $g <= 8; $g++ ) $levels[ 'grade-' . $g ] = 'Grade ' . $g;
		return $levels;
	}

	/** Where a level sits in that list, counting from one. */
	public static function reading_level_rank( $value ) {
		$i = 1;
		foreach ( array_keys( self::reading_levels() ) as $key ) {
			if ( (string) $key === (string) $value ) return $i;
			$i++;
		}
		return 0;
	}

	/**
	 * Reading a passage: the published end-of-year fluency norms.
	 *
	 * One list again, used four ways: the choices a tutor picks from, the
	 * reference table a parent reads, the figure the bar is worked out
	 * against, and the line printed beside that bar. Two of the ten bands have
	 * no number at all, and that is a fact about the norms rather than a gap
	 * to fill in, so it is carried here rather than worked around at each
	 * place that asks.
	 *
	 * Source: easyCBM, words correct per minute, 50th percentile, spring.
	 */
	public static function fluency_norms() {
		return array(
			'k'        => array( 'label' => 'Kindergarten', 'wcpm' => null,
			                     'note'  => 'No oral reading fluency norm. Pre-reading skills are assessed instead.' ),
			'grade-1'  => array( 'label' => 'Grade 1',  'wcpm' => 60 ),
			'grade-2'  => array( 'label' => 'Grade 2',  'wcpm' => 100 ),
			'grade-3'  => array( 'label' => 'Grade 3',  'wcpm' => 112 ),
			'grade-4'  => array( 'label' => 'Grade 4',  'wcpm' => 133 ),
			'grade-5'  => array( 'label' => 'Grade 5',  'wcpm' => 146 ),
			'grade-6'  => array( 'label' => 'Grade 6',  'wcpm' => 146 ),
			'grade-7'  => array( 'label' => 'Grade 7',  'wcpm' => 150 ),
			'grade-8'  => array( 'label' => 'Grade 8',  'wcpm' => 151 ),
			'grade-9-12' => array( 'label' => 'Grades 9 to 12', 'wcpm' => null,
			                     'note'  => 'No published benchmark. Fluency is considered to plateau at about 150.' ),
		);
	}

	/** The picker: the band, and the figure it is measured against. */
	public static function fluency_norm_options() {
		$out = array( '' => 'Not chosen' );
		foreach ( self::fluency_norms() as $key => $n ) {
			$out[ $key ] = ( null === $n['wcpm'] )
				? $n['label'] . ', no published norm'
				: $n['label'] . ', ' . $n['wcpm'] . ' words per minute';
		}
		return $out;
	}

	/** The figure a chosen band is measured against, or zero where there is none. */
	public static function fluency_norm_wcpm( $key ) {
		$norms = self::fluency_norms();
		if ( ! isset( $norms[ $key ] ) || null === $norms[ $key ]['wcpm'] ) return 0;
		return (int) $norms[ $key ]['wcpm'];
	}

	/**
	 * What one assessment card measured, as a number the page can use.
	 *
	 * The six diagnostic sections do not hold their results in one shape. Two
	 * carry a fixed goal, two divide by whatever total the tutor marked out
	 * of, one looks its goal up from a chosen grade band, and one stores a
	 * named reading level with no arithmetic of its own. The card on the
	 * report worked all of that out inline, which was fine while the card was
	 * the only thing reading it. It is not any more: the progress report now
	 * draws these same six scores as lines over time, and two copies of this
	 * would be a chart that disagreed with the card beside it the first time
	 * either was touched.
	 *
	 * Returns null where there is nothing to draw, which is the honest answer
	 * for a section nobody has scored.
	 */
	public static function measure( $section_id, $v ) {
		$section = self::section( $section_id );
		if ( ! $section || empty( $section['card'] ) ) return null;

		$raw = isset( $v['score'] ) ? $v['score'] : '';
		if ( ! BFTD_Fields::has_value( $raw ) ) return null;

		// The goal. Fixed on the section, or read off a field, or looked up
		// from the band the tutor chose.
		$bench = isset( $section['benchmark'] ) ? (float) $section['benchmark'] : 0;
		list( $bfield, $lookup, $from ) = self::goal_source( $section_id, $section, $v );
		if ( '' !== $bfield ) {
			if ( 'fluency_norm' === $lookup )      $bench = (float) self::fluency_norm_wcpm( $from );
			elseif ( 'word_minimum' === $lookup )  $bench = (float) self::word_minimum_wpm( $from );
			else                                   $bench = (float) $from;
		}

		// A named level has no arithmetic, so what stands in for it is its
		// place in the list: the first of ten fills a tenth.
		$value = (float) $raw;
		if ( ! empty( $section['score_rank'] ) && 'reading_level' === $section['score_rank'] ) {
			$value = (float) self::reading_level_rank( $raw );
		}

		// What a family reads. A chosen score is stored as its key, and the
		// key is never the thing to print.
		$shown = (string) $raw;
		if ( isset( $section['fields']['score']['options'][ $shown ] ) ) {
			$shown = $section['fields']['score']['options'][ $shown ];
		}

		$unit = '';
		if ( ! empty( $v['unit'] ) )              $unit = (string) $v['unit'];
		elseif ( ! empty( $section['unit'] ) )    $unit = (string) $section['unit'];

		return array(
			'value' => $value,
			'bench' => $bench,
			'raw'   => $raw,
			'shown' => $shown,
			'unit'  => $unit,
			'label' => isset( $section['label'] ) ? $section['label'] : $section_id,
		);
	}

	/* ------------------------------------------------------------------ */
	/* The four kinds of reading diagnostic                                */
	/* ------------------------------------------------------------------ */

	/** Everything written before this field existed is an initial assessment. */
	const KIND_DEFAULT = 'initial';

	/**
	 * Initial, middle, final, and an interim any time.
	 *
	 * Three of them are the shape of a programme: where the child started,
	 * where they were halfway, where they finished. Those three are the cards
	 * above the chart on a family's progress report, and a programme always
	 * begins with the first of them.
	 *
	 * An interim is the fourth and is not one of the three. A practice may
	 * reassess in March because a parent asked, and that reading belongs on
	 * the chart — it is a real measurement — without pretending to be the
	 * mid-programme reassessment at activity 70.
	 */
	public static function assessment_kinds() {
		return array(
			'initial' => 'Initial',
			'middle'  => 'Middle',
			'final'   => 'Final',
			'interim' => 'Interim',
		);
	}

	/** The three that are the shape of a programme, in the order they happen. */
	public static function milestone_kinds() {
		return array( 'initial', 'middle', 'final' );
	}

	public static function assessment_kind_label( $kind ) {
		$all = self::assessment_kinds();
		return isset( $all[ $kind ] ) ? $all[ $kind ] : '';
	}

	/**
	 * The reading level a child at a given school grade is aiming at.
	 *
	 * The target is their own grade, which is the whole point: a Grade 8
	 * reading at preprimer is not aiming at "better", they are aiming at
	 * Grade 8, and the distance between those two is the thing the chart is
	 * drawn to show.
	 *
	 * Grades past eight target Grade 8, because that is where the levels this
	 * practice assesses against stop. An adult learner targets the same.
	 */
	public static function grade_target_level( $grade ) {
		$grade = trim( (string) $grade );
		if ( '' === $grade ) return '';
		if ( '0' === $grade ) return 'primer';          // Kindergarten
		if ( 'adult' === $grade ) return 'grade-8';

		$n = (int) $grade;
		if ( $n < 1 ) return '';
		return 'grade-' . min( 8, $n );
	}

	/**
	 * The words a minute a child of this school grade is aiming at.
	 *
	 * The same principle as grade_target_level, applied to the other thing
	 * this practice measures: the target is the benchmark for the grade they
	 * are actually in, not one a tutor chose.
	 *
	 * The band on the diagnostic is a different question and stays a choice.
	 * A tutor working a Grade 8 child up from fourteen words a minute may well
	 * measure this term's reading against Grade 5, and the bar on that card
	 * should say so. But the chart is answering "where should they be", and
	 * the answer to that is their own grade — a target somebody can quietly
	 * move is not a target.
	 *
	 * Grades past eight, and adult learners, aim at the Grade 8 figure, which
	 * is where the published norms stop.
	 */
	public static function grade_target_wcpm( $grade ) {
		$key = self::grade_target_level( $grade );   // 'primer' | 'grade-N' | ''
		if ( '' === $key ) return 0;
		if ( 'primer' === $key ) return 0;           // Kindergarten has no published norm
		return self::fluency_norm_wcpm( $key );
	}

	/** The name of that band, for the label beside the marker. */
	public static function grade_target_wcpm_label( $grade ) {
		$wcpm = self::grade_target_wcpm( $grade );
		return $wcpm ? $wcpm . ' wpm' : '';
	}

	/*
	 * Timed reading is two assessments, and a student does one of them.
	 *
	 * A passage for a student reading above Kindergarten level, a word list
	 * for one reading at Kindergarten level or lower. Each has its own
	 * introduction, its own reference table, its own results table and its own
	 * answer to how the method helps, all taken from the tutor training
	 * document ("4 Timed Passage Reading" and "4 Kindergarten Word Fluency
	 * Assessment"). The two introductions and the two answers are the
	 * practice's standing wording, changed in Settings by a senior manager or
	 * an administrator.
	 */
	public static function passage_intro() {
		return '<p>Oral reading fluency isn&#8217;t just speed. It&#8217;s speed, accuracy, and prosody. Prosody is the flow: smooth, even, with natural phrasing and expression. All three elements are essential for strong comprehension. As fluency improves, reading becomes more enjoyable and less effortful, allowing students to focus on meaning rather than decoding each word.</p>'
			. '<p>Easy CBM (Curriculum Based Measurement) norm-referenced standardized test is used for paragraph reading. The grade level is the independent reading level determined by the San Diego Quick Assessment.</p>';
	}

	public static function passage_method() {
		return '<p>EBLI builds fluency from the inside out. It doesn&#8217;t drill speed. It strengthens what fluency is made of: accurate decoding, flexible word solving, whole-sentence reading, and meaning. Get those right, and fluency follows.</p>'
			. '<p><strong>1. Accurate, flexible decoding first</strong><br>Students pull apart the sounds in whole words, map them to spellings, and use logic, not guessing, to solve unknown words. You can&#8217;t read fast if you&#8217;re constantly unsure. EBLI removes the uncertainty.</p>'
			. '<p><strong>2. Whole-sentence reading from day one</strong><br>Students read real words in real sentences, grouping words into natural phrases and pausing at punctuation. Fluency is a speech skill, not a speed skill.</p>'
			. '<p><strong>3. Writing builds fluency too</strong><br>Spelling words by sound strengthens phonemic awareness and sound-spelling patterns, so future reading becomes nearly automatic.</p>'
			. '<p><strong>4. Less cognitive load</strong><br>Traditional programs stack up dozens of phonics rules and long word lists. EBLI teaches only what the brain needs: mapping sounds to print flexibly. Kids learn that one sound can have many spellings (/ee/ in <em>He dreams of cookies and sees a happy trio</em>) and one spelling can have many sounds (<em>ea</em> in dream, bread, great). That frees up brain space for comprehension.</p>'
			. '<p><strong>5. Meaning comes first</strong><br>EBLI keeps asking: What does that word mean? Does this make sense? Can you summarize it? That&#8217;s reading for life, not for a stopwatch.</p>'
			. '<p><strong>The result</strong><br>EBLI doesn&#8217;t teach kids to read fast. It teaches them to read accurately and with understanding, and speed follows. That&#8217;s why EBLI students accelerate so quickly, even after years of struggle.</p>';
	}

	public static function kinder_intro() {
		return '<p>If a student&#8217;s independent reading level on the San Diego Quick Assessment is Kindergarten or lower, we skip the paragraph reading. A paragraph is too much to ask, and it won&#8217;t tell us much. Instead, the student completes the Kindergarten-word fluency assessment on EasyCBM, a standardized, norm-referenced test. It shows us how well the child reads individual words.</p>'
			. '<p>Speed minimums rise by grade level. Grade 3 is 70 words per minute, grade 5 is 100, grades 7 and 8 are 130. They come from the <a href="https://www.donpotter.net/pdf/flesch_exercises.pdf" target="_blank" rel="noopener">Victory Drill Book, by Donald Potter</a>, built on Rudolf Flesch&#8217;s <em>Exercises</em>, and years of classroom experience, not a normed sample with percentiles. They run lower than oral reading fluency (ORF) norms for passages, and that&#8217;s expected. Reading isolated words is a different task from reading a passage.</p>';
	}

	public static function kinder_method() {
		return '<p>EBLI raises reading grade level by fixing the skills that drive it: accurate word reading, flexible word solving, comprehension, and writing.</p>'
			. '<ul>'
			. '<li><strong>It fixes the root, not the symptom.</strong> Students learn to hear every sound in a word, map those sounds to letters, and solve unfamiliar words without guessing. Letter names stay out of reading, writing, and spelling, because letters rarely say their names. Grade level rises from stronger word solving, not harder books.</li>'
			. '<li><strong>Real reading starts early.</strong> Students read real sentences and real texts right away, not just narrow &#8220;decodable&#8221; ones. That builds vocabulary, comprehension, and exposure to advanced language.</li>'
			. '<li><strong>Writing and reading grow together.</strong> Students write words, sentences, and short paragraphs, sounds first, then spellings. Every bit of writing strengthens word recognition.</li>'
			. '<li><strong>Less clutter, faster learning.</strong> One logical process works for every word. No long rule lists. No syllable-type memorization.</li>'
			. '<li><strong>Meaning comes first.</strong> Summarizing, vocabulary in context, and meaning checks happen at every step, so students understand more, not just sound better.</li>'
			. '</ul>'
			. '<p><strong>The result:</strong> Many EBLI students grow 1 to 3 grade levels in a year. They don&#8217;t work harder. They learn the way the brain is wired to read.</p>';
	}

	/**
	 * The box under the passage results explaining the norm column, with its
	 * sources. Fixed reference material from the training document, the same
	 * on every report, so it is drawn rather than stored and nobody edits it.
	 */
	public static function passage_norm_note() {
		return '<h4>End of year ORF* norm for enrolled grade</h4>'
			. '<p><strong>*ORF (oral reading fluency) norm</strong></p>'
			. '<p>This number is the 50th percentile spring benchmark, the end of year norm for a student&#8217;s enrolled grade. The 50th percentile is a solid mid-range marker: half of students score higher, half score lower.</p>'
			. '<p>Grades 1 to 6 use Hasbrouck &amp; Tindal 2017 norms. Grades 7 and 8 use their 2006 norms, the only published normed data available at those grade levels. No peer-reviewed ORF benchmarks exist for high school grades. For older struggling readers, the Grade 8 benchmark (151 WCPM) serves as the standard clinical reference point; a score below 140 WCPM on grade-level text is a meaningful signal worth investigating.</p>'
			. '<p>The plateau at Grades 6 to 8 (about 146 to 151 WCPM) is a well-documented pattern in the research, sometimes called the middle-school slump.</p>'
			. '<p>Sources:<br>'
			. '2017 Norms (Grades 1 to 6): <a href="https://www.readingrockets.org/sites/default/files/2023-08/2017_ORF_NORMS.pdf" target="_blank" rel="noopener">readingrockets.org, 2017 ORF Norms</a><br>'
			. '2006 Norms (Grades 1 to 8, page 8): <a href="https://www.aimpa.org/uploaded/pdfs/Hasbrouck-ORF_NATIONAL_NORMS_Full_and_50ile.pdf" target="_blank" rel="noopener">aimpa.org, Hasbrouck ORF National Norms</a></p>';
	}

	/**
	 * The word list's minimum speeds, by grade.
	 *
	 * From the Victory Drill Book (Donald Potter), as the training document
	 * gives them. Minimums rather than percentiles, and a different list from
	 * the passage norms on purpose: reading isolated words is a different task.
	 * One list, used for the reference table, the enrolled grade picker, the
	 * results table and the goal on the card.
	 */
	public static function word_minimums() {
		return array(
			'pre-k'   => array( 'label' => 'Pre-Kindergarten', 'wpm' => 20 ),
			'k'       => array( 'label' => 'Kindergarten',     'wpm' => 30 ),
			'grade-1' => array( 'label' => 'Grade 1',          'wpm' => 40 ),
			'grade-2' => array( 'label' => 'Grade 2',          'wpm' => 55 ),
			'grade-3' => array( 'label' => 'Grade 3',          'wpm' => 70 ),
			'grade-4' => array( 'label' => 'Grade 4',          'wpm' => 85 ),
			'grade-5' => array( 'label' => 'Grade 5',          'wpm' => 100 ),
			'grade-6' => array( 'label' => 'Grade 6',          'wpm' => 115 ),
			'grade-7' => array( 'label' => 'Grade 7',          'wpm' => 130 ),
			'grade-8' => array( 'label' => 'Grade 8',          'wpm' => 130 ),
		);
	}

	public static function word_minimum_options() {
		$out = array( '' => 'Not chosen' );
		foreach ( self::word_minimums() as $key => $m ) {
			$out[ $key ] = $m['label'] . ', ' . $m['wpm'] . ' words per minute';
		}
		return $out;
	}

	public static function word_minimum_wpm( $key ) {
		$all = self::word_minimums();
		return isset( $all[ $key ] ) ? (int) $all[ $key ]['wpm'] : 0;
	}

	public static function word_minimum_rows() {
		$rows = array();
		foreach ( self::word_minimums() as $m ) {
			$rows[] = array( $m['label'], $m['wpm'] . ' words per minute' );
		}
		return $rows;
	}

	/** The passage norms as the training document lists them, Kindergarten to Grade 8. */
	public static function passage_norm_rows() {
		$extra = array(
			'grade-6' => ' (not a typo: the well-documented middle-school slump)',
			'grade-7' => ' (2006 norms)',
			'grade-8' => ' (2006 norms)',
		);
		$rows = array();
		foreach ( self::fluency_norms() as $key => $n ) {
			if ( 'grade-9-12' === $key ) continue;   // the document lists none past Grade 8
			$rows[] = array( $n['label'], ( null === $n['wcpm'] )
				? 'No ORF norm (pre-reading skills assessed instead)'
				: $n['wcpm'] . ' WCPM' . ( isset( $extra[ $key ] ) ? $extra[ $key ] : '' ) );
		}
		return $rows;
	}

	/**
	 * Which of a section's alternative assessments was done.
	 *
	 * The one the tutor chose. A report written before there was a choice
	 * has none stored, so it is read from what was filled in: a word list
	 * figure means the word list, as it always did, and passage figures mean
	 * the passage. Nothing chosen and nothing filled in is no answer.
	 */
	public static function active_form( $section_id, $values ) {
		$section = self::section( $section_id );
		if ( ! $section || empty( $section['form_field'] ) ) return '';
		$key    = $section['form_field'];
		$chosen = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		if ( '' !== $chosen && isset( $section['fields'][ $key ]['options'][ $chosen ] ) ) return $chosen;
		foreach ( (array) ( isset( $section['form_infer'] ) ? $section['form_infer'] : array() ) as $form => $field ) {
			if ( isset( $values[ $field ] ) && '' !== trim( (string) $values[ $field ] ) ) return $form;
		}
		return '';
	}

	/** Does a field belong on the report for the assessment that was done? */
	public static function shows_for_form( $section_id, $field, $values ) {
		if ( empty( $field['form'] ) ) return true;
		return self::active_form( $section_id, $values ) === $field['form'];
	}

	/** Where a section's goal comes from, for the assessment that was done. */
	private static function goal_source( $section_id, $section, $values ) {
		$field  = isset( $section['benchmark_field'] ) ? $section['benchmark_field'] : '';
		$lookup = isset( $section['benchmark_lookup'] ) ? $section['benchmark_lookup'] : '';
		$form   = self::active_form( $section_id, $values );
		if ( '' !== $form && isset( $section['form_benchmark'][ $form ] ) ) {
			$field  = $section['form_benchmark'][ $form ]['field'];
			$lookup = $section['form_benchmark'][ $form ]['lookup'];
		}
		$chosen = ( '' !== $field && isset( $values[ $field ] ) ) ? (string) $values[ $field ] : '';
		return array( $field, $lookup, $chosen );
	}

	/**
	 * The line beside the bar naming whose goal it is, or '' where the
	 * section's goal is not a chosen band.
	 */
	public static function goal_line( $section_id, $values ) {
		$section = self::section( $section_id );
		if ( ! $section ) return '';
		list( , $lookup, $chosen ) = self::goal_source( $section_id, $section, $values );
		if ( 'fluency_norm' === $lookup ) {
			$norms = self::fluency_norms();
			if ( ! isset( $norms[ $chosen ] ) ) return '';
			return ( null === $norms[ $chosen ]['wcpm'] )
				? $norms[ $chosen ]['label'] . ', no published norm'
				: $norms[ $chosen ]['label'] . ' norm, ' . $norms[ $chosen ]['wcpm'] . ' ' . $section['unit'];
		}
		if ( 'word_minimum' === $lookup ) {
			$all = self::word_minimums();
			if ( ! isset( $all[ $chosen ] ) ) return '';
			return $all[ $chosen ]['label'] . ' minimum, ' . $all[ $chosen ]['wpm'] . ' words per minute';
		}
		return '';
	}

	/** The word lists the spelling test is drawn from, Kindergarten to Grade 12. */
	public static function spelling_lists() {
		$out = array( '' => 'Not chosen', 'k' => 'Kindergarten' );
		for ( $g = 1; $g <= 12; $g++ ) $out[ 'grade-' . $g ] = 'Grade ' . $g;
		return $out;
	}

	public static function spelling_intro() {
		return '<p>The purpose of the 25 word spelling test is not to find out how many words a child can spell correctly, the way a school test does. We are looking for <strong>patterns in the errors</strong>, so we can identify exactly which skills or sound to spelling connections need to be taught.</p>'
			. '<p>In fact we want a student to make mistakes, because that gives the clearest picture of what is missing or misunderstood. Multiple errors are not a sign of failure. They are what makes a targeted plan possible.</p>'
			. '<h3>The reading and spelling connection</h3>'
			. '<p>Reading and spelling both rely on the same sound to symbol connections. Reading is receptive, spelling is expressive. Improved spelling often leads to stronger reading, because when a student learns to spell a word correctly they are reinforcing the decoding skills needed to read it.</p>'
			. '<h3>Why spelling is harder than reading</h3>'
			. '<p>Spelling requires a student to choose the correct spelling for each sound, and in English most sounds can be spelled several ways. The long e sound alone can be spelled ee, ea, y, ie or ey. That makes spelling a more complex process than recognising a word on a page.</p>';
	}

	public static function spelling_method() {
		return '<p>Every activity asks a student to say a word, hear its sounds in order, and write a spelling for each one. There are no spelling rules to memorise and no lists to learn by Friday. The same thinking that solves an unfamiliar word on the page is what writes it on the line, so each session strengthens both at once.</p>';
	}

	public static function writing_intro() {
		return '<p>The student was encouraged to write a sentence or a short paragraph, depending on their comfort and ability. We chose the topic together, something that interested them and felt manageable. If a student\'s anxiety rises or the task becomes overwhelming we pause for a brain break or move on. The goal is to build confidence, not pressure.</p>'
			. '<p><strong>This is the only time a student is asked to write without being helped to correct their writing.</strong></p>';
	}

	public static function writing_method() {
		return '<p>Writing is not saved for the end. It is part of every activity from the first session, because writing a word is what proves a student can hear its sounds and choose spellings for them. Conventions are taught inside real sentences the student has written, rather than as a worksheet about commas.</p>';
	}

	public static function alphabet_method() {
		return '<p>The goal of handwriting instruction is not just legibility. It is <strong>motor memory</strong> that frees up brainpower for thinking, understanding and learning. With repeated, correct practice the brain builds a <strong>motor plan</strong> for how to write letters and words, until a student can focus on <strong>what they want to say or read</strong> rather than on how to form the words.</p>'
			. '<p><strong>One to five minutes of each session</strong> is dedicated to strengthening handwriting. Once the student can form all lowercase letters accurately from memory, a metronome is introduced to help them develop a steady, automatic rhythm.</p>'
			. '<p>After the midpoint of instruction, at Activity 70, or earlier if the student is ready, we repeat the <strong>one minute alphabet writing check</strong> to measure growth. If the student meets the criteria of <strong>40 letters per minute</strong>, written <strong>in order</strong>, <strong>all lowercase</strong> and <strong>formed top to bottom and left to right</strong>, no further alphabet writing practice is needed.</p>';
	}

	/**
	 * The derived results table, built once and drawn in both places.
	 *
	 * The admin preview and the family's report are the same markup from the
	 * same figures, because two renderers is how a preview starts quietly
	 * disagreeing with the thing it is previewing.
	 */
	/**
	 * What a section's placeholders stand for.
	 *
	 * Any field in the section can be placed in a table or a figure tile, so a
	 * section that records three levels does not also have to store them a
	 * second time to report them. A chosen value is stored as its key, and is
	 * turned back into the words before it goes anywhere near a parent.
	 *
	 * One definition, because the same figures are now presented two ways, and
	 * two copies of this is how a norm reads as "grade-1" in one of them.
	 */
	/**
	 * Does a column heading only repeat the heading already above it?
	 *
	 * A checklist carries its name twice: once as the block's own heading and
	 * once as the label on the column of findings. Where those say the same
	 * thing the second one is noise, and on the writing lists it read as a
	 * line printed twice. The column keeps its heading only where it adds
	 * something the heading above does not already say.
	 *
	 * The comparison ignores case, punctuation and spacing, and treats a
	 * heading that begins with the column's words as saying them: "Grammar
	 * and word usage" under "Grammar and word usage are correct" is a repeat,
	 * and so is the singular "Writing convention" under "Writing conventions".
	 */
	public static function heading_repeats( $heading, $above ) {
		$norm = function ( $text ) {
			$text = strtolower( wp_strip_all_tags( (string) $text ) );
			return preg_replace( '/[^a-z0-9]+/', '', $text );
		};
		$h = $norm( $heading );
		$a = $norm( $above );
		if ( '' === $h || '' === $a ) {
			return false;
		}
		return 0 === strpos( $a, $h ) || 0 === strpos( $h, $a );
	}

	private static function swaps( $section, $values ) {
		$swap = array(
			'{benchmark}' => isset( $section['benchmark'] ) ? (string) $section['benchmark'] : '',
			'{unit}'      => isset( $section['unit'] ) ? (string) $section['unit'] : '',
		);
		foreach ( $section['fields'] as $fkey => $fdef ) {
			// A fixed figure is part of the task, so this knows it whether or
			// not the caller passed it in. Depending on the caller for it is
			// how a denominator goes missing in one of the two places the same
			// figures are drawn and not the other.
			$v = isset( $values[ $fkey ] ) ? $values[ $fkey ] : '';
			if ( '' === $v && isset( $fdef['fixed'] ) ) $v = $fdef['fixed'];
			if ( is_array( $v ) ) continue;
			$v = (string) $v;
			if ( isset( $fdef['options'][ $v ] ) && '' !== $v ) $v = (string) $fdef['options'][ $v ];
			$swap[ '{' . $fkey . '}' ] = $v;
		}

		// A chosen band, split into the two parts a figure tile needs. The
		// whole label — "Grade 1, 60 words per minute" — set at tile size
		// shouted over the score it was there to be compared with. As a bare
		// 60 beside a bare 12 it is the comparison, which is the point of it.
		if ( ! empty( $section['benchmark_lookup'] ) && 'fluency_norm' === $section['benchmark_lookup'] ) {
			$chosen = isset( $values[ $section['benchmark_field'] ] ) ? (string) $values[ $section['benchmark_field'] ] : '';
			$norms  = self::fluency_norms();
			$swap['{norm_grade}'] = isset( $norms[ $chosen ] ) ? $norms[ $chosen ]['label'] : '';
			$swap['{norm_wcpm}']  = ( isset( $norms[ $chosen ] ) && null !== $norms[ $chosen ]['wcpm'] )
				? (string) $norms[ $chosen ]['wcpm'] : '';
			// The norm as one cell of the passage results table.
			$swap['{norm_line}'] = ! isset( $norms[ $chosen ] ) ? ''
				: ( null === $norms[ $chosen ]['wcpm']
					? $norms[ $chosen ]['label'] . ', no published norm'
					: $norms[ $chosen ]['wcpm'] . ' WCPM, ' . $norms[ $chosen ]['label'] );
		}

		// The word list's minimums: Kindergarten's, which is fixed, and the
		// enrolled grade's, which the tutor chose.
		if ( isset( $section['fields']['min_grade'] ) ) {
			$mins   = self::word_minimums();
			$chosen = isset( $values['min_grade'] ) ? (string) $values['min_grade'] : '';
			$swap['{k_min_line}']     = $mins['k']['wpm'] . ' WPM';
			$swap['{min_grade_line}'] = isset( $mins[ $chosen ] )
				? $mins[ $chosen ]['wpm'] . ' WPM, ' . $mins[ $chosen ]['label'] : '';
		}

		return $swap;
	}

	/**
	 * What each choice in a section's dropdowns turns its placeholders into.
	 *
	 * The tutor's screen previews the results table, and a cell such as the
	 * enrolled grade's minimum has to change the moment the grade is picked,
	 * not after a save. Worked out here, by the same swaps() the report uses,
	 * so the screen never has a second copy of the lists or the wording.
	 */
	public static function live_tokens( $section_id ) {
		$section = self::section( $section_id );
		if ( ! $section ) return array();
		$base = self::swaps( $section, array() );
		$out  = array( 'base' => $base, 'select' => array(), 'derived' => array() );
		foreach ( $section['fields'] as $key => $f ) {
			if ( isset( $f['derived']['minus'] ) ) $out['derived'][ $key ] = $f['derived'];
			if ( ! isset( $f['type'] ) || 'select' !== $f['type'] || empty( $f['options'] ) ) continue;
			foreach ( array_keys( $f['options'] ) as $opt ) {
				$sw = self::swaps( $section, array( $key => (string) $opt ) );
				$changed = array();
				foreach ( $sw as $tok => $val ) {
					if ( '{' . $key . '}' === $tok || ! isset( $base[ $tok ] ) || $base[ $tok ] !== $val ) $changed[ $tok ] = $val;
				}
				$out['select'][ $key ][ (string) $opt ] = $changed;
			}
		}
		if ( ! empty( $section['form_field'] ) ) $out['form_field'] = $section['form_field'];
		return $out;
	}

	/**
	 * A row of figures, drawn as the design draws figures.
	 *
	 * A single result in a one row table is a number floating at the far right
	 * of a wide band, with its label at the far left and nothing joining them.
	 * The design already has a way of showing a figure — the tiles across the
	 * top of a progress report — so this uses that rather than inventing a
	 * second one.
	 *
	 * A tile whose figure has not been entered is left out, and if none of
	 * them have been, there is nothing to show.
	 */
	public static function figures_html( $section_id, $values, $field_key ) {
		$section = self::section( $section_id );
		if ( ! $section || ! isset( $section['fields'][ $field_key ]['tiles'] ) ) return '';
		$cfg = $section['fields'][ $field_key ];

		if ( ! empty( $cfg['unless'] ) ) {
			$other = isset( $values[ $cfg['unless'] ] ) ? trim( (string) $values[ $cfg['unless'] ] ) : '';
			if ( '' !== $other ) return '';
		}

		$swap = self::swaps( $section, $values );

		// The first tile is the figure the whole block exists to report. If it
		// has not been entered there is nothing to report, whatever the tiles
		// beside it hold: a norm chosen for the assessment that was actually
		// done would otherwise be enough to announce a reading nobody did.
		// This is the same rule the tables follow, and it is here rather than
		// inherited because the two draw from the same figures and must not
		// disagree about when there is something to draw.
		$lead = (string) $cfg['tiles'][0]['value'];
		if ( $lead !== strtr( $lead, $swap ) && '' === trim( strtr( $lead, $swap ) ) ) return '';

		$tiles = array();
		foreach ( $cfg['tiles'] as $tile ) {
			$value = strtr( (string) $tile['value'], $swap );
			if ( '' === trim( $value ) ) continue;
			$tiles[] = array( $value, strtr( (string) $tile['label'], $swap ) );
		}
		if ( ! $tiles ) return '';

		$n = min( 4, count( $tiles ) );
		ob_start();
		?>
		<?php if ( ! empty( $cfg['title'] ) ) : ?>
			<h4 class="figs-t"><?php echo esc_html( $cfg['title'] ); ?></h4>
		<?php endif; ?>
		<?php if ( ! empty( $cfg['sub'] ) ) : ?>
			<?php /* Which of the two assessments this was, on a line of its own:
			         run into the title it read as one long sentence, and the
			         part that says what the child actually did is the part a
			         parent needs to pick out. */ ?>
			<p class="figs-s"><?php echo esc_html( $cfg['sub'] ); ?></p>
		<?php endif; ?>
		<div class="kpis n<?php echo (int) $n; ?>" style="margin:14px 0 18px">
			<?php foreach ( $tiles as $tile ) : ?>
				<div class="kpit">
					<div class="n"><?php echo esc_html( $tile[0] ); ?></div>
					<div class="l"><?php echo esc_html( $tile[1] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function score_table_html( $section_id, $values, $field_key = null, $live = false ) {
		$section = self::section( $section_id );
		if ( ! $section ) return '';

		// A section may carry more than one drawn table — one explaining how
		// the levels are read, one reporting the child's — so the caller can
		// name the one it means. Without a name, the first is used, which is
		// what every caller wanted back when there was only ever one.
		$cfg = null;
		if ( null !== $field_key ) {
			if ( isset( $section['fields'][ $field_key ]['type'] )
				&& 'score_table' === $section['fields'][ $field_key ]['type'] ) {
				$cfg = $section['fields'][ $field_key ];
			}
		} else {
			foreach ( $section['fields'] as $f ) {
				if ( isset( $f['type'] ) && 'score_table' === $f['type'] ) { $cfg = $f; break; }
			}
		}
		if ( ! $cfg ) return '';

		// One of two alternative assessments: drawn only for the one done.
		if ( ! self::shows_for_form( $section_id, $cfg, $values ) ) return '';

		$swap = self::swaps( $section, $values );

		// A table can stand aside for another one. Two accounts of the same
		// minute of reading, side by side, leave a parent working out which of
		// them counted, so the section says which takes precedence rather than
		// leaving it to whichever happens to be drawn first.
		if ( ! empty( $cfg['unless'] ) ) {
			$other = isset( $values[ $cfg['unless'] ] ) ? trim( (string) $values[ $cfg['unless'] ] ) : '';
			if ( '' !== $other ) return '';
		}

		// Nothing has been counted yet, so there is no result to table. A
		// table showing only its fixed rows reads as a finding about the
		// child rather than as a section nobody has reached.
		//
		// Only tables that report the child's score wait like this. A table
		// that explains how the levels are read says the same thing whether
		// or not anybody has been assessed, so it is drawn either way.
		$reports_score = false;
		foreach ( array_merge( $cfg['head'], $cfg['rows'] ) as $part ) {
			foreach ( (array) $part as $cell ) {
				if ( false !== strpos( (string) $cell, '{score' ) ) { $reports_score = true; break 2; }
			}
		}
		if ( $reports_score && '' === trim( (string) $swap['{score}'] ) ) return '';

		// A table can name the figure it exists to report, where that is not
		// the score.
		if ( ! empty( $cfg['needs'] ) ) {
			$need = isset( $values[ $cfg['needs'] ] ) ? trim( (string) $values[ $cfg['needs'] ] ) : '';
			if ( '' === $need ) return '';
		}

		// The head row carries figures in some sections and column labels in
		// others, so it is substituted the same way the body is.
		// The head row carries column labels in some sections and a reported
		// value in others, so whether it counts as content has to be worked
		// out rather than assumed. A table whose only filled band is the one
		// in the head is still a table with something to say.
		$head      = array();
		$head_says = false;
		$head_asks = false;
		foreach ( $cfg['head'] as $h ) {
			$raw = (string) $h;
			$out = strtr( $raw, $swap );
			if ( $raw !== $out ) {
				$head_asks = true;                                  // it reports a figure
				if ( '' !== trim( $out ) ) $head_says = true;       // and the figure is there
			}
			$head[] = $out;
		}

		// The figure this table reports has not been entered. Its other rows
		// may well have values — a fixed target, or a band chosen for the
		// assessment that was actually done — and drawing the table on the
		// strength of those says a reading happened that did not. That is how
		// the Kindergarten table appeared on every report the moment a norm
		// was picked, with the figure it exists to report left blank.
		if ( $head_asks && ! $head_says ) return '';

		$rows = array();
		$tpls = array();   // each kept row as written, for a preview that follows the inputs
		foreach ( $cfg['rows'] as $row ) {
			$cells = array();
			foreach ( $row as $cell ) $cells[] = strtr( (string) $cell, $swap );
			// A row whose figure has not been entered is not shown. A parent
			// reading "Total letters written:" with nothing after it assumes
			// something is broken rather than that it is early days.
			// A table can ask to keep its one row of results whatever its last
			// column holds: its own score check above already decided whether
			// there is a reading to report.
			if ( empty( $cfg['keep_row'] ) && '' === trim( (string) end( $cells ) ) ) continue;
			$rows[] = $cells;
			$tpls[] = $row;
		}
		if ( ! $rows && ! $head_says ) return '';

		ob_start();
		?>
		<?php
		/*
		 * Where the head row goes, and why it is not always the same place.
		 *
		 * The design bands the first row of the table body and leaves the head
		 * above it plain. So a head row that is column labels — Level,
		 * Description — belongs in the thead, plain, with the first finding
		 * banded under it. A head row that is itself a finding, such as the
		 * score or the independent reading level, belongs in the body, because
		 * it is the row the design means to band.
		 *
		 * Getting this wrong is not subtle once you see it and is invisible
		 * until you do: the band lands on the second finding, so a table of
		 * three bands appears to be highlighting the middle one.
		 */
		$head_in_body = $head_says;
		?>
		<div class="tablewrap"><table class="doc">
			<?php if ( ! empty( $cfg['title'] ) || ! $head_in_body ) : ?>
				<thead>
					<?php if ( ! empty( $cfg['title'] ) ) : ?>
						<tr><th colspan="<?php echo (int) count( $cfg['head'] ); ?>"><strong><?php echo esc_html( $cfg['title'] ); ?></strong></th></tr>
					<?php endif; ?>
					<?php if ( ! $head_in_body ) : ?>
						<tr><?php foreach ( $head as $h ) : ?><th><strong><?php echo esc_html( $h ); ?></strong></th><?php endforeach; ?></tr>
					<?php endif; ?>
				</thead>
			<?php endif; ?>
			<tbody>
				<?php if ( $head_in_body ) : ?>
					<tr><?php foreach ( $head as $h ) : ?><td><?php echo esc_html( $h ); ?></td><?php endforeach; ?></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $ri => $row ) : ?>
					<tr><?php foreach ( $row as $ci => $cell ) :
						// On the tutor's screen a cell built from the inputs
						// carries what it was built from, so it can follow them
						// before anything is saved.
						$tpl = (string) $tpls[ $ri ][ $ci ];
						$attr = ( $live && false !== strpos( $tpl, '{' ) ) ? ' data-tpl="' . esc_attr( $tpl ) . '"' : '';
						?><td<?php echo $attr; ?>><?php echo esc_html( $cell ); ?></td><?php endforeach; ?></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $cfg['note'] ) ) : ?>
			<p class="note"><?php echo esc_html( $cfg['note'] ); ?></p>
		<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function screen_label( $screen ) {
		$s = self::screens();
		return isset( $s[ $screen ] ) ? $s[ $screen ]['label'] : $screen;
	}

	/**
	 * Every section, keyed by the same id the front end uses as its DOM id
	 * ("dxsec-1", "sec-missed-lessons", ...). Order here is the order the
	 * parent sees, and the order the tutor fills in.
	 */
	public static function sections() {
		if ( null !== self::$sections ) return self::$sections;

		$s = array();

		/* ---------------------------------------------------------------
		 * Reading diagnostic
		 * ------------------------------------------------------------- */

		$s['sec-assessment-overview'] = array(
			'screen'  => self::SCREEN_REPORT,
			'label'   => 'Assessment overview',
			'eyebrow' => 'Start here',
			'thread'  => false,
			'summary' => 'The one-paragraph plain-language summary a parent reads first.',
			'fields'  => array(
				// These three name the report in its heading, so they are not
				// printed again as rows underneath it.
				// Which of the four this is. It decides where the assessment
				// sits on the family's progress chart, and which of the three
				// milestone cards above it lights up.
				'kind'          => array( 'type' => 'select', 'label' => 'Which assessment', 'card' => true,
					'default' => self::KIND_DEFAULT, 'options' => self::assessment_kinds(),
					'help' => 'A programme always begins with the initial assessment. An interim one can be done at any point and does not replace the three.' ),
				'assessed_on'   => array( 'type' => 'date',   'label' => 'Assessment date', 'card' => true ),
				'assessed_by'   => array( 'type' => 'staff',  'label' => 'Assessed by', 'card' => true ),
				'grade'         => array( 'type' => 'text',   'label' => 'Grade at assessment', 'card' => true ),
				// The paragraph under the name at the top of the report. Fixed
				// wording until Karl asked for the tutor to be able to edit it.
				// {tutor} becomes the name of whoever assessed.
				'intro'         => array( 'type' => 'textarea', 'label' => 'Introduction at the top of the report', 'card' => true,
					'default' => self::DIAGNOSTIC_INTRO,
					'help'    => 'Shown under the student\'s name. {tutor} is replaced with the name of whoever assessed.' ),
				// A "Headline finding" sentence used to sit here; Karl removed it
				// from the diagnostic. Anything already typed into it stays in
				// the database and is no longer shown or edited.
				'overview'      => array( 'type' => 'rich',   'label' => 'Overview', 'help' => 'Written for the parent, not for a file. Two or three short paragraphs.' ),
			),
		);

		$skills = array(
			'dxsec-1' => array(
				'label' => 'Writing the alphabet',
				'sub'   => 'Handwriting automaticity',
				'unit'  => 'letters in 60 seconds',
			),
			'dxsec-2' => array(
				'label' => 'Hearing sounds in words',
				'sub'   => 'Phoneme segmentation',
				'unit'  => 'of 36 correct',
			),
			'dxsec-3' => array(
				'label' => 'Reading grade level',
				'sub'   => 'San Diego Quick Assessment',
				'unit'  => 'independent level',
			),
			'dxsec-4' => array(
				'label' => 'Timed reading',
				'sub'   => 'Oral reading fluency, easyCBM',
				'unit'  => 'words correct per minute',
			),
			'dxsec-5' => array(
				'label' => 'Spelling',
				'sub'   => 'Whole words and sounds correct',
				'unit'  => 'sounds correct',
			),
			'dxsec-6' => array(
				'label' => 'Writing',
				'sub'   => 'Independent writing sample',
				'unit'  => 'words written',
			),
		);
		$n = 1;
		foreach ( $skills as $id => $meta ) {
			$s[ $id ] = array(
				'screen'  => self::SCREEN_REPORT,
				'label'   => $meta['label'],
				'eyebrow' => 'Section ' . $n . ' of 6',
				'thread'  => true,
				'card'    => true, // also renders as a summary card at the top of the report
				'summary' => $meta['sub'],
				'fields'  => array(
					// Everything marked 'card' is drawn on the summary card at
					// the top of the report. It must not also be listed as a
					// row in the section body: a family reading "Meter fill, 0
					// to 100: 63" is being shown the machinery.
					'score'      => array( 'type' => 'text',     'label' => 'Score', 'card' => true, 'help' => $meta['unit'] . '. Leave blank and this card is not shown.' ),
					'score_of'   => array( 'type' => 'text',     'label' => 'Out of', 'card' => true ),
					'unit'       => array( 'type' => 'text',     'label' => 'Unit shown to parents', 'card' => true, 'default' => $meta['unit'] ),
					'status'     => array( 'type' => 'select',   'label' => 'Where this sits', 'card' => true, 'options' => array(
						'strength'   => 'A strength',
						'developing' => 'Developing',
						'priority'   => 'A priority to work on',
					) ),
					'meter_pct'  => array( 'type' => 'number',   'label' => 'Meter fill, 0 to 100', 'card' => true, 'help' => 'Drives the bar on the card. Colour is never the only signal.' ),
					'baseline'   => array( 'type' => 'text',     'label' => 'Baseline marker', 'card' => true ),
					'card_note'  => array( 'type' => 'textarea', 'label' => 'One-line card summary', 'card' => true, 'help' => 'The sentence on the card, before the parent opens the detail.' ),
					'body'       => array( 'type' => 'rich',     'label' => 'Full section', 'help' => 'Everything the parent sees when they open this section.' ),
					'images'     => array( 'type' => 'gallery',  'label' => 'Work samples' ),
				),
			);
			$n++;
		}

		// The two structured tables inside the diagnostic. Rows the tutor
		// leaves at "Not assessed" are not shown to the parent at all.
		// The two checklists inside the diagnostic. Rows the tutor leaves at
		// "Not assessed" are not shown to the parent at all, and a row set to
		// "Heading" is a label that divides the list rather than a finding.
		// 'conv' is the design's own layout for these: a verdict chip beside
		// the convention and its note, which reads far better on a phone than
		// a three-column table does.
		$checklist = array(
			''      => 'Not assessed',
			'yes'   => 'Yes',
			'no'    => 'No',
			'na'    => 'Not applicable',
			'group' => 'Heading, not a finding',
		);
		/*
		 * Writing, which is judged against two fixed lists.
		 *
		 * There is nothing to count here and no goal to count towards, so the
		 * card reports the lists themselves: how many of the things looked for
		 * were there, out of how many were looked at. A row nobody assessed is
		 * in neither figure, so a part-finished section reports what was done
		 * rather than scoring a child on questions nobody asked.
		 */
		$writing_answers = array(
			''    => 'Not assessed',
			'yes' => 'Yes',
			'no'  => 'No',
			'na'  => 'N/A',
		);

		// The card reads as the fraction it is: two of the sixteen points that
		// were looked at. Hung off a unit it came out as "2 of the things
		// looked for, goal 16 of the things looked for", which is the same
		// phrase twice and a sentence neither time.
		$s['dxsec-6']['unit']            = '';
		$s['dxsec-6']['own_order']       = true;
		$s['dxsec-6']['benchmark_field'] = 'score_of';
		$s['dxsec-6']['meter_note']      = 'Marked yes, out of the points looked at';
		$s['dxsec-6']['fields']          = array(
			// Both figures come from one walk of the same two lists, so the
			// numerator can never be counted out of a different set than the
			// denominator.
			'score' => array(
				'type'    => 'number',
				'label'   => 'Marked yes',
				'card'    => true,
				'derived' => array( 'count' => array( 'fields' => array( 'conventions', 'grammar' ), 'value' => 'yes' ) ),
				'help'    => 'How many of the two lists below were marked yes. The bar is this against however many were answered.',
			),
			'score_of' => array(
				'type'    => 'number',
				'label'   => 'Points looked at',
				'card'    => true,
				'hidden'  => true,
				'derived' => array( 'count' => array( 'fields' => array( 'conventions', 'grammar' ) ) ),
			),

			'body' => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'label'     => 'Intro',
				'help'      => 'How the writing sample was taken. The two lists are drawn under this.',
				'shipped'   => self::writing_intro(),
			),

			'conventions' => array(
				'type'     => 'checklist',
				'label'    => 'Writing conventions',
				'headings' => array( 'value' => 'Result', 'item' => 'Writing convention' ),
				'help'     => 'A row left at "Not assessed" is left out of the family\'s report, and out of both figures on the card.',
				'options'  => $writing_answers,
				'rows'     => array(
					'indent'     => 'Paragraphs are indented',
					'caps_start' => 'Writing piece has <strong>capital letters</strong> at the beginning of every sentence',
					'caps_proper'=> '<strong>Capital letters</strong> used appropriately for <strong>proper nouns</strong>',
					'end_punct'  => 'Writing piece has <strong>punctuation at the end</strong> of every sentence',
					'other_punct'=> '<strong>Other punctuation</strong> is appropriate for sentences',
					'spaces'     => 'There is a <strong>space between each word</strong>',
					'each_sound' => 'All words contain a <strong>spelling for each sound</strong>',
					'misspelled' => 'Writing piece contains some <strong>misspelled words</strong>',
				),
			),

			'grammar' => array(
				'type'     => 'checklist',
				'label'    => 'Grammar and word usage',
				'title'    => 'Grammar and word usage are correct',
				'headings' => array( 'value' => 'Result', 'item' => 'Grammar and word usage' ),
				'options'  => $writing_answers,
				'rows'     => array(
					'complete'   => 'Complete sentences, a noun and an action',
					'run_on'     => 'Run-on sentences',
					'combined'   => 'Simple sentences combined',
					'variety'    => 'A variety of sentence structures and lengths',
					'flow'       => 'Overall flow',
					'order'      => 'Order of sentences makes sense, with a clear beginning, middle and end',
					'vivid'      => 'Powerful verbs, specific nouns and colourful adjectives or adverbs',
					'multisyll'  => 'Writing piece contains <strong>multisyllable words</strong>',
				),
			),

			'images' => array( 'type' => 'gallery', 'label' => 'Work samples' ),

			'body_after' => array(
				'type'    => 'rich',
				'label'   => 'Notes',
				'heading' => 'Notes',
				'help'    => 'What you saw, in your own words. Shown under the lists.',
			),

			'method' => array(
				'type'      => 'rich',
				'label'     => 'How the method addresses this',
				'heading'   => 'How does Evidence-Based Literacy Instruction support writing?',
				'edit_rank' => 3,
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::writing_method(),
			),
		);
		/*
		 * Spelling, which is a set of measures and a list of patterns.
		 *
		 * The three measures go in a table because they are three different
		 * kinds of thing and a row of tiles would imply they were comparable:
		 * a word list is a level, correct spellings counts sounds, and the
		 * spelling score counts whole words. What they have in common is only
		 * that a tutor read them off the same sheet.
		 *
		 * The bar is the spelling score against its own denominator, which is
		 * the one place a goal is simply the other half of the fraction beside
		 * it. Three of twenty-five is twelve per cent, and nobody has to say so.
		 */
		$s['dxsec-5']['unit']            = 'words correct';
		$s['dxsec-5']['own_order']       = true;
		$s['dxsec-5']['benchmark_field'] = 'score_of';
		$s['dxsec-5']['fields']          = array(
			'word_list' => array(
				'type'    => 'select',
				'label'   => 'Word list',
				'card'    => true,
				'options' => self::spelling_lists(),
				'help'    => 'Which graded list the twenty-five words came from.',
			),
			'spellings' => array(
				'type'  => 'number',
				'label' => 'Correct spellings',
				'card'  => true,
				'pair'  => array( 'key' => 'spellings_of', 'sep' => 'of' ),
				'help'  => 'Individual spellings, which counts sounds rather than whole words.',
			),
			'spellings_of' => array( 'type' => 'number', 'label' => 'Out of how many spellings', 'card' => true, 'in_pair' => true ),

			'score'    => array(
				'type'  => 'number',
				'label' => 'Spelling score',
				'card'  => true,
				'pair'  => array( 'key' => 'score_of', 'sep' => 'of' ),
				'help'  => 'Whole words spelled correctly. The bar on the card is this against the number attempted.',
			),
			'score_of' => array( 'type' => 'number', 'label' => 'Out of how many words', 'card' => true, 'in_pair' => true ),

			'body' => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'label'     => 'Intro',
				'help'      => 'What the spelling test is for. The results are drawn under this.',
				'shipped'   => self::spelling_intro(),
			),

			'results' => array(
				'type'  => 'score_table',
				'label' => 'Results',
				'derived_note' => 'Drawn from the figures above. Nothing here is typed.',
				'head'  => array( 'Measure', 'Result' ),
				'rows'  => array(
					array( 'Word list',         '{word_list}' ),
					array( 'Correct spellings', '{spellings} of {spellings_of} individual spellings' ),
					array( 'Spelling score',    '{score} of {score_of} words correct' ),
				),
			),

			// The six challenges are the marking scheme for this test, not a
			// list a tutor writes out each time. Typed from memory they came
			// out worded differently on every report, so two reports for the
			// same child could not be read side by side.
			'challenges' => array(
				'type'     => 'checklist',
				'label'    => 'Challenges',
				'title'    => 'Challenges',
				'headings' => array( 'value' => 'Result', 'item' => 'Challenges' ),
				'help'     => 'A row left at "Not assessed" is left out of the family\'s report entirely. The note is optional.',
				'options'  => array(
					''    => 'Not assessed',
					'yes' => 'Yes',
					'no'  => 'No',
					'na'  => 'N/A',
				),
				'rows'     => array(
					'reversals' => 'b/d reversals',
					'omits'     => 'omits spellings',
					// "puts blends together" removed at Karl's direction.
					'multi'     => 'trouble with 2,3,4 letter spellings',
					'inserts'   => 'inserts spellings',
					'order'     => 'spellings out of order',
				),
			),

			'images' => array( 'type' => 'gallery', 'label' => 'Work samples' ),

			'body_after' => array(
				'type'    => 'rich',
				'label'   => 'Notes',
				'heading' => 'Notes',
				'help'    => 'What you saw, in your own words. Shown under the results.',
			),

			'method' => array(
				'type'      => 'rich',
				'label'     => 'How the method addresses this',
				'heading'   => 'How does Evidence-Based Literacy Instruction support spelling?',
				'edit_rank' => 3,
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::spelling_method(),
			),
		);

		/*
		 * Writing the alphabet, which is measured rather than judged.
		 *
		 * Four of the fields the other areas carry are answers this one
		 * already knows. The unit is always letters in 60 seconds. The goal
		 * is always 40, which is the published automaticity benchmark, not a
		 * per-child target. The meter is that fraction against that goal, so
		 * asking a tutor to work out a percentage is asking them to do
		 * arithmetic the page can do and get wrong in a way nobody notices.
		 * And where it sits is what the strengths and growth areas section
		 * is for, said once rather than twice.
		 *
		 * The card's own one-liner is the fixed line under its title, which
		 * is the same for every child because the thing being measured is.
		 * A second sentence to write per report was asking for a summary of
		 * a summary.
		 *
		 * What is left is the only thing a tutor actually counted: how many
		 * letters were right, out of how many were written.
		 */
		$s['dxsec-1']['benchmark'] = 40;
		$s['dxsec-1']['unit']      = 'letters in 60 seconds';
		$s['dxsec-1']['own_order'] = true;
		$s['dxsec-1']['fields']    = array(
			'score'     => array(
				'type'  => 'number',
				'label' => 'Letters correct, out of 40',
				'card'  => true,
				'pair'  => array( 'key' => 'score_of', 'sep' => '/' ),
				'help'  => 'What the one minute timing produced. The goal of 40 is fixed, and the bar on the card is worked out from it.',
			),
			// Always forty, at Karl's direction: stated, not typed, the way the
			// segmentation task's 36 is. The results table's "Total letters
			// written" row reads it too, and Karl chose to keep that row.
			'score_of'  => array( 'type' => 'number', 'label' => 'Out of', 'card' => true, 'in_pair' => true, 'fixed' => 40 ),
			// Standing wording. It explains what the task measures and why it
			// matters, which is the same on every report the practice sends,
			// so it is not one tutor's to reword on a Tuesday. It says "the
			// student" rather than a name, which keeps it right for a girl,
			// a boy and a grown adult learner without anybody editing it.
			'body'      => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'label'     => 'Intro',
				'help'      => 'What the task is and why it matters. The results tables are drawn under this.',
				'shipped'   => self::alphabet_intro(),
			),

			// Drawn from the fraction and the goal. There is nothing to type,
			// so it is not a field a tutor ever meets; it is here because the
			// order of the fields is the order of the section.
			'score_table' => array(
				'type'  => 'score_table',
				'label' => 'Results',
				'title' => 'One minute timed handwriting and alphabet assessment',
				'head'  => array( 'Score', 'Detail' ),
				'rows'  => array(
					array( 'Letters produced correctly', '{score}' ),
					array( 'Total letters written',      '{score_of}' ),
					array( 'Goal',                       '40 correct lowercase letters per minute' ),
				),
			),

			// The marking rules, which are the same for every child because
			// they are how the task is marked. What changes report to report
			// is whether anything needs saying about a rule, so that is the
			// only thing anybody types.
			'marking' => array(
				'type'   => 'criteria',
				'label'  => 'What counted, and what did not',
				'help'   => 'The rules are fixed. Mark each row with a green tick or a red cross, and add a comment wherever something needs saying. A row left with no mark and no comment shows the family a tick, meaning nothing to report.',
				'groups' => array(
					'correct' => array(
						'label' => 'Counted as correct',
						'tone'  => 'sage',
						'rows'  => array(
							'lowercase' => 'Lowercase letters, even if out of order',
							'bottom_up' => 'Letters formed from bottom to top, provided the finished letter is correct',
							'capitals'  => 'Capital letters, because the number of letters written correctly is what matters for handwriting fluency',
						),
					),
					'incorrect' => array(
						'label' => 'Counted as incorrect',
						'tone'  => 'clay',
						'rows'  => array(
							// A tick against a rule about mistakes is ambiguous:
							// it could mean there were none, or that nobody
							// looked. This one says which.
							'reversals' => array(
								'label' => 'Reversals. Examples of reversals are b for d, p for q, n for u',
								'empty' => 'No reversals',
							),
						),
					),
				),
				'footer' => array(
					'label' => 'Lowercase alphabet, 26 letters',
					'value' => 'a b c d e f g h i j k l m n o p q r s t u v w x y z',
				),
			),

			// What the tutor saw. This one is theirs, and it is the only
			// writing in this section that is about this child.
			// The page of letters the score was counted from. It belongs with
			// the result it is evidence for, and ahead of the notes a tutor
			// writes about it, because they are describing what is above.
			'images'     => array( 'type' => 'gallery', 'label' => 'Work samples' ),

			'body_after' => array(
				'type'    => 'rich',
				'label'   => 'Notes',
				'heading' => 'Notes',
				'help'    => 'What you saw, in your own words. Shown under the results.',
			),

			// Standing wording again: how the method addresses what the task
			// measures. The same answer on every report, so it is held at the
			// same level as the explanation at the top.
			'method' => array(
				'type'      => 'rich',
				'label'     => 'How the method addresses this',
				'heading'   => 'How does Evidence-Based Literacy Instruction support handwriting automaticity?',
				'edit_rank' => 3,
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::alphabet_method(),
			),
		);

		/*
		 * Hearing sounds in words, which is counted rather than judged.
		 *
		 * The same shape as the alphabet, and for the same reason: the task
		 * is fixed, so most of the fields the generated set offers are
		 * questions this section already knows the answer to. It is always
		 * out of 36, the target is always every sound, and where it sits is
		 * what the strengths and growth areas section is for.
		 *
		 * What a tutor actually counted is how many sounds were right, out of
		 * how many were asked. Both numbers are kept, because a tutor who
		 * assesses part of the list still has a real fraction to record, and
		 * the bar is worked out from the first of them against the fixed 36.
		 */
		$s['dxsec-2']['benchmark'] = 36;
		$s['dxsec-2']['unit']      = 'correct';
		$s['dxsec-2']['own_order'] = true;
		$s['dxsec-2']['fields']    = array(
			'score'    => array(
				'type'  => 'number',
				'label' => 'Sounds correct, out of sounds assessed',
				'card'  => true,
				'pair'  => array( 'key' => 'score_of', 'sep' => '/' ),
				'help'  => 'What the segmentation task produced. The target of 36 is fixed, and the bar on the card is worked out from it.',
			),
			// Always thirty-six, so it is stated rather than typed. A number
			// a person retypes on every report is a number that eventually
			// disagrees with the target the bar is worked out from.
			'score_of' => array(
				'type'    => 'number',
				'label'   => 'Sounds assessed',
				'card'    => true,
				'in_pair' => true,
				'fixed'   => 36,
			),

			'body' => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'label'     => 'Intro',
				'help'      => 'What the task is and why it matters. The results table is drawn under this.',
				'shipped'   => self::segmenting_intro(),
			),

			// Drawn from the fraction and the fixed target. Nothing to type.
			'score_table' => array(
				'type'  => 'score_table',
				'label' => 'Results',
				'title' => 'Phoneme segmentation assessment',
				'head'  => array( 'Score', '{score} / {score_of}' ),
				'rows'  => array(
					array( 'Target', '100 per cent, for students of every age' ),
				),
				'note'  => 'A student who achieves 100 per cent on the pre-assessment does not need to be retested on segmenting.',
			),

			// The page of work the score was counted from, ahead of the notes
			// a tutor writes about it, because they describe what is above.
			'images' => array( 'type' => 'gallery', 'label' => 'Work samples' ),

			'body_after' => array(
				'type'    => 'rich',
				'label'   => 'Notes',
				'heading' => 'Notes',
				'help'    => 'What you saw, in your own words. Shown under the results.',
			),

			'method' => array(
				'type'      => 'rich',
				'label'     => 'How the method addresses this',
				'heading'   => 'How does Evidence-Based Literacy Instruction support phonemic awareness?',
				'edit_rank' => 3,
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::segmenting_method(),
			),
		);

		/*
		 * Reading grade level, which is a level rather than a count.
		 *
		 * The score is one of ten named levels, so it is chosen rather than
		 * typed: a free text box here produced "Preprimer", "pre-primer" and
		 * "PP" for the same finding. There is no denominator, because a level
		 * is not a fraction, and the bar is the level's position in that list
		 * of ten — Preprimer is the first of ten, so the card shows a tenth
		 * filled, exactly as it would for a score of one out of ten.
		 */
		$s['dxsec-3']['benchmark']  = 10;
		$s['dxsec-3']['unit']       = '';
		$s['dxsec-3']['own_order']  = true;
		$s['dxsec-3']['score_rank'] = 'reading_level';   // how the bar reads the score
		$s['dxsec-3']['meter_note'] = 'Preprimer to Grade 8';
		$s['dxsec-3']['fields']     = array(
			'score' => array(
				'type'    => 'select',
				'label'   => 'Independent reading level',
				'card'    => true,
				'options' => array_merge( array( '' => 'Not assessed' ), self::reading_levels() ),
				'help'    => 'The level the student reads at without help. The bar on the card is this level\'s place in the list.',
			),

			// The other two bands, chosen from the same ten levels. They sit
			// beside the independent level because a tutor reads all three off
			// one scoring sheet in one go, and they are marked as drawn
			// elsewhere so they are not also printed as loose rows under the
			// table that already reports them.
			'instructional' => array(
				'type'    => 'select',
				'label'   => 'Instructional level',
				'card'    => true,
				'options' => array_merge( array( '' => 'Not assessed' ), self::reading_levels() ),
				'help'    => 'Three to four errors. Shown in the word reading table.',
			),
			'frustration' => array(
				'type'    => 'select',
				'label'   => 'Frustration level',
				'card'    => true,
				'options' => array_merge( array( '' => 'Not assessed' ), self::reading_levels() ),
				'help'    => 'Five or more errors. Shown in the word reading table.',
			),

			'body' => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'label'     => 'Intro',
				'help'      => 'What the assessment is for. The table of levels is drawn under this.',
				'shipped'   => self::wordreading_intro(),
			),

			// How the levels are read. Fixed, and the same for every child,
			// so it is drawn rather than written, and it is drawn whether or
			// not this child has been assessed yet.
			'levels_key' => array(
				'type'  => 'score_table',
				'label' => 'How the levels are read',
				'derived_note' => 'Fixed, and the same on every report. Nothing here is typed.',
				'head'  => array( 'Level', 'Description' ),
				'rows'  => array(
					array( 'Independent, 0 to 2 errors',   'Reads comfortably, accurately and fluently without help. Ideal for silent reading and confidence building.' ),
					array( 'Instructional, 3 to 4 errors', 'Reads with some support. The optimal zone for instruction and guided text.' ),
					array( 'Frustration, 5 or more errors', 'Too difficult even with support. Not appropriate for instruction or assessment passages.' ),
				),
			),

			// This child's own result against those three bands, drawn from the
			// three choices above rather than typed a second time. A band left
			// at "Not assessed" is left out of the table entirely, the same as
			// every other empty field in this report.
			'levels' => array(
				'type'  => 'score_table',
				'label' => 'Word reading fluency assessment',
				'title' => 'Word reading fluency assessment',
				'derived_note' => 'Drawn from the three levels chosen above. Nothing here is typed.',
				'head'  => array( 'Independent level, 0 to 2 errors', '{score}' ),
				'rows'  => array(
					array( 'Instructional level, 3 to 4 errors',  '{instructional}' ),
					array( 'Frustration level, 5 or more errors', '{frustration}' ),
				),
			),

			'images' => array( 'type' => 'gallery', 'label' => 'Work samples' ),

			'body_after' => array(
				'type'    => 'rich',
				'label'   => 'Notes',
				'heading' => 'Notes',
				'help'    => 'What you saw, in your own words. Shown under the results.',
			),

			'method' => array(
				'type'      => 'rich',
				'label'     => 'How the method addresses this',
				'heading'   => 'How does Evidence-Based Literacy Instruction support word reading?',
				'edit_rank' => 3,
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::wordreading_method(),
			),
		);

		/*
		 * Reading a passage, which is three counts and a comparison.
		 *
		 * The figure that matters is correct words per minute, and what makes
		 * it mean anything is the end-of-year norm for a grade. That norm is
		 * not a property of this section, the way forty letters or thirty-six
		 * sounds are: it changes with the child. So the bar's goal is read
		 * from a field rather than fixed on the section, which is the only
		 * place in this report where that is true.
		 *
		 * Two of the ten bands have no published figure, and a bar cannot be
		 * drawn against nothing. That is handled by saying so beside the score
		 * rather than by inventing a number to divide by.
		 */
		$s['dxsec-4']['unit']            = 'correct words per minute';
		$s['dxsec-4']['own_order']       = true;
		$s['dxsec-4']['benchmark_field']  = 'norm';
		$s['dxsec-4']['benchmark_lookup'] = 'fluency_norm';   // the band stands for a figure

		// Two assessments, and a student does one. The tutor says which; a
		// report from before there was a choice is read from what was filled
		// in, word list first, as it always was. Fields marked 'form' belong
		// to one of them only, on the report and on the tutor's screen.
		$s['dxsec-4']['form_field']     = 'form';
		$s['dxsec-4']['form_infer']     = array( 'kinder' => 'kinder_score', 'passage' => 'words' );
		$s['dxsec-4']['form_benchmark'] = array(
			'kinder' => array( 'field' => 'min_grade', 'lookup' => 'word_minimum' ),
		);
		$s['dxsec-4']['fields']          = array(
			'form' => array(
				'type'    => 'select',
				'label'   => 'Which assessment',
				'card'    => true,   // a choice, not something printed on the report
				'options' => array(
					''        => 'Not chosen',
					'passage' => 'Timed passage reading',
					'kinder'  => 'Kindergarten word fluency',
				),
				'help'    => 'Only one is done. The report shows the one chosen here. Kindergarten word fluency is for a student whose independent reading level is Kindergarten or lower.',
			),

			'words'  => array( 'type' => 'number', 'label' => 'Total words read', 'card' => true, 'form' => 'passage',
			                   'help' => 'What the one minute timing produced, before errors are taken off.' ),
			'errors' => array( 'type' => 'number', 'label' => 'Errors', 'card' => true, 'form' => 'passage' ),

			// Total words read, less the errors. On the word list there is no
			// passage and no errors to take off, so the figure is the one
			// counted from the list.
			'score'  => array( 'type' => 'number', 'label' => 'Correct words per minute', 'card' => true, 'form' => 'passage',
			                   'derived' => array( 'minus' => array( 'words', 'errors' ), 'else' => 'kinder_score', 'else_form' => 'kinder' ),
			                   'help' => 'The figure the card reports. The bar is this against the norm chosen below.' ),
			'norm'   => array(
				'type'    => 'select',
				'label'   => 'End of year norm for enrolled grade',
				'card'    => true,
				'form'    => 'passage',
				'options' => self::fluency_norm_options(),
				'help'    => 'The band this reading is measured against. It sets the goal on the card.',
			),

			'kinder_score' => array(
				'type'  => 'number',
				'label' => 'Total words per minute',
				'card'  => true,
				'form'  => 'kinder',
				'help'  => 'Counted from the one minute Kindergarten word list. The figure the card reports.',
			),
			'min_grade' => array(
				'type'    => 'select',
				'label'   => 'Enrolled grade',
				'card'    => true,
				'form'    => 'kinder',
				'options' => self::word_minimum_options(),
				'help'    => 'Sets the minimum for the enrolled grade in the results table, and the goal on the card.',
			),

			/* ---- Timed passage reading ---- */
			'passage_intro' => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'form'      => 'passage',
				'label'     => 'Intro, timed passage reading',
				'help'      => 'What the assessment is. The norms and the results are drawn under this.',
				'shipped'   => self::passage_intro(),
			),
			'passage_norms' => array(
				'type'  => 'score_table',
				'form'  => 'passage',
				'label' => 'Passage reading norms',
				'title' => 'Passage reading norms for oral reading fluency',
				'derived_note' => 'Fixed, and the same on every report. Nothing here is typed.',
				'head'  => array( 'Grade', '50th percentile (spring) WCPM' ),
				'rows'  => self::passage_norm_rows(),
				'note'  => 'WCPM: words correct per minute, toward the end of a grade level (spring).',
			),
			'passage_results' => array(
				'type'     => 'score_table',
				'live'     => true,
				'form'     => 'passage',
				'keep_row' => true,
				'label'    => 'Results',
				'title'    => '1-minute timed reading, unpracticed grade-level passage',
				'derived_note' => 'Drawn from the figures above. Nothing here is typed.',
				'head'     => array( 'Total words read', 'Errors', 'WCPM (words correct per minute)', 'End of year ORF norm for enrolled grade' ),
				'rows'     => array( array( '{words}', '{errors}', '{score} WCPM', '{norm_line}' ) ),
			),
			'passage_note' => array(
				'type'  => 'reference',
				'form'  => 'passage',
				'label' => 'About the norm',
				'html'  => self::passage_norm_note(),
			),

			/* ---- Kindergarten word fluency ---- */
			'kinder_intro' => array(
				'type'      => 'rich',
				'edit_rank' => 3,
				'form'      => 'kinder',
				'label'     => 'Intro, Kindergarten word fluency',
				'heading'   => 'Kindergarten word fluency assessment',
				'help'      => 'What the assessment is. The minimums and the results are drawn under this.',
				'shipped'   => self::kinder_intro(),
			),
			'kinder_minimums' => array(
				'type'  => 'score_table',
				'form'  => 'kinder',
				'label' => 'Minimum speed benchmarks',
				'derived_note' => 'Fixed, and the same on every report. Nothing here is typed.',
				'head'  => array( 'Grade', 'Minimum speed benchmarks' ),
				'rows'  => self::word_minimum_rows(),
			),
			'kinder_results' => array(
				'type'     => 'score_table',
				'live'     => true,
				'form'     => 'kinder',
				'keep_row' => true,
				'needs'    => 'kinder_score',
				'label'    => 'Results',
				'title'    => '1-minute timed reading, unpracticed Kindergarten word list',
				'derived_note' => 'Drawn from the figures above. Nothing here is typed.',
				'head'     => array( 'Total WPM', 'Minimum WPM for Kindergarten', 'Minimum WPM for enrolled grade' ),
				'rows'     => array( array( '{kinder_score}', '{k_min_line}', '{min_grade_line}' ) ),
				'note'     => 'WPM: words per minute.',
			),

			'images' => array( 'type' => 'gallery', 'label' => 'Work samples' ),

			'body_after' => array(
				'type'    => 'rich',
				'label'   => 'Notes',
				'heading' => 'Notes',
				'help'    => 'What you saw, in your own words. Shown under the results.',
			),

			'passage_method' => array(
				'type'      => 'rich',
				'label'     => 'How Linguistic Literacy helps, timed passage reading',
				'heading'   => 'How does Linguistic Literacy help?',
				'edit_rank' => 3,
				'form'      => 'passage',
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::passage_method(),
			),
			'kinder_method' => array(
				'type'      => 'rich',
				'label'     => 'How Linguistic Literacy helps, Kindergarten word fluency',
				'heading'   => 'How does Linguistic Literacy help?',
				'edit_rank' => 3,
				'form'      => 'kinder',
				'help'      => 'The practice\'s standing answer. Shown under the notes.',
				'shipped'   => self::kinder_method(),
			),
		);

		// A checklist sits inside the document rather than after it: the
		// tutor's notes and how the method addresses what was found both come
		// after the findings. So those two sections get a second body.
		// Every skill area now declares its own fields, so the loop that used
		// to add a body after the checklist has nothing left to serve. It ran
		// after those sections and put its own version back over theirs, which
		// is why a rename to Notes looked as though it had not taken.

		// For the areas still on the generated field set, the work samples
		// close the section. A section that declares its own fields has
		// already said where they go, and this must not quietly move them.
		foreach ( array_keys( $skills ) as $sid ) {
			if ( ! empty( $s[ $sid ]['own_order'] ) ) continue;
			$f = $s[ $sid ]['fields'];
			$images = isset( $f['images'] ) ? $f['images'] : null;
			unset( $f['images'] );
			if ( $images ) $f['images'] = $images;
			$s[ $sid ]['fields'] = $f;
		}

		$s['sec-strengths-areas-for-growth'] = array(
			'screen'  => self::SCREEN_REPORT,
			'label'   => 'Strengths and areas for growth',
			'eyebrow' => 'Profile',
			'thread'  => true,
			'fields'  => array(
				'strengths' => array( 'type' => 'rich', 'label' => 'Strengths' ),
				'growth'    => array( 'type' => 'rich', 'label' => 'Areas for growth' ),
			),
		);

		$s['sec-recommended-approach'] = array(
			'screen'  => self::SCREEN_REPORT,
			'label'   => 'Recommended approach',
			'eyebrow' => 'The plan',
			'thread'  => true,
			'fields'  => array(
				'body' => array( 'type' => 'rich', 'label' => 'Recommended approach' ),
			),
		);

		$s['sec-instructional-plan'] = array(
			'screen'  => self::SCREEN_REPORT,
			'label'   => 'Instructional plan',
			'eyebrow' => 'The plan',
			'thread'  => true,
			'fields'  => array(
				'body'      => array( 'type' => 'rich', 'label' => 'Instructional plan' ),
				'frequency' => array( 'type' => 'text', 'label' => 'Recommended frequency' ),
				'duration'  => array( 'type' => 'text', 'label' => 'Expected duration' ),
			),
		);

		$s['sec-conclusion'] = array(
			'screen'  => self::SCREEN_REPORT,
			'label'   => 'Conclusion',
			'eyebrow' => 'Closing',
			'thread'  => true,
			'fields'  => array(
				'body' => array( 'type' => 'rich', 'label' => 'Conclusion' ),
			),
		);

		/* ---------------------------------------------------------------
		 * Progress report
		 * ------------------------------------------------------------- */

		$s['sec-welcome'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'Welcome',
			'eyebrow' => 'Front matter',
			'thread'  => true,
			'fields'  => array(
				'body' => array( 'type' => 'rich', 'label' => 'Welcome' ),
			),
		);

		$s['sec-progress-overview'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'Progress overview',
			'eyebrow' => 'Where things stand',
			'thread'  => true,
			'chart'   => 'journey',
			'summary' => 'Drawn from every reading diagnostic on file. Nothing here is typed in.',
			'fields'  => array(
				'intro' => array( 'type' => 'textarea', 'label' => 'One-line explainer', 'lede' => true,
					'help' => 'What this chart shows, in a sentence.' ),
			),
			'derived' => 'The three milestone cards and the reading level chart are read from this student\'s reading diagnostics, and the target from the grade on their record. Publish another diagnostic and another point appears.',
		);

		$s['sec-activity-map'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'The spiral made visible',
			'eyebrow' => 'How the method works',
			'thread'  => true,
			'chart'   => 'coverage',
			'fields'  => array(
				'intro' => array( 'type' => 'textarea', 'label' => 'One-line explainer', 'lede' => true, 'help' => 'What this chart shows, in a sentence.' ),
			),
			'derived' => 'Built automatically from the skills the activities on each session teach, and from any skill a tutor recorded on the session itself.',
		);

		$s['sec-texts-read'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'What we have read so far',
			'eyebrow' => 'Reading log',
			'thread'  => true,
			'chart'   => 'texts',
			'fields'  => array(
				'intro' => array( 'type' => 'textarea', 'label' => 'One-line explainer', 'lede' => true ),
			),
			'derived' => 'Every text listed on the activities used in a session. Texts are kept on the activity, so they are written down once.',
		);

		/*
		 * Reading speed, on its own chart.
		 *
		 * A separate section from the reading level rather than a second line
		 * on the same axes: a level and a rate cannot share a y scale, and a
		 * child can gain thirty words a minute inside one reading level, which
		 * the level chart cannot show at all.
		 */
		$s['sec-fluency'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'Words correct per minute',
			'eyebrow' => 'Reading speed',
			'thread'  => true,
			'chart'   => 'wpm',
			'fields'  => array(
				'intro' => array( 'type' => 'textarea', 'label' => 'Explainer', 'lede' => true ),
			),
			'derived' => 'Every timed reading on record, against the end of year benchmark for the grade the student is in.',
		);

		/*
		 * How the programme is being attended, rather than what was taught in
		 * it. Placed before the session list because it is the shape of the
		 * thing the list is made of, and because the one figure on it that
		 * changes what a family does — how long since the last session — is
		 * not visible anywhere in a list sorted newest first.
		 */
		$s['sec-attendance'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'Attendance',
			'eyebrow' => 'How it is going',
			'thread'  => true,
			'chart'   => 'attendance',
			'fields'  => array(
				'intro' => array( 'type' => 'textarea', 'label' => 'Explainer', 'lede' => true ),
			),
			'derived' => 'Counted from the session records: how many were attended, moved or missed, and how long it has been since the last one.',
		);

		$s['sec-sessions'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'Sessions',
			'eyebrow' => 'Session by session',
			'thread'  => false, // each session carries its own thread instead
			'sessions' => true,
			'summary' => 'Session records are edited one at a time, from the Sessions screen.',
			'fields'  => array(),
		);

		/*
		 * The missed sessions used to be a six column table of their own,
		 * further down the report. They are rows inside Schedule and
		 * attendance now: a family reading about the day they remember wants
		 * the reason and who recorded it, not a column to compare down, and
		 * two places listing the same cancellations in two different shapes
		 * is one place too many.
		 */

		$s['sec-legend-and-definitions'] = array(
			'screen'  => self::SCREEN_PROGRESS,
			'label'   => 'Legend and definitions',
			'eyebrow' => 'Reference',
			'thread'  => true,
			'fields'  => array(
				'terms' => array(
					'type'    => 'rows',
					'label'   => 'Terms',
					'columns' => array(
						'term' => array( 'type' => 'text',     'label' => 'Term' ),
						'defn' => array( 'type' => 'textarea', 'label' => 'What it means' ),
					),
				),
			),
		);

		/* ---------------------------------------------------------------
		 * Resources and setup
		 * ------------------------------------------------------------- */

		$s['sec-home-practice'] = array(
			'screen'  => self::SCREEN_RESOURCES,
			'label'   => 'Suggestions for home',
			'eyebrow' => 'At home',
			'thread'  => true,
			'library' => 'bftd_resource',
			'summary' => 'Shared library. Assign the ones that apply to each student.',
			'fields'  => array(),
		);

		$s['sec-setup'] = array(
			'screen'  => self::SCREEN_SETUP,
			'label'   => 'Getting set up',
			'eyebrow' => 'Before your first session',
			'thread'  => true,
			'library' => 'bftd_resource',
			'summary' => 'Document camera, headset and KoalaGo walkthroughs.',
			'fields'  => array(),
		);

		self::$sections = apply_filters( 'bftd_sections', $s );
		return self::$sections;
	}

	public static function section( $id ) {
		$all = self::sections();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public static function sections_for_screen( $screen ) {
		$out = array();
		foreach ( self::sections() as $id => $cfg ) {
			if ( $cfg['screen'] === $screen ) $out[ $id ] = $cfg;
		}
		return $out;
	}

	/** The one screen a post type shows on, which is what a preview renders. */
	public static function screen_for_post_type( $post_type ) {
		foreach ( self::screens() as $screen => $cfg ) {
			if ( $cfg['post_type'] === $post_type ) return $screen;
		}
		return '';
	}

	public static function sections_for_post_type( $post_type ) {
		$screens = array();
		foreach ( self::screens() as $screen => $cfg ) {
			if ( $cfg['post_type'] === $post_type ) $screens[] = $screen;
		}
		$out = array();
		foreach ( self::sections() as $id => $cfg ) {
			if ( in_array( $cfg['screen'], $screens, true ) ) $out[ $id ] = $cfg;
		}
		return $out;
	}

	/** Sections that accept a parent conversation thread. */
	public static function thread_sections() {
		$out = array();
		foreach ( self::sections() as $id => $cfg ) {
			if ( ! empty( $cfg['thread'] ) ) $out[ $id ] = $cfg;
		}
		return $out;
	}

	public static function label( $id ) {
		$sec = self::section( $id );
		return $sec ? $sec['label'] : $id;
	}

	/** The postmeta key one field is stored under. */
	public static function meta_key( $section_id, $field_key ) {
		return '_bftd_' . str_replace( '-', '_', $section_id ) . '__' . $field_key;
	}
}
