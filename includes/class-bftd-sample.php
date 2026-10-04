<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Two finished reports, so anybody can see what one looks like.
 *
 * A blank report tells a new tutor nothing about how much to write, how a
 * section reads once it is filled, or what the family actually receives. A
 * finished one answers all three in about thirty seconds.
 *
 * The content is real work, lightly trimmed: Kaine's reading diagnostic and
 * Jot's progress report. Names are kept because these were written as
 * examples to hand around, and a sample full of "Student A" reads like a
 * form rather than like a report.
 *
 * These are plain arrays in the same shape the field store returns, handed
 * to BFTD_Fields::use_fixture, so the sample renders through the ordinary
 * report renderer and cannot look different from the real thing.
 */
class BFTD_Sample {

	/** Ids for the sample's own lesson records. Far outside any real range. */
	const SESSION_BASE = 990000;

	/* The stand-in activity library the sample's lessons point at. */
	const ACTIVITY_BASE = 993000;

	/** The sample's own skill ids, well clear of anything real. */
	const SKILL_BASE = 994000;

	/* The stand-in diagnostic a sample progress report pulls from. Far away
	   from any real post id, the same way the sample lessons are. */
	const DX_ID = 995003;

	public static function all() {
		return array(
			'diagnostic' => array(
				'title'   => 'Reading diagnostic, sample',
				'student' => 'Kaine Miller',
				'screen'  => BFTD_Schema::SCREEN_REPORT,
				'note'    => 'This is a sample report, filled in with real assessment content so you can see how a finished one reads. Nothing here belongs to a student in this dashboard.',
				'fields'  => self::diagnostic(),
				'threads' => self::threads( 'diagnostic' ),
				'items'   => array(
					BFTD_Items::PRIORITY => array(
						array(
							'text'    => 'Print the handwriting practice sheet and try five minutes a day',
							'detail'  => 'The "Print Rhythm Leader" sheet is in Resources. Have Kaine say each prompt aloud as he writes the letter. Five minutes is plenty.',
							'section' => '',
						),
					),
					BFTD_Items::REVIEW => array(
						array(
							'text'    => 'Read the baseline report before your review call',
							'detail'  => 'You do not need to understand every number. Read the Assessment overview at the top and the letter to Kaine at the end, and bring any questions to the call.',
							'section' => '',
						),
					),
				),
			),
			'progress' => array(
				'title'   => 'Progress report, sample',
				'student' => 'Jot Singh',
				'screen'  => BFTD_Schema::SCREEN_PROGRESS,
				'note'    => 'This is a sample progress report, filled in with real content so you can see how a finished one reads. Nothing here belongs to a student in this dashboard.',
				'fields'  => self::progress(),
				'threads' => self::threads( 'progress' ),
				'eyebrow' => 'Jot Singh · Grade 3 · started 5 July 2026 · tutor Laurel Sanders',
				// Skills have a length and activities do not: a child is
				// somewhere along the list of things this practice teaches,
				// while the activity library is the ways of teaching them and
				// nobody goes through all of it.
				'kpis'    => array(
					array( 16, 'sessions recorded' ),
					array( '14<span style="font-size:15px;color:var(--muted)">/' . count( self::sample_skills_library() ) . '</span>', 'skills we\'re building' ),
					array( 6, 'texts read, up to Grade 4' ),
					array( 18, 'activities completed' ),
				),
				'sessions' => self::lessons(),
				'skills_library' => self::sample_skills_library(),
				// The four tables a progress report builds rather than stores.
				// A sample has no lessons or activities in the database behind
				// it, so it answers these the way it answers its stored fields:
				// from a fixture, cleared the moment the sample is rendered.
				'derived'    => self::derived(),
				'activities' => self::sample_activities(),
				'diagnostic' => self::DX_ID,
				// The sample has no student, so no portal screen of its own to
				// link to. It points at the diagnostic sample, which is the
				// thing a reader of this page actually wants to open.
				'diagnostic_link' => class_exists( 'BFTD_Preview' ) ? BFTD_Preview::sample_url( 'diagnostic' ) : '',
				'posts'      => array( self::DX_ID => self::dx_for_progress() ),
				'items'   => array(
					BFTD_Items::PRIORITY => array(
						array(
							'text'    => 'Listen to an audiobook together, twenty minutes, eyes on the text',
							'detail'  => 'Any book Jot enjoys, read above his own reading level. The point is his eyes following the printed words while he hears them.',
							'section' => '',
							'checked' => 1,
						),
						array(
							'text'    => 'Bring the reading log to the next session',
							'detail'  => '',
							'section' => '',
						),
					),
					BFTD_Items::REVIEW => array(
						array(
							'text'    => 'Look through the skills covered map before the next call',
							'detail'  => 'The orange squares are the sessions a skill was first taught. The pale ones after it are every session it came back in. That spacing is the programme working as intended.',
							'section' => '',
						),
					),
				),
			),
		);
	}

	public static function get( $which ) {
		$which = trim( (string) $which, '-' );
		$all   = self::all();
		if ( ! $which ) $which = 'diagnostic';
		return isset( $all[ $which ] ) ? $all[ $which ] : null;
	}

