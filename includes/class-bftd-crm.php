<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keeping student details in step with the CRM.
 *
 * THE RULE, decided deliberately: a tutor's typing wins. The sync fills what
 * is blank and corrects what it filled last time, and it never overwrites
 * something a person changed here. A record that a tutor has corrected by
 * hand is the more recent knowledge; silently replacing it on the next sync
 * would make the dashboard untrustworthy in exactly the situation where
 * somebody had noticed the CRM was wrong.
 *
 * HOW THAT IS TOLD APART, because "changed by a person" is not a thing a
 * field remembers on its own. Every value the CRM writes is also kept in a
 * shadow copy. On the next sync, for each field:
 *
 *   the value is empty            -> the CRM fills it
 *   the value equals the shadow   -> untouched since the CRM wrote it, so the
 *                                    CRM may update it
 *   the value differs from shadow -> a person changed it, so it is left alone
 *                                    and reported as held back
 *
 * That is a three way merge, and it is the only way to answer the question
 * without keeping a dirty flag on every field and hoping nothing bypasses it.
 * Clearing a field by hand hands it back to the CRM, which is the escape
 * hatch when the local value was the wrong one.
 *
 * WHAT IS NOT HERE. No transport. Nothing in this file knows what
 * GoHighLevel is or how to talk to it. It takes a plain array of incoming
 * values and merges it, so the connector, whenever it is written, is a thin
 * thing that fetches and calls apply().
 */
class BFTD_CRM {

