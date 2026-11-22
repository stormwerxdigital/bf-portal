<?php
/**
 * The school list (Karl, 1.212).
 *
 * "We need a list of schools so we can track students by school in the
 * portal." One clean list, kept here, with no duplicates. A school can be
 * linked to a GoHighLevel Company, which is where the practice keeps its
 * schools. The student's School field searches both, links the student to
 * one, or adds a new school when neither has it. Senior managers and
 * administrators edit the list on Brilliant Futures, Schools.
 *
 * The student still holds the school's name as text (the field GoHighLevel's
 * School is kept in step with, and what the report prints); the link is the
 * school's id beside it, kept in step whenever the text changes.
 *
 * GoHighLevel's Businesses API lists Companies but cannot search them by
 * name, so the names are copied here for an hour at a time and searched
 * locally.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Schools {

	const OPTION      = 'bftd_schools';        // id => array( id, name, ghl_id, added, by )
	const NEXT        = 'bftd_schools_next';
	const STUDENT_KEY = '_bftd_school_id';
	const CACHE       = 'bftd_ghl_companies';  // array( array( id, name ) )
	const CACHE_ERR   = 'bftd_ghl_companies_err';
	const SLUG        = 'bftd-schools';
	const MAX_PAGES   = 50;                    // 5,000 Companies

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 23 );
		add_action( 'wp_ajax_bftd_school_search', array( __CLASS__, 'ajax_search' ) );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );   // after the student's fields (priority 10)
		foreach ( array( 'add', 'rename', 'merge', 'delete', 'link', 'unlink', 'refresh', 'import', 'save' ) as $a ) {
			add_action( 'admin_post_bftd_school_' . $a, array( __CLASS__, 'handle' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The list                                                            */
	/* ------------------------------------------------------------------ */

	public static function all() {
		$l = get_option( self::OPTION, array() );
		return is_array( $l ) ? $l : array();
	}

	private static function put( $list ) {
		uasort( $list, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
		update_option( self::OPTION, $list, false );
	}

	public static function get( $id ) {
		$l = self::all();
		return isset( $l[ (int) $id ] ) ? $l[ (int) $id ] : null;
	}

	/**
	 * A school's name as compared for duplicates: case, accents, punctuation,
	 * spacing, "the", "school" and the usual short forms make no difference.
	 * "Foothills Elem." and "The Foothills Elementary School" are the same;
	 * "Foothills Middle" is not.
	 */
	public static function norm( $s ) {
		$s = strtolower( function_exists( 'remove_accents' ) ? remove_accents( (string) $s ) : (string) $s );
		$s = str_replace( array( "'", "’" ), '', $s );   // St. Mary's, St Marys
		$s = str_replace( '&', ' and ', $s );
		$s = preg_replace( '/[^a-z0-9]+/', ' ', $s );
		$long = array( 'elem' => 'elementary', 'el' => 'elementary', 'jr' => 'junior', 'sr' => 'senior', 'sec' => 'secondary',
			'mid' => 'middle', 'acad' => 'academy', 'intl' => 'international', 'mt' => 'mount', 'ctr' => 'centre', 'center' => 'centre',
			'hs' => 'high', 'ms' => 'middle', 'es' => 'elementary', 'sch' => '', 'school' => '', 'the' => '' );
		$out = array();
		foreach ( explode( ' ', trim( $s ) ) as $w ) {
			if ( '' === $w ) continue;
			if ( array_key_exists( $w, $long ) ) $w = $long[ $w ];
			if ( '' !== $w ) $out[] = $w;
		}
		return implode( ' ', $out );
	}

	/** The list's school with the same name, or 0. */
	public static function same_on_list( $name, $except = 0 ) {
		$n = self::norm( $name );
		if ( '' === $n ) return 0;
		foreach ( self::all() as $id => $s ) {
			if ( (int) $id !== (int) $except && self::norm( $s['name'] ) === $n ) return (int) $id;
		}
		return 0;
	}

	/** Names that are close but not the same: "Did you mean…?" */
	public static function near( $name, $names ) {
		$n = self::norm( $name );
		$w = class_exists( 'BFTD_GHL' ) ? BFTD_GHL::school_words( $name ) : array();
		$out = array();
		if ( ! $w ) return $out;
		foreach ( $names as $k => $other ) {
			if ( self::norm( $other ) === $n ) continue;
			$ow = BFTD_GHL::school_words( $other );
			if ( self::words_meet( $w, $ow ) ) $out[ $k ] = $other;
		}
		return $out;
	}

	/** A word in common, allowing a slip of a letter or two in longer words (Fothills, Foothils). */
	private static function words_meet( $a, $b ) {
		foreach ( $a as $x ) {
			foreach ( $b as $y ) {
				if ( $x === $y ) return true;
				$len = min( strlen( $x ), strlen( $y ) );
				if ( $len >= 5 && levenshtein( $x, $y ) <= ( $len >= 8 ? 2 : 1 ) ) return true;
			}
		}
		return false;
	}

	/**
	 * Add a school, or return the one already there by the same name. A
	 * GoHighLevel id given is kept on whichever it ends up being.
	 */
	public static function add( $name, $ghl_id = '' ) {
		$name = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $name ) ) );
		if ( '' === self::norm( $name ) ) return 0;
		$list = self::all();
		$same = self::same_on_list( $name );
		if ( ! $same && '' !== $ghl_id ) {
			foreach ( $list as $id => $s ) if ( (string) $s['ghl_id'] === (string) $ghl_id ) { $same = (int) $id; break; }
		}
		if ( $same ) {
			if ( '' !== $ghl_id && '' === (string) $list[ $same ]['ghl_id'] ) { $list[ $same ]['ghl_id'] = (string) $ghl_id; self::put( $list ); }
			return $same;
		}
		$id = max( 1, (int) get_option( self::NEXT, 1 ) );
		while ( isset( $list[ $id ] ) ) $id++;
		update_option( self::NEXT, $id + 1, false );
		$list[ $id ] = array( 'id' => $id, 'name' => $name, 'ghl_id' => (string) $ghl_id, 'added' => time(), 'by' => (int) get_current_user_id() );
		self::put( $list );
		return $id;
	}

	/* ------------------------------------------------------------------ */
	/* GoHighLevel's Companies                                             */
	/* ------------------------------------------------------------------ */

	/** array( array( id, name ) ), from the hour's copy unless asked to refresh. */
	public static function companies( $refresh = false ) {
		if ( ! $refresh ) {
			$c = get_transient( self::CACHE );
			if ( is_array( $c ) ) return $c;
			if ( get_transient( self::CACHE_ERR ) ) return array();
		}
		if ( ! class_exists( 'BFTD_GHL' ) || ! BFTD_GHL::configured() ) return array();
		$out = array();
		$err = '';
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$rows = null;
			foreach ( array( 'v3', '2021-07-28' ) as $v ) {
				list( $code, $data, $e ) = BFTD_GHL::request( 'GET', '/businesses/', array( 'locationId' => BFTD_GHL::location(), 'limit' => 100, 'skip' => $page * 100 ), null, $v );
				if ( $code >= 200 && $code < 300 && is_array( $data ) ) { $rows = (array) ( $data['businesses'] ?? array() ); $err = ''; break; }
				$err = 401 === $code || 403 === $code
					? 'GoHighLevel would not show its Companies. The private integration needs permission to read businesses (businesses.readonly).'
					: ( $e ? $e : 'GoHighLevel did not answer.' );
			}
			if ( null === $rows ) break;
			foreach ( $rows as $r ) {
				if ( ! empty( $r['id'] ) && '' !== trim( (string) ( $r['name'] ?? '' ) ) ) $out[] = array( 'id' => (string) $r['id'], 'name' => trim( (string) $r['name'] ) );
			}
			if ( count( $rows ) < 100 ) break;
		}
		if ( '' !== $err && ! $out ) {
			set_transient( self::CACHE_ERR, $err, 10 * MINUTE_IN_SECONDS );
			delete_transient( self::CACHE );
			return array();
		}
		delete_transient( self::CACHE_ERR );
		set_transient( self::CACHE, $out, HOUR_IN_SECONDS );
		return $out;
	}

	public static function company( $ghl_id ) {
		foreach ( self::companies() as $c ) if ( $c['id'] === (string) $ghl_id ) return $c;
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Searching                                                           */
	/* ------------------------------------------------------------------ */

	/** Does $name match what was typed? Any part of it, any case, each word the start of one. */
	public static function matches( $q, $name ) {
		$qn = self::norm( $q );
		if ( '' === $qn ) $qn = strtolower( trim( (string) $q ) );
		$n = self::norm( $name );
		if ( '' === $qn ) return false;
		if ( false !== strpos( $n, $qn ) || false !== stripos( (string) $name, trim( (string) $q ) ) ) return true;
		$words = explode( ' ', $n );
		foreach ( explode( ' ', $qn ) as $qw ) {
			$hit = false;
			foreach ( $words as $w ) if ( 0 === strpos( $w, $qw ) ) { $hit = true; break; }
			if ( ! $hit ) return false;
		}
		return true;
	}

	/**
	 * What the School box offers for $q: GoHighLevel's matches (each saying
	 * whether it is already on our list), our list's matches, whether the
	 * name typed is already one of them exactly, and close names.
	 */
	public static function search( $q, $limit = 8 ) {
		$q = trim( (string) $q );
		$out = array( 'ghl' => array(), 'list' => array(), 'exact' => null, 'near' => array(), 'ghl_error' => '' );
		if ( strlen( $q ) < 2 ) return $out;
		$qn   = self::norm( $q );
		$list = self::all();
		$by_ghl = array();
		foreach ( $list as $id => $s ) if ( '' !== (string) $s['ghl_id'] ) $by_ghl[ (string) $s['ghl_id'] ] = (int) $id;

		foreach ( self::companies() as $c ) {
			if ( ! self::matches( $q, $c['name'] ) ) continue;
			$on = $by_ghl[ $c['id'] ] ?? self::same_on_list( $c['name'] );
			$out['ghl'][] = array( 'ghl_id' => $c['id'], 'name' => $c['name'], 'list_id' => (int) $on );
			if ( null === $out['exact'] && self::norm( $c['name'] ) === $qn ) $out['exact'] = array( 'kind' => 'ghl', 'ghl_id' => $c['id'], 'name' => $c['name'] );
			if ( count( $out['ghl'] ) >= $limit ) break;
		}
		$out['ghl_error'] = (string) get_transient( self::CACHE_ERR );

		foreach ( $list as $id => $s ) {
			if ( ! self::matches( $q, $s['name'] ) ) continue;
			$out['list'][] = array( 'id' => (int) $id, 'name' => $s['name'], 'ghl' => '' !== (string) $s['ghl_id'] );
			if ( self::norm( $s['name'] ) === $qn ) $out['exact'] = array( 'kind' => 'list', 'id' => (int) $id, 'name' => $s['name'] );
			if ( count( $out['list'] ) >= $limit ) break;
		}

		if ( ! $out['ghl'] && ! $out['list'] ) {
			$names = array();
			foreach ( $list as $id => $s ) $names[ 'list:' . $id ] = $s['name'];
			foreach ( self::companies() as $c ) $names[ 'ghl:' . $c['id'] ] = $c['name'];
			foreach ( array_slice( self::near( $q, $names ), 0, 5, true ) as $k => $name ) $out['near'][] = array( 'pick' => $k, 'name' => $name );
		}
		return $out;
	}

	public static function ajax_search() {
		check_ajax_referer( 'bftd_nonce', 'nonce' );
		if ( ! BFTD_Roles::is_staff() ) wp_send_json_error( array( 'message' => 'Not for this account.' ), 403 );
		wp_send_json_success( self::search( sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* A student's school                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * The list's id for what was picked: "list:ID", "ghl:ID", or anything
	 * else for the name typed. A GoHighLevel Company not yet on the list is
	 * added; a name on neither is added. Never a second copy of one there.
	 */
	public static function resolve( $pick, $name ) {
		$pick = (string) $pick;
		if ( 0 === strpos( $pick, 'list:' ) ) {
			$id = (int) substr( $pick, 5 );
			if ( self::get( $id ) ) return $id;
		}
		if ( 0 === strpos( $pick, 'ghl:' ) ) {
			$c = self::company( substr( $pick, 4 ) );
			if ( $c ) return self::add( $c['name'], $c['id'] );
		}
		$name = trim( (string) $name );
		if ( '' === self::norm( $name ) ) return 0;
		$id = self::same_on_list( $name );
		if ( $id ) return $id;
		foreach ( self::companies() as $c ) {
			if ( self::norm( $c['name'] ) === self::norm( $name ) ) return self::add( $c['name'], $c['id'] );
		}
		return self::add( $name );
	}

	public static function of_student( $sid ) {
		return (int) get_post_meta( (int) $sid, self::STUDENT_KEY, true );
	}

	/**
	 * Link a student to a school, and give them its name. Sends the name on
	 * to GoHighLevel when it changed and $send is true.
	 */
	public static function set_student( $sid, $id, $send = true ) {
		$sid = (int) $sid;
		$s   = self::get( $id );
		if ( ! $s ) { delete_post_meta( $sid, self::STUDENT_KEY ); return; }
		update_post_meta( $sid, self::STUDENT_KEY, (int) $id );
		$had = trim( (string) BFTD_Fields::get( $sid, 'student', 'school' ) );
		if ( $had === $s['name'] ) return;
		BFTD_Fields::set( $sid, 'student', 'school', $s['name'] );
		if ( $send ) self::send( $sid );
	}

	/** The student's School as text, linked to the list, adding it there if need be. */
	public static function resolve_student( $sid, $pick = '', $send = true ) {
		$text = trim( (string) BFTD_Fields::get( (int) $sid, 'student', 'school' ) );
		if ( '' === $text && 0 !== strpos( (string) $pick, 'list:' ) && 0 !== strpos( (string) $pick, 'ghl:' ) ) {
			delete_post_meta( (int) $sid, self::STUDENT_KEY );
			return 0;
		}
		$id = self::resolve( $pick, $text );
		if ( $id ) self::set_student( $sid, $id, $send );
		return $id;
	}

	private static function send( $sid ) {
		if ( ! class_exists( 'BFTD_GHL' ) || ! class_exists( 'BFTD_CRM' ) || ! BFTD_GHL::configured() ) return;
		$rid = BFTD_CRM::linked( $sid );
		if ( '' !== $rid ) BFTD_GHL::send_changes( $sid, $rid );
	}

	public static function on_save( $post_id, $post ) {
		if ( BFTD_CPT::STUDENT !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
		if ( in_array( $post->post_status, array( 'auto-draft', 'trash' ), true ) ) return;
		if ( ! isset( $_POST['bftd_school_pick'] ) ) return;   // only from the edit screen's form
		self::resolve_student( $post_id, sanitize_text_field( wp_unslash( $_POST['bftd_school_pick'] ) ) );
	}

	/** Student ids linked to a school. */
	public static function students_at( $id ) {
		return array_map( 'intval', get_posts( array(
			'post_type' => BFTD_CPT::STUDENT, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_key' => self::STUDENT_KEY, 'meta_value' => (int) $id,
		) ) );
	}

	/** id => number of students linked. */
	public static function counts() {
		$out = array();
		$ids = get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		if ( $ids && function_exists( 'update_meta_cache' ) ) update_meta_cache( 'post', $ids );
		foreach ( $ids as $sid ) {
			$id = self::of_student( $sid );
			if ( $id ) $out[ $id ] = ( $out[ $id ] ?? 0 ) + 1;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Editing the list: senior managers and administrators                */
	/* ------------------------------------------------------------------ */

	public static function can_edit() {
		return BFTD_Roles::can_administer();
	}

	public static function rename( $id, $name ) {
		$list = self::all();
		$name = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( (string) $name ) ) );
		if ( ! isset( $list[ $id ] ) ) return new WP_Error( 'bftd_school', 'That school is not on the list.' );
		if ( '' === self::norm( $name ) ) return new WP_Error( 'bftd_school', 'A school needs a name.' );
		$same = self::same_on_list( $name, $id );
		if ( $same ) return new WP_Error( 'bftd_school', '"' . $list[ $same ]['name'] . '" is already on the list. Merge the two instead.' );
		$list[ $id ]['name'] = $name;
		self::put( $list );
		foreach ( self::students_at( $id ) as $sid ) self::set_student( $sid, $id );
		return true;
	}

	/** Every student of $from moves to $into, which keeps a GoHighLevel link from either. */
	public static function merge( $from, $into ) {
		$list = self::all();
		if ( (int) $from === (int) $into || ! isset( $list[ $from ], $list[ $into ] ) ) return new WP_Error( 'bftd_school', 'Choose two different schools on the list.' );
		if ( '' === (string) $list[ $into ]['ghl_id'] ) $list[ $into ]['ghl_id'] = (string) $list[ $from ]['ghl_id'];
		unset( $list[ $from ] );
		self::put( $list );
		foreach ( self::students_at( $from ) as $sid ) self::set_student( $sid, $into );
		return true;
	}

	public static function remove( $id ) {
		$list = self::all();
		if ( ! isset( $list[ $id ] ) ) return new WP_Error( 'bftd_school', 'That school is not on the list.' );
		if ( self::students_at( $id ) ) return new WP_Error( 'bftd_school', 'Students are linked to it. Merge it into another school instead.' );
		unset( $list[ $id ] );
		self::put( $list );
		return true;
	}

	public static function link( $id, $ghl_id ) {
		$list = self::all();
		if ( ! isset( $list[ $id ] ) ) return new WP_Error( 'bftd_school', 'That school is not on the list.' );
		foreach ( $list as $other => $s ) {
			if ( (int) $other !== (int) $id && '' !== (string) $ghl_id && (string) $s['ghl_id'] === (string) $ghl_id ) {
				return new WP_Error( 'bftd_school', 'That Company is already linked to "' . $s['name'] . '". Merge the two instead.' );
			}
		}
		$list[ $id ]['ghl_id'] = (string) $ghl_id;
		self::put( $list );
		return true;
	}

	/** Every student with a School typed and no link yet, linked (1.212's one-time import, safe to run again). */
	public static function import_from_students() {
		$n = 0;
		$ids = get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		foreach ( $ids as $sid ) {
			if ( self::of_student( $sid ) && self::get( self::of_student( $sid ) ) ) continue;
			if ( '' === trim( (string) BFTD_Fields::get( $sid, 'student', 'school' ) ) ) continue;
			if ( self::resolve_student( $sid, '', false ) ) $n++;
		}
		return $n;
	}

	/** Pairs on the list that may be one school: id => array( other ids ). */
	public static function possible_duplicates() {
		$names = array();
		foreach ( self::all() as $id => $s ) $names[ $id ] = $s['name'];
		$out = array();
		foreach ( $names as $id => $name ) {
			foreach ( self::near( $name, $names ) as $other => $n ) {
				if ( (int) $other > (int) $id ) $out[] = array( (int) $id, (int) $other );
			}
		}
		return $out;
	}

	public static function handle() {
		$what = str_replace( 'bftd_school_', '', sanitize_key( $_REQUEST['action'] ?? '' ) );
		check_admin_referer( 'bftd_school_' . $what );
		if ( ! self::can_edit() ) wp_die( 'Only senior managers and administrators can edit the school list.', 403 );
		$id = absint( $_POST['id'] ?? 0 );
		$r  = true;
		$say = '';
		switch ( $what ) {
			case 'add':
				$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
				$had  = self::same_on_list( $name );
				$new  = self::add( $name );
				$r    = $new ? true : new WP_Error( 'bftd_school', 'A school needs a name.' );
				$say  = $had ? 'That school is already on the list.' : 'Added.';
				break;
			case 'rename': $r = self::rename( $id, wp_unslash( $_POST['name'] ?? '' ) ); $say = 'Renamed, and its students now show the new name.'; break;
			case 'merge':  $r = self::merge( $id, absint( $_POST['into'] ?? 0 ) ); $say = 'Merged.'; break;
			case 'delete': $r = self::remove( $id ); $say = 'Deleted.'; break;
			case 'link':   $r = self::link( $id, sanitize_text_field( wp_unslash( $_POST['ghl_id'] ?? '' ) ) ); $say = 'Linked to GoHighLevel.'; break;
			case 'unlink': $r = self::link( $id, '' ); $say = 'No longer linked to GoHighLevel.'; break;
			case 'refresh':
				self::companies( true );
				$err = (string) get_transient( self::CACHE_ERR );
				$r   = $err ? new WP_Error( 'bftd_school', $err ) : true;
				$say = count( self::companies() ) . ' Companies read from GoHighLevel.';
				break;
			case 'import': $say = self::import_from_students() . ' students linked to the list.'; break;
			case 'save':   list( $r, $say ) = self::save_table( wp_unslash( $_POST ) ); break;
		}
		$args = is_wp_error( $r ) ? array( 'bftd_err' => rawurlencode( $r->get_error_message() ) ) : array( 'bftd_msg' => rawurlencode( $say ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * The Schools table, saved at once (Karl, 1.213.2: "you are missing the
	 * save button in here"). Unlink and Delete act straight away; otherwise
	 * every name changed is renamed, every Company picked is linked, and
	 * every "Merge into" chosen is merged, last, so a school renamed and
	 * merged in one save ends up under the name it is merged into.
	 *
	 * @return array( true|WP_Error, what was done )
	 */
	public static function save_table( $post ) {
		$do = (string) ( $post['do'] ?? 'save' );
		if ( preg_match( '/^(unlink|delete):(\d+)$/', $do, $m ) ) {
			$r = 'unlink' === $m[1] ? self::link( (int) $m[2], '' ) : self::remove( (int) $m[2] );
			return array( $r, 'unlink' === $m[1] ? 'No longer linked to GoHighLevel.' : 'Deleted.' );
		}
		$errors = array();
		$n = array( 'renamed' => 0, 'linked' => 0, 'merged' => 0 );
		foreach ( (array) ( $post['name'] ?? array() ) as $id => $name ) {
			$s = self::get( (int) $id );
			if ( ! $s || trim( (string) $name ) === $s['name'] ) continue;
			$r = self::rename( (int) $id, (string) $name );
			if ( is_wp_error( $r ) ) $errors[] = $r->get_error_message(); else $n['renamed']++;
		}
		foreach ( (array) ( $post['ghl'] ?? array() ) as $id => $gid ) {
			$s = self::get( (int) $id );
			if ( ! $s || '' === (string) $gid || '' !== (string) $s['ghl_id'] ) continue;
			$r = self::link( (int) $id, sanitize_text_field( (string) $gid ) );
			if ( is_wp_error( $r ) ) $errors[] = $r->get_error_message(); else $n['linked']++;
		}
		foreach ( (array) ( $post['into'] ?? array() ) as $id => $into ) {
			if ( ! $into || ! self::get( (int) $id ) || ! self::get( (int) $into ) ) continue;
			$r = self::merge( (int) $id, (int) $into );
			if ( is_wp_error( $r ) ) $errors[] = $r->get_error_message(); else $n['merged']++;
		}
		$bits = array();
		foreach ( $n as $what => $k ) if ( $k ) $bits[] = $k . ' ' . $what;
		$say = $bits ? 'Saved: ' . implode( ', ', $bits ) . '.' : 'Nothing was changed.';
		return array( $errors ? new WP_Error( 'bftd_school', ( $bits ? $say . ' ' : '' ) . implode( ' ', $errors ) ) : true, $say );
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( BFTD_Admin::MENU_SLUG, 'Schools', 'Schools', BFTD_Roles::ADMIN_CAP, self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	/** The School box on a student's History, in place of the plain text box. */
	public static function render_field( $sid, $field ) {
		$text = (string) BFTD_Fields::get( $sid, 'student', 'school' );
		$s    = self::get( self::of_student( $sid ) );
		?>
		<div class="bftd-field bftd-school">
			<label class="bftd-label" for="bftd_student_school"><?php echo esc_html( $field['label'] ?? 'School' ); ?></label>
			<div class="bftd-school-box">
				<input type="text" id="bftd_student_school" name="bftd[student][school]" value="<?php echo esc_attr( $text ); ?>" autocomplete="off"
					role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="bftd-school-list" placeholder="Search schools">
				<input type="hidden" name="bftd_school_pick" value="<?php echo $s ? 'list:' . (int) $s['id'] : ''; ?>">
				<div id="bftd-school-list" class="bftd-school-list" role="listbox" aria-label="Schools" hidden></div>
			</div>
			<p class="description bftd-school-said" aria-live="polite"><?php
				if ( $s ) echo esc_html( 'On the school list' . ( '' !== (string) $s['ghl_id'] ? ', linked to GoHighLevel.' : '.' ) );
				elseif ( '' !== trim( $text ) ) echo 'Not on the school list yet. Saving adds it, or links the school already there by this name.';
				else echo 'Search GoHighLevel and the school list.';
			?></p>
		</div>
		<?php
	}

	public static function render_page() {
		if ( ! self::can_edit() ) return;
		$list   = self::all();
		$counts = self::counts();
		$comps  = self::companies();
		$err    = (string) get_transient( self::CACHE_ERR );
		$dupes  = self::possible_duplicates();
		$unlinked_students = 0;
		foreach ( get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $sid ) {
			if ( ! self::of_student( $sid ) && '' !== trim( (string) BFTD_Fields::get( $sid, 'student', 'school' ) ) ) $unlinked_students++;
		}
		$form = function ( $what, $inner, $class = '' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bftd-sch-form ' . esc_attr( $class ) . '">';
			echo '<input type="hidden" name="action" value="bftd_school_' . esc_attr( $what ) . '">';
			wp_nonce_field( 'bftd_school_' . $what );
			echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts below
			echo '</form>';
		};
		?>
		<div class="wrap bftd-wrap bftd-schools">
			<h1>Schools</h1>
			<?php if ( isset( $_GET['bftd_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['bftd_msg'] ) ) ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['bftd_err'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['bftd_err'] ) ) ) ); ?></p></div><?php endif; ?>
			<p class="description">The schools students are linked to. A student's School searches GoHighLevel's Companies and this list, and adds a school here when neither has it. Names that differ only in case, punctuation or short forms (Elem., Jr.) are the same school, so the list holds one of each.</p>

			<div class="bftd-sch-top">
				<?php
				$form( 'add', '<label class="screen-reader-text" for="bftd-sch-add">New school</label><input type="text" id="bftd-sch-add" name="name" placeholder="Add a school" required> <button class="button button-primary">Add</button>' );
				$form( 'refresh', '<button class="button">Refresh from GoHighLevel</button>' );
				if ( $unlinked_students ) {
					$form( 'import', '<button class="button">Link ' . (int) $unlinked_students . ' students by the school typed on them</button>' );
				}
				?>
				<p class="bftd-sch-ghl"><?php echo $err ? '<strong>' . esc_html( $err ) . '</strong>' : esc_html( count( $comps ) . ' Companies in GoHighLevel.' ); ?></p>
			</div>

			<?php if ( $dupes ) : ?>
				<h2>Possibly the same school</h2>
				<p class="description">These names share a word. Merge them if they are one school; leave them if not.</p>
				<ul class="bftd-sch-dupes">
					<?php foreach ( $dupes as $d ) :
						list( $a, $b ) = $d;
						$into = ( $counts[ $b ] ?? 0 ) > ( $counts[ $a ] ?? 0 ) ? $b : $a;
						$from = $into === $a ? $b : $a; ?>
						<li><?php echo esc_html( $list[ $a ]['name'] . ' and ' . $list[ $b ]['name'] ); ?>
							<?php $form( 'merge', '<input type="hidden" name="id" value="' . (int) $from . '"><input type="hidden" name="into" value="' . (int) $into . '"><button class="button-link">Merge into ' . esc_html( $list[ $into ]['name'] ) . '</button>', 'bftd-sch-inline' ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php // One form for the table, saved at once (1.213.2). ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bftd-sch-save">
				<input type="hidden" name="action" value="bftd_school_save">
				<?php wp_nonce_field( 'bftd_school_save' ); ?>
				<p class="bftd-sch-savebar"><button type="submit" class="button button-primary" name="do" value="save">Save changes</button>
					<span class="description">Change names, pick Companies to link and schools to merge, then save once.</span></p>
				<table class="widefat striped bftd-sch-table">
					<thead><tr><th scope="col">School</th><th scope="col">Students</th><th scope="col">GoHighLevel</th><th scope="col">Merge or delete</th></tr></thead>
					<tbody>
					<?php if ( ! $list ) : ?><tr><td colspan="4">No schools yet.</td></tr><?php endif; ?>
					<?php foreach ( $list as $id => $s ) :
						$n = (int) ( $counts[ $id ] ?? 0 );
						$c = '' !== (string) $s['ghl_id'] ? self::company( $s['ghl_id'] ) : null; ?>
						<tr>
							<td><label class="screen-reader-text" for="bftd-sch-<?php echo (int) $id; ?>">Name</label>
								<input type="text" id="bftd-sch-<?php echo (int) $id; ?>" name="name[<?php echo (int) $id; ?>]" value="<?php echo esc_attr( $s['name'] ); ?>"></td>
							<td><?php if ( $n ) : ?><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . BFTD_Students::SLUG . '&school=' . (int) $id ) ); ?>"><?php echo (int) $n; ?></a><?php else : ?>0<?php endif; ?></td>
							<td class="bftd-sch-ghlcell"><?php if ( '' !== (string) $s['ghl_id'] ) : ?>
									<span><?php echo esc_html( $c ? 'Linked: ' . $c['name'] : 'Linked' ); ?></span>
									<button type="submit" class="button-link" name="do" value="unlink:<?php echo (int) $id; ?>">Unlink</button>
								<?php elseif ( $comps ) : ?>
									<label class="screen-reader-text" for="bftd-sch-l<?php echo (int) $id; ?>">Company</label>
									<select id="bftd-sch-l<?php echo (int) $id; ?>" name="ghl[<?php echo (int) $id; ?>]">
										<option value="">Not linked</option>
										<?php foreach ( $comps as $cc ) : ?><option value="<?php echo esc_attr( $cc['id'] ); ?>"><?php echo esc_html( $cc['name'] ); ?></option><?php endforeach; ?>
									</select>
								<?php else : ?>Not linked<?php endif; ?></td>
							<td class="bftd-sch-ghlcell"><?php if ( count( $list ) > 1 ) : ?>
									<label class="screen-reader-text" for="bftd-sch-m<?php echo (int) $id; ?>">Merge into</label>
									<select id="bftd-sch-m<?php echo (int) $id; ?>" name="into[<?php echo (int) $id; ?>]">
										<option value="">Keep</option>
										<?php foreach ( $list as $oid => $os ) : if ( (int) $oid === (int) $id ) continue; ?><option value="<?php echo (int) $oid; ?>">Merge into <?php echo esc_html( $os['name'] ); ?></option><?php endforeach; ?>
									</select>
								<?php endif; ?>
								<?php if ( ! $n ) : ?><button type="submit" class="button-link bftd-sch-del" name="do" value="delete:<?php echo (int) $id; ?>">Delete</button><?php endif; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $list ) : ?><p class="bftd-sch-savebar"><button type="submit" class="button button-primary" name="do" value="save">Save changes</button></p><?php endif; ?>
			</form>
		</div>
		<?php
	}
}