	/* ------------------------------------------------------------------ */
	/* Kaine Miller, reading diagnostic                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Mocked conversations, so the sample shows the part families use most.
	 *
	 * A question belongs where it was asked, and the whole point of putting
	 * a thread inside every section is that a parent reading about spelling
	 * can ask about spelling without leaving the paragraph. A sample with
	 * empty threads everywhere shows the box but not the reason for it.
	 */
	private static function threads( $which ) {
		if ( 'diagnostic' === $which ) {
			return array(
				'dxsec-1' => array(
					array(
						'name' => 'Dana Miller', 'mine' => true, 'when' => '3 days ago',
						'title' => 'Tuesday, 11 August 2026 at 7:42 PM',
						'body'  => 'Is 25 out of 40 as far behind as it sounds? He has always been slow at writing and I assumed that was just him.',
					),
					array(
						'name' => 'Candice Paluck', 'when' => '3 days ago',
						'title' => 'Tuesday, 11 August 2026 at 9:15 PM',
						'body'  => 'It is behind, but it is the most fixable thing on this report. Handwriting speed responds to a few minutes of the right practice per session, and it is not a measure of how bright he is. What it does do is slow everything else down, which is why we start here.',
					),
				),
				'dxsec-2' => array(
					array(
						'name' => 'Dana Miller', 'mine' => true, 'when' => '2 days ago',
						'title' => 'Wednesday, 12 August 2026 at 8:02 PM',
						'body'  => 'What does /k/ /a/ /t/ mean when it is written with the slashes?',
					),
					array(
						'name' => 'Candice Paluck', 'when' => '2 days ago',
						'title' => 'Wednesday, 12 August 2026 at 8:40 PM',
						'body'  => 'Slashes mean the <b>sound</b> rather than the letter. So /k/ is the sound at the start of cat and also at the start of kite, even though they are spelled differently. There is a full list of these marks in the legend at the end of the report.',
					),
				),
				'dxsec-5' => array(
					array(
						'name' => 'Dana Miller', 'mine' => true, 'when' => '2 days ago',
						'title' => 'Wednesday, 12 August 2026 at 8:11 PM',
						'body'  => 'Three out of twenty five was hard to read. Should we be doing spelling lists at home?',
					),
					array(
						'name' => 'Candice Paluck', 'when' => '2 days ago',
						'title' => 'Wednesday, 12 August 2026 at 8:52 PM',
						'body'  => 'Please do not. Lists ask him to memorise words he cannot yet hear the sounds in, so they tend to confirm to a child that spelling is something they are bad at. The score is low on purpose here, because the errors are what tell me which patterns to teach first.',
					),
				),
			);
		}

		return array(
			'sec-progress-overview' => array(
				array(
					'name' => 'Priya Singh', 'mine' => true, 'when' => '5 days ago',
					'title' => 'Monday, 1 September 2026 at 8:02 PM',
					'body'  => 'Reading speed went from 40 to 64. Is that on track, or should it be faster by now?',
				),
				array(
					'name' => 'Laurel Sanders', 'when' => '5 days ago',
					'title' => 'Tuesday, 2 September 2026 at 8:40 AM',
					'body'  => 'That is 24 words per minute in two months, which is ahead of where I expected him. The Grade 3 benchmark is 83, so he is not there yet, but the shape of the line matters more than the number and this line is steep.',
				),
			),
			'sec-activity-map' => array(
				array(
					'name' => 'Priya Singh', 'mine' => true, 'when' => '8 days ago',
					'title' => 'Saturday, 30 August 2026 at 6:48 PM',
					'body'  => 'Why do some skills keep coming back after they were taught? I thought once he had it we would move on.',
				),
				array(
					'name' => 'Laurel Sanders', 'when' => '8 days ago',
					'title' => 'Sunday, 31 August 2026 at 11:20 AM',
					'body'  => 'That spacing is deliberate. A skill met once is recognised, a skill met again three sessions later is owned. It is the difference between this and a programme that gates each step until it is mastered and then never returns to it.',
				),
			),
		);
	}

	/**
	 * The six assessment sections, written out in full.
	 *
	 * A sample report exists to show a family what a finished one reads
	 * like, so an abridged section teaches nobody anything. Each of these
	 * carries what the design gives a section: what the task was and why
	 * it matters, the result as a table, the tutor's notes, and how the
	 * method addresses what was found.
	 */
	private static function dx_bodies() {
		return array(
			'dxsec-1' => array( 'body_after' => <<<'HTML'
<p>Kaine willingly completed this part of the assessment. He found the activity challenging, which tells us he would benefit from additional alphabet handwriting practice. As observed during both this activity and his spelling assessment, Kaine lacks lowercase letter formation skills, lacks discernment between capital and lowercase letters, and lacks automaticity, which is the ability to write smoothly, evenly and without conscious effort.</p>
<p>These weaknesses negatively affect reading and writing. Each session will therefore include five minutes of explicit handwriting instruction. Practising printing at home between sessions will also help.</p>
HTML,
			),
			// Only what is about this child. The introduction, the results
			// table and the standing answer about the method all come from
			// the schema, so the sample cannot drift from what a real report
			// says: they are the same wording, resolved the same way.
			'dxsec-2' => array( 'body_after' => <<<'HTML'
<p>Identifying individual sounds within words was challenging for Kaine, which is very common for developing readers. The good news is that sound by sound awareness is a skill that can be explicitly taught and strengthened. This is one of the key areas where Evidence-Based Literacy Instruction is especially effective.</p>
HTML,
			),

			// Only what is about this child. The introduction, both tables and
			// the standing answer all come from the schema.
			'dxsec-3' => array( 'body_after' => <<<'HTML'
<p>Kaine&#8217;s independent reading level is currently Preprimer and his instructional level is Primer. To support his progress we will bring the Switch It activity into sessions. It pairs especially well with this method for older students who are still developing strong word identification, because it combines phonemic awareness, phonics, spelling and reading into one integrated activity.</p>
<p>At this stage the most supportive home practice is <strong>listening to an audiobook while the eyes follow the matching text</strong>. Audiobooks give exposure to rich vocabulary, background knowledge and strong language models, and having the eyes track printed words while listening strengthens word recognition, sound to spelling connections and visual attention to print.</p>
<p>All direct reading, spelling and handwriting instruction is provided during sessions. <strong>There is no expectation of drilling, memorisation or extra teaching at home.</strong></p>
HTML,
			),

			// Only what is about this child. The introduction, both tables and
			// the two standing explanations all come from the schema.
			'dxsec-4' => array( 'body_after' => <<<'HTML'
<p>Kaine currently reads a small number of Kindergarten level words accurately within a one minute timing, which tells us his word reading fluency is still developing. We will apply the strategies set out in the word reading section to build it systematically.</p>
HTML,
			),

			// Only what is about this child. The introduction, the results and
			// the standing answer all come from the schema.
			'dxsec-5' => array( 'body_after' => <<<'HTML'
<p>Kaine has difficulty applying multi-letter spellings, where two, three or four letters work together to represent a single sound. This is a very teachable skill, and it is one this method introduces in a way that accelerates learning, using clear sound to symbol strategies. Spelling, reading and writing are integrated throughout instruction, so the skills build together rather than in isolation.</p>
<p>Kaine is sometimes unsure when to use uppercase and lowercase letters. He also tends to omit, insert or place spellings in the wrong order. That reflects phonemic awareness, the ability to identify individual sounds in words, which is critical to reading and spelling because that is how the brain stores language. The brain maps sounds to print.</p>
HTML,
			),

			'dxsec-6' => array( 'body' => <<<'HTML'
<p>Kaine was encouraged to write a sentence or a short paragraph, depending on his comfort and ability. We chose the topic together, something that interested him and felt manageable. If a student&#8217;s anxiety rises or the task becomes overwhelming we pause for a brain break or move on. The goal is to build confidence, not pressure.</p>
<p><strong>This is the only time a student is asked to write without being helped to correct their writing.</strong></p>
HTML,
				'body_after' => <<<'HTML'
<p><strong>Transcribed for clarity:</strong> I like to travel the world.</p>
<h3>Notes</h3>
<p>Kaine willingly provided an independent writing sample. Writing conventions, end punctuation, capitalisation and complete sentence structure will be taught as part of integrated literacy instruction rather than as a separate subject.</p>
<h3>How does Evidence-Based Literacy Instruction support writing?</h3>
<p>Writing is not saved for the end. It is part of every activity from the first session, because writing a word is what proves a student can hear its sounds and choose spellings for them. Conventions are taught inside real sentences the student has written, rather than as a worksheet about commas.</p>
HTML,
			),
		);
	}

