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
