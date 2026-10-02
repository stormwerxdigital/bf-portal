<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * What the practice owes a tutor, and why.
 *
 * Everywhere else in this plugin the rule is that nothing is stored which can
 * be worked out. This is the one place that rule is wrong, and it is worth
 * saying why rather than leaving it looking like an oversight.
 *
 * A pay entry is a claim about money. The moment somebody approves one it
 * stops describing a lesson and starts describing a debt, and a debt that
 * recalculates itself is not a debt. A tutor's rate goes up in March and
 * February must not repay; a lesson is corrected in June and May's payroll,
 * already run and already in somebody's bank account, must not silently
 * change to disagree with the deposit. So the rate is copied onto the entry
 * when it is made and the entry is frozen when it is decided. After that the
 * lesson can be edited all it likes; the entry says what was paid.
 *
 * ONE LESSON, ONE ENTRY. A lesson record can be taught, cancelled, or moved
 * three times over a fortnight, and it is one hour of somebody's working life
 * either way. The entry is keyed to the lesson, so there can only ever be one
 * of it, and rescheduling a lesson four times pays a tutor once. What changes
 * as the lesson changes is what the entry is FOR — taught, cancelled, moved —
 * which is what the approver is actually deciding about.
 *
 * DRAFTS PAY NOTHING. An entry is made when a record is published and never
 * when it is saved. A draft is a tutor thinking; a published record is a
 * tutor saying this happened.
 *
 * Money is in cents, as integers, everywhere in here. Floats are fine for
 * reading levels and wrong for money, and the place they go wrong is in the
 * hundredth of a cent that turns a cheque for $1,237.00 into one for
 * $1,236.99 in front of the person it belongs to.
 */
class BFTD_Pay {

	const ENTRY = 'bftd_pay_entry';

	/** The meta group. One prefix, built the way every other one is. */
	const GROUP = 'pay';

	/** Where a diagnostic says who did it and when. Named once. */
	const DX_SECTION = 'sec-assessment-overview';

	/* What an entry can be for. */
	const TAUGHT      = 'taught';
	const DIAGNOSTIC  = 'diagnostic';
	const CANCELLED   = 'cancelled';
	const RESCHEDULED = 'rescheduled';
	/* A statutory holiday, and the extra owed for having worked one. */
	const STAT        = 'stat';
	const STAT_EXTRA  = 'stat_extra';

	/* Where an entry is up to. */
	const PENDING  = 'pending';
	const APPROVED = 'approved';
	const DECLINED = 'declined';
	const PAID     = 'paid';

	/* Who a tutor is to the practice, which decides what comes off their pay. */
	const EMPLOYEE   = 'employee';
	const CONTRACTOR = 'contractor';

	/* Where a tutor's own terms are kept. */
	const META_KIND      = 'bftd_pay_kind';
	const META_SESSION   = 'bftd_rate_session';
	const META_DIAGNOSIS = 'bftd_rate_diagnostic';

	/* And the practice's defaults, for a tutor with nothing set. */
	const OPT_SESSION   = 'bftd_rate_session_default';
	const OPT_DIAGNOSIS = 'bftd_rate_diagnostic_default';

	/* How long each kind of work is, for the one calculation that needs
	   hours rather than a count: the premium owed for working a statutory
	   holiday, which the Act sets per hour. Stored as hundredths of an hour
	   so 2.5 is an integer here too. */
	const OPT_HOURS_SESSION   = 'bftd_hours_session';
	const OPT_HOURS_DIAGNOSIS = 'bftd_hours_diagnostic';
	const HOURS_SESSION       = 100;   // one hour
	const HOURS_DIAGNOSIS     = 250;   // two and a half