	private static function diagnostic() {
		$out = array(

			// The student's own record, which the heading line draws on.
			'student' => array(
				'birth_date' => '2013-07-06',
				'school'     => 'Chewelah School District',
				'grade'      => '8',
			),

			'sec-assessment-overview' => array(
				// A programme always begins with the initial assessment, and
				// this sample is the baseline its hero line says it is.
				'kind'        => 'initial',
				'assessed_on' => '2026-08-11',
				'assessed_by' => '',
				'grade'       => 'Grade 8',
				'headline'    => 'Kaine can name exactly where reading trips him up, and every one of those things is teachable.',
				'overview'    => '<p>This diagnostic establishes a baseline of Kaine&#8217;s reading and writing strengths and challenges across six core literacy areas. It is not a formal evaluation. It is designed to guide instructional planning should tutoring move forward.</p>'
					. '<p>Kaine took part in a comprehensive assessment covering six skill areas. The results give a baseline profile of his current literacy and inform an individual plan using Evidence-Based Literacy Instruction.</p>'
					. '<p>He told me he learned more in fifty minutes online during the assessment than he had in the past seven years, and he left excited to begin.</p>',
			),

			// Twenty-five of the twenty-six letters he wrote were correct. The
			// bar is that against the goal of 40, which the page works out.
			// Twenty-five of the twenty-six letters he wrote were correct. The
			// bar is that against the goal of 40, which the page works out,
			// and the two tables under the prose are drawn rather than typed.
			'dxsec-1' => array(
				'score'     => '25',
				'score_of'  => '26',
				'marking'   => array(
					'lowercase' => 'He wrote a to z with the order reversed for m and n. The letter e was illegible and counted as an error.',
					'bottom_up' => 'Kaine wrote his letters top to bottom.',
					'capitals'  => 'Some confusion between upper and lowercase.',
					// Reversals is left blank on purpose, so the sample shows
					// what a rule with nothing to report looks like.
				),
				'images'    => array( BFTD_URL . 'assets/img/work-sample.svg' ),
			),

			// Sixteen of the thirty-six sounds. The bar is that against the
			// fixed target of 36, which the page works out, and the results
			// table under the prose is drawn rather than typed.
			'dxsec-2' => array(
				'score'     => '16',
				'score_of'  => '36',
				'images'    => array( BFTD_URL . 'assets/img/work-sample.svg' ),
			),

			// The first of the ten reading levels, so the bar shows a tenth
			// filled. The levels table under the prose is the tutor's own
			// reading of the three bands; the table above it explaining what
			// those bands mean is fixed and drawn by the schema.
			'dxsec-3' => array(
				'score'         => 'preprimer',
				'instructional' => 'primer',
				'frustration'   => 'grade-1',
				// No work sample here on purpose. Reading a word list aloud
				// leaves nothing behind to photograph, and the field is still
				// offered in case a tutor has the scoring sheet.
			),

			// Twelve correct words in the minute, measured against the Grade 1
			// band. The bar is that against 60, which the page works out from
			// the band chosen rather than from a percentage anybody typed.
			// Kaine reads at Preprimer, so he did the Kindergarten word list
			// rather than a passage. That is the case the introduction to this
			// section describes, and it is the one worth demonstrating: the
			// results table swaps, and the explanation that belongs with the
			// word list appears with it.
			'dxsec-4' => array(
				'kinder_score' => '12',
				'norm'         => 'grade-1',
				'images'       => array( BFTD_URL . 'assets/img/work-sample.svg' ),
			),

			// Fifty-seven of eighty-nine individual spellings, but three of the
			// twenty-five whole words. The card reports the whole words,
			// because that is the figure with a denominator to measure it
			// against, and the bar is that fraction.
			'dxsec-5' => array(
				'word_list'    => 'grade-1',
				'spellings'    => '57',
				'spellings_of' => '89',
				'score'        => '3',
				'score_of'     => '25',
				// Answers against the fixed list, not rows of its own.
				'challenges' => array(
					'reversals' => array( 'value' => 'no' ),
					'omits'     => array( 'value' => 'yes', 'note' => 'Omitted the n in junk, the t in gift and the l in slap.' ),
					'blends'    => array( 'value' => 'yes' ),
					'multi'     => array( 'value' => 'yes', 'note' => 'The pattern behind most of the errors on the list.' ),
					'inserts'   => array( 'value' => 'yes' ),
					'order'     => array( 'value' => 'yes', 'note' => 'Wrote payl for play.' ),
				),
				'images' => array( BFTD_URL . 'assets/img/work-sample.svg' ),
			),

			// Two fixed lists, answered. The card counts how many were marked
			// yes against how many were answered at all, so both figures come
			// from the same rows and neither is typed.
			'dxsec-6' => array(
				'conventions' => array(
					'indent'      => array( 'value' => 'na',  'note' => 'Only one sentence written.' ),
					'caps_start'  => array( 'value' => 'no',  'note' => 'Sentences were not defined by end marks. Capital letters used inappropriately.' ),
					'caps_proper' => array( 'value' => 'na',  'note' => 'Capital letters used inappropriately.' ),
					'end_punct'   => array( 'value' => 'no',  'note' => 'No punctuation was used.' ),
					'other_punct' => array( 'value' => 'no',  'note' => 'No punctuation was used.' ),
					'spaces'      => array( 'value' => 'no' ),
					'each_sound'  => array( 'value' => 'no',  'note' => 'Omitted spellings for sounds in like, travel and world.' ),
					'misspelled'  => array( 'value' => 'yes', 'note' => 'Five misspelled out of six words in total.' ),
				),
				'grammar' => array(
					'complete'  => array( 'value' => 'no' ),
					'run_on'    => array( 'value' => 'na' ),
					'combined'  => array( 'value' => 'na' ),
					'variety'   => array( 'value' => 'no' ),
					'flow'      => array( 'value' => 'no' ),
					'order'     => array( 'value' => 'na' ),
					'vivid'     => array( 'value' => 'no',  'note' => 'It is not unusual for a student to rely on simple sentence structure without colourful language. That is a skill that can be explicitly taught.' ),
					'multisyll' => array( 'value' => 'yes', 'note' => 'One.' ),
				),
				'images' => array( BFTD_URL . 'assets/img/work-sample.svg' ),
			),

			'sec-strengths-areas-for-growth' => array(
				'strengths' => '<ul>'
					. '<li>Cooperative, willing and fully engaged throughout the assessment</li>'
					. '<li>Strong self-awareness: he recognises and can explain his own reading confusion</li>'
					. '<li>High motivation and buy-in, eager to begin and felt he learned a great deal</li>'
					. '<li>No letter reversals of b, d, p or q, and left-to-right directionality is intact</li>'
					. '</ul>',
				'growth' => '<ul>'
					. '<li>Phonemic awareness, hearing individual sounds in sequence, currently 16 of 36</li>'
					. '<li>Applying multi-letter spelling patterns, where two to four letters spell one sound</li>'
					. '<li>Handwriting automaticity, consistent lowercase formation, and telling upper from lowercase</li>'
					. '<li>Word and oral reading fluency, independent level Preprimer at 12 correct words per minute</li>'
					. '<li>Writing conventions: end punctuation, capitalisation and complete sentences</li>'
					. '</ul>',
			),

			'sec-recommended-approach' => array(
				'body' => '<p>These results point to a clear starting place: instruction built on sound. EBLI teaches one flexible skill and applies it to everything a student reads and writes. Hear the sounds in a whole word, match those sounds to the letters that spell them, then use that same thinking to both read and spell.</p>'
					. '<p>Starting from sounds in whole words, rather than matching letters or letter names to sounds in isolation, is what makes reading come faster. This research-aligned approach is called speech-to-print, or linguistic phonics.</p>'
					. '<ul>'
					. '<li>It folds multiple skills into every session: phonemic awareness, phonics, spelling, vocabulary, reading, writing and handwriting.</li>'
					. '<li>There are no rules to memorise. Your child sorts words by pattern: the same sound can be spelled several ways, and the same letters can make different sounds.</li>'
					. '<li>Patterns return on a spiral, repeated and spaced over time, so your child never has to master one step before moving to the next.</li>'
					. '<li>A lower cognitive load means progress comes faster still. Many students grow one to three grade levels, sometimes more, in a single year.</li>'
					. '</ul>',
			),

			'sec-instructional-plan' => array(
				'frequency' => '2 sessions per week',
				'duration'  => 'Reassessed at Activity 70 and Activity 140',
				'body' => '<ul>'
					. '<li>Consistent EBLI-based instruction, two sessions per week.</li>'
					. '<li>Reinforce the core elements: each sound can be represented by one to four letters, a single sound can be spelled several ways, a single spelling can represent several sounds, and a strategy for decoding multisyllable words.</li>'
					. '<li>Reinforce spelling conventions through word sorts and patterns rather than memorisation or flashcards, such as when to use ck or k at the end of a word, c or k at the beginning, and ch or tch at the end.</li>'
					. '<li>Teach these as part of integrated literacy instruction during creative writing activities.</li>'
					. '<li>Include brief structured alphabet handwriting practice, three to five minutes per session, to build automaticity.</li>'
					. '<li>Progress updates, including activities introduced and completed, shared through this dashboard and available at any time.</li>'
					. '</ul>',
			),

			'sec-conclusion' => array(
				'body' => '<p>Kaine is a delight to work with. His enthusiasm and willingness to tackle new challenges are qualities that will support his growth as a reader and writer.</p>'
					. '<p>His standout strength is not a test score, it is self-awareness. Kaine could name exactly where reading trips him up: he has never been sure when letters work on their own and when they team up to spell a single sound. I explained that two, three or four letters can represent one sound, and reassured him that we will focus on teaching those patterns. He was excited that we had identified one of his main areas of difficulty.</p>'
					. '<p>A student who can locate his own confusion is ready to learn. His growth areas are all highly teachable and quick to respond to instruction. Paired with his motivation and clean letter directionality, that is a strong foundation to build on.</p>'
					. '<p><strong>To Kaine.</strong> Here is what we want you to know: the way you have been taught to read is not the way the brain learns to process language, and that is not your fault. We have seen students just like you make remarkable progress once they are taught an approach that finally makes sense. You have not failed at reading. You have simply not yet had the right tools.</p>'
					. '<p>Please do not hesitate to reach out with any questions. We would count it a privilege to support Kaine on his reading and writing journey.</p>'
					. '<p>Warmly,<br>Candice Paluck<br>Brilliant Futures Tutoring</p>',
			),
		);

		// The six assessment sections are written out separately because they
		// are documents rather than values, and reading them beside a list of
		// scores makes both harder to follow.
		foreach ( self::dx_bodies() as $sid => $parts ) {
			if ( isset( $out[ $sid ] ) ) $out[ $sid ] = array_merge( $out[ $sid ], $parts );
		}
		return $out;
	}

