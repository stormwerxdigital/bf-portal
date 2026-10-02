<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Nothing gets typed twice.
 *
 * A reading diagnostic asks several things the student record already knows:
 * what grade they are in, when the diagnostic happened, who did it, how fast
 * they read at the start. Asking again is not just slow, it is how two
 * answers to one question end up in the database and nobody can say which is
 * right.
 *
 * So the report screen fills those in from the student the moment a student
 * is chosen.
 *
 * FILLED IN, NOT DERIVED, and the distinction matters. A diagnostic is a
 * photograph: it records what was true on the day. The student record is the
 * present tense and moves on. If the grade on a diagnostic were read live
 * from the student, last year's report would silently start claiming the
 * child was in the grade they are in now. So the value is copied once, into
 * a field that can then be corrected, and nothing keeps it in step
 * afterwards. That is the right answer for a record of an assessment.
 *
 * ONLY WHAT IS EMPTY. A field with something in it is somebody's work and is
 * never overwritten.
 */
class BFTD_Prefill {

	/**
	 * Report field to student field.
	 *
	 * Keyed by "section.field" on the report. Each entry names where the
	 * value comes from on the student and how to turn it into what the
	 * report field expects, because a grade is stored as "3" and a report
	 * wants to say "Grade 3".
	 */
	public static function map() {
		return apply_filters( 'bftd_prefill_map', array(

			/* The diagnostic's own header. */
			'sec-assessment-overview.grade' => array(
				'from' => 'grade',
				'as'   => 'grade_label',
			),
			'sec-assessment-overview.assessed_by' => array(
				'from' => 'assigned_tutor',
				'as'   => 'raw',
			),

			/*
			 * The date, the reading level and the speed used to be prefilled
			 * from the student record, back when the student record was where
			 * somebody typed them. It is the other way round now: those three
			 * are read off the diagnostics, so prefilling a new diagnostic
			 * from them would be filling this assessment in with the last
			 * one's answers — a reassessment that opens already agreeing with
			 * the result it is meant to test.
			 */
		) );
	}

	/**
	 * Everything a report for this student could be filled in with.
	 *
	 * Returns section => field => value, with anything the student has no
	 * answer for left out entirely rather than sent as an empty string. The
	 * browser fills in what it is given and leaves the rest alone, so a
	 * missing value and an empty one behave identically.
	 */
	public static function values( $student_id, $post_type = '' ) {
		$student_id = (int) $student_id;
		if ( ! $student_id ) return array();

		$sections = $post_type ? BFTD_Schema::sections_for_post_type( $post_type ) : array();

		$out = array();
		foreach ( self::map() as $target => $rule ) {
			list( $section_id, $key ) = array_pad( explode( '.', $target, 2 ), 2, '' );
			if ( ! $section_id || ! $key ) continue;

			// Skip anything not on this kind of report, so a diagnostic is
			// never handed progress report fields.
			if ( $sections && ! isset( $sections[ $section_id ] ) ) continue;

			$value = self::read( $student_id, $rule );
			if ( '' === $value || null === $value ) continue;

			$out[ $section_id ][ $key ] = $value;
		}
		return $out;
	}

	/** One value, in the shape the report field wants. */
	private static function read( $student_id, $rule ) {
		$from = isset( $rule['from'] ) ? $rule['from'] : '';
		$as   = isset( $rule['as'] ) ? $rule['as'] : 'raw';

		if ( 'assigned_tutor' === $from ) {
			$staff = BFTD_CPT::staff_ids( $student_id );
			return $staff ? (string) $staff[0] : '';
		}

		$value = (string) BFTD_Fields::get( $student_id, 'student', $from );
		if ( '' === $value ) return '';

		if ( 'grade_label' === $as ) {
			$options = BFTD_MetaBoxes::grade_options();
			return isset( $options[ $value ] ) ? $options[ $value ] : $value;
		}

		if ( 'level_label' === $as ) {
			$options = BFTD_MetaBoxes::level_options();
			return isset( $options[ $value ] ) ? $options[ $value ] : $value;
		}

		return $value;
	}

	/**
	 * What the student screen should say about this, so the arrangement is
	 * visible rather than a surprise the first time a field fills itself.
	 */
	public static function explain() {
		$labels = array();
		foreach ( self::map() as $target => $rule ) {
			list( $section_id, $key ) = array_pad( explode( '.', $target, 2 ), 2, '' );
			$section = BFTD_Schema::section( $section_id );
			if ( ! $section || ! isset( $section['fields'][ $key ] ) ) continue;
			$labels[] = $section['fields'][ $key ]['label'] . ' (' . $section['label'] . ')';
		}
		return $labels;
	}
}
