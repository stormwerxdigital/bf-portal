<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GoHighLevel calendars, booking phase 1 (1.187).
 *
 * Bookings will be made from the portal into GoHighLevel's calendars, and
 * GoHighLevel sends the reminders. Before any booking, two things have to be
 * true: each tutor's GoHighLevel calendar is known, and GoHighLevel's clock
 * agrees with ours.
 *
 * Which calendar is whose is set by a manager, senior manager or
 * administrator on Settings, Calendars, with a suggestion from the person
 * GoHighLevel has on each calendar.
 *
 * The time zone: GoHighLevel reads it from Business info, and Karl expects
 * it may lag behind the time Prince George is actually on. The portal cannot
 * change Business info (GoHighLevel keeps that to the agency), so it checks
 * instead: Karl sets the UTC offset we are on, and every hour the portal
 * works out what GoHighLevel's time zone gives today. When they differ it
 * says so, on the tab and at the top of the portal's screens for managers,
 * with the time zone to choose in GoHighLevel. Read only.
 */
class BFTD_Calendars {

	/** Calendar id => portal user id. */
	const LINKS = 'bftd_ghl_calendar_links';

	/** GoHighLevel's calendars and users, kept ten minutes. */
	const CACHE = 'bftd_ghl_calendars';

	/** The offset we are on, in minutes east of UTC; '' when not set. */
	const OFFSET = 'bftd_tz_offset';

	/** The last time zone check. */
	const CHECKED = 'bftd_tz_check';

	/**
	 * Zones offered first when they fit: two in northeast BC that stay on
	 * UTC-7 all year, then Yukon and Arizona, which GoHighLevel's list is
	 * likelier to have.
	 */
	const PREFERRED = array( 'America/Dawson_Creek', 'America/Fort_Nelson', 'America/Whitehorse', 'America/Phoenix' );