	/**
	 * The sixteen lessons behind Jot's progress report.
	 *
	 * Real enough to demonstrate the parts of the report that are built from
	 * lesson records rather than typed: the coverage map, the counts across
	 * the top, and the session stream. Activities repeat on a spiral, which is
	 * the whole point of the map, so the dates and the repeats are the ones
	 * from the programme rather than invented.
	 */
	/**
	 * The stand-in activity library the sample's lessons point at.
	 *
	 * A finished report names its activities the way the library names them:
	 * track, number, name. A sample whose lessons carried typed names showed
	 * "Sound Lines" where a real report shows "Track 1 · 2 · Sound Lines",
	 * which is exactly the thing a sample is for and exactly what it was
	 * getting wrong.
	 *
	 * Numbered within each track, because that is how the programme numbers
	 * them: each track counts its own from one.
	 */
	/**
	 * Which skills each activity builds.
	 *
	 * The same map the spiral used to be generated from, moved onto the
	 * activities themselves — which is where a real practice keeps it. The
	 * sample's grid, the skills named on each session record and the summary
	 * above them are now all read off this one map by the real code, so they
	 * cannot tell a parent three versions of the same term.
	 */
	private static function teaches() {
		return array(
			'Alphabet Handwriting Practice'         => array( 'Forming lowercase letters automatically' ),
			'Handwriting Fluency with a Metronome'  => array( 'Forming lowercase letters automatically', 'Writing evenly and without effort' ),
			'1 to 4 Letters Spell a Sound'          => array( 'One sound can be spelled by several letters' ),
			'Phoneme Manipulation: Deletion'        => array( 'Holding the sounds of a word in order' ),
			'Listen, Tally, Say, Write (LTSW)'      => array( 'Holding the sounds of a word in order', 'Writing every sound heard' ),
			'Sound Lines'                           => array( 'Mapping sounds to their spellings' ),
			'Multisyllable Sound Lines'             => array( 'Mapping sounds to their spellings', 'Reading a long word by chunk' ),
			'Multisyllable Split Word Reading'      => array( 'Reading a long word by chunk' ),
			'Multisyllable Spelling'                => array( 'Reading a long word by chunk', 'Writing every sound heard' ),
			'Consonant-e Pattern'                   => array( 'Reading a spelling pattern as a pattern' ),
			'Double Consonant'                      => array( 'Reading a spelling pattern as a pattern' ),
			'Same Sound / Different Spelling'       => array( 'Reading a spelling pattern as a pattern', 'Flexing a sound until the word makes sense' ),
			'Same Spelling / Different Sound'       => array( 'Flexing a sound until the word makes sense' ),
			'Homophones'                            => array( 'Letting meaning decide the spelling' ),
			'High Frequency Words'                  => array( 'Reading common words by their sounds' ),
			'Read, Read Back, Read Again'           => array( 'Reading a passage smoothly' ),
			'Sound Search Activity'                 => array( 'Finding a pattern in a real text' ),
			'Key word, Action, Thing (KAT)'         => array( 'Working out what a sentence is saying' ),
		);
	}

