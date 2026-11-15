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
					// Everything the record holds, for the sync (1.175).
					'props' => $p,
					// Who it is linked to (1.176): its contacts, and the
					// GoHighLevel users who own or follow it.
					'contacts' => self::contact_ids( $rec['relations'] ?? array() ),
					'staff'    => self::staff_ids( $rec ),
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
	 * Same name, and the birth date agrees where both have one, first. Then a
	 * close spelling of the name, marked to be checked. No record is offered
	 * twice, and a record already linked elsewhere is not offered at all.
	 *
	 * @param array $portal sid => array( name, birth )
	 * @param array $ghl    record id => array( name, birth )
	 * @param array $taken  record ids already linked
	 * @return array sid => array( record, why )
	 */
	public static function propose( $portal, $ghl, $taken = array() ) {
		$out  = array();
		$used = array_fill_keys( array_map( 'strval', (array) $taken ), true );

		// Exact names first, so a close spelling never takes a record an
		// exact match wanted.
		foreach ( array( 'exact', 'close' ) as $pass ) {
			foreach ( $portal as $sid => $p ) {
				if ( isset( $out[ $sid ] ) ) continue;
				$pk = self::name_key( $p['name'] );
				if ( '' === $pk ) continue;
				$best = ''; $best_score = 0;
				foreach ( $ghl as $rid => $g ) {
					$rid = (string) $rid;
					if ( isset( $used[ $rid ] ) ) continue;
					$gk = self::name_key( $g['name'] );
					if ( '' === $gk ) continue;
					// Two birth dates that disagree rule a pair out, whatever
					// the names say.
					if ( '' !== $p['birth'] && '' !== $g['birth'] && $p['birth'] !== $g['birth'] ) continue;
					if ( 'exact' === $pass ) {
						if ( $pk === $gk ) { $best = $rid; break; }
					} else {
						similar_text( $pk, $gk, $pct );
						if ( $pct >= 85 && $pct > $best_score && $pk[0] === $gk[0] ) { $best = $rid; $best_score = $pct; }
					}
				}
				if ( '' === $best ) continue;
				$both = '' !== $p['birth'] && '' !== $ghl[ $best ]['birth'];
				$out[ $sid ] = array(
					'record' => $best,
					'why'    => 'exact' === $pass
						? ( $both ? 'Same name and birth date' : 'Same name' )
						: 'Similar name, check it',
				);
				$used[ $best ] = true;
			}
		}
		return $out;
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
	 * Through BFTD_CRM::upsert, the merge the older endpoint uses: it fills
	 * what is blank, follows what it filled last time, leaves what a person
	 * typed, and never touches the journey (status, dates) on a student who
	 * already exists. Read only on GoHighLevel's side. Logged.
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

		$lookup = array( 'contacts' => array(), 'users' => array() );   // each person read once a run
		foreach ( self::linked_students() as $sid => $rid ) {
			if ( $only && (int) $only !== $sid ) continue;
			$row = array( 'student' => $sid, 'name' => get_the_title( $sid ), 'written' => array(), 'held' => array(), 'missing' => false );
			if ( ! isset( $all['records'][ $rid ] ) ) {
				$row['missing'] = true;           // linked to a record GoHighLevel no longer has
			} else {
				$res = BFTD_CRM::upsert( self::to_incoming( $rid, $all['records'][ $rid ]['props'] ?? array() ) );
				if ( is_wp_error( $res ) ) {
					$row['error'] = $res->get_error_message();
				} else {
					$row['written'] = $res['merge']['written'];
					$row['held']    = $res['merge']['held'];
				}
				// Its caregivers, teachers and staff in GoHighLevel (1.176).
				$people = self::gather( $all['records'][ $rid ], $lookup );
				update_post_meta( $sid, self::PEOPLE_KEY, $people );
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

	/**
	 * Every GoHighLevel student with no student here, made a draft student
	 * and linked. Only when asked, by somebody allowed to: it is how a
	 * prospect from an enquiry arrives, and nobody here decides that by
	 * accident. Drafts, so somebody still decides when each becomes real.
	 *
	 * @return array( made, skipped )
	 */
	public static function import_missing() {
		$all = self::students( true );
		if ( ! $all['ok'] ) return array( 0, 0 );
		$taken = array_flip( self::linked_students() );
		$made = 0; $skipped = 0;
		foreach ( $all['records'] as $rid => $g ) {
			if ( isset( $taken[ $rid ] ) ) continue;
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
		$labels = class_exists( 'BFTD_MetaBoxes' ) ? BFTD_MetaBoxes::crm_fields() : array();
		$say    = function ( $keys ) use ( $labels ) {
			$out = array();
			foreach ( (array) $keys as $k ) $out[] = isset( $labels[ $k ] ) ? $labels[ $k ]['label'] : $k;
			return implode( ', ', $out );
		};
		if ( ! $log['ok'] )         $msg = 'GoHighLevel did not answer: ' . $log['error'];
		elseif ( ! $row )           $msg = 'This student is not linked to GoHighLevel.';
		elseif ( $row['missing'] )  $msg = 'GoHighLevel no longer has the student this is linked to.';
		elseif ( $row['written'] )  $msg = 'Filled in from GoHighLevel: ' . $say( $row['written'] ) . '.';
		else                        $msg = 'Already in step with GoHighLevel.';
		if ( $row && $row['held'] ) $msg .= ' Left as typed here: ' . $say( $row['held'] ) . '.';
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
				BFTD_Audit::log( 'staff_assigned', array( 'post_id' => $sid, 'student_id' => $sid,
					'summary' => 'Tutor ' . $o['name'] . ' was given access to ' . $student . ', from GoHighLevel.' ) );
				if ( class_exists( 'BFTD_Notices' ) ) BFTD_Notices::add( $uid, 'system', $sid, $sid, '', 'You have been assigned ' . $student, '' );
				$done[] = $o['name'] . ' as a tutor';
				continue;
			}
			add_post_meta( $sid, BFTD_CPT::CLIENT_KEY, $uid );
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