	public static function init() {
		add_action( BFTD_GHL::CRON, array( __CLASS__, 'check_timezone' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Calendars and who they belong to                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * GoHighLevel's calendars and users.
	 *
	 * @return array { ok, error, calendars: id => array( name, type, active, members ), users: id => array( name, email ) }
	 */
	public static function read( $fresh = false ) {
		if ( ! $fresh ) {
			$c = get_transient( self::CACHE );
			if ( is_array( $c ) ) return $c;
		}
		$out = array( 'ok' => false, 'error' => '', 'calendars' => array(), 'users' => array() );
		if ( ! BFTD_GHL::configured() ) { $out['error'] = 'The GoHighLevel token is not set up.'; return $out; }

		list( , $data, $err ) = BFTD_GHL::request( 'GET', '/calendars/', array( 'locationId' => BFTD_GHL::location() ), null, '2021-04-15' );
		if ( '' !== $err ) { $out['error'] = $err; return $out; }
		foreach ( (array) ( $data['calendars'] ?? array() ) as $cal ) {
			if ( empty( $cal['id'] ) ) continue;
			$members = array();
			foreach ( (array) ( $cal['teamMembers'] ?? array() ) as $tm ) if ( ! empty( $tm['userId'] ) ) $members[] = (string) $tm['userId'];
			$out['calendars'][ (string) $cal['id'] ] = array(
				'name'    => sanitize_text_field( (string) ( $cal['name'] ?? '' ) ),
				'type'    => (string) ( $cal['calendarType'] ?? '' ),
				'active'  => ! empty( $cal['isActive'] ),
				'members' => $members,
			);
		}

		list( , $data, $err ) = BFTD_GHL::request( 'GET', '/users/', array( 'locationId' => BFTD_GHL::location() ), null, '2021-07-28' );
		if ( '' === $err ) {
			foreach ( (array) ( $data['users'] ?? array() ) as $u ) {
				if ( empty( $u['id'] ) ) continue;
				$out['users'][ (string) $u['id'] ] = array(
					'name'  => trim( sanitize_text_field( (string) ( $u['firstName'] ?? '' ) . ' ' . (string) ( $u['lastName'] ?? '' ) ) ),
					'email' => strtolower( trim( (string) ( $u['email'] ?? '' ) ) ),
				);
			}
		}
		$out['ok'] = true;
		set_transient( self::CACHE, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}

	/** Calendar id => portal user id, as saved. */
	public static function links() {
		$l = get_option( self::LINKS, array() );
		return is_array( $l ) ? array_map( 'intval', $l ) : array();
	}

	/** A tutor's calendar, or ''. */
	public static function calendar_for( $user_id ) {
		$cal = array_search( (int) $user_id, self::links(), true );
		return false === $cal ? '' : (string) $cal;
	}

	/**
	 * A suggested tutor for each calendar not linked yet. Only a suggestion;
	 * nothing is linked until it is saved.
	 *
	 * The tutor is the staff account here that is somebody GoHighLevel has on
	 * the calendar: the same email, or failing that the same name (1.188:
	 * GoHighLevel has the team at bftutoring.ca, the portal at
	 * bftutoring.com). A tutor on several calendars is suggested for the one
	 * named after them ("Reading Specialist - Laurel", and "Candace" for
	 * Candice), and for none when no name says which.
	 *
	 * @param array $read  as read() returns
	 * @param array $staff email => portal user id, staff accounts only
	 * @param array $names portal user id => display name; staff_names() when null
	 * @return array calendar id => user id
	 */
	public static function suggest( $read, $staff, $names = null ) {
		$names  = null === $names ? self::staff_names() : $names;
		$byname = array();
		foreach ( $names as $uid => $n ) {
			$k = BFTD_GHL::name_key( $n );
			if ( '' !== $k ) $byname[ $k ] = (int) $uid;
		}
		$links = self::links();
		$taken = array_flip( $links );

		$cands = array();   // user id => calendar ids
		foreach ( $read['calendars'] as $cid => $cal ) {
			if ( isset( $links[ $cid ] ) ) continue;
			foreach ( $cal['members'] as $gid ) {
				$email = $read['users'][ $gid ]['email'] ?? '';
				$gname = BFTD_GHL::name_key( $read['users'][ $gid ]['name'] ?? '' );
				$uid   = 0;
				if ( '' !== $email && isset( $staff[ $email ] ) ) $uid = (int) $staff[ $email ];
				elseif ( '' !== $gname && isset( $byname[ $gname ] ) ) $uid = $byname[ $gname ];
				if ( ! $uid || isset( $taken[ $uid ] ) ) continue;
				$cands[ $uid ][] = $cid;
			}
		}

		$out = array();
		foreach ( $cands as $uid => $cids ) {
			$cids = array_values( array_unique( $cids ) );
			if ( count( $cids ) > 1 ) {
				$first = explode( ' ', BFTD_GHL::name_key( $names[ $uid ] ?? '' ) )[0];
				$cids  = array_values( array_filter( $cids, function ( $cid ) use ( $read, $first ) {
					return self::names_person( $read['calendars'][ $cid ]['name'], $first );
				} ) );
			}
			if ( 1 === count( $cids ) && ! isset( $out[ $cids[0] ] ) ) $out[ $cids[0] ] = (int) $uid;
		}
		return $out;
	}

	/**
	 * Does a calendar's name have this first name in it? Exactly, or with
	 * one letter different for a name of four letters or more ("Candace",
	 * "Candice"), where one letter is not most of the name.
	 */
	public static function names_person( $calendar, $first ) {
		if ( '' === $first ) return false;
		foreach ( explode( ' ', BFTD_GHL::name_key( $calendar ) ) as $w ) {
			if ( $w === $first ) return true;
			if ( strlen( $first ) >= 4 && strlen( $w ) === strlen( $first ) && $w[0] === $first[0] && 1 === levenshtein( $w, $first ) ) return true;
		}
		return false;
	}

	/** Staff accounts here: user id => display name. */
	public static function staff_names() {
		$out = array();
		foreach ( BFTD_Roles::staff_users() as $u ) $out[ (int) $u->ID ] = (string) $u->display_name;
		return $out;
	}

	/** Staff accounts here: email => user id. */
	public static function staff_by_email() {
		$out = array();
		foreach ( BFTD_Roles::staff_users() as $u ) {
			$e = strtolower( trim( (string) $u->user_email ) );
			if ( '' !== $e ) $out[ $e ] = (int) $u->ID;
		}
		return $out;
	}

	/**
	 * Calendars linked to tutors, as ticked. A calendar must be one
	 * GoHighLevel has, a tutor a staff account, and each tutor has one
	 * calendar: the one their bookings go into.
	 *
	 * @param array $picked calendar id => user id (0 for nobody)
	 * @return array( changed, refused ) refused is a list of sentences
	 */
	public static function save_links( $picked ) {
		$read  = self::read();
		$staff = array_flip( self::staff_by_email() );   // user id => email
		$was   = self::links();
		$now   = array();
		$used  = array();
		$refused = array();
		foreach ( (array) $picked as $cid => $uid ) {
			$cid = sanitize_text_field( (string) $cid );
			$uid = (int) $uid;
			if ( ! isset( $read['calendars'][ $cid ] ) ) continue;
			if ( ! $uid ) continue;
			$cal = $read['calendars'][ $cid ]['name'];
			if ( ! isset( $staff[ $uid ] ) ) { $refused[] = $cal . ' (that account is not a tutor)'; continue; }
			if ( isset( $used[ $uid ] ) ) {
				$u = get_userdata( $uid );
				$refused[] = $cal . ' (' . ( $u ? $u->display_name : 'that tutor' ) . ' already has ' . $read['calendars'][ $used[ $uid ] ]['name'] . ')';
				continue;
			}
			$now[ $cid ]  = $uid;
			$used[ $uid ] = $cid;
		}
		// A calendar GoHighLevel did not list this time is kept, not dropped.
		foreach ( $was as $cid => $uid ) {
			if ( ! isset( $read['calendars'][ $cid ] ) && ! isset( $used[ $uid ] ) ) { $now[ $cid ] = $uid; $used[ $uid ] = $cid; }
		}
		$changed = 0;
		foreach ( array_unique( array_merge( array_keys( $was ), array_keys( $now ) ) ) as $cid ) {
			$before = $was[ $cid ] ?? 0;
			$after  = $now[ $cid ] ?? 0;
			if ( $before === $after ) continue;
			$changed++;
			$u    = $after ? get_userdata( $after ) : null;
			$name = $read['calendars'][ $cid ]['name'] ?? $cid;
			BFTD_Audit::log( 'calendar_linked', array( 'summary' => $after
				? 'GoHighLevel calendar ' . $name . ' is now ' . ( $u ? $u->display_name : 'user ' . $after ) . '\'s.'
				: 'GoHighLevel calendar ' . $name . ' is no longer linked to a tutor.' ) );
		}
		update_option( self::LINKS, $now, false );
		return array( $changed, $refused );
	}

	/* ------------------------------------------------------------------ */
	/* The time zone                                                       */
	/* ------------------------------------------------------------------ */

	/** The offset we are on, in minutes east of UTC, or null when not set. */
	public static function offset() {
		$v = get_option( self::OFFSET, '' );
		return ( '' === $v || null === $v || false === $v ) ? null : (int) $v;
	}

	/** "UTC-7:00". */
	public static function offset_label( $minutes ) {
		$m = (int) $minutes;
		return 'UTC' . ( $m < 0 ? '-' : '+' ) . intdiv( abs( $m ), 60 ) . ':' . sprintf( '%02d', abs( $m ) % 60 );
	}

	/** A time zone's offset at a moment, in minutes, by this server's time zone rules. */
	public static function zone_offset( $zone, $at = null ) {
		try {
			$tz = new DateTimeZone( (string) $zone );
		} catch ( Exception $e ) {
			return null;
		}
		$at = null === $at ? time() : (int) $at;
		return (int) ( $tz->getOffset( new DateTime( '@' . $at ) ) / 60 );
	}

	/**
	 * Time zones on this offset now that stay on it all year, those in the
	 * same country as $like first: the ones to choose in GoHighLevel.
	 */
	public static function zones_for( $minutes, $like = '', $at = null, $limit = 3 ) {
		$at      = null === $at ? time() : (int) $at;
		$country = '';
		if ( '' !== $like ) {
			try { $loc = ( new DateTimeZone( $like ) )->getLocation(); $country = (string) ( $loc['country_code'] ?? '' ); } catch ( Exception $e ) {}
		}
		$near = array(); $far = array();
		$ids  = array_unique( array_merge( array_intersect( self::PREFERRED, DateTimeZone::listIdentifiers() ), DateTimeZone::listIdentifiers() ) );
		foreach ( $ids as $z ) {
			$tz = new DateTimeZone( $z );
			if ( (int) ( $tz->getOffset( new DateTime( '@' . $at ) ) / 60 ) !== (int) $minutes ) continue;
			if ( count( $tz->getTransitions( $at, $at + 366 * DAY_IN_SECONDS ) ) > 1 ) continue;   // changes during the year
			$loc = $tz->getLocation();
			if ( in_array( $z, self::PREFERRED, true ) || ( '' !== $country && ( $loc['country_code'] ?? '' ) === $country ) ) $near[] = $z; else $far[] = $z;
		}
		return array_slice( array_merge( $near, $far ), 0, $limit );
	}

	/**
	 * Does GoHighLevel's time zone give the time we are on? Read only.
	 * Hourly, and from Check now on the tab.
	 *
	 * @return array the check: when, state (off, ok, differs, error), zone, theirs, ours, suggest, error
	 */
	public static function check_timezone() {
		$ours = self::offset();
		$out  = array( 'when' => time(), 'state' => 'off', 'zone' => '', 'theirs' => null, 'ours' => $ours, 'suggest' => array(), 'error' => '' );
		if ( ! BFTD_GHL::configured() ) { update_option( self::CHECKED, $out, false ); return $out; }
		list( , $data, $err ) = BFTD_GHL::request( 'GET', '/locations/' . rawurlencode( BFTD_GHL::location() ), array(), null, '2021-07-28' );
		// Where the business is, from its business info, for the statutory
		// holidays nothing is booked on (Karl, 1.203).
		if ( '' === $err ) self::remember_place( (array) ( $data['location'] ?? array() ) );
		if ( null === $ours ) { update_option( self::CHECKED, $out, false ); return $out; }
		$zone = (string) ( $data['location']['timezone'] ?? '' );
		if ( '' !== $err || '' === $zone ) {
			$out['state'] = 'error';
			$out['error'] = '' !== $err ? $err : 'GoHighLevel did not say which time zone it is on.';
			update_option( self::CHECKED, $out, false );
			return $out;
		}
		$out['zone']   = $zone;
		$out['theirs'] = self::zone_offset( $zone );
		if ( null === $out['theirs'] ) {
			$out['state'] = 'error';
			$out['error'] = 'GoHighLevel\'s time zone, ' . $zone . ', is not one this server knows.';
		} elseif ( $out['theirs'] === $ours ) {
			$out['state'] = 'ok';
		} else {
			$out['state']   = 'differs';
			$out['suggest'] = self::zones_for( $ours, $zone );
		}
		$was = get_option( self::CHECKED );
		if ( ! is_array( $was ) || ( $was['state'] ?? '' ) !== $out['state'] ) {
			BFTD_Audit::log( 'timezone_checked', array( 'summary' => self::say( $out ) ) );
		}
		update_option( self::CHECKED, $out, false );
		return $out;
	}

	/**
	 * Keep the business's country and province or state, from GoHighLevel's
	 * location: its business address first, the location's own after.
	 */
	public static function remember_place( $loc ) {
		$biz     = (array) ( $loc['business'] ?? array() );
		$country = (string) ( $biz['country'] ?? '' ) ?: (string) ( $loc['country'] ?? '' );
		$state   = (string) ( $biz['state'] ?? '' ) ?: (string) ( $loc['state'] ?? '' );
		if ( '' === $country && '' === $state ) return;
		list( $c, $s ) = BFTD_Stat_Holidays::place_codes( $country, $state );
		$new = array( 'country' => $c, 'state' => $s, 'label' => trim( $state . ', ' . $country, ', ' ) );
		$was = BFTD_Stat_Holidays::place();
		if ( ( $was['country'] ?? '' ) !== $c || ( $was['state'] ?? '' ) !== $s ) {
			BFTD_Audit::log( 'business_place', array( 'summary' => 'The business is in ' . $new['label'] . ', from GoHighLevel. ' . ( BFTD_Stat_Holidays::known()[ $c . '-' . $s ] ?? null ? 'Its statutory holidays are closed to bookings.' : 'The portal has no statutory holiday list for it yet.' ) ) );
		}
		update_option( BFTD_Stat_Holidays::PLACE, $new + array( 'when' => time() ), false );
	}

	/** The check, in a sentence or two. */
	public static function say( $c ) {
		if ( ! is_array( $c ) ) return 'Not checked yet.';
		switch ( $c['state'] ) {
			case 'off':
				return 'Not checked: set the time we are on first.';
			case 'error':
				return 'Could not check: ' . $c['error'];
			case 'ok':
				return 'GoHighLevel is on ' . $c['zone'] . ', which is ' . self::offset_label( $c['theirs'] ) . ' now, the same as us.';
		}
		$hours = abs( $c['ours'] - $c['theirs'] ) / 60;
		$diff  = ( 1.0 === (float) $hours ? 'an hour' : rtrim( rtrim( number_format( $hours, 1 ), '0' ), '.' ) . ' hours' )
			. ( $c['theirs'] < $c['ours'] ? ' behind' : ' ahead of' );
		$say = 'GoHighLevel is on ' . $c['zone'] . ', which is ' . self::offset_label( $c['theirs'] ) . ' now, but we are on '
			. self::offset_label( $c['ours'] ) . ': GoHighLevel is ' . $diff . ( $c['theirs'] < $c['ours'] ? '' : ' us' ) . '.';
		if ( $c['suggest'] ) {
			$say .= ' In GoHighLevel, change Time zone under Settings, Business profile, to ' . $c['suggest'][0]
				. ( count( $c['suggest'] ) > 1 ? ' (or ' . implode( ', or ', array_slice( $c['suggest'], 1 ) ) . ', whichever its list has)' : '' )
				. ', which ' . ( count( $c['suggest'] ) > 1 ? 'are' : 'is' ) . ' ' . self::offset_label( $c['ours'] ) . ' all year.';
		}
		return $say;
	}

	/** At the top of the portal's screens, for managers, while GoHighLevel's clock is wrong. */
	public static function notice() {
		if ( ! BFTD_Roles::can_manage() ) return;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'bftd' ) ) return;
		$c = get_option( self::CHECKED );
		if ( ! is_array( $c ) || 'differs' !== $c['state'] ) return;
		$url = admin_url( 'admin.php?page=' . BFTD_Settings::PAGE_SLUG . '&tab=calendars' );
		echo '<div class="notice notice-warning"><p><strong>GoHighLevel\'s time zone is wrong.</strong> ' . esc_html( self::say( $c ) )
			. ' <a href="' . esc_url( $url ) . '">Calendars</a></p></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings, Calendars                                                 */
	/* ------------------------------------------------------------------ */

	/** Every offset in use, half hours where they exist. */
	public static function offsets() {
		$out = array();
		for ( $m = -12 * 60; $m <= 14 * 60; $m += 30 ) $out[] = $m;
		$out[] = 345; $out[] = 525; $out[] = 765; $out[] = 825;   // Nepal, Australia, Chatham
		sort( $out );
		return array_values( array_unique( $out ) );
	}

	/**
	 * Saved from Settings, Calendars. Managers and up (the page already
	 * requires it; checked again here).
	 *
	 * @return bool whether to say "Saved."
	 */
	public static function save() {
		if ( ! BFTD_Roles::can_manage() ) return false;
		if ( ! empty( $_POST['bftd_cal_refresh'] ) ) { self::read( true ); return false; }

		// Our school year's breaks (1.203), saved whether or not GoHighLevel answers.
		if ( isset( $_POST['bftd_school'] ) ) {
			$before = BFTD_Stat_Holidays::school_years();
			$after  = BFTD_Stat_Holidays::save_school_years( wp_unslash( $_POST['bftd_school'] ) );
			if ( $before !== $after ) {
				BFTD_Audit::log( 'school_year_set', array( 'summary' => 'The school year breaks were changed: ' . ( $after ? implode( '; ', array_map( function ( $y, $row ) {
					return $y . ' to ' . ( $y + 1 ) . ' Christmas ' . ( $row['xmas_from'] ?: 'not set' ) . ' to ' . ( $row['xmas_to'] ?: 'not set' ) . ', spring break ' . ( $row['spring_from'] ?: 'not set' ) . ' to ' . ( $row['spring_to'] ?: 'not set' );
				}, array_keys( $after ), $after ) ) : 'none' ) . '.' ) );
			}
		}
		if ( ! BFTD_GHL::configured() ) return true;

		$off = isset( $_POST['bftd_tz_offset'] ) ? sanitize_text_field( wp_unslash( $_POST['bftd_tz_offset'] ) ) : '';
		$off = ( '' !== $off && in_array( (int) $off, self::offsets(), true ) ) ? (string) (int) $off : '';
		if ( (string) get_option( self::OFFSET, '' ) !== $off ) {
			update_option( self::OFFSET, $off, false );
			BFTD_Audit::log( 'timezone_set', array( 'summary' => '' === $off ? 'The time we are on was cleared.' : 'The time we are on was set to ' . self::offset_label( (int) $off ) . '.' ) );
		}

		$picked = isset( $_POST['bftd_cal'] ) ? (array) wp_unslash( $_POST['bftd_cal'] ) : array();
		list( $changed, $refused ) = self::save_links( $picked );
		if ( $refused ) add_settings_error( 'bftd_settings', 'cal_refused', 'Not linked: ' . implode( '; ', $refused ) . '.', 'error' );

		$c = self::check_timezone();
		add_settings_error( 'bftd_settings', 'tz', self::say( $c ), 'differs' === $c['state'] || 'error' === $c['state'] ? 'error' : 'updated' );
		return true;
	}

	/**
	 * Our school year (Karl, 1.203): each year's Christmas holidays and
	 * spring break, closed to bookings. One row per school year, the next
	 * one entered ahead of time, plus an empty row to add another.
	 */
	public static function render_school_year() {
		$years = BFTD_Stat_Holidays::school_years();
		$now   = (int) substr( BFTD_Time::today(), 0, 4 );
		$rows  = $years;
		$next  = $years ? max( array_keys( $years ) ) + 1 : ( (int) substr( BFTD_Time::today(), 5, 2 ) >= 8 ? $now : $now - 1 );
		$rows[ $next ] = array();
		?>
		<h3 style="margin-top:28px">Our school year</h3>
		<p>Brilliant Futures' own school year, not a school district's. The Christmas holidays and spring break move every year, so enter
			each year's dates here, the first and last day of each break. Nothing can be booked in either break, and weekly bookings skip them.
			Leave a year's dates empty and save to remove it.</p>
		<table class="widefat striped bftd-school-years" style="max-width:900px">
			<thead><tr>
				<th>School year</th><th>Christmas holidays, first day</th><th>Last day</th><th>Spring break, first day</th><th>Last day</th>
			</tr></thead>
			<tbody>
			<?php $i = 0; foreach ( $rows as $y => $row ) : $n = 'bftd_school[' . $i++ . ']'; ?>
				<tr>
					<td><label class="screen-reader-text" for="bftd-school-<?php echo (int) $i; ?>">School year starting</label>
						<select id="bftd-school-<?php echo (int) $i; ?>" name="<?php echo esc_attr( $n ); ?>[year]">
							<?php for ( $opt = $now - 1; $opt <= $now + 3; $opt++ ) : ?>
								<option value="<?php echo (int) $opt; ?>"<?php selected( (int) $y, $opt ); ?>><?php echo esc_html( $opt . ' to ' . ( $opt + 1 ) ); ?></option>
							<?php endfor; ?>
						</select></td>
					<?php foreach ( array( 'xmas_from', 'xmas_to', 'spring_from', 'spring_to' ) as $k ) : ?>
						<td><input type="date" name="<?php echo esc_attr( $n . '[' . $k . ']' ); ?>" value="<?php echo esc_attr( (string) ( $row[ $k ] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( str_replace( '_', ' ', $k ) ); ?>"></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render_tab() {
		if ( ! BFTD_GHL::configured() ) {
			echo '<p><strong>Not set up yet.</strong> GoHighLevel has to be connected first, under CRM connection.</p>';
			self::render_school_year();
			return;
		}
		$read = self::read();
		if ( ! $read['ok'] ) {
			echo '<div class="notice notice-error inline"><p>GoHighLevel did not answer: ' . esc_html( $read['error'] ) . '</p></div>';
			self::render_school_year();
			return;
		}
		$links   = self::links();
		$suggest = self::suggest( $read, self::staff_by_email() );
		$staff   = BFTD_Roles::staff_users();
		$ours    = self::offset();
		?>
		<p class="bftd-lede">Each tutor books into their own GoHighLevel calendar, and GoHighLevel sends the reminders. Link each tutor's
			calendar here. A suggestion comes from the person GoHighLevel has on the calendar, matched by email or by name, and for
			someone on several calendars, the one named after them; nothing is linked until you save.</p>
		<p><?php echo esc_html( count( $read['calendars'] ) ); ?> calendars in GoHighLevel, <?php echo esc_html( count( array_intersect_key( $links, $read['calendars'] ) ) ); ?> linked to a tutor.
			<button class="button-link" name="bftd_cal_refresh" value="1">Read GoHighLevel again</button></p>

		<table class="widefat striped bftd-cal-table" style="max-width:1000px">
			<thead><tr><th>Calendar</th><th>Kind</th><th>On it in GoHighLevel</th><th>Tutor here</th></tr></thead>
			<tbody>
			<?php foreach ( $read['calendars'] as $cid => $cal ) :
				$names = array();
				foreach ( $cal['members'] as $gid ) $names[] = $read['users'][ $gid ]['name'] ?? 'someone';
				$now  = $links[ $cid ] ?? 0;
				$sugg = ! $now && isset( $suggest[ $cid ] ) ? $suggest[ $cid ] : 0;
				$pick = $now ? $now : $sugg;
				?>
				<tr id="bftd-cal-<?php echo esc_attr( $cid ); ?>">
					<td><?php echo esc_html( $cal['name'] ); ?><?php echo $cal['active'] ? '' : ' <span class="description">(turned off)</span>'; ?></td>
					<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', $cal['type'] ) ) ); ?></td>
					<td><?php echo $names ? esc_html( implode( ', ', $names ) ) : '<span class="description">Nobody</span>'; ?></td>
					<td>
						<select name="bftd_cal[<?php echo esc_attr( $cid ); ?>]" aria-label="<?php echo esc_attr( 'Tutor for ' . $cal['name'] ); ?>">
							<option value="0">Not a tutor's calendar</option>
							<?php foreach ( $staff as $u ) : ?>
								<option value="<?php echo (int) $u->ID; ?>"<?php selected( $pick, (int) $u->ID ); ?>><?php echo esc_html( $u->display_name ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( $sugg ) : ?><span class="description">Suggested, not saved yet</span><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3 style="margin-top:28px">Time zone</h3>
		<p>GoHighLevel's calendars use the time zone in its Business profile, which the portal is not allowed to change. Set the time
			Prince George is on now, and every hour the portal checks that GoHighLevel's time zone gives the same time, and says so
			here and at the top of the portal's screens if it does not.</p>
		<p><label for="bftd-tz-offset"><strong>The time we are on</strong></label><br>
			<select id="bftd-tz-offset" name="bftd_tz_offset">
				<option value="">Not set, do not check</option>
				<?php foreach ( self::offsets() as $m ) : ?>
					<option value="<?php echo (int) $m; ?>"<?php selected( null === $ours ? '' : (string) $ours, (string) $m ); ?>><?php echo esc_html( self::offset_label( $m ) ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<?php $c = get_option( self::CHECKED ); ?>
		<p class="<?php echo is_array( $c ) && 'differs' === $c['state'] ? 'bftd-cal-warn' : 'description'; ?>"><?php echo esc_html( self::say( $c ) ); ?>
			<?php if ( is_array( $c ) && ! empty( $c['when'] ) ) echo esc_html( ' Checked ' . BFTD_Time::format( 'j M Y, g:i a', (int) $c['when'] ) . '.' ); ?></p>

		<h3 style="margin-top:28px">Days closed to bookings</h3>
		<?php
		$place = BFTD_Stat_Holidays::place();
		$list  = BFTD_Stat_Holidays::list_for_place();
		?>
		<p>Nothing can be booked on a Sunday, or on a statutory holiday where the business is. Where it is comes from the business
			address in GoHighLevel, read every hour with the time zone check.</p>
		<?php if ( ! $place ) : ?>
			<p class="bftd-cal-warn">GoHighLevel's business address has not been read yet, so only Sundays are closed. It is read on the next hourly check, or when this tab is saved.</p>
		<?php elseif ( ! $list ) : ?>
			<p class="bftd-cal-warn">The business is in <?php echo esc_html( $place['label'] ); ?>, and the portal has no statutory holiday list for it yet, so only Sundays are closed.</p>
		<?php else : ?>
			<p class="description">The business is in <?php echo esc_html( $place['label'] ); ?>. The next statutory holidays, closed to bookings:</p>
			<ul class="ul-disc">
				<?php
				$today = BFTD_Time::today();
				$n     = 0;
				foreach ( BFTD_Stat_Holidays::between( $today, BFTD_Time::add_days( $today, 400 ) ) as $ymd => $name ) {
					if ( '' === BFTD_Stat_Holidays::holiday_here( $ymd ) ) continue;
					echo '<li>' . esc_html( $name . ', ' . BFTD_Time::day( $ymd, 'D j M Y' ) ) . '</li>';
					if ( ++$n >= 4 ) break;
				}
				?>
			</ul>
		<?php endif; ?>
		<?php
		self::render_school_year();
	}
}