	/**
	 * The sample's skill library, in the shape BFTD_Skills::all() returns:
	 * id => array( number, name, track, group ), in first-taught order.
	 *
	 * Each skill takes the track of the first activity that teaches it, and
	 * is numbered in that track from one. The ones still ahead are Track 1.
	 * It used to be id => name, which stopped working when skills gained
	 * numbers and tracks: the real skills code reads a row, and the sample
	 * preview died on the first skill name it looked up.
	 */
	public static function sample_skills_library() {
		$t23   = self::sample_t23();
		$track = array();
		foreach ( self::teaches() as $activity => $skills ) {
			foreach ( $skills as $name ) {
				if ( ! isset( $track[ $name ] ) ) $track[ $name ] = isset( $t23[ $activity ] ) ? 't23' : 't1';
			}
		}
		$out = array();
		$n   = array( 't1' => 0, 't23' => 0 );
		foreach ( self::sample_skill_names() as $id => $name ) {
			$t = isset( $track[ $name ] ) ? $track[ $name ] : 't1';
			$out[ $id ] = array(
				'number' => ++$n[ $t ],
				'name'   => $name,
				'track'  => $t,
				'group'  => 'skill',
			);
		}
		return $out;
	}

	/**
	 * The sample's skill names: id => name, in first-taught order.
	 *
	 * The ones the sample's activities build, and then the ones still ahead of
	 * this child. Without the second group the library was exactly the list
	 * Jot had already reached, and the stat tile read "14 of 14" — a child
	 * eight weeks into a year being shown a finished programme, which is the
	 * one thing a sample must not say.
	 */
	private static function sample_skill_names() {
		$out = array();
		$id  = self::SKILL_BASE;
		foreach ( self::teaches() as $skills ) {
			foreach ( $skills as $name ) {
				if ( ! in_array( $name, $out, true ) ) $out[ ++$id ] = $name;
			}
		}
		foreach ( array(
			'Reading a compound word as two words',
			'Hearing a syllable break',
			'Spelling a plural',
			'Reading a contraction',
			'Keeping a long sentence in mind to its end',
			'Reading dialogue as speech',
			'Writing a paragraph that holds together',
			'Working out a word from the sentence around it',
		) as $name ) {
			if ( ! in_array( $name, $out, true ) ) $out[ ++$id ] = $name;
		}
		return $out;
	}

	/** The id the sample's library holds for a skill name. */
	private static function skill_id( $name ) {
		$found = array_search( $name, self::sample_skill_names(), true );
		return $found ? (int) $found : 0;
	}

	/**
	 * The sample activities in Tracks 2 and 3, by name. Everything not named
	 * here is Track 1, which is the foundational programme and most of what a
	 * first term is. Read by the activities and by the skills they teach.
	 */
	private static function sample_t23() {
		return array(
			'Sound Search Activity'         => true,
			'Key word, Action, Thing (KAT)' => true,
			'Homophones'                    => true,
		);
	}

	private static function sample_activities() {
		$t23 = self::sample_t23();

		$about   = self::activity_notes();
		$teaches = self::teaches();
		$out     = array();
		$n       = array( 't1' => 0, 't23' => 0 );
		$id      = self::ACTIVITY_BASE;

		foreach ( $about as $name => $words ) {
			$track  = isset( $t23[ $name ] ) ? 't23' : 't1';
			$skills = array();
			foreach ( isset( $teaches[ $name ] ) ? $teaches[ $name ] : array() as $sk ) {
				$sid = self::skill_id( $sk );
				if ( $sid ) $skills[] = $sid;
			}
			$out[ ++$id ] = array(
				'number' => ++$n[ $track ],
				'name'   => $name,
				'track'  => $track,
				'about'  => $words,
				'skills' => $skills,
			);
		}
		return $out;
	}

	/** The id the sample's lessons point at for a given activity name. */
	private static function activity_id( $name ) {
		foreach ( self::sample_activities() as $id => $one ) {
			if ( $one['name'] === $name ) return $id;
		}
		return 0;
	}