	const KEY_OPTION = 'bftd_crm_key';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The way in                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * One endpoint, which the CRM posts a student to.
	 *
	 * Authenticated with a shared key rather than a WordPress login, because
	 * the caller is a machine with no account here. The key travels in a
	 * header so it never lands in a server access log the way a query string
	 * would.
	 */
	public static function routes() {
		register_rest_route( 'bftd/v1', '/student', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => array( __CLASS__, 'authorised' ),
		) );
	}

	public static function authorised( $request ) {
		$key = self::key();
		if ( '' === $key ) {
			return new WP_Error( 'bftd_crm_off', 'The CRM connection has not been set up yet.', array( 'status' => 503 ) );
		}

		$sent = (string) $request->get_header( 'x-bftd-key' );
		if ( '' === $sent ) $sent = (string) $request->get_param( 'key' );

		// Constant time, so the key cannot be guessed a character at a time
		// by measuring how long the answer takes to come back.
		if ( ! hash_equals( $key, $sent ) ) {
			return new WP_Error( 'bftd_crm_denied', 'That key is not right.', array( 'status' => 401 ) );
		}
		return true;
	}

	public static function handle( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! $body ) $body = $request->get_params();

		$result = self::upsert( $body );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'ok'    => false,
				'error' => $result->get_error_message(),
			), 400 );
		}

		return new WP_REST_Response( array(
			'ok'         => true,
			'student_id' => $result['student_id'],
			'created'    => $result['created'],
			'edit_url'   => get_edit_post_link( $result['student_id'], 'raw' ),
			'written'    => $result['merge']['written'],
			'held'       => $result['merge']['held'],
			'journey'    => $result['merge']['journey'],
		), $result['created'] ? 201 : 200 );
	}

	public static function endpoint_url() {
		return rest_url( 'bftd/v1/student' );
	}

	public static function key() {
		return (string) get_option( self::KEY_OPTION, '' );
	}

	public static function new_key() {
		$key = wp_generate_password( 40, false, false );
		update_option( self::KEY_OPTION, $key, false );
		BFTD_Audit::log( 'crm_key_changed', array( 'summary' => 'The CRM connection key was replaced.' ) );
		return $key;
	}

	/** The CRM's own id for this student. Its absence means "not linked". */
	const LINK_KEY   = '_bftd_crm_id';

	/** What the CRM last wrote, field by field. The other half of the merge. */
	const SHADOW_KEY = '_bftd_crm_shadow';

	/** When we last heard from it. */
	const SYNCED_KEY = '_bftd_crm_synced';

	/**
	 * Their column names against ours.
	 *
	 * Taken from the CRM's own student list, so a change there is one line
	 * here rather than a hunt through the merge code.
	 */
	public static function mapping() {
		return apply_filters( 'bftd_crm_mapping', array(
			'Birth Date'            => 'birth_date',
			'School'                => 'school',
			'Phone'                 => 'phone',
			'Email'                 => 'email',
			'Status'                => 'status',
			'Diagnostic Assessment' => 'diagnostic_on',
			'From'                  => 'attend_from',
			'To'                    => 'attend_to',
		) );
	}

	/** Which of our fields the CRM is allowed to touch at all. */
	public static function fields() {
		return array_values( self::mapping() );
	}

	/**
	 * Fields the CRM may set when it creates a student, and never again.
	 *
	 * The agreed flow is that the CRM enters the basics and creates the
	 * record, and from then on the dashboard manages the customer journey.
	 * Status is the customer journey. So is when they started and stopped,
	 * and when their diagnostic was. If the CRM kept writing those, a student
	 * moved to Active here would be dragged back to Prospect by the next
	 * sync, and nobody would be able to see why.
	 *
	 * Contact details are the opposite case: a family that changes its phone
	 * number tells the CRM, and there is no reason for the dashboard to be
	 * the last to know. Those stay in step.
	 */
	public static function seed_only() {
		return apply_filters( 'bftd_crm_seed_only', array(
			'status', 'diagnostic_on', 'attend_from', 'attend_to',
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Creating a student                                                  */
	/* ------------------------------------------------------------------ */

	/** The student carrying this CRM id, or 0. */
	public static function find( $external_id ) {
		$external_id = sanitize_text_field( (string) $external_id );
		if ( '' === $external_id ) return 0;

		$ids = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => self::LINK_KEY, 'value' => $external_id ) ),
		) );
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Find the student this record is about, or make them.
	 *
	 * Idempotent on the CRM's own id, which is the whole point: a webhook
	 * that fires twice, a retry after a timeout, or a nightly full push must
	 * all land on the same student rather than filling the list with
	 * duplicates of the same child.
	 *
	 * @return array {
	 *   @type int    $student_id
	 *   @type bool   $created
	 *   @type array  $merge     What apply() reported.
	 * }
	 */
	public static function upsert( $incoming ) {
		$incoming = (array) $incoming;

		$external = '';
		foreach ( array( 'crm_id', 'id', 'contact_id', 'Contact Id', 'Id' ) as $k ) {
			if ( ! empty( $incoming[ $k ] ) ) { $external = sanitize_text_field( (string) $incoming[ $k ] ); break; }
		}
		if ( '' === $external ) {
			return new WP_Error( 'bftd_crm_no_id', 'The CRM record has no id, so it cannot be matched to a student or safely created.' );
		}

		$name = '';
		foreach ( array( 'Student Name', 'student_name', 'name', 'full_name' ) as $k ) {
			if ( ! empty( $incoming[ $k ] ) ) { $name = sanitize_text_field( (string) $incoming[ $k ] ); break; }
		}

		$student_id = self::find( $external );
		$created    = false;

		if ( ! $student_id ) {
			if ( '' === $name ) {
				return new WP_Error( 'bftd_crm_no_name', 'A new student needs a name, and this record has none.' );
			}

			$student_id = wp_insert_post( array(
				'post_type'   => BFTD_CPT::STUDENT,
				'post_title'  => $name,
				// Draft, not published: somebody here decides when a student
				// becomes real. A prospect from a diagnostic enquiry is not
				// yet a family with a portal.
				'post_status' => 'draft',
			), true );

			if ( is_wp_error( $student_id ) ) return $student_id;

			$created = true;
			self::link( $student_id, $external );

			BFTD_Audit::log( 'crm_created', array(
				'post_id'    => $student_id,
				'student_id' => $student_id,
				'summary'    => $name . ' was created from the CRM.',
			) );
		} elseif ( $name && $name !== get_the_title( $student_id ) ) {
			// A name is corrected in one place or the other, and the CRM is
			// where it was typed first. Renaming is safe: nothing keys off it.
			wp_update_post( array( 'ID' => $student_id, 'post_title' => $name ) );
		}

		$merge = self::apply( $student_id, $incoming, true, $created );

		return array( 'student_id' => (int) $student_id, 'created' => $created, 'merge' => $merge );
	}

	public static function linked( $student_id ) {
		return (string) get_post_meta( (int) $student_id, self::LINK_KEY, true );
	}

	public static function link( $student_id, $external_id ) {
		$external_id = sanitize_text_field( (string) $external_id );
		if ( '' === $external_id ) {
			delete_post_meta( (int) $student_id, self::LINK_KEY );
			return;
		}
		update_post_meta( (int) $student_id, self::LINK_KEY, $external_id );
	}

	public static function shadow( $student_id ) {
		$s = get_post_meta( (int) $student_id, self::SHADOW_KEY, true );
		return is_array( $s ) ? $s : array();
	}

	public static function last_synced( $student_id ) {
		return (int) get_post_meta( (int) $student_id, self::SYNCED_KEY, true );
	}

	public static function last_synced_label( $student_id ) {
		$ts = self::last_synced( $student_id );
		return $ts ? BFTD_Time::format( 'j M Y, g:i A', $ts ) . ' ' . BFTD_Time::abbreviation( $ts ) : 'never';
	}

	/**
	 * Merge what the CRM has into a student.
	 *
	 * $incoming may be keyed by our field names or by the CRM's column names;
	 * both are accepted so a connector can hand over whatever shape it has.
	 *
	 * @return array {
	 *   @type string[] $written The fields that were updated.
	 *   @type string[] $held    The fields left alone because a person had
	 *                           changed them.
	 *   @type string[] $same    The fields that already agreed.
	 * }
	 */
	public static function apply( $student_id, $incoming, $mark_synced = true, $creating = false ) {
		$student_id = (int) $student_id;
		$incoming   = self::normalise( $incoming );
		$shadow     = self::shadow( $student_id );
		$allowed    = self::fields();
		$seed       = self::seed_only();

		$written = array();
		$held    = array();
		$same    = array();
		$journey = array();

		foreach ( $incoming as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) continue;

			// The dashboard owns the journey once the record exists.
			if ( ! $creating && in_array( $key, $seed, true ) ) { $journey[] = $key; continue; }

			$value   = self::clean( $key, $value );
			$current = (string) BFTD_Fields::get( $student_id, 'student', $key );
			$was     = isset( $shadow[ $key ] ) ? (string) $shadow[ $key ] : '';

			// A person changed it since the CRM last wrote. Leave it.
			if ( '' !== $current && $current !== $was ) {
				$shadow[ $key ] = $value;          // remember what was offered
				if ( $current !== $value ) $held[] = $key;
				continue;
			}

			$shadow[ $key ] = $value;

			if ( $current === $value ) { $same[] = $key; continue; }

			// Never blank something out: the CRM having nothing is not the
			// same as the CRM knowing the answer is nothing.
			if ( '' === $value ) continue;

			BFTD_Fields::set( $student_id, 'student', $key, $value );
			$written[] = $key;
		}

		update_post_meta( $student_id, self::SHADOW_KEY, $shadow );
		if ( $mark_synced ) update_post_meta( $student_id, self::SYNCED_KEY, time() );

		if ( $written ) {
			BFTD_Audit::log( 'crm_synced', array(
				'post_id'    => $student_id,
				'student_id' => $student_id,
				'summary'    => 'The CRM filled in ' . implode( ', ', $written ) . '.',
			) );
		}

		return array( 'written' => $written, 'held' => $held, 'same' => $same, 'journey' => $journey );
	}

	/** Fields the CRM disagrees with and is not touching, for the screen. */
	public static function held_back( $student_id ) {
		$shadow = self::shadow( $student_id );
		$labels = BFTD_MetaBoxes::crm_fields();

		$seed = self::seed_only();

		$out = array();
		foreach ( $shadow as $key => $offered ) {
			if ( in_array( $key, $seed, true ) ) continue;   // not the CRM's to hold back
			$current = (string) BFTD_Fields::get( $student_id, 'student', $key );
			if ( '' === $current ) continue;
			if ( $current === (string) $offered ) continue;
			$out[] = isset( $labels[ $key ] ) ? $labels[ $key ]['label'] : $key;
		}
		return $out;
	}

	/** Accept either our field names or the CRM's column names. */
	private static function normalise( $incoming ) {
		$map = self::mapping();
		$out = array();

		foreach ( (array) $incoming as $key => $value ) {
			if ( isset( $map[ $key ] ) ) { $out[ $map[ $key ] ] = $value; continue; }
			$out[ $key ] = $value;
		}

		// "2022-Apr END 2023-Jun" is one column in the CRM and two dates here.
		if ( isset( $out['From/To'] ) ) {
			$span = self::split_span( $out['From/To'] );
			unset( $out['From/To'] );
			if ( $span['from'] && ! isset( $out['attend_from'] ) ) $out['attend_from'] = $span['from'];
			if ( $span['to']   && ! isset( $out['attend_to'] ) )   $out['attend_to']   = $span['to'];
		}

		return $out;
	}

	/** "2022-Apr END 2023-Jun" into two dates. */
	public static function split_span( $text ) {
		$text = trim( (string) $text );
		$out  = array( 'from' => '', 'to' => '' );
		if ( '' === $text ) return $out;

		$parts = preg_split( '/\s*(?:END|to|-{2,}|–)\s*/i', $text, 2 );
		$out['from'] = self::month_date( isset( $parts[0] ) ? $parts[0] : '' );
		$out['to']   = self::month_date( isset( $parts[1] ) ? $parts[1] : '' );
		return $out;
	}

	/** "2022-Apr" as the first of that month. */
	private static function month_date( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) return '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $text ) ) return $text;
		if ( preg_match( '/^(\d{4})[-\s]+([A-Za-z]{3,})/', $text, $m ) ) {
			$month = date_parse( $m[2] );
			if ( ! empty( $month['month'] ) ) {
				return sprintf( '%04d-%02d-01', (int) $m[1], (int) $month['month'] );
			}
		}
		return '';
	}

	/** Make a value fit the field it is going into. */
	private static function clean( $key, $value ) {
		$fields = BFTD_MetaBoxes::crm_fields();
		$type   = isset( $fields[ $key ]['type'] ) ? $fields[ $key ]['type'] : 'text';
		$value  = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( 'date' === $type ) {
			if ( '' === $value ) return '';
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) return $value;
			// "Mar 3, 2011" is how the CRM prints a birthday.
			$ts = strtotime( $value . ' 12:00:00 UTC' );
			return $ts ? gmdate( 'Y-m-d', $ts ) : '';
		}

		if ( 'select' === $type ) {
			$options = isset( $fields[ $key ]['options'] ) ? $fields[ $key ]['options'] : array();
			if ( isset( $options[ $value ] ) ) return $value;
			foreach ( $options as $k => $label ) {
				if ( 0 === strcasecmp( $label, $value ) ) return $k;
			}
			return '';
		}

		return sanitize_text_field( $value );
	}
}
