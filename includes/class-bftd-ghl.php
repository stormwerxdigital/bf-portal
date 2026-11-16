<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reading GoHighLevel (Phase 1 of the integration, 1.173).
 *
 * THE RULES, decided with Karl before any of it was written:
 *
 * Read only. Nothing here sends anything to GoHighLevel except requests to
 * read. The CRM stays the place details are typed; the portal follows.
 *
 * The token lives in wp-config.php as BFTD_GHL_TOKEN, with the location as
 * BFTD_GHL_LOCATION. Not in the database, not on a screen, never sent to a
 * browser, never written to a log.
 *
 * This phase only finds out what is there: which objects the location has,
 * which fields the Students object carries, and whether a student's linked
 * contacts can be reached. It reports which fields hold something, never
 * what they hold, so a child's details do not pass through a settings page.
 * Mapping and syncing come after, against what this found.
 *
 * Phase 4 (1.176) reads each linked student's contacts and staff and offers
 * them as caregivers and tutors here. Still read only: an offer accepted
 * here changes the portal, never GoHighLevel (Karl's choice).
 */
class BFTD_GHL {

	const BASE = 'https://services.leadconnectorhq.com';

	/**
	 * The API version header. GoHighLevel's current docs say v3; most
	 * integrations written before send 2021-07-28. Both are tried, in this
	 * order, and the test reports which one the account answered to.
	 */
	const VERSIONS = array( 'v3', '2021-07-28' );

	const REPORT = 'bftd_ghl_report_';

	/** The last sync: when, who, and what it did to each student. */
	const SYNC_LOG = 'bftd_ghl_sync_log';

	/** The sync's hook. Hourly since 1.176, at Karl's request. */
	const CRON = 'bftd_ghl_hourly';

	/** The nightly hook 1.175 scheduled, cleared on the way to hourly. */
	const OLD_CRON = 'bftd_ghl_nightly';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'hourly' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
		add_action( 'admin_post_bftd_ghl_sync_one', array( __CLASS__, 'sync_one_request' ) );
		// A change made here goes to GoHighLevel when the student is saved (1.180).
		add_action( 'save_post_' . BFTD_CPT::STUDENT, array( __CLASS__, 'on_save' ), 99, 2 );
		add_action( 'add_meta_boxes_' . BFTD_CPT::STUDENT, array( __CLASS__, 'add_box' ) );
	}

	/**
	 * Every hour while GoHighLevel is connected, and not at all when it is
	 * not: a sync with no token would only fail every hour. A full run is
	 * about one call for the students and one for each linked contact and
	 * staff member, well inside GoHighLevel's limits.
	 */
	public static function schedule() {
		$old = wp_next_scheduled( self::OLD_CRON );
		if ( $old ) wp_unschedule_event( $old, self::OLD_CRON );

		$next = wp_next_scheduled( self::CRON );
		if ( self::configured() && ! $next ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON );
		} elseif ( ! self::configured() && $next ) {
			wp_unschedule_event( $next, self::CRON );
		}
	}

	public static function hourly() {
		self::sync( 0, 'the hourly sync' );
	}

	public static function token() {
		return defined( 'BFTD_GHL_TOKEN' ) ? trim( (string) BFTD_GHL_TOKEN ) : '';
	}

	public static function location() {
		return defined( 'BFTD_GHL_LOCATION' ) ? trim( (string) BFTD_GHL_LOCATION ) : '';
	}

	public static function configured() {
		return '' !== self::token() && '' !== self::location();
	}

	/**
	 * One request. Returns array( code, data, error ). The token goes in a
	 * header and nowhere else.
	 */
	public static function request( $method, $path, $query = array(), $body = null, $version = 'v3' ) {
		$url = self::BASE . $path;
		if ( $query ) $url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $query );

		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . self::token(),
				'Version'       => $version,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) return array( 0, null, $res->get_error_message() );

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$err  = '';
		if ( $code < 200 || $code >= 300 ) {
			$err = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( '; ', $data['message'] ) : (string) $data['message'] )
				: 'HTTP ' . $code;
		}
		return array( $code, $data, $err );
	}

	/**
	 * What a value is, without what it says: "text", "number", "list of 2",
	 * or "empty".
	 */
	public static function shape( $v ) {
		if ( null === $v || '' === $v || array() === $v ) return 'empty';
		if ( is_bool( $v ) ) return 'yes/no';
		if ( is_int( $v ) || is_float( $v ) ) return 'number';
		if ( is_array( $v ) ) {
			$list = array_keys( $v ) === range( 0, count( $v ) - 1 );
			return $list ? 'list of ' . count( $v ) : 'group of ' . count( $v );
		}
		return 'text';
	}

	/** Every key of a record's properties, with only the shape of each value. */
	public static function shapes( $props ) {
		$out = array();
		foreach ( (array) $props as $k => $v ) $out[ (string) $k ] = self::shape( $v );
		ksort( $out );
		return $out;
	}

	/** The object that is most likely the students, by its key or its label. */
	public static function pick_students( $objects ) {
		foreach ( (array) $objects as $o ) {
			$key   = strtolower( (string) ( $o['key'] ?? '' ) );
			$label = strtolower( (string) ( $o['labels']['singular'] ?? '' ) . ' ' . (string) ( $o['labels']['plural'] ?? '' ) );
			if ( empty( $o['standard'] ) && ( false !== strpos( $key, 'student' ) || false !== strpos( $label, 'student' ) ) ) {
				return (string) $o['key'];
			}
		}
		return '';
	}

	/**
	 * The discovery run behind Settings, CRM connection, Test connection.
	 *
	 * Each step is recorded with what was asked (never the token), what came
	 * back, and what it means, so a failure says which permission or which
	 * call is the problem.
	 */
	public static function discover() {
		$r = array(
			'when'    => time(),
			'ok'      => false,
			'version' => '',
			'steps'   => array(),
			'objects' => array(),
			'object'  => '',
			'fields'  => array(),
			'total'   => null,
			'sample'  => array(),
			'links'   => array(),
			'contact' => array(),
		);
		if ( ! self::configured() ) {
			$r['steps'][] = array( 'Read the token', 0, false, 'BFTD_GHL_TOKEN or BFTD_GHL_LOCATION is not set in wp-config.php.' );
			return $r;
		}
		$loc = self::location();

		// 1. The objects on this location, and which API version answers.
		$got     = null;
		$refused = array();
		foreach ( self::VERSIONS as $v ) {
			list( $code, $data, $err ) = self::request( 'GET', '/objects/', array( 'locationId' => $loc ), null, $v );
			if ( '' === $err ) {
				// A version refused before this one answered is a note, not a
				// failure: the connection works, on this version.
				$r['steps'][] = array( 'List objects (Version ' . $v . ')', $code, true,
					$refused ? 'Answered. Refused first: ' . implode( '; ', $refused ) . '.' : 'Answered.' );
				$got = $data; $r['version'] = $v; break;
			}
			$refused[] = 'Version ' . $v . ' (' . $err . ')';
			if ( 401 === $code || 403 === $code || $v === self::VERSIONS[ count( self::VERSIONS ) - 1 ] ) {
				// A permission problem, not a version one, or nothing left to try.
				$r['steps'][] = array( 'List objects (Version ' . $v . ')', $code, false, $err );
				break;
			}
		}
		if ( null === $got ) return $r;
		$ver = $r['version'];

		foreach ( (array) ( $got['objects'] ?? array() ) as $o ) {
			$r['objects'][] = array(
				'label'    => (string) ( $o['labels']['plural'] ?? $o['labels']['singular'] ?? $o['key'] ?? '' ),
				'key'      => (string) ( $o['key'] ?? '' ),
				'standard' => ! empty( $o['standard'] ),
				'display'  => (string) ( $o['primaryDisplayProperty'] ?? '' ),
			);
		}
		$key = self::pick_students( $got['objects'] ?? array() );
		$r['object'] = $key;
		if ( '' === $key ) {
			$r['steps'][] = array( 'Find the Students object', 200, false, 'No custom object with "student" in its name or key.' );
			return $r;
		}

		// 2. Its fields.
		list( $code, $data, $err ) = self::request( 'GET', '/objects/' . rawurlencode( $key ), array( 'locationId' => $loc, 'fetchProperties' => 'true' ), null, $ver );
		$r['steps'][] = array( 'Read the Students fields', $code, '' === $err, '' === $err ? 'Answered.' : $err );
		foreach ( (array) ( $data['fields'] ?? array() ) as $f ) {
			$r['fields'][] = array(
				'name' => (string) ( $f['name'] ?? '' ),
				'key'  => (string) ( $f['fieldKey'] ?? '' ),
				'type' => (string) ( $f['dataType'] ?? '' ),
			);
		}

		// 3. One student record, to see how its properties come back.
		$search = array( 'locationId' => $loc, 'page' => 1, 'pageLimit' => 1 );
		list( $code, $data, $err ) = self::request( 'POST', '/objects/' . rawurlencode( $key ) . '/records/search', array(), $search, $ver );
		if ( '' !== $err && 400 === $code ) {
			// The docs mark query and searchAfter as required; try with them.
			$search += array( 'query' => '', 'searchAfter' => array() );
			list( $code, $data, $err ) = self::request( 'POST', '/objects/' . rawurlencode( $key ) . '/records/search', array(), $search, $ver );
		}
		$r['steps'][] = array( 'Read one student record', $code, '' === $err, '' === $err ? 'Answered.' : $err );
		$rec = isset( $data['records'][0] ) ? $data['records'][0] : null;
		$r['total'] = isset( $data['total'] ) ? (int) $data['total'] : null;
		if ( ! $rec ) {
			if ( '' === $err ) $r['steps'][] = array( 'Read one student record', $code, false, 'The Students object has no records yet.' );
			$r['ok'] = '' === $err;
			return $r;
		}
		$r['sample'] = self::shapes( $rec['properties'] ?? array() );

		// 4. The contacts linked to that student.
		list( $code, $data, $err ) = self::request( 'GET', '/associations/relations/' . rawurlencode( (string) $rec['id'] ), array( 'locationId' => $loc, 'skip' => 0, 'limit' => 10 ), null, $ver );
		$r['steps'][] = array( 'Read the student\'s linked contacts', $code, '' === $err, '' === $err ? 'Answered.' : $err );
		$rows = isset( $data['relations'] ) ? $data['relations'] : ( isset( $data[0] ) ? $data : array() );
		$contact_id = '';
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) continue;
			// The student may be either end of a link. What it links TO is
			// the other end: its object, and its record.
			$student_first = (string) ( $row['firstRecordId'] ?? '' ) === (string) $rec['id'];
			$other_obj     = (string) ( $student_first ? ( $row['secondObjectKey'] ?? '' ) : ( $row['firstObjectKey'] ?? '' ) );
			$other_id      = (string) ( $student_first ? ( $row['secondRecordId'] ?? '' ) : ( $row['firstRecordId'] ?? '' ) );
			$r['links'][] = array(
				'with'  => $other_obj,
				// The association's own name (its key, as "student_parent"),
				// which says what the link is. Not a person's detail.
				'label' => (string) ( $row['associationKey'] ?? $row['key'] ?? $row['secondObjectLabel'] ?? '' ),
				'keys'  => implode( ', ', array_keys( $row ) ),
			);
			if ( '' === $contact_id && 'contact' === $other_obj && '' !== $other_id ) $contact_id = $other_id;
		}

		// 5. One of those contacts, to confirm contacts can be read.
		if ( '' !== $contact_id ) {
			list( $code, $data, $err ) = self::request( 'GET', '/contacts/' . rawurlencode( $contact_id ), array(), null, $ver );
			$r['steps'][] = array( 'Read a linked contact', $code, '' === $err, '' === $err ? 'Answered.' : $err );
			$c = isset( $data['contact'] ) && is_array( $data['contact'] ) ? $data['contact'] : array();
			foreach ( array( 'name', 'firstName', 'lastName', 'email', 'phone', 'additionalPhones', 'additionalEmails', 'customFields' ) as $k ) {
				if ( array_key_exists( $k, $c ) ) $r['contact'][ $k ] = self::shape( $c[ $k ] );
			}
		}

		$r['ok'] = true;
		foreach ( $r['steps'] as $s ) if ( ! $s[2] ) $r['ok'] = false;
		return $r;
	}

	/* ------------------------------------------------------------------ */
	/* Phase 2: linking the portal's students to GoHighLevel's (1.174)     */
	/* ------------------------------------------------------------------ */

	/** The Students object, as Phase 1 found it on Karl's account. */
	const STUDENTS = 'custom_objects.students';

	const CACHE = 'bftd_ghl_students';

	/**
	 * Every GoHighLevel student: record id => name and birth date. Read a
	 * hundred at a time, and kept ten minutes so the linking screen does not
	 * ask again on every load. Read only.
	 *
	 * @return array { ok, error, records }
	 */
	public static function students( $fresh = false ) {
		if ( ! $fresh ) {
			$c = get_transient( self::CACHE );
			if ( is_array( $c ) ) return $c;
		}
		$out = array( 'ok' => false, 'error' => '', 'records' => array() );
		if ( ! self::configured() ) { $out['error'] = 'The GoHighLevel token is not set up.'; return $out; }

		$page  = 1;
		$total = null;
		do {
			$body = array( 'locationId' => self::location(), 'page' => $page, 'pageLimit' => 100 );
			list( $code, $data, $err ) = self::request( 'POST', '/objects/' . rawurlencode( self::STUDENTS ) . '/records/search', array(), $body );
			if ( '' !== $err ) { $out['error'] = $err; return $out; }
			$rows  = (array) ( $data['records'] ?? array() );
			$total = isset( $data['total'] ) ? (int) $data['total'] : count( $rows );
			foreach ( $rows as $rec ) {
				if ( empty( $rec['id'] ) ) continue;
				$p = (array) ( $rec['properties'] ?? array() );
				$out['records'][ (string) $rec['id'] ] = array(
					'name'  => trim( (string) ( $p['student_name'] ?? '' ) ),
					'birth' => self::birth( $p['birth_date'] ?? '' ),
					'school' => trim( (string) ( $p['school'] ?? '' ) ),   // the third thing matched on (1.182)
					// Everything the record holds, for the sync (1.175).
					'props' => $p,
					// Who it is linked to (1.176): its contacts, and the
					// GoHighLevel users who own or follow it.
					'contacts' => self::contact_ids( $rec['relations'] ?? array() ),
					'staff'    => self::staff_ids( $rec ),
					// When GoHighLevel last changed it, for "the later change wins" (1.180).
					'updated'  => (string) ( $rec['updatedAt'] ?? '' ),
				);
			}
			$page++;
		} while ( $rows && count( $out['records'] ) < $total && $page <= 50 );

		$out['ok'] = true;
		set_transient( self::CACHE, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** A birth date as Y-m-d, from a date string or a millisecond stamp. */
	public static function birth( $v ) {
		if ( is_numeric( $v ) && (float) $v > 100000000000 ) return gmdate( 'Y-m-d', (int) ( $v / 1000 ) );
		$v = trim( (string) $v );
		return preg_match( '/^(\d{4}-\d{2}-\d{2})/', $v, $m ) ? $m[1] : '';
	}

	/** A name as it compares: lower case, letters and spaces only, one space between. */
	public static function name_key( $name ) {
		$n = strtolower( remove_accents( (string) $name ) );
		$n = preg_replace( '/[^a-z ]+/', ' ', $n );
		return trim( preg_replace( '/\s+/', ' ', $n ) );
	}

	/**
	 * A suggested GoHighLevel student for each portal student. A suggestion,
	 * never a link: somebody ticks it before anything is saved, because a
	 * wrong link puts one family's details on another child's record.
	 *
	 * Matched in Karl's order (1.182): the name, then the birth date, then
	 * the school. A name is the same, shortened ("Kaine M" for "Kaine
	 * Miller"), or a close spelling. Two birth dates that disagree no longer
	 * rule a pair out, because a birth date typed wrongly here would then
	 * hide the right record; the pair is still offered when the name is the
	 * same or the school agrees, and says the birth dates differ. The best
	 * pairs are taken first, so no record is offered twice, and a record
	 * already linked elsewhere is not offered at all.
	 *
	 * @param array $portal sid => array( name, birth, school )
	 * @param array $ghl    record id => array( name, birth, school )
	 * @param array $taken  record ids already linked
	 * @return array sid => array( record, why )
	 */
	public static function propose( $portal, $ghl, $taken = array() ) {
		$used  = array_fill_keys( array_map( 'strval', (array) $taken ), true );
		$pairs = array();
		$order = 0;
		foreach ( $portal as $sid => $p ) {
			$pk = self::name_key( $p['name'] );
			if ( '' === $pk ) continue;
			foreach ( $ghl as $rid => $g ) {
				$rid = (string) $rid;
				if ( isset( $used[ $rid ] ) ) continue;
				$gk = self::name_key( $g['name'] );
				if ( '' === $gk ) continue;
				if ( $pk === $gk ) {
					$name = 3;
				} elseif ( self::short_name( $pk, $gk ) ) {
					$name = 2;
				} else {
					similar_text( $pk, $gk, $pct );
					$name = ( $pct >= 85 && $pk[0] === $gk[0] ) ? 1 : 0;
				}
				if ( ! $name ) continue;
				$pb = (string) ( $p['birth'] ?? '' );
				$gb = (string) ( $g['birth'] ?? '' );
				$birth  = ( '' === $pb || '' === $gb ) ? 'unknown' : ( $pb === $gb ? 'same' : 'differs' );
				$school = self::same_school( $p['school'] ?? '', $g['school'] ?? '' );
				// A birth date that disagrees is only borne by the same name, or the same school.
				if ( 'differs' === $birth && $name < 3 && 'same' !== $school ) continue;
				$score = $name * 100 + array( 'same' => 20, 'unknown' => 10, 'differs' => 0 )[ $birth ]
					+ array( 'same' => 5, 'unknown' => 2, 'different' => 0 )[ $school ];
				$pairs[] = array( $score, $order++, $sid, $rid, $name, $birth, $school );
			}
		}
		usort( $pairs, function ( $a, $b ) { return $b[0] <=> $a[0] ?: $a[1] <=> $b[1]; } );

		$out = array();
		foreach ( $pairs as $x ) {
			list( , , $sid, $rid, $name, $birth, $school ) = $x;
			if ( isset( $out[ $sid ] ) || isset( $used[ $rid ] ) ) continue;
			$agree = array();
			if ( 'same' === $birth )  $agree[] = 'birth date';
			if ( 'same' === $school ) $agree[] = 'school';
			if ( 3 === $name ) {
				$why = 'Same ' . self::and_list( array_merge( array( 'name' ), $agree ) );
			} else {
				$why = ( 2 === $name ? 'Shortened name' : 'Similar name' ) . ( $agree ? ', same ' . self::and_list( $agree ) : '' );
			}
			if ( 'differs' === $birth ) $why .= '; birth dates differ';
			if ( $name < 3 || 'differs' === $birth ) $why .= ', check it';
			$out[ $sid ] = array( 'record' => $rid, 'why' => $why );
			$used[ $rid ] = true;
		}
		return $out;
	}

	/** "Kaine M" and "Kaine Miller": the same first name, and one last name the start of the other. */
	public static function short_name( $a, $b ) {
		$x = explode( ' ', $a );
		$y = explode( ' ', $b );
		if ( count( $x ) < 2 || count( $y ) < 2 || $x[0] !== $y[0] ) return false;
		$xl = end( $x );
		$yl = end( $y );
		return $xl !== $yl && ( 0 === strpos( $xl, $yl ) || 0 === strpos( $yl, $xl ) );
	}

	/** The words of a school's name that say which school, without "school", "district" and the like. */
	public static function school_words( $s ) {
		$s    = strtolower( remove_accents( (string) $s ) );
		$stop = array( 'school', 'schools', 'district', 'elementary', 'elem', 'high', 'highschool', 'middle', 'secondary', 'academy',
			'the', 'of', 'and', 'sd', 'wa', 'bc', 'ab', 'private', 'public', 'online', 'primary', 'junior', 'senior', 'jr', 'sr', 'k12' );
		$out  = array();
		foreach ( preg_split( '/[^a-z0-9]+/', $s ) as $w ) {
			if ( strlen( $w ) >= 3 && ! in_array( $w, $stop, true ) ) $out[] = $w;
		}
		return array_values( array_unique( $out ) );
	}

	/** Two schools: same, different, or unknown when either is blank. */
	public static function same_school( $a, $b ) {
		$x = self::school_words( $a );
		$y = self::school_words( $b );
		if ( ! $x || ! $y ) return 'unknown';
		return array_intersect( $x, $y ) ? 'same' : 'different';
	}

	/** "name", "name and school", "name, birth date and school". */
	private static function and_list( $words ) {
		$last = array_pop( $words );
		return $words ? implode( ', ', $words ) . ' and ' . $last : $last;
	}

	/* ------------------------------------------------------------------ */
	/* Phase 3: keeping linked students in step (1.175)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * GoHighLevel's Students fields, by the short key a record's properties
	 * use, against the column names the CRM merge already understands
	 * (BFTD_CRM::mapping). Found by the Phase 1 test on Karl's account.
	 */
	public static function property_map() {
		return array(
			'student_name'          => 'Student Name',
			'birth_date'            => 'Birth Date',
			'school'                => 'School',
			'phone'                 => 'Phone',
			'email'                 => 'Email',
			'status'                => 'Status',
			'diagnostic_assessment' => 'Diagnostic Assessment',
			'fromto'                => 'From/To',
		);
	}

	/** One GoHighLevel student as what the CRM merge takes in. */
	public static function to_incoming( $record_id, $props ) {
		$out = array( 'crm_id' => (string) $record_id );
		foreach ( self::property_map() as $short => $column ) {
			if ( ! array_key_exists( $short, (array) $props ) ) continue;
			$v = $props[ $short ];
			if ( is_array( $v ) ) $v = implode( ', ', array_map( 'strval', $v ) );   // an option list
			if ( 'birth_date' === $short ) $v = self::birth( $v ) ?: $v;
			// GoHighLevel's status option, as the portal's own (1.180).
			if ( 'status' === $short && false !== ( $k = array_search( (string) $v, self::STATUS_MAP, true ) ) ) $v = $k;
			$out[ $column ] = $v;
		}
		return $out;
	}

	/** Portal students with a link, as student id => record id. */
	public static function linked_students() {
		$ids = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => BFTD_CRM::LINK_KEY, 'compare' => 'EXISTS' ) ),
		) );
		$out = array();
		foreach ( $ids as $sid ) {
			$rid = BFTD_CRM::linked( $sid );
			if ( '' !== $rid ) $out[ (int) $sid ] = $rid;
		}
		return $out;
	}

	/**
	 * Bring every linked student, or one, in step with GoHighLevel.
	 *
	 * Both ways since 1.180 (Karl): each field, status included, ends the
	 * same in both, and where both changed since the last sync the later
	 * change wins (merge()). Then its caregivers from GoHighLevel are added
	 * (auto_add()). Logged.
	 *
	 * @return array the log entry
	 */
	public static function sync( $only = 0, $by = '' ) {
		$log = array(
			'when'  => time(),
			'by'    => '' !== $by ? $by : ( get_current_user_id() ? wp_get_current_user()->display_name : '' ),
			'ok'    => false,
			'error' => '',
			'rows'  => array(),
		);
		$all = self::students( true );
		if ( ! $all['ok'] ) {
			$log['error'] = $all['error'];
			if ( ! $only ) update_option( self::SYNC_LOG, $log, false );
			return $log;
		}

		$defs   = self::field_defs( true );                                // a field added in GoHighLevel arrives here
		$lookup = array( 'contacts' => array(), 'users' => array() );   // each person read once a run
		foreach ( self::linked_students() as $sid => $rid ) {
			if ( $only && (int) $only !== $sid ) continue;
			$row = array( 'student' => $sid, 'name' => get_the_title( $sid ), 'written' => array(), 'held' => array(), 'missing' => false );
			if ( ! isset( $all['records'][ $rid ] ) ) {
				$row['missing'] = true;           // linked to a record GoHighLevel no longer has
			} else {
				$m = self::merge( $sid, $rid, $all['records'][ $rid ], $defs );
				$row['written'] = $m['written'];
				$row['sent']    = $m['sent'];
				$row['kept']    = $m['kept'];
				if ( '' !== $m['error'] ) $row['error'] = $m['error'];
				// Its caregivers, teachers and staff in GoHighLevel (1.176),
				// the caregivers added by themselves (1.180).
				$people = self::gather( $all['records'][ $rid ], $lookup );
				update_post_meta( $sid, self::PEOPLE_KEY, $people );
				$added = self::auto_add( $sid );
				$row['added']  = $added['done'];
				if ( $added['failed'] ) $row['add_failed'] = $added['failed'];
				$row['offers'] = count( self::offers_for( $sid ) );
			}
			$log['rows'][] = $row;
		}
		$log['ok'] = true;
		if ( ! $only ) update_option( self::SYNC_LOG, $log, false );
		return $log;
	}

	/** GoHighLevel students no student here is linked to: record id => name. */
	public static function unlinked_records() {
		$all = self::students();
		if ( ! $all['ok'] ) return array();
		$taken = array_flip( self::linked_students() );
		$out   = array();
		foreach ( $all['records'] as $rid => $g ) if ( ! isset( $taken[ $rid ] ) ) $out[ $rid ] = $g['name'];
		return $out;
	}

	/** GoHighLevel's statuses that are brought in; a past student, or one with no status, is not (Karl, 1.181). */
	const BRING_IN = array( 'current_student', 'pending' );

	/** A GoHighLevel student's status option, '' when it has none. */
	public static function ghl_status( $g ) {
		$v = $g['props']['status'] ?? '';
		if ( is_array( $v ) ) $v = reset( $v );
		return (string) $v;
	}

	/** GoHighLevel students with no student here that are current or pending: record id => name. */
	public static function to_bring_in( $all = null ) {
		$all = null === $all ? self::students() : $all;
		if ( ! $all['ok'] ) return array();
		$taken = array_flip( self::linked_students() );
		$out   = array();
		foreach ( $all['records'] as $rid => $g ) {
			if ( isset( $taken[ $rid ] ) || ! in_array( self::ghl_status( $g ), self::BRING_IN, true ) ) continue;
			$out[ $rid ] = $g['name'];
		}
		return $out;
	}

	/**
	 * Every current or pending GoHighLevel student with no student here,
	 * made a draft student and linked. Past students, and any with no
	 * status, stay in GoHighLevel only (Karl, 1.181). Only when asked, by
	 * somebody allowed to: it is how a prospect from an enquiry arrives,
	 * and nobody here decides that by accident. Drafts, so somebody still
	 * decides when each becomes real.
	 *
	 * @return array( made, skipped )
	 */
	public static function import_missing() {
		$all = self::students( true );
		if ( ! $all['ok'] ) return array( 0, 0 );
		$want = self::to_bring_in( $all );
		$made = 0; $skipped = 0;
		foreach ( $all['records'] as $rid => $g ) {
			if ( ! isset( $want[ $rid ] ) ) continue;
			if ( '' === trim( $g['name'] ) ) { $skipped++; continue; }   // a student needs a name
			$res = BFTD_CRM::upsert( self::to_incoming( $rid, $g['props'] ?? array() ) );
			if ( is_wp_error( $res ) || empty( $res['created'] ) ) { $skipped++; continue; }
			$made++;
		}
		return array( $made, $skipped );
	}

	/** "Sync from GoHighLevel now" on a student's record. */
	public static function sync_one_request() {
		$sid = isset( $_GET['student'] ) ? absint( $_GET['student'] ) : 0;
		check_admin_referer( 'bftd_ghl_sync_' . $sid );
		if ( ! $sid || ! BFTD_Roles::can_manage() || ! current_user_can( 'edit_post', $sid ) ) {
			wp_die( 'You cannot sync this student.', 403 );
		}
		$log = self::sync( $sid );
		$row = $log['rows'][0] ?? null;
		// Said as the fields are labelled on the screen, not as their keys.
		$say = function ( $keys ) {
			return implode( ', ', array_map( array( __CLASS__, 'label' ), (array) $keys ) );
		};
		if ( ! $log['ok'] )        $msg = 'GoHighLevel did not answer: ' . $log['error'];
		elseif ( ! $row )          $msg = 'This student is not linked to GoHighLevel.';
		elseif ( $row['missing'] ) $msg = 'GoHighLevel no longer has the student this is linked to.';
		else {
			$parts = array();
			if ( $row['written'] )          $parts[] = 'From GoHighLevel: ' . $say( $row['written'] ) . '.';
			if ( ! empty( $row['sent'] ) )  $parts[] = 'Sent to GoHighLevel: ' . $say( $row['sent'] ) . '.';
			if ( ! empty( $row['added'] ) ) $parts[] = 'Added: ' . implode( ', ', $row['added'] ) . '.';
			if ( ! empty( $row['error'] ) ) $parts[] = $row['error'];
			$msg = $parts ? implode( ' ', $parts ) : 'Already the same as GoHighLevel.';
		}
		set_transient( 'bftd_ghl_flash_' . get_current_user_id(), $msg, 60 );
		wp_safe_redirect( (string) get_edit_post_link( $sid, 'raw' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Phase 4: caregivers, teachers and tutors (1.176)                    */
	/* ------------------------------------------------------------------ */

	/**
	 * What GoHighLevel says about a linked student's people, as of the last
	 * sync: array( when, contacts, staff, staff_error ).
	 */
	const PEOPLE_KEY = '_bftd_ghl_people';

	/** Offers somebody said no to, on the student: "c:<contact>" or "u:<user>". */
	const DISMISSED_KEY = '_bftd_ghl_dismissed';

	/** The tag that marks a caregiver as the family's main contact. */
	const PRIMARY_TAG = 'primary parent';

	/** The contact ids a record is linked to, from the relations its search returns. */
	public static function contact_ids( $relations ) {
		$out = array();
		foreach ( (array) $relations as $r ) {
			if ( ! is_array( $r ) ) continue;
			if ( 'contact' === (string) ( $r['objectKey'] ?? '' ) && '' !== (string) ( $r['recordId'] ?? '' ) ) {
				$out[] = (string) $r['recordId'];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** The GoHighLevel users on a record: user id => "owner" or "follower". */
	public static function staff_ids( $rec ) {
		$out = array();
		foreach ( (array) ( $rec['followers'] ?? array() ) as $u ) if ( '' !== (string) $u ) $out[ (string) $u ] = 'follower';
		foreach ( (array) ( $rec['owners'] ?? $rec['owner'] ?? array() ) as $u ) if ( '' !== (string) $u ) $out[ (string) $u ] = 'owner';
		return $out;
	}

	/** One contact as the portal keeps it: who they are and how to reach them. */
	public static function shape_contact( $id, $c ) {
		$c    = (array) $c;
		$name = trim( trim( (string) ( $c['firstName'] ?? '' ) ) . ' ' . trim( (string) ( $c['lastName'] ?? '' ) ) );
		if ( '' === $name ) $name = trim( (string) ( $c['contactName'] ?? $c['name'] ?? '' ) );
		$tags = array_map( function ( $t ) { return strtolower( trim( (string) $t ) ); }, (array) ( $c['tags'] ?? array() ) );
		return array(
			'id'      => (string) $id,
			'first'   => trim( (string) ( $c['firstName'] ?? '' ) ),
			'last'    => trim( (string) ( $c['lastName'] ?? '' ) ),
			'name'    => $name,
			'email'   => strtolower( trim( (string) ( $c['email'] ?? '' ) ) ),
			'phone'   => trim( (string) ( $c['phone'] ?? '' ) ),
			'primary' => in_array( self::PRIMARY_TAG, $tags, true ),
		);
	}

	/** One GoHighLevel user as the portal keeps it. */
	public static function shape_user( $id, $u, $role ) {
		$u    = (array) $u;
		$name = trim( (string) ( $u['name'] ?? '' ) );
		if ( '' === $name ) $name = trim( trim( (string) ( $u['firstName'] ?? '' ) ) . ' ' . trim( (string) ( $u['lastName'] ?? '' ) ) );
		return array(
			'id'    => (string) $id,
			'name'  => $name,
			'email' => strtolower( trim( (string) ( $u['email'] ?? '' ) ) ),
			'role'  => $role,
		);
	}

	/**
	 * Read a record's contacts and staff from GoHighLevel. Each person is read
	 * once a run however many children they are linked to ($lookup carries
	 * them between students). Read only.
	 */
	public static function gather( $record, &$lookup ) {
		$out = array( 'when' => time(), 'contacts' => array(), 'staff' => array(), 'staff_error' => '', 'error' => '' );
		foreach ( (array) ( $record['contacts'] ?? array() ) as $cid ) {
			if ( ! array_key_exists( $cid, $lookup['contacts'] ) ) {
				list( $code, $data, $err ) = self::request( 'GET', '/contacts/' . rawurlencode( $cid ), array(), null, '2021-07-28' );
				$lookup['contacts'][ $cid ] = '' === $err && isset( $data['contact'] ) ? self::shape_contact( $cid, $data['contact'] ) : null;
				if ( '' !== $err ) $out['error'] = $err;
			}
			if ( $lookup['contacts'][ $cid ] ) $out['contacts'][] = $lookup['contacts'][ $cid ];
		}
		foreach ( (array) ( $record['staff'] ?? array() ) as $uid => $role ) {
			$uid = (string) $uid;
			if ( ! array_key_exists( $uid, $lookup['users'] ) ) {
				list( $code, $data, $err ) = self::request( 'GET', '/users/' . rawurlencode( $uid ), array(), null, '2021-07-28' );
				if ( '' !== $err ) {
					$lookup['users'][ $uid ] = array( 'error' => ( 401 === $code || 403 === $code )
						? 'GoHighLevel will not share its staff accounts with the portal yet. Add the View Users permission (users.readonly) to the portal\'s token.'
						: $err );
				} else {
					$lookup['users'][ $uid ] = self::shape_user( $uid, isset( $data['user'] ) ? $data['user'] : $data, $role );
				}
			}
			$u = $lookup['users'][ $uid ];
			if ( isset( $u['error'] ) ) { $out['staff_error'] = $u['error']; continue; }
			$u['role']      = $role;
			$out['staff'][] = $u;
		}
		// The main contact first, then by name.
		usort( $out['contacts'], function ( $a, $b ) {
			if ( $a['primary'] !== $b['primary'] ) return $a['primary'] ? -1 : 1;
			return strcasecmp( $a['name'], $b['name'] );
		} );
		return $out;
	}

	/** What the last sync found for a student, or null before the first one. */
	public static function people( $sid ) {
		$p = get_post_meta( $sid, self::PEOPLE_KEY, true );
		return is_array( $p ) ? $p : null;
	}

	public static function dismissed( $sid ) {
		$d = get_post_meta( $sid, self::DISMISSED_KEY, true );
		return is_array( $d ) ? $d : array();
	}

	/** Portal accounts by email, lower case: email => user id. */
	public static function accounts_by_email( $emails ) {
		$out = array();
		foreach ( array_unique( array_filter( (array) $emails ) ) as $e ) {
			$u = get_user_by( 'email', $e );
			if ( $u ) $out[ strtolower( $e ) ] = (int) $u->ID;
		}
		return $out;
	}

	/**
	 * What to offer for one student, worked out from what GoHighLevel has and
	 * what the portal already has. Pure: everything it needs is passed in.
	 *
	 * Each offer is one of:
	 *   link    a caregiver with a portal account who is not on this student
	 *   create  a caregiver with no portal account yet (needs an email)
	 *   assign  a GoHighLevel staff member whose portal account is staff,
	 *           not yet a tutor on this student
	 * and "notes" says what could not be offered, and why.
	 *
	 * @param array $people   as gather() returns
	 * @param array $have     array( clients => ids, staff => ids, primary => id )
	 * @param array $accounts email => array( id, staff ) for portal accounts
	 * @param array $no       dismissed keys
	 */
	public static function offers( $people, $have, $accounts, $no = array() ) {
		$offers = array(); $notes = array();
		$no      = array_fill_keys( (array) $no, true );
		$clients = array_map( 'intval', (array) ( $have['clients'] ?? array() ) );
		$staff   = array_map( 'intval', (array) ( $have['staff'] ?? array() ) );
		$primary = (int) ( $have['primary'] ?? 0 );
		$seen    = array();

		foreach ( (array) ( $people['contacts'] ?? array() ) as $c ) {
			$key = 'c:' . $c['id'];
			if ( isset( $no[ $key ] ) ) continue;
			$label = '' !== $c['name'] ? $c['name'] : 'A contact with no name';
			if ( '' === $c['email'] ) { $notes[] = $label . ' has no email in GoHighLevel, so cannot be given a portal account.'; continue; }
			if ( isset( $seen[ $c['email'] ] ) ) continue;               // two contacts, one email
			$seen[ $c['email'] ] = true;
			$acct = $accounts[ $c['email'] ] ?? null;
			$make_primary = $c['primary'] && ! $primary;
			if ( $acct ) {
				if ( in_array( (int) $acct['id'], $clients, true ) ) continue;   // already a caregiver here
				if ( ! empty( $acct['staff'] ) ) { $notes[] = $label . ' has a staff account, so is not offered as a caregiver.'; continue; }
				$offers[] = array( 'key' => $key, 'kind' => 'link', 'name' => $label, 'email' => $c['email'], 'user' => (int) $acct['id'], 'primary' => $make_primary, 'contact' => $c );
			} else {
				$offers[] = array( 'key' => $key, 'kind' => 'create', 'name' => $label, 'email' => $c['email'], 'user' => 0, 'primary' => $make_primary, 'contact' => $c );
			}
		}
		foreach ( (array) ( $people['staff'] ?? array() ) as $u ) {
			$key = 'u:' . $u['id'];
			if ( isset( $no[ $key ] ) ) continue;
			$label = '' !== $u['name'] ? $u['name'] : 'A GoHighLevel user';
			$acct  = '' !== $u['email'] ? ( $accounts[ $u['email'] ] ?? null ) : null;
			if ( ! $acct ) { $notes[] = $label . ' (' . $u['role'] . ' in GoHighLevel) has no staff account here with the same email.'; continue; }
			if ( empty( $acct['staff'] ) ) { $notes[] = $label . '\'s account here is not a staff account, so cannot be a tutor.'; continue; }
			if ( in_array( (int) $acct['id'], $staff, true ) ) continue;    // already their tutor
			$offers[] = array( 'key' => $key, 'kind' => 'assign', 'name' => $label, 'email' => $u['email'], 'user' => (int) $acct['id'], 'primary' => false, 'role' => $u['role'] );
		}
		return array( 'offers' => $offers, 'notes' => array_values( array_unique( $notes ) ) );
	}

	/** The offers for one portal student, from what the last sync stored. */
	public static function offers_for( $sid, $with_notes = false ) {
		$people = self::people( $sid );
		if ( ! $people ) return $with_notes ? array( 'offers' => array(), 'notes' => array() ) : array();
		$emails = array();
		foreach ( (array) $people['contacts'] as $c ) $emails[] = $c['email'];
		foreach ( (array) $people['staff'] as $u ) $emails[] = $u['email'];
		$accounts = array();
		foreach ( self::accounts_by_email( $emails ) as $e => $uid ) {
			$accounts[ $e ] = array( 'id' => $uid, 'staff' => BFTD_Roles::is_staff( $uid ) );
		}
		$r = self::offers( $people, array(
			'clients' => BFTD_CPT::client_ids( $sid ),
			'staff'   => BFTD_CPT::staff_ids( $sid ),
			'primary' => BFTD_CPT::primary_client_id( $sid ),
		), $accounts, self::dismissed( $sid ) );
		return $with_notes ? $r : $r['offers'];
	}

	/**
	 * A portal account for a GoHighLevel contact, made quietly: a family
	 * account with a random password and no email. Somebody here sends the
	 * welcome when the family is ready for it (Karl, 1.176).
	 *
	 * @return int|WP_Error the new user id
	 */
	public static function create_client( $contact ) {
		$email = sanitize_email( (string) $contact['email'] );
		if ( ! is_email( $email ) ) return new WP_Error( 'bftd_email', 'not a usable email' );
		if ( email_exists( $email ) ) return new WP_Error( 'bftd_taken', 'that email already has an account' );
		$login = sanitize_user( strtok( $email, '@' ), true );
		if ( '' === $login ) $login = 'family';
		$base = $login; $n = 2;
		while ( username_exists( $login ) ) $login = $base . $n++;
		$name = '' !== trim( (string) $contact['name'] ) ? (string) $contact['name'] : $email;
		return wp_insert_user( array(
			'user_login'   => $login,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'first_name'   => (string) ( $contact['first'] ?? '' ),
			'last_name'    => (string) ( $contact['last'] ?? '' ),
			'display_name' => $name,
			'role'         => BFTD_Roles::CLIENT_ROLE,
		) );
	}

	/**
	 * Carry out the offers somebody ticked for one student. Each key is
	 * checked against the offers worked out now, so only something actually
	 * on offer is done. Nothing is emailed: a caregiver's welcome is sent
	 * from here by a person, when the family is ready.
	 *
	 * @return array( done => labels, failed => labels )
	 */
	public static function accept( $sid, $keys ) {
		$done = array(); $failed = array();
		$by   = array();
		foreach ( self::offers_for( $sid ) as $o ) $by[ $o['key'] ] = $o;
		$student = get_the_title( $sid );
		foreach ( (array) $keys as $key ) {
			if ( ! isset( $by[ $key ] ) ) continue;
			$o   = $by[ $key ];
			$uid = (int) $o['user'];
			if ( 'create' === $o['kind'] ) {
				$uid = self::create_client( $o['contact'] );
				if ( is_wp_error( $uid ) ) { $failed[] = $o['name'] . ' (' . $uid->get_error_message() . ')'; continue; }
				BFTD_Audit::log( 'client_created', array( 'post_id' => $sid, 'student_id' => $sid,
					'summary' => 'A portal account was made for ' . $o['name'] . ' from GoHighLevel. No email was sent.' ) );
			}
			if ( 'assign' === $o['kind'] ) {
				add_post_meta( $sid, BFTD_CPT::STAFF_KEY, $uid );
				self::remember_added( $sid, $key );
				BFTD_Audit::log( 'staff_assigned', array( 'post_id' => $sid, 'student_id' => $sid,
					'summary' => 'Tutor ' . $o['name'] . ' was given access to ' . $student . ', from GoHighLevel.' ) );
				if ( class_exists( 'BFTD_Notices' ) ) BFTD_Notices::add( $uid, 'system', $sid, $sid, '', 'You have been assigned ' . $student, '' );
				$done[] = $o['name'] . ' as a tutor';
				continue;
			}
			add_post_meta( $sid, BFTD_CPT::CLIENT_KEY, $uid );
			self::remember_added( $sid, $key );
			BFTD_Audit::log( 'client_linked', array( 'post_id' => $sid, 'student_id' => $sid,
				'summary' => 'Caregiver ' . $o['name'] . ' was given access to ' . $student . ', from GoHighLevel.' ) );
			if ( $o['primary'] && ! BFTD_CPT::primary_client_id( $sid ) ) {
				update_post_meta( $sid, BFTD_CPT::PRIMARY_KEY, $uid );
				BFTD_Audit::log( 'client_linked', array( 'post_id' => $sid, 'student_id' => $sid,
					'summary' => $o['name'] . ' is now the client for ' . $student . '.' ) );
			}
			$done[] = $o['name'] . ( 'create' === $o['kind'] ? ' (new account)' : '' ) . ( $o['primary'] ? ' as the client' : ' as a caregiver' );
		}
		return array( 'done' => $done, 'failed' => $failed );
	}

	/** "Not this one": the offer stays out of the list until somebody brings it back. */
	public static function dismiss( $sid, $keys ) {
		$have = self::dismissed( $sid );
		foreach ( (array) $keys as $k ) {
			$k = (string) $k;
			if ( preg_match( '/^[cu]:[A-Za-z0-9_-]+$/', $k ) && ! in_array( $k, $have, true ) ) $have[] = $k;
		}
		update_post_meta( $sid, self::DISMISSED_KEY, $have );
	}

	/* ------------------------------------------------------------------ */
	/* Phase 5: caregivers added by themselves, every field both ways     */
	/* (1.180)                                                             */
	/* ------------------------------------------------------------------ */

	/** The portal's status => GoHighLevel's option: one status, kept the same in both. */
	const STATUS_MAP = array( 'prospect' => 'pending', 'active' => 'current_student', 'past' => 'past_student' );

	/** Per student, per field: what each side held when they last agreed. */
	const SHADOW_KEY = '_bftd_ghl_shadow';

	/** Per student, per field: when it was last changed here. */
	const EDITED_KEY = '_bftd_ghl_edited';

	/** Per student: the last change GoHighLevel would not take. */
	const PUSH_ERROR = '_bftd_ghl_push_error';

	/** Per student: the people from GoHighLevel already added once. */
	const ADDED_KEY = '_bftd_ghl_added';

	/** A GoHighLevel field the portal has no place of its own for, kept on the student under this. */
	const EXTRA_PREFIX = '_bftd_ghl_f_';

	/** Every GoHighLevel Students field, as last read. */
	const FIELDS = 'bftd_ghl_fields';

	/** Kinds of field that hold a file, which a form here cannot change. */
	const UNSYNCED_TYPES = array( 'FILE_UPLOAD', 'SIGNATURE' );

	/** Set while GoHighLevel's values are being written here, so they are not sent straight back. */
	private static $pulling = false;

	/** The eight fields the portal has its own place for, labelled as people read them. */
	public static function fixed_fields() {
		return array(
			'student_name'          => 'Student name',
			'birth_date'            => 'Birth date',
			'school'                => 'School',
			'phone'                 => 'Phone',
			'email'                 => 'Email',
			'status'                => 'Status',
			'diagnostic_assessment' => 'Diagnostic assessment',
			'fromto'                => 'Attending from and to',
		);
	}

	/**
	 * Every field GoHighLevel's Students object has: short key => array(
	 * label, type, options ). Read again at each sync, so one added there
	 * arrives here within the hour; kept otherwise.
	 */
	public static function field_defs( $fresh = false ) {
		$have = get_option( self::FIELDS );
		if ( ! $fresh && is_array( $have ) ) return $have;
		if ( ! self::configured() ) return is_array( $have ) ? $have : array();
		list( , $data, $err ) = self::request( 'GET', '/objects/' . rawurlencode( self::STUDENTS ), array( 'locationId' => self::location(), 'fetchProperties' => 'true' ) );
		if ( '' !== $err || empty( $data['fields'] ) ) return is_array( $have ) ? $have : array();
		$out = array();
		foreach ( (array) $data['fields'] as $f ) {
			$short = preg_replace( '/^' . preg_quote( self::STUDENTS, '/' ) . '\./', '', (string) ( $f['fieldKey'] ?? '' ) );
			if ( ! preg_match( '/^[a-z0-9_]+$/', $short ) ) continue;
			$opts = array();
			foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
				if ( is_array( $o ) && isset( $o['key'] ) ) $opts[ (string) $o['key'] ] = sanitize_text_field( (string) ( $o['label'] ?? $o['key'] ) );
			}
			$out[ $short ] = array(
				'label'   => sanitize_text_field( (string) ( $f['name'] ?? $short ) ),
				'type'    => strtoupper( (string) ( $f['dataType'] ?? 'TEXT' ) ),
				'options' => $opts,
			);
		}
		update_option( self::FIELDS, $out, false );
		return $out;
	}

	/** GoHighLevel's fields beyond the eight: added to the student by themselves. */
	public static function extra_fields( $defs = null ) {
		$defs = null === $defs ? self::field_defs() : $defs;
		return array_diff_key( (array) $defs, self::fixed_fields() );
	}

	/** Every field kept the same both ways: short key => GoHighLevel type. */
	public static function sync_keys( $defs ) {
		$out = array();
		foreach ( self::fixed_fields() as $k => $l ) $out[ $k ] = $defs[ $k ]['type'] ?? ( 'birth_date' === $k ? 'DATE' : 'TEXT' );
		foreach ( self::extra_fields( $defs ) as $k => $d ) {
			if ( ! in_array( $d['type'], self::UNSYNCED_TYPES, true ) ) $out[ $k ] = $d['type'];
		}
		return $out;
	}

	public static function label( $short ) {
		$fixed = self::fixed_fields();
		if ( isset( $fixed[ $short ] ) ) return $fixed[ $short ];
		$defs = self::field_defs();
		return isset( $defs[ $short ]['label'] ) ? $defs[ $short ]['label'] : (string) $short;
	}

	/** A date written as a date, "2023-03-12" or "Mar 12, 2023", as a timestamp; anything else, false. */
	public static function date_ts( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) || preg_match( '/^[A-Za-z]{3,}\.?\s+\d{1,2},?\s+\d{4}$/', $v ) ) {
			$ts = strtotime( str_replace( '.', '', $v ) . ' 12:00:00 UTC' );
			return $ts ? $ts : false;
		}
		return false;
	}

	/** Attending from and to as "2022-Apr END 2023-Jun", the way GoHighLevel has always held it. */
	public static function span( $from, $to ) {
		$m = function ( $d ) { $ts = self::date_ts( $d ); return $ts ? gmdate( 'Y-M', $ts ) : ''; };
		$f = $m( $from );
		$t = $m( $to );
		if ( '' === $t ) return $f;
		return trim( $f . ' END ' . $t );
	}

	/**
	 * A phone number as GoHighLevel's Phone fields take it, "+17786758433"
	 * (1.183: it refused "(778) 675-8433"). Ten digits are taken as North
	 * American; anything that cannot be read as a number is left as typed.
	 */
	public static function phone( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) return '';
		$d = preg_replace( '/\D+/', '', $v );
		if ( 0 === strpos( $v, '+' ) && strlen( $d ) >= 8 ) return '+' . $d;
		if ( 10 === strlen( $d ) ) return '+1' . $d;
		if ( 11 === strlen( $d ) && '1' === $d[0] ) return '+' . $d;
		return $v;
	}

	/** A GoHighLevel value as it compares with the portal's. */
	public static function canon( $short, $raw, $type = '' ) {
		if ( is_array( $raw ) ) $raw = implode( ', ', array_map( 'strval', $raw ) );
		$raw = trim( (string) $raw );
		if ( '' === $raw ) return '';
		if ( 'birth_date' === $short || 'DATE' === $type ) return self::birth( $raw ) ?: $raw;
		if ( 'phone' === $short || 'PHONE' === $type ) return self::phone( $raw );
		if ( 'diagnostic_assessment' === $short ) {
			$ts = self::date_ts( $raw );
			return $ts ? gmdate( 'M j, Y', $ts ) : $raw;    // "2023-2024" stays as GoHighLevel wrote it
		}
		return $raw;
	}

	/** The portal's value for one GoHighLevel field, as GoHighLevel would hold it. */
	public static function here( $sid, $short, $type = '' ) {
		switch ( $short ) {
			case 'student_name':
				return trim( (string) get_post_field( 'post_title', $sid ) );
			case 'status':
				$s = (string) BFTD_Fields::get( $sid, 'student', 'status' );
				return isset( self::STATUS_MAP[ $s ] ) ? self::STATUS_MAP[ $s ] : '';
			case 'diagnostic_assessment':
				$ts = self::date_ts( BFTD_CPT::diagnostic_on( $sid ) );
				return $ts ? gmdate( 'M j, Y', $ts ) : '';
			case 'fromto':
				return self::span( BFTD_Fields::get( $sid, 'student', 'attend_from' ), BFTD_Fields::get( $sid, 'student', 'attend_to' ) );
			case 'phone':
				// Compared, and sent, as GoHighLevel holds it (1.183).
				return self::phone( BFTD_Fields::get( $sid, 'student', 'phone' ) );
			case 'birth_date':
			case 'school':
			case 'email':
				return trim( (string) BFTD_Fields::get( $sid, 'student', $short ) );
		}
		return self::canon( $short, get_post_meta( $sid, self::EXTRA_PREFIX . $short, true ), $type );
	}

	/**
	 * GoHighLevel's value written here. False when it cannot be: a status
	 * the portal has no word for, or a date written as words that are not
	 * a date ("2023-2024"), which is left as it is on both sides.
	 */
	public static function put_here( $sid, $short, $g, $type = '' ) {
		self::$pulling = true;
		$ok = false;
		switch ( $short ) {
			case 'student_name':
				if ( '' !== $g ) $ok = ! is_wp_error( wp_update_post( array( 'ID' => $sid, 'post_title' => $g ), true ) );
				break;
			case 'status':
				$k = array_search( $g, self::STATUS_MAP, true );
				if ( false !== $k ) { BFTD_Fields::set( $sid, 'student', 'status', $k ); $ok = true; }
				break;
			case 'diagnostic_assessment':
				$ts = self::date_ts( $g );
				if ( $ts || '' === $g ) { BFTD_Fields::set( $sid, 'student', 'diagnostic_on', $ts ? gmdate( 'Y-m-d', $ts ) : '' ); $ok = true; }
				break;
			case 'fromto':
				$sp = BFTD_CRM::split_span( $g );
				if ( '' === $g || '' !== $sp['from'] || '' !== $sp['to'] ) {
					BFTD_Fields::set( $sid, 'student', 'attend_from', $sp['from'] );
					BFTD_Fields::set( $sid, 'student', 'attend_to', $sp['to'] );
					$ok = true;
				}
				break;
			case 'birth_date':
				$v = self::birth( $g );
				if ( '' !== $v || '' === $g ) { BFTD_Fields::set( $sid, 'student', 'birth_date', $v ); $ok = true; }
				break;
			case 'school':
			case 'phone':
			case 'email':
				BFTD_Fields::set( $sid, 'student', $short, sanitize_text_field( $g ) );
				$ok = true;
				break;
			default:
				update_post_meta( $sid, self::EXTRA_PREFIX . $short, sanitize_textarea_field( $g ) );
				$ok = true;
		}
		self::$pulling = false;
		return $ok;
	}

	/** The portal's value as GoHighLevel's API takes it. */
	public static function to_api( $short, $h, $type = '' ) {
		if ( 'birth_date' === $short || 'DATE' === $type ) return '' === $h ? null : $h;
		if ( in_array( $type, array( 'MULTIPLE_OPTIONS', 'CHECKBOX' ), true ) ) return '' === $h ? array() : array_map( 'trim', explode( ',', $h ) );
		if ( in_array( $type, array( 'NUMERICAL', 'MONETORY' ), true ) && is_numeric( $h ) ) return $h + 0;
		return $h;
	}

	/**
	 * One linked student and its GoHighLevel record, made the same.
	 *
	 * Field by field, against what both held when they last agreed: the
	 * side that changed since is copied to the other; where both changed,
	 * the later change wins (Karl, 1.180), this side's time being when it
	 * was saved here and GoHighLevel's when the record was last changed.
	 * The first time, before there is anything to compare with, what
	 * GoHighLevel has stands and only its blanks are filled from here, so
	 * linking never overwrites what the practice already keeps there.
	 *
	 * @return array( written, sent, kept, error )
	 */
	public static function merge( $sid, $rid, $rec, $defs ) {
		$props  = (array) ( $rec['props'] ?? array() );
		$shadow = get_post_meta( $sid, self::SHADOW_KEY, true );
		$shadow = is_array( $shadow ) ? $shadow : array();
		$edited = get_post_meta( $sid, self::EDITED_KEY, true );
		$edited = is_array( $edited ) ? $edited : array();
		$theirs = ! empty( $rec['updated'] ) ? (int) strtotime( (string) $rec['updated'] ) : 0;
		$out    = array( 'written' => array(), 'sent' => array(), 'kept' => array(), 'error' => '' );
		$push   = array();

		foreach ( self::sync_keys( $defs ) as $short => $type ) {
			$g = self::canon( $short, $props[ $short ] ?? '', $type );
			$h = self::here( $sid, $short, $type );
			if ( $g === $h ) { $shadow[ $short ] = array( 'ghl' => $g, 'here' => $h ); continue; }
			$s = $shadow[ $short ] ?? null;
			if ( ! is_array( $s ) ) {
				$win = '' !== $g ? 'ghl' : 'here';
			} else {
				$gc = $g !== $s['ghl'];
				$hc = $h !== $s['here'];
				if ( ! $gc && ! $hc ) continue;       // a difference already settled
				if ( $gc && $hc ) $win = (int) ( $edited[ $short ] ?? 0 ) > $theirs ? 'here' : 'ghl';
				else $win = $gc ? 'ghl' : 'here';
			}
			if ( 'ghl' === $win ) {
				if ( self::put_here( $sid, $short, $g, $type ) ) $out['written'][] = $short;
				else $out['kept'][] = $short;
				$shadow[ $short ] = array( 'ghl' => $g, 'here' => self::here( $sid, $short, $type ) );
			} else {
				$push[ $short ] = $h;
			}
		}
		update_post_meta( $sid, self::SHADOW_KEY, $shadow );
		// "Last checked" on the student's record (1.182: it said never).
		update_post_meta( $sid, BFTD_CRM::SYNCED_KEY, time() );

		if ( $out['written'] ) {
			BFTD_Audit::log( 'crm_synced', array( 'post_id' => $sid, 'student_id' => $sid,
				'summary' => 'From GoHighLevel: ' . implode( ', ', array_map( array( __CLASS__, 'label' ), $out['written'] ) ) . '.' ) );
		}
		if ( $push ) {
			$out['error'] = self::push( $sid, $rid, $push, $defs );
			if ( '' === $out['error'] ) $out['sent'] = array_keys( $push );
		}
		return $out;
	}

	/**
	 * Fields changed here, sent to the GoHighLevel record. The only thing
	 * the portal writes to GoHighLevel: a linked student's own fields.
	 *
	 * @return string '' when GoHighLevel took it, else why not
	 */
	public static function push( $sid, $rid, $values, $defs = null ) {
		$defs  = null === $defs ? self::field_defs() : $defs;
		$props = array();
		foreach ( $values as $short => $h ) $props[ $short ] = self::to_api( $short, $h, $defs[ $short ]['type'] ?? '' );
		list( $code, , $err ) = self::request( 'PUT', '/objects/' . rawurlencode( self::STUDENTS ) . '/records/' . rawurlencode( $rid ),
			array( 'locationId' => self::location() ), array( 'properties' => $props ) );
		if ( '' !== $err ) {
			$say = ( 401 === (int) $code || 403 === (int) $code )
				? 'GoHighLevel would not let the portal change its students. The private integration needs permission to edit custom object records (objects/record.write).'
				: 'GoHighLevel did not take the change: ' . $err;
			update_post_meta( $sid, self::PUSH_ERROR, array( 'when' => time(), 'error' => $say ) );
			return $say;
		}
		delete_post_meta( $sid, self::PUSH_ERROR );
		$shadow = get_post_meta( $sid, self::SHADOW_KEY, true );
		$shadow = is_array( $shadow ) ? $shadow : array();
		foreach ( $values as $short => $h ) $shadow[ $short ] = array( 'ghl' => $h, 'here' => $h );
		update_post_meta( $sid, self::SHADOW_KEY, $shadow );
		delete_transient( self::CACHE );
		BFTD_Audit::log( 'crm_sent', array( 'post_id' => $sid, 'student_id' => $sid,
			'summary' => 'Sent to GoHighLevel: ' . implode( ', ', array_map( array( __CLASS__, 'label' ), array_keys( $values ) ) ) . '.' ) );
		return '';
	}

	/** A student saved here: its extra fields kept, and what changed sent to GoHighLevel. */
	public static function on_save( $sid, $post ) {
		if ( self::$pulling || wp_is_post_revision( $sid ) || wp_is_post_autosave( $sid ) ) return;
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) return;
		self::save_extras( $sid );
		if ( ! self::configured() ) return;
		$rid = BFTD_CRM::linked( $sid );
		if ( '' !== $rid ) self::send_changes( $sid, $rid );
	}

	/**
	 * Each field that differs from what both sides last agreed, sent now.
	 * Before the first sync there is nothing agreed; the sync settles it.
	 */
	public static function send_changes( $sid, $rid ) {
		$shadow = get_post_meta( $sid, self::SHADOW_KEY, true );
		if ( ! is_array( $shadow ) || ! $shadow ) return '';
		$edited = get_post_meta( $sid, self::EDITED_KEY, true );
		$edited = is_array( $edited ) ? $edited : array();
		$defs   = self::field_defs();
		$push   = array();
		foreach ( self::sync_keys( $defs ) as $short => $type ) {
			if ( ! isset( $shadow[ $short ] ) ) continue;
			$h = self::here( $sid, $short, $type );
			if ( $h === $shadow[ $short ]['here'] ) continue;
			$edited[ $short ] = time();
			$push[ $short ]   = $h;
		}
		if ( ! $push ) return '';
		update_post_meta( $sid, self::EDITED_KEY, $edited );
		return self::push( $sid, $rid, $push, $defs );
	}

	/** Remember that this person from GoHighLevel was added, so removing them here is final. */
	private static function remember_added( $sid, $key ) {
		$have = get_post_meta( $sid, self::ADDED_KEY, true );
		$have = is_array( $have ) ? $have : array();
		if ( ! in_array( $key, $have, true ) ) $have[] = $key;
		update_post_meta( $sid, self::ADDED_KEY, $have );
	}

	/**
	 * A linked student's caregivers from GoHighLevel, added (Karl, 1.180):
	 * each associated contact with an email is linked, or given a portal
	 * account quietly, with no email. Someone added once and removed here
	 * since is not added again; they stay on offer on the Caregivers and
	 * tutors tab. Tutors are still offered, not added.
	 */
	public static function auto_add( $sid ) {
		$before = get_post_meta( $sid, self::ADDED_KEY, true );
		$before = is_array( $before ) ? $before : array();
		$keys   = array();
		foreach ( self::offers_for( $sid ) as $o ) {
			if ( 'assign' === $o['kind'] || in_array( $o['key'], $before, true ) ) continue;
			$keys[] = $o['key'];
		}
		return $keys ? self::accept( $sid, $keys ) : array( 'done' => array(), 'failed' => array() );
	}

	public static function add_box() {
		if ( ! self::configured() || ! self::extra_fields() ) return;
		add_meta_box( 'bftd_student_ghl_more', 'More from GoHighLevel', array( __CLASS__, 'box_more' ), BFTD_CPT::STUDENT, 'normal', 'default' );
	}

	/** GoHighLevel's other student fields, each the kind of input its type asks for. */
	public static function box_more( $post ) {
		wp_nonce_field( 'bftd_ghl_more_' . $post->ID, 'bftd_ghl_more_nonce' );
		echo '<p class="description">Fields GoHighLevel has for students beyond the ones above. One added in GoHighLevel appears here by itself, and each is kept the same in both.</p><div class="bftd-grid">';
		foreach ( self::extra_fields() as $short => $d ) {
			$v    = self::here( $post->ID, $short, $d['type'] );
			$name = 'bftd_ghl_more[' . $short . ']';
			$id   = 'bftd-ghl-' . $short;
			echo '<div class="bftd-field"><label class="bftd-label" for="' . esc_attr( $id ) . '">' . esc_html( $d['label'] ) . '</label>';
			if ( in_array( $d['type'], self::UNSYNCED_TYPES, true ) ) {
				echo '<p class="bftd-readonly" id="' . esc_attr( $id ) . '">' . ( '' !== $v ? esc_html( $v ) : '<span class="bftd-none">Nothing</span>' ) . '</p>';
			} elseif ( in_array( $d['type'], array( 'MULTIPLE_OPTIONS', 'CHECKBOX' ), true ) ) {
				$on = array_map( 'trim', explode( ',', $v ) );
				echo '<span id="' . esc_attr( $id ) . '">';
				foreach ( $d['options'] as $k => $l ) {
					echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $k ) . '"' . checked( in_array( (string) $k, $on, true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
				}
				echo '</span>';
			} elseif ( $d['options'] ) {
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">Not set</option>';
				foreach ( $d['options'] as $k => $l ) echo '<option value="' . esc_attr( $k ) . '"' . selected( $v, (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
				echo '</select>';
			} elseif ( 'LARGE_TEXT' === $d['type'] ) {
				echo '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" rows="3">' . esc_textarea( $v ) . '</textarea>';
			} else {
				$t = 'DATE' === $d['type'] ? 'date' : ( in_array( $d['type'], array( 'NUMERICAL', 'MONETORY' ), true ) ? 'number' : 'text' );
				echo '<input type="' . esc_attr( $t ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $v ) . '"' . ( 'number' === $t ? ' step="any"' : '' ) . '>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/** The box above, saved. Only what the box drew, only a value its options allow. */
	public static function save_extras( $sid ) {
		if ( empty( $_POST['bftd_ghl_more_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_ghl_more_nonce'] ) ), 'bftd_ghl_more_' . $sid ) ) return;
		if ( ! current_user_can( 'edit_post', $sid ) ) return;
		$in = isset( $_POST['bftd_ghl_more'] ) ? (array) wp_unslash( $_POST['bftd_ghl_more'] ) : array();
		foreach ( self::extra_fields() as $short => $d ) {
			if ( in_array( $d['type'], self::UNSYNCED_TYPES, true ) ) continue;
			$multi = in_array( $d['type'], array( 'MULTIPLE_OPTIONS', 'CHECKBOX' ), true );
			$v     = $in[ $short ] ?? ( $multi ? array() : '' );
			if ( $multi ) {
				$v = implode( ', ', array_values( array_intersect( array_map( 'strval', (array) $v ), array_map( 'strval', array_keys( $d['options'] ) ) ) ) );
			} else {
				$v = is_scalar( $v ) ? (string) $v : '';
				if ( $d['options'] && '' !== $v && ! isset( $d['options'][ $v ] ) ) continue;
				$v = 'LARGE_TEXT' === $d['type'] ? sanitize_textarea_field( $v ) : sanitize_text_field( $v );
			}
			update_post_meta( $sid, self::EXTRA_PREFIX . $short, $v );
		}
	}

	/** The last report, kept for the person who ran it, for a day. */
	public static function last_report() {
		$r = get_transient( self::REPORT . get_current_user_id() );
		return is_array( $r ) ? $r : null;
	}

	public static function run_and_keep() {
		$r = self::discover();
		set_transient( self::REPORT . get_current_user_id(), $r, DAY_IN_SECONDS );
		BFTD_Audit::log( 'ghl_tested', array( 'summary' => 'The GoHighLevel connection was tested: ' . ( $r['ok'] ? 'everything answered.' : 'something did not answer.' ) ) );
		return $r;
	}
}