	private static function activity_notes() {
		return array(
			'Alphabet Handwriting Practice' => 'Five minutes of printing the lowercase alphabet, said aloud as it is written. The aim is motor memory, not neatness: once letter formation is automatic it stops competing for attention and frees the brain for reading and spelling.',
			'1 to 4 Letters Spell a Sound' => 'One sound can be spelled by one, two, three or four letters. Once a child expects that, words like night and eight stop being exceptions to memorise and become patterns to read.',
			'Phoneme Manipulation: Deletion' => 'Saying a word, then saying it again with one sound removed. It sounds like a game and it is the clearest measure we have of whether a child can hold a word\'s sounds in order.',
			'Sound Lines' => 'Drawing a line under each sound in a word, then writing the spelling above it. It makes the invisible visible: how many sounds are in the word, and which letters are doing which job.',
			'Consonant-e Pattern' => 'The pattern in cake, ride and hope, taught as a pattern rather than as a rule about a silent e.',
			'Double Consonant' => 'When a sound is spelled with two of the same letter, as in happy or bell, and why that is not random.',
			'Same Sound / Different Spelling' => 'The sound /ee/ can be spelled ee, ea, y, ie or ey. Sorting words by sound rather than by spelling is what makes English look logical instead of arbitrary.',
			'Multisyllable Sound Lines' => 'Sound lines applied to longer words, one chunk at a time, so a word like fantastic is read rather than guessed at.',
			'Read, Read Back, Read Again' => 'Read a line, read it back, read it again. Three passes turn slow decoding into fluent reading in a few minutes, without any speed drilling.',
			'Sound Search Activity' => 'Hunting for every spelling of one sound in a real text. It moves a pattern from a worksheet into the books a child is actually reading.',
			'Handwriting Fluency with a Metronome' => 'Once every letter can be formed correctly, a metronome adds a steady rhythm. Still untimed, still practice, but it builds the evenness that makes writing effortless.',
			'Multisyllable Split Word Reading' => 'Splitting a long word between two chunks, reading each, then blending. A strategy for any unfamiliar word rather than a list to learn.',
			'Same Spelling / Different Sound' => 'The letters ea say something different in dream, bread and great. Children learn to flex the sound until the word makes sense, which is what proficient readers do.',
			'Key word, Action, Thing (KAT)' => 'A simple frame for working out what a sentence is actually saying, used on real text rather than on comprehension questions.',
			'High Frequency Words' => 'The words that appear most often, taught by their sounds and spellings like every other word, not memorised as shapes.',
			'Listen, Tally, Say, Write (LTSW)' => 'Listen to the word, tally its sounds, say it, write it. Four steps that catch the sounds a child would otherwise leave out.',
			'Homophones' => 'Words that sound the same and are spelled differently, sorted by meaning: their, there and they\'re stop being a trap once the meaning drives the spelling.',
			'Multisyllable Spelling' => 'Spelling long words by chunk, saying as you write, with immediate correction. It is how a child gets from reading a word to owning it.',
		);
	}