	/* When this person started, which the statutory holiday test asks for. */
	const META_STARTED = 'bftd_started_on';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );

		// On the transition, not on save_post. The difference is the whole
		// rule: transition_post_status can tell publishing from saving, and
		// save_post cannot, so a ledger built on save_post pays a tutor
		// every time they touch a draft.
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
	}

	public static function register() {
		register_post_type( self::ENTRY, array(
			'labels'              => array( 'name' => 'Pay entries', 'singular_name' => 'Pay entry' ),
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'capability_type'     => array( 'bftd_pay_entry', 'bftd_pay_entries' ),
			'map_meta_cap'        => true,
		) );
	}

	public static function key( $field ) {
		return BFTD_Schema::meta_key( self::GROUP, $field );
	}

	/* ------------------------------------------------------------------ */
	/* A tutor's terms                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Employee or contractor.
	 *
	 * This is not a preference. Which one somebody is decides whether CPP and
	 * EI come off their pay and whether the practice owes its own share, and
	 * getting it wrong is assessed against the practice and not the worker.
	 * The setting records a decision that was made somewhere else.
	 */
	public static function employment( $user_id ) {
		$kind = get_user_meta( (int) $user_id, self::META_KIND, true );
		return ( self::CONTRACTOR === $kind ) ? self::CONTRACTOR : self::EMPLOYEE;
	}

	public static function employments() {
		return array(
			self::EMPLOYEE   => 'Employee',
			self::CONTRACTOR => 'Contractor (sole proprietor)',
		);
	}

	/**
	 * What one of these is worth to this tutor, in cents.
	 *
	 * A cancelled or moved lesson is paid at the lesson rate, because the
	 * tutor held the hour either way. What separates them from a lesson that
	 * went ahead is not the money, it is that somebody has to say yes.
	 */
	public static function rate_for( $user_id, $kind ) {
		$per = ( self::DIAGNOSTIC === $kind )
			? array( self::META_DIAGNOSIS, self::OPT_DIAGNOSIS )
			: array( self::META_SESSION, self::OPT_SESSION );

		// The tutor's own, in cents, or the practice's default. Both are
		// stored as cents, so neither is converted on the way out and there
		// is no float anywhere between the settings screen and the payslip.
		$own = get_user_meta( (int) $user_id, $per[0], true );
		if ( '' !== trim( (string) $own ) ) return (int) $own;

		return (int) get_option( $per[1], 0 );
	}

	/**
	 * Dollars as they are typed, to cents.
	 *
	 * Through a string, because 75.10 * 100 is 7509.999999999999 in binary
	 * floating point and (int) of that is 7509. A tutor's rate is a decimal
	 * number of dollars a person typed, and the only safe reading of it is
	 * the one that rounds rather than truncates.
	 */
	public static function cents( $dollars ) {
		$raw = trim( (string) $dollars );
		if ( '' === $raw ) return 0;
		$raw = str_replace( array( '$', ',', ' ' ), '', $raw );
		if ( ! is_numeric( $raw ) ) return 0;
		return (int) round( ( (float) $raw ) * 100 );
	}

	/** And back again, for a screen. */
	public static function money( $cents ) {
		$cents = (int) $cents;
		$sign  = ( $cents < 0 ) ? '-' : '';
		return $sign . '$' . number_format( abs( $cents ) / 100, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Making an entry                                                     */
	/* ------------------------------------------------------------------ */

	public static function on_transition( $new, $old, $post ) {
		if ( 'publish' !== $new ) return;
		if ( ! $post || wp_is_post_revision( $post->ID ) ) return;
		if ( ! in_array( $post->post_type, array( BFTD_CPT::SESSION, BFTD_CPT::ASSESSMENT ), true ) ) return;

		self::record( $post );
	}

	/**
	 * The entry for a record, made or brought up to date.
	 *
	 * Called on every publish, including the second and third, which is why
	 * it looks for an existing entry first. A pending entry follows its
	 * lesson: a lesson moved from the 3rd to the 14th is still in the same
	 * pay period, and one moved to the 17th is not, and the ledger has to
	 * agree with the lesson about which. A decided entry does not follow
	 * anything, because somebody has already answered for it.
	 */
	public static function record( $post ) {
		$post = is_numeric( $post ) ? get_post( (int) $post ) : $post;
		if ( ! $post ) return 0;

		$facts = self::facts( $post );
		if ( ! $facts ) return 0;

		return self::put( $facts );
	}

	/**
	 * One entry, made or brought up to date. Never a second one.
	 *
	 * Shared by everything that can put something on a time card, so the
	 * rules about not paying twice and not moving a decided entry are
	 * written once. A session goes through here, and so does a statutory
	 * holiday, which has no record behind it at all.
	 */
	public static function put( $facts ) {
		if ( empty( $facts['ref'] ) ) return 0;

		$existing = self::entry_by_ref( $facts['ref'] );

		if ( $existing ) {
			if ( self::PENDING !== self::state( $existing ) ) {
				// Decided already. What it was made from changed underneath
				// it, which is not an error and not something to absorb
				// silently: it is something an approver should be able to
				// see.
				update_post_meta( $existing, self::key( 'changed' ), BFTD_Time::mysql() );
				return $existing;
			}
			self::write( $existing, $facts );
			return $existing;
		}

		$id = wp_insert_post( array(
			'post_type'   => self::ENTRY,
			'post_status' => 'publish',
			'post_title'  => self::title( $facts ),
		), true );
		if ( is_wp_error( $id ) || ! $id ) return 0;

		self::write( $id, $facts );
		update_post_meta( $id, self::key( 'state' ), self::PENDING );

		BFTD_Audit::log( 'pay_entry_made', array(
			'post_id' => (int) ( isset( $facts['source'] ) ? $facts['source'] : 0 ),
			'summary' => self::title( $facts ) . ' went to the time card.',
		) );

		return (int) $id;
	}

	/**
	 * What the record says, read once.
	 *
	 * Returns null where there is nothing to pay for — no tutor named, or a
	 * lesson with no date — rather than making an entry with a hole in it.
	 * An entry nobody can attribute is worse than no entry: it sits on the
	 * time card as an unanswerable question.
	 */
	public static function facts( $post ) {
		if ( BFTD_CPT::ASSESSMENT === $post->post_type ) {
			$tutor = (int) BFTD_Fields::get( $post->ID, self::DX_SECTION, 'assessed_by' );
			$on    = (string) BFTD_Fields::get( $post->ID, self::DX_SECTION, 'assessed_on' );
			if ( ! $on ) $on = substr( (string) $post->post_date, 0, 10 );
			$kind  = self::DIAGNOSTIC;
			$at    = '';
		} else {
			$tutor = self::tutor_on( $post->ID );
			$on    = (string) BFTD_Fields::get( $post->ID, 'session', 'session_date' );
			$at    = (string) BFTD_Fields::get( $post->ID, 'session', 'session_time' );
			$kind  = self::kind_of_session( $post->ID );
		}

		if ( ! $tutor || '' === $on || ! BFTD_Pay_Period::key_for( $on ) ) return null;

		$rate = self::rate_for( $tutor, $kind );

		return array(
			'ref'     => 'post:' . (int) $post->ID,
			'source'  => (int) $post->ID,
			'tutor'   => (int) $tutor,
			'student' => (int) self::student_on( $post ),
			'kind'    => $kind,
			'on'      => $on,
			'at'      => $at,
			'rate'    => $rate,
			'amount'  => $rate,
		);
	}

	/** Taught, cancelled, or moved. */
	public static function kind_of_session( $session_id ) {
		$status = (string) BFTD_Fields::get( $session_id, 'session', 'status' );
		if ( 'missed' === $status ) return self::CANCELLED;
		if ( 'rescheduled' === $status ) return self::RESCHEDULED;
		return self::TAUGHT;
	}

	/**
	 * Whose hour it was.
	 *
	 * The lesson says so, on a field of its own, because a student's assigned
	 * tutor is who normally teaches them and not necessarily who taught this
	 * one. Covering a colleague's lesson is the case that breaks anything
	 * built on the assignment alone, and it is also the case where getting it
	 * wrong pays the wrong person.
	 */
	public static function tutor_on( $session_id ) {
		return (int) BFTD_Fields::get( $session_id, 'session', 'delivered_by' );
	}

	private static function student_on( $post ) {
		return (int) BFTD_CPT::student_id( $post->ID );
	}

	private static function write( $entry_id, $facts ) {
		foreach ( $facts as $k => $v ) update_post_meta( $entry_id, self::key( $k ), $v );
		wp_update_post( array( 'ID' => (int) $entry_id, 'post_title' => self::title( $facts ) ) );
	}

	/**
	 * A name for the row, which is an id rather than a title.
	 *
	 * The same reasoning as a lesson's own title: nobody writes these and
	 * nobody should have to. It exists so a row in the database is
	 * recognisable to a person looking at it directly.
	 */
	public static function title( $facts ) {
		$who  = get_userdata( (int) $facts['tutor'] );
		$name = $who ? $who->display_name : 'Unassigned';
		return $name . ' · ' . self::kind_label( $facts['kind'] ) . ' · ' . BFTD_Time::day( $facts['on'], 'j M Y' );
	}

	public static function kind_label( $kind ) {
		$all = array(
			self::TAUGHT      => 'Session',
			self::DIAGNOSTIC  => 'Reading diagnostic',
			self::CANCELLED   => 'Cancelled session',
			self::RESCHEDULED => 'Rescheduled session',
			self::STAT        => 'Statutory holiday',
			self::STAT_EXTRA  => 'Worked a statutory holiday',
		);
		return isset( $all[ $kind ] ) ? $all[ $kind ] : 'Session';
	}

	/** Which kinds an approver has to make a decision about before they pay. */
	public static function needs_a_decision( $kind ) {
		return in_array( $kind, array( self::CANCELLED, self::RESCHEDULED, self::STAT, self::STAT_EXTRA ), true );
	}

	/** How long one of these takes, in hundredths of an hour. */
	public static function hours_for( $kind ) {
		if ( self::DIAGNOSTIC === $kind ) {
			$set = (int) get_option( self::OPT_HOURS_DIAGNOSIS, 0 );
			return $set > 0 ? $set : self::HOURS_DIAGNOSIS;
		}
		$set = (int) get_option( self::OPT_HOURS_SESSION, 0 );
		return $set > 0 ? $set : self::HOURS_SESSION;
	}

	/* ------------------------------------------------------------------ */
	/* Reading the ledger                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * The one entry for a thing, or 0. There is never more than one.
	 *
	 * Everything the ledger can hold has a reference that names it, and the
	 * reference is what stops a second entry being made. A session is
	 * "post:412". A statutory holiday is the holiday and the person it is
	 * owed to, because there is no record behind it to point at, and running
	 * the holiday calculation twice must not pay Christmas twice.
	 */
	public static function entry_by_ref( $ref ) {
		$ref = trim( (string) $ref );
		if ( '' === $ref ) return 0;

		$found = get_posts( array(
			'post_type'      => self::ENTRY,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => self::key( 'ref' ),
			'meta_value'     => $ref,
		) );
		return $found ? (int) $found[0] : 0;
	}

	/** The entry for a published record. One definition, through the above. */
	public static function entry_for( $source_id ) {
		return self::entry_by_ref( 'post:' . (int) $source_id );
	}

	public static function state( $entry_id ) {
		$s = get_post_meta( (int) $entry_id, self::key( 'state' ), true );
		return in_array( $s, array( self::PENDING, self::APPROVED, self::DECLINED, self::PAID ), true ) ? $s : self::PENDING;
	}

	public static function get( $entry_id, $field ) {
		return get_post_meta( (int) $entry_id, self::key( $field ), true );
	}

	/**
	 * Every entry in a period, optionally for one tutor.
	 *
	 * On the date the work was done, not the date the entry was made. A
	 * lesson taught on the 14th and written up on the 20th belongs to the
	 * first half of the month, because that is when the tutor worked.
	 */
	public static function entries_in( $period, $tutor_id = 0 ) {
		if ( ! BFTD_Pay_Period::valid( $period ) ) return array();
		return self::entries_between(
			BFTD_Pay_Period::start( $period ),
			BFTD_Pay_Period::end( $period ),
			$tutor_id
		);
	}

	/**
	 * Every entry between two dates, inclusive, optionally for one tutor.
	 *
	 * A pay period is one of these and the thirty days before a statutory
	 * holiday is another, and they are the same question asked twice, so
	 * they are the same query.
	 */
	public static function entries_between( $from, $to, $tutor_id = 0 ) {
		$from = (string) $from;
		$to   = (string) $to;
		if ( '' === $from || '' === $to || $from > $to ) return array();

		$meta = array(
			array(
				'key'     => self::key( 'on' ),
				'value'   => array( $from, $to ),
				'compare' => 'BETWEEN',
				'type'    => 'CHAR',
			),
		);
		if ( $tutor_id ) {
			$meta[] = array( 'key' => self::key( 'tutor' ), 'value' => (int) $tutor_id );
		}

		$ids = get_posts( array(
			'post_type'      => self::ENTRY,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => $meta,
			'orderby'        => 'meta_value',
			'meta_key'       => self::key( 'on' ),
			'order'          => 'ASC',
		) );

		// One trip for all of it, the way every other list in here is built.
		if ( $ids ) update_meta_cache( 'post', $ids );

		return array_map( 'absint', (array) $ids );
	}

	/** An entry read out in full, for a screen or a total. */
	public static function row( $entry_id ) {
		$entry_id = (int) $entry_id;
		return array(
			'id'      => $entry_id,
			'tutor'   => (int) self::get( $entry_id, 'tutor' ),
			'student' => (int) self::get( $entry_id, 'student' ),
			'source'  => (int) self::get( $entry_id, 'source' ),
			'ref'     => (string) self::get( $entry_id, 'ref' ),
			'kind'    => (string) self::get( $entry_id, 'kind' ),
			'on'      => (string) self::get( $entry_id, 'on' ),
			'at'      => (string) self::get( $entry_id, 'at' ),
			'rate'    => (int) self::get( $entry_id, 'rate' ),
			'amount'  => (int) self::get( $entry_id, 'amount' ),
			'state'   => self::state( $entry_id ),
			'note'    => (string) self::get( $entry_id, 'note' ),
			'changed' => (string) self::get( $entry_id, 'changed' ),
		);
	}

	/**
	 * What a period comes to for one tutor.
	 *
	 * Approved and paid entries only. A pending entry is a question, not an
	 * amount, and adding it into a total is how a tutor comes to expect money
	 * nobody has agreed to yet.
	 */
	public static function totals( $rows ) {
		$out = array( 'due' => 0, 'pending' => 0, 'declined' => 0, 'count' => 0, 'waiting' => 0 );
		foreach ( $rows as $r ) {
			if ( self::DECLINED === $r['state'] ) { $out['declined'] += $r['amount']; continue; }
			if ( self::PENDING === $r['state'] )  { $out['pending']  += $r['amount']; $out['waiting']++; continue; }
			$out['due'] += $r['amount'];
			$out['count']++;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Deciding                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Approve or decline one entry.
	 *
	 * A paid entry is not decidable. Money has moved; the record of it is
	 * closed, and the way to fix a mistake in a closed period is an
	 * adjustment in an open one, not a quiet edit to what was already paid.
	 */
	public static function decide( $entry_id, $to, $by = 0 ) {
		$entry_id = (int) $entry_id;
		if ( ! in_array( $to, array( self::APPROVED, self::DECLINED, self::PENDING ), true ) ) return false;
		if ( self::PAID === self::state( $entry_id ) ) return false;
		if ( self::ENTRY !== get_post_type( $entry_id ) ) return false;

		$by = $by ? (int) $by : get_current_user_id();

		update_post_meta( $entry_id, self::key( 'state' ), $to );
		update_post_meta( $entry_id, self::key( 'decided_by' ), $by );
		update_post_meta( $entry_id, self::key( 'decided_at' ), BFTD_Time::mysql() );
		delete_post_meta( $entry_id, self::key( 'changed' ) );

		BFTD_Audit::log( 'pay_entry_' . $to, array(
			'post_id' => (int) self::get( $entry_id, 'source' ),
			'summary' => get_the_title( $entry_id ) . ' was ' . $to . '.',
		) );

		return true;
	}

	/* ------------------------------------------------------------------ */
	/* A worked example                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * A time card nobody is paid for.
	 *
	 * Somebody learning this screen has to be shown a fortnight with all of
	 * it on: a diagnostic, a cancellation, a reschedule, something already
	 * approved, something already paid and something declined. A real
	 * fortnight almost never has all six at once, and waiting for one that
	 * does is how a person ends up being trained on the week that happened
	 * to be quiet.
	 *
	 * Made here rather than seeded into the database. A sample entry in the
	 * ledger is a sample entry somebody eventually approves.
	 */
	public static function sample( $period ) {
		$start = BFTD_Pay_Period::start( $period );
		if ( ! $start ) return array();

		$day = function ( $n ) use ( $start ) { return BFTD_Time::add_days( $start, (int) $n ); };

		$rows = array(
			array( 'kind' => self::TAUGHT,      'on' => $day( 0 ),  'at' => '16:00', 'who' => 'Jot Singh',    'amount' => 4500, 'state' => self::PAID ),
			array( 'kind' => self::TAUGHT,      'on' => $day( 2 ),  'at' => '09:00', 'who' => 'Kaine M',      'amount' => 4500, 'state' => self::APPROVED ),
			array( 'kind' => self::DIAGNOSTIC,  'on' => $day( 3 ),  'at' => '',      'who' => 'Bea Fournier', 'amount' => 15000, 'state' => self::APPROVED ),
			array( 'kind' => self::RESCHEDULED, 'on' => $day( 7 ),  'at' => '16:00', 'who' => 'Jot Singh',    'amount' => 4500, 'state' => self::PENDING ),
			array( 'kind' => self::CANCELLED,   'on' => $day( 9 ),  'at' => '17:30', 'who' => 'Bea Fournier', 'amount' => 4500, 'state' => self::PENDING ),
			array( 'kind' => self::TAUGHT,      'on' => $day( 10 ), 'at' => '15:00', 'who' => 'Kaine M',      'amount' => 4500, 'state' => self::DECLINED ),
		);

		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array_merge( array(
				'id'      => 0,
				'tutor'   => 0,
				'student' => 0,
				'source'  => 0,
				'rate'    => $r['amount'],
				'note'    => '',
				'changed' => '',
			), $r );
		}
		return $out;
	}

	/** Who may say yes to money. Admins and senior managers, nobody else. */
	public static function can_approve( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		if ( ! $user_id ) return false;
		return user_can( $user_id, BFTD_Roles::ADMIN_CAP ) || user_can( $user_id, 'manage_options' );
	}
}