	private static function lessons() {
		$plan = array(
			1 => array( '2026-07-07', array( array( 'Alphabet Handwriting Practice', 1 ), array( '1 to 4 Letters Spell a Sound', 1 ), array( 'Phoneme Manipulation: Deletion', 1 ), array( 'Sound Lines', 1 ), array( 'Consonant-e Pattern', 1 ) ) ),
			2 => array( '2026-07-09', array( array( 'Double Consonant', 1 ) ) ),
			3 => array( '2026-07-13', array( array( 'Phoneme Manipulation: Deletion', 0 ), array( 'Same Sound / Different Spelling', 1 ), array( 'Multisyllable Sound Lines', 1 ) ) ),
			4 => array( '2026-07-14', array( array( 'Sound Lines', 0 ) ) ),
			5 => array( '2026-07-21', array( array( 'Alphabet Handwriting Practice', 0 ), array( 'Read, Read Back, Read Again', 1 ), array( 'Sound Search Activity', 1 ) ) ),
			6 => array( '2026-07-23', array( array( 'Alphabet Handwriting Practice', 0 ), array( 'Read, Read Back, Read Again', 0 ) ) ),
			7 => array( '2026-07-28', array( array( 'Alphabet Handwriting Practice', 0 ), array( 'Phoneme Manipulation: Deletion', 0 ), array( 'Read, Read Back, Read Again', 0 ) ) ),
			8 => array( '2026-07-30', array( array( 'Handwriting Fluency with a Metronome', 1 ), array( 'Multisyllable Split Word Reading', 1 ) ) ),
			9 => array( '2026-08-04', array( array( 'Phoneme Manipulation: Deletion', 0 ), array( 'Multisyllable Sound Lines', 0 ), array( 'Handwriting Fluency with a Metronome', 0 ), array( 'Same Spelling / Different Sound', 1 ) ) ),
			10 => array( '2026-08-06', array( array( 'Handwriting Fluency with a Metronome', 0 ), array( 'Multisyllable Split Word Reading', 0 ) ) ),
			11 => array( '2026-08-11', array( array( 'Read, Read Back, Read Again', 0 ) ) ),
			12 => array( '2026-08-13', array( array( 'Key word, Action, Thing (KAT)', 1 ) ) ),
			13 => array( '2026-08-18', array( array( 'Phoneme Manipulation: Deletion', 0 ), array( 'Key word, Action, Thing (KAT)', 0 ), array( 'High Frequency Words', 1 ) ) ),
			14 => array( '2026-08-20', array( array( 'Same Sound / Different Spelling', 0 ), array( 'High Frequency Words', 0 ), array( 'Listen, Tally, Say, Write (LTSW)', 1 ) ) ),
			15 => array( '2026-08-25', array( array( 'Homophones', 1 ) ) ),
			16 => array( '2026-09-03', array( array( 'Read, Read Back, Read Again', 0 ), array( 'Key word, Action, Thing (KAT)', 0 ), array( 'Multisyllable Spelling', 1 ) ) ),
		);

		/*
		 * One session that did not go ahead.
		 *
		 * It is a record like any other, because that is what it is: the
		 * missed table and the attendance figures are both counted off these
		 * sessions now rather than written out separately, so a sample with
		 * nothing but attended sessions would show an empty missed table and
		 * a clean sheet — neither of which demonstrates anything.
		 */
		$cancelled = array(
			17 => array(
				'session_date'   => '2026-08-07',
				'status'         => 'rescheduled',
				'missed_by'      => 'family',
				'missed_why'     => 'Family away',
				'missed_madeup'  => '2026-08-11',
				// Stamped on save in a real practice. Written out here because
				// the sample has no save to stamp it.
				'missed_marked_by' => 0,
				'missed_marked_at' => '2026-08-06 09:12:00',
			),
		);

		$notes = array(
			1 => 'We began with the alphabet timing, then moved straight into sound lines using words from Jot\'s own speech. He was cautious at first and much more willing by the end of the fifty minutes.',
			5 => 'Read, Read Back, Read Again on The Yucky Feeling. First pass was effortful, third pass was smooth and expressive. Jot noticed the difference himself, which matters more than the timing.',
			9 => 'Multisyllable sound lines on four and five syllable words. Jot\'s instinct is still to guess from the first letter, and he is catching himself doing it, which is the step before stopping.',
			13 => 'Key word, Action, Thing applied to Rook of the Pines. Jot summarised page two back to me with no prompting.',
			16 => 'Multisyllable spelling introduced, then straight into a Grade 4 text. He read it.',
		);
		// What used to be "what to do at home" is the homework, since the
		// lesson screen no longer asks for both.
		$homework = array(
			1 => 'Five minutes of the printing sheet, saying each prompt aloud. Nothing else this week.',
			5 => 'Twenty minutes of audiobook with eyes on the printed text. Any book he enjoys.',
			9 => 'Keep going with the audiobook. If he wants to read aloud to you, let him pick something easy.',
			13 => 'Ask him what happened first in whatever he is reading. One question is enough.',
			16 => 'Nothing new. Keep the audiobook going over the break.',
		);

		// What was read in which lesson. The reading log on the family's report
		// is built from these, so the two cannot disagree.
		$read = array(
			5  => array( array( 'title' => 'The Yucky Feeling', 'level' => 'Grade 1' ),
			             array( 'title' => 'Sammy and the Yucky Feeling', 'level' => 'Grade 2' ) ),
			6  => array( array( 'title' => 'A Time at the Lake', 'level' => 'Grade 2' ) ),
			7  => array( array( 'title' => 'The Mean Green Dragon', 'level' => 'Grade 3' ) ),
			11 => array( array( 'title' => 'Rook of the Pines', 'level' => 'Grade 4' ) ),
			12 => array( array( 'title' => 'Rook of the Pines', 'level' => 'Grade 4' ) ),
			13 => array( array( 'title' => 'Rook of the Pines', 'level' => 'Grade 4' ),
			             array( 'title' => 'A Time at the Lake', 'level' => 'Grade 2' ) ),
			16 => array( array( 'title' => 'This is North America', 'level' => 'Grade 4' ) ),
		);

		$out = array();
		foreach ( $plan as $n => $row ) {
			list( $date, $acts ) = $row;

			// Pointing at the library, the way a real lesson does. Which one
			// was the first to use an activity is worked out by the report
			// itself, across the whole term, so nothing here says so.
			$rows = array();
			foreach ( $acts as $a ) {
				$id = self::activity_id( $a[0] );
				if ( $id ) $rows[] = array( 'id' => (string) $id, 'note' => '' );
			}

			$out[ self::SESSION_BASE + $n ] = array( 'session' => array(
				'session_date' => $date,
				'status'       => 'held',
				'notes'        => isset( $notes[ $n ] ) ? $notes[ $n ] : '',
				'homework'     => isset( $homework[ $n ] ) ? $homework[ $n ] : '',
				'activities'   => $rows,
				// Texts are recorded on the lesson that read them, so the
				// sample's lessons carry them too rather than the reading log
				// appearing from nowhere.
				'texts'        => isset( $read[ $n ] ) ? $read[ $n ] : array(),
			) );
		}

		foreach ( $cancelled as $n => $record ) {
			$out[ self::SESSION_BASE + $n ] = array( 'session' => $record );
		}

		// Oldest first, which is the order a real report reads them back in.
		// The cancelled one belongs where it happened rather than on the end,
		// or the fixture disagrees with every screen that draws it.
		uasort( $out, function ( $a, $b ) {
			return strcmp( $a['session']['session_date'], $b['session']['session_date'] );
		} );
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Jot Singh, the tables a progress report builds                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The stand-in reading diagnostic the sample progress report pulls from.
	 *
	 * Only what that pull actually shows: the overview a parent reads and the
	 * six scores the cards are drawn from. The full written sections live on
	 * the diagnostic sample, which is where the link goes.
	 */
	private static function dx_for_progress() {
		return array(
			'sec-assessment-overview' => array(
				'kind'        => 'interim',
				'assessed_on' => '2026-09-01',
				'grade'       => 'Grade 3',
				'headline'    => 'Jot has moved a full reading level in two months, and his handwriting is no longer competing for his attention.',
				'overview'    => '<p>Jot was reassessed on 1 September, eight weeks after his baseline. Every one of the six areas has moved, and two of them have moved a long way: handwriting automaticity from 14 letters a minute to 29, and passage reading from 40 words a minute to 64.</p>'
					. '<p>His independent reading level is now Grade 2. That is the number families ask about most, and it is the slowest one to move, so a full level in eight weeks is ahead of what I expected when we started.</p>',
			),
			'dxsec-1' => array( 'score' => '29', 'score_of' => '32' ),
			'dxsec-2' => array( 'score' => '36', 'score_of' => '36' ),
			'dxsec-3' => array( 'score' => 'grade-2' ),
			// Two of the six work their score out rather than being told it:
			// fluency is the words read less the errors, and writing is a
			// count of the points marked yes. Handing those a score directly
			// would be filling in a field nobody can fill in, and the card
			// would come out blank — which it did.
			'dxsec-4' => array( 'words' => '71', 'errors' => '7', 'norm' => 'grade-3' ),
			'dxsec-5' => array( 'score' => '75', 'score_of' => '79' ),
			'dxsec-6' => array(
				'conventions' => self::writing_marks( array(
					'indent' => 'no', 'caps_start' => 'yes', 'caps_proper' => 'yes', 'end_punct' => 'yes',
					'other_punct' => 'no', 'spaces' => 'yes', 'each_sound' => 'yes', 'misspelled' => 'yes',
				) ),
				'grammar' => self::writing_marks( array(
					'complete' => 'yes', 'run_on' => 'no', 'combined' => 'yes', 'variety' => 'yes',
					'flow' => 'yes', 'order' => 'yes', 'vivid' => 'no', 'multisyll' => 'yes',
				) ),
			),
		);
	}

	/** A checklist answer set, in the shape the checklist field stores. */
	private static function writing_marks( $answers ) {
		$out = array();
		foreach ( $answers as $key => $value ) $out[ $key ] = array( 'value' => $value, 'note' => '' );
		return $out;
	}

	/**
	 * The four derived tables, for a report with no records behind it.
	 *
	 * These are what BFTD_Derived would read off Jot's lessons, activities and
	 * diagnostics if he had any. Written out here so the sample shows the real
	 * shape of each one — including the parts that only appear once there is
	 * something to say, like a text read in more than one lesson.
	 */
	private static function derived() {
		return array(
			// Only the two that cannot be built. Everything else on this list
			// used to be written out here and is now read off the sample's own
			// sessions by the real code: the reading log, the skills, the
			// missed table and the attendance figures. A sample that carries
			// its answers cannot show a family what the code does with theirs,
			// and it drifts — this one charted a mark out of forty for a
			// section that marks sixteen.
			//
			// The two that remain are readings of the child, which no amount
			// of session records can produce.
			'milestones' => self::sample_milestones(),
			'journey'    => self::sample_journey(),
			'fluency'    => self::sample_fluency(),
		);
	}

	/**
	 * The same journey in words a minute.
	 *
	 * The figures are the sample diagnostic's own: forty words a minute at the
	 * baseline, sixty four at the reassessment, which is what the overview on
	 * that card says in words. Jot is in Grade 3, so the benchmark he is
	 * heading for is the Grade 3 one.
	 */
	private static function sample_fluency() {
		$target = BFTD_Schema::grade_target_wcpm( '3' );
		return array(
			'points' => array(
				array( 'report' => 995001, 'kind' => 'initial', 'when' => 'Initial',
					'on' => '2026-07-05', 'ts' => BFTD_Time::stamp( '2026-07-05' ),
					'rank' => 40, 'level' => '40 wpm' ),
				array( 'report' => 995002, 'kind' => 'interim', 'when' => 'Interim',
					'on' => '2026-08-04', 'ts' => BFTD_Time::stamp( '2026-08-04' ),
					'rank' => 52, 'level' => '52 wpm' ),
				array( 'report' => self::DX_ID, 'kind' => 'interim', 'when' => 'Interim',
					'on' => '2026-09-01', 'ts' => BFTD_Time::stamp( '2026-09-01' ),
					'rank' => 64, 'level' => '64 wpm' ),
			),
			'target' => array( 'rank' => $target, 'level' => $target . ' wpm' ),
		);
	}

	/**
	 * Where a programme is up to, and the three cards that say so.
	 *
	 * Jot is eight weeks in: the initial assessment is behind him, the middle
	 * and the final are not. That is the useful shape to show in a sample,
	 * because a family reading their own report will usually be somewhere in
	 * the middle of it too.
	 */
	private static function sample_milestones() {
		return array(
			'initial' => array( 'kind' => 'initial', 'label' => 'Initial', 'done' => true,
				'id' => 995001, 'on' => '2026-07-05', 'ts' => BFTD_Time::stamp( '2026-07-05' ) ),
			'middle'  => array( 'kind' => 'middle',  'label' => 'Middle',  'done' => false,
				'id' => 0, 'on' => '', 'ts' => 0 ),
			'final'   => array( 'kind' => 'final',   'label' => 'Final',   'done' => false,
				'id' => 0, 'on' => '', 'ts' => 0 ),
		);
	}

	/**
	 * Reading grade level, from where Jot started to the grade he is in.
	 *
	 * An interim reading between the initial and today, because that is the
	 * case worth showing: it is a real measurement and it is not one of the
	 * three milestones, and the chart has to carry both without confusing the
	 * two.
	 */
	private static function sample_journey() {
		return array(
			'points' => array(
				array( 'report' => 995001, 'kind' => 'initial', 'when' => 'Initial',
					'on' => '2026-07-05', 'ts' => BFTD_Time::stamp( '2026-07-05' ),
					'rank' => 3, 'level' => 'Grade 1' ),
				array( 'report' => 995002, 'kind' => 'interim', 'when' => 'Interim',
					'on' => '2026-08-04', 'ts' => BFTD_Time::stamp( '2026-08-04' ),
					'rank' => 3, 'level' => 'Grade 1' ),
				array( 'report' => self::DX_ID, 'kind' => 'interim', 'when' => 'Interim',
					'on' => '2026-09-01', 'ts' => BFTD_Time::stamp( '2026-09-01' ),
					'rank' => 4, 'level' => 'Grade 2' ),
			),
			// Jot is in Grade 3, so Grade 3 is what he is aiming at.
			'target' => array( 'rank' => 5, 'level' => 'Grade 3' ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Jot Singh, progress report                                          */
	/* ------------------------------------------------------------------ */

	private static function progress() {
		return array(

			'student' => array( 'grade' => '3' ),

			'sec-welcome' => array(
				'body' => '<p>Welcome to Brilliant Futures Tutoring. We are so glad to have your child joining us on their reading journey. At the heart of our approach is EBLI, Evidence-Based Literacy Instruction, a research-backed system that teaches students how to read, spell and write with accuracy and confidence. EBLI is not a traditional phonics program or a memorisation-based method. It equips students with a set of thinking strategies they can apply to any word, in any context, for life.</p>'
					. '<p>Unlike methods that require years of repetition and rule memorisation, EBLI focuses on developing the brain&#8217;s ability to process written language efficiently. Instruction is fast-paced, interactive and student-centred. Sessions are designed to eliminate confusion by streamlining instruction to what is essential. Students learn to connect sounds to letters and letter combinations, identify patterns, and analyse the structure and meaning of words.</p>'
					. '<p>You will likely begin seeing improvements not just in reading accuracy, but in your child&#8217;s confidence and motivation. EBLI works with students of all ages and abilities, from emerging readers to those who have struggled for years. This is a living document, updated as we go.</p>',
			),

			'sec-progress-overview' => array(
				'intro' => 'Where Jot started, where he is now, and the reading level of his own grade, which is what we are working towards.',
			),

			'sec-activity-map' => array(
				'intro' => 'Skills are introduced then keep coming back, spaced over time. Nothing has to be mastered before the next thing starts, and that spacing is what makes the programme different.',
			),

			'sec-texts-read' => array(
				'intro' => 'Every text used in a session, with the level it is written at.',
			),

			'sec-attendance' => array(
				'intro' => 'How often Jot is here, counted from the session records themselves.',
			),

			'sec-fluency' => array(
				'intro' => 'How many words Jot reads correctly in a minute, against the benchmark for his own grade.',
			),


			'sec-legend-and-definitions' => array(
				'terms' => array(
					array( 'term' => '&lt;_&gt;', 'defn' => 'The name of a letter. For example &lt;b&gt; is the letter b, and &lt;gh&gt; is the letters g and h.' ),
					array( 'term' => '/_/', 'defn' => 'The sound of a letter or letters. For example /f/ as in the first sound in fish or phone, or the last sound in cough.' ),
					array( 'term' => 'New', 'defn' => 'The first time an activity appears it is labelled New, with a short overview explaining what it is for. After that it appears without the label.' ),
					array( 'term' => 'Parent notes', 'defn' => 'Personalised observations about your child, marked throughout the report.' ),
					array( 'term' => 'Session number', 'defn' => 'A number beside a date indicates the session number. For example 14 July 2026, 1 means session one.' ),
				),
			),
		);
	}
}
