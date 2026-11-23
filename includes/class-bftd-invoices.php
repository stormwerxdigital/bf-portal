<?php
/**
 * Renewal invoices for families (Karl, 1.219: "For regular students, without
 * a linked school, paying in advance, when they reach the end of their
 * sessions, we need a draft invoice created 1 week in advance of their last
 * session that we can adjust in the portal, and then send through GHL").
 *
 * A week before a family-paid student's last session (Attending to), a draft
 * is made: the number of sessions they bought last time at their Rate per
 * session, due on that last session. Senior managers and administrators see
 * a banner and review drafts on Brilliant Futures, Invoices: change the
 * sessions, rate, due date or note, discard it, or send it. Sending creates
 * the invoice in GoHighLevel for the family's contact and emails it from
 * there. Every hour the portal asks GoHighLevel about invoices it sent; one
 * paid adds its sessions to Sessions purchased, so Attending to moves out.
 *
 * One draft for each end date: a student whose Attending to moves gets a new
 * one when the new date comes within the week; a draft still waiting for an
 * old date is left for somebody to send or discard.
 *
 * "Last time" is the purchase history kept from 1.219 on: each time Sessions
 * purchased goes up, how many it went up by. Until a student has one, the
 * first renewal starts from their Sessions purchased.
 */
defined( 'ABSPATH' ) || exit;

class BFTD_Invoices {

	const OPTION    = 'bftd_invoices';           // id => invoice
	const NEXT      = 'bftd_invoices_next';
	const SETTINGS  = 'bftd_invoice_settings';   // currency, sender (GoHighLevel user id)
	const PURCHASES = '_bftd_purchases';         // on the student: list of array( at, n )
	const SLUG      = 'bftd-invoices';
	const LEAD_DAYS = 7;
	const CHECKED   = 'bftd_invoices_checked';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 24 );
		add_action( 'admin_post_bftd_invoice', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_bftd_invoice_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'banner' ) );
		// Each hour with the GoHighLevel sync, and on an admin screen at most
		// once an hour, so drafts appear even when the sync is not running.
		add_action( 'bftd_ghl_hourly', array( __CLASS__, 'hourly' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_run' ) );
		// Purchase history: every rise in Sessions purchased.
		add_filter( 'update_post_metadata', array( __CLASS__, 'watch_bank' ), 10, 4 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'watch_bank' ), 10, 4 );
	}

	/* ------------------------------------------------------------------ */
	/* Store                                                               */
	/* ------------------------------------------------------------------ */

	public static function all() {
		$l = get_option( self::OPTION, array() );
		return is_array( $l ) ? $l : array();
	}

	public static function get( $id ) {
		$l = self::all();
		return $l[ (int) $id ] ?? null;
	}

	private static function put( $inv ) {
		$l = self::all();
		$l[ (int) $inv['id'] ] = $inv;
		update_option( self::OPTION, $l, false );
		return $inv;
	}

	public static function settings() {
		$s = get_option( self::SETTINGS, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'currency' => (string) ( $s['currency'] ?? '' ), 'sender' => (string) ( $s['sender'] ?? '' ) );
	}

	public static function currencies() {
		return array( 'CAD' => 'Canadian dollars (CAD)', 'USD' => 'US dollars (USD)' );
	}

	public static function words( $status ) {
		$w = array( 'draft' => 'To review', 'sent' => 'Sent', 'paid' => 'Paid', 'void' => 'Discarded' );
		return $w[ $status ] ?? $status;
	}

	/* ------------------------------------------------------------------ */
	/* Purchase history                                                    */
	/* ------------------------------------------------------------------ */

	/** Sessions purchased going up is a purchase: how many it went up by. */
	public static function watch_bank( $check, $object_id, $meta_key, $value ) {
		if ( BFTD_Schema::meta_key( 'student', 'lessons_bank' ) !== $meta_key ) return $check;
		$old = (int) get_post_meta( (int) $object_id, $meta_key, true );
		$new = (int) ( is_array( $value ) ? 0 : $value );
		if ( $new > $old ) self::record_purchase( (int) $object_id, $new - $old );
		return $check;
	}

	public static function record_purchase( $student_id, $n ) {
		$h = self::purchases( $student_id );
		$h[] = array( 'at' => time(), 'n' => (int) $n );
		update_post_meta( (int) $student_id, self::PURCHASES, array_slice( $h, -24 ) );
	}

	public static function purchases( $student_id ) {
		$h = get_post_meta( (int) $student_id, self::PURCHASES, true );
		return is_array( $h ) ? $h : array();
	}

	/** What they bought last time, or their Sessions purchased before there is a history. */
	public static function last_purchase( $student_id ) {
		$h = self::purchases( $student_id );
		if ( $h ) { $l = end( $h ); if ( (int) $l['n'] > 0 ) return (int) $l['n']; }
		return max( 0, (int) BFTD_Fields::get( (int) $student_id, 'student', 'lessons_bank' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Drafts                                                              */
	/* ------------------------------------------------------------------ */

	/** Whether a student is one a family pays for in advance. */
	public static function family_pays( $student_id ) {
		return ! class_exists( 'BFTD_School_Billing' ) || '' === BFTD_School_Billing::mode_for( $student_id );
	}

	public static function for_end( $student_id, $end ) {
		foreach ( self::all() as $inv ) {
			if ( (int) $inv['student'] === (int) $student_id && $inv['end'] === $end ) return $inv;
		}
		return null;
	}

	/**
	 * A draft for this student's end date if it is due: within a week of the
	 * last session and not past it, family-paid, none made for it yet.
	 *
	 * @return array|null the draft made
	 */
	public static function maybe_draft( $student_id, $today ) {
		$sid = (int) $student_id;
		$end = (string) BFTD_Fields::get( $sid, 'student', 'attend_to' );
		if ( '' === $end || $end < $today || $end > BFTD_Time::add_days( $today, self::LEAD_DAYS ) ) return null;
		if ( ! self::family_pays( $sid ) || self::for_end( $sid, $end ) ) return null;
		$qty = self::last_purchase( $sid );
		if ( $qty < 1 ) return null;
		$id = (int) get_option( self::NEXT, 1 );
		update_option( self::NEXT, $id + 1, false );
		$rate = trim( (string) BFTD_Fields::get( $sid, 'student', 'rate' ) );
		$inv = self::put( array(
			'id' => $id, 'student' => $sid, 'end' => $end, 'status' => 'draft',
			'qty' => $qty, 'rate' => '' === $rate ? '' : number_format( (float) $rate, 2, '.', '' ),
			'due' => $end, 'note' => '', 'made' => time(),
			'ghl_id' => '', 'number' => '', 'sent' => 0, 'paid' => 0, 'error' => '',
		) );
		if ( class_exists( 'BFTD_Audit' ) ) BFTD_Audit::log( 'invoice_drafted', array( 'post_id' => $sid, 'student_id' => $sid, 'invoice' => $id ) );
		return $inv;
	}

	/** Drafts for every student whose last session is within the week. */
	public static function make_drafts( $today = null ) {
		$today = $today ?: BFTD_Time::today();
		$made  = 0;
		foreach ( get_posts( array( 'post_type' => BFTD_CPT::STUDENT, 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) ) as $sid ) {
			if ( self::maybe_draft( $sid, $today ) ) $made++;
		}
		return $made;
	}

	public static function hourly() {
		set_transient( self::CHECKED, 1, HOUR_IN_SECONDS );
		self::make_drafts();
		self::check_paid();
	}

	public static function maybe_run() {
		if ( wp_doing_ajax() || get_transient( self::CHECKED ) ) return;
		set_transient( self::CHECKED, 1, HOUR_IN_SECONDS );
		self::make_drafts();
	}

	/** Change a draft: sessions, rate, due date, note. */
	public static function edit( $id, $in ) {
		$inv = self::get( $id );
		if ( ! $inv || 'draft' !== $inv['status'] ) return new WP_Error( 'bftd_invoice', 'Only a draft can be changed.' );
		$qty  = (int) ( $in['qty'] ?? $inv['qty'] );
		$rate = trim( str_replace( array( '$', ',' ), '', (string) ( $in['rate'] ?? $inv['rate'] ) ) );
		$due  = (string) ( $in['due'] ?? $inv['due'] );
		if ( $qty < 1 || $qty > 500 ) return new WP_Error( 'bftd_invoice', 'Sessions must be between 1 and 500.' );
		if ( '' !== $rate && ( ! is_numeric( $rate ) || (float) $rate < 0 ) ) return new WP_Error( 'bftd_invoice', 'The rate must be an amount, like 45.00.' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $due ) ) return new WP_Error( 'bftd_invoice', 'The due date is not a date.' );
		$inv['qty']  = $qty;
		$inv['rate'] = '' === $rate ? '' : number_format( (float) $rate, 2, '.', '' );
		$inv['due']  = $due;
		$inv['note'] = mb_substr( trim( (string) ( $in['note'] ?? $inv['note'] ) ), 0, 500 );
		return self::put( $inv );
	}

	public static function discard( $id ) {
		$inv = self::get( $id );
		if ( ! $inv || 'draft' !== $inv['status'] ) return new WP_Error( 'bftd_invoice', 'Only a draft can be discarded.' );
		$inv['status'] = 'void';
		return self::put( $inv );
	}

	public static function total( $inv ) {
		return '' === (string) $inv['rate'] ? null : round( (int) $inv['qty'] * (float) $inv['rate'], 2 );
	}

	/* ------------------------------------------------------------------ */
	/* GoHighLevel                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Who the invoice goes to: the GoHighLevel contact whose email is the
	 * client's, else the one tagged primary parent, else the first. Null
	 * when the student is not linked or has no contact with an email.
	 */
	public static function bill_to( $student_id ) {
		if ( ! class_exists( 'BFTD_GHL' ) ) return null;
		$p = BFTD_GHL::people( (int) $student_id );
		$contacts = array_values( array_filter( (array) ( $p['contacts'] ?? array() ), function ( $c ) { return '' !== (string) ( $c['email'] ?? '' ); } ) );
		if ( ! $contacts ) return null;
		$uid   = BFTD_CPT::primary_client_id( (int) $student_id );
		$u     = $uid ? get_userdata( $uid ) : null;
		$email = $u ? strtolower( (string) $u->user_email ) : '';
		foreach ( $contacts as $c ) if ( '' !== $email && $c['email'] === $email ) return $c;
		foreach ( $contacts as $c ) if ( ! empty( $c['primary'] ) ) return $c;
		return $contacts[0];
	}

	/** Why a draft cannot be sent yet, or ''. */
	public static function blocked( $inv ) {
		$s = self::settings();
		if ( ! class_exists( 'BFTD_GHL' ) || ! BFTD_GHL::configured() ) return 'GoHighLevel is not connected.';
		if ( '' === $s['currency'] ) return 'Choose the currency under Invoice settings first.';
		if ( '' === $s['sender'] ) return 'Choose who invoices are sent from under Invoice settings first.';
		if ( '' === (string) $inv['rate'] ) return 'Set a rate first.';
		if ( '' === BFTD_CRM::linked( (int) $inv['student'] ) ) return 'This student is not linked to GoHighLevel.';
		if ( ! self::bill_to( (int) $inv['student'] ) ) return 'GoHighLevel has no contact with an email for this student.';
		return '';
	}

	/** The body GoHighLevel's Create Invoice takes, for a draft. */
	public static function ghl_body( $inv, $contact, $today ) {
		$s     = self::settings();
		$first = trim( strtok( (string) get_the_title( (int) $inv['student'] ), ' ' ) );
		$desc  = $inv['qty'] . ' tutoring sessions' . ( '' !== $first ? ' for ' . $first : '' );
		return array(
			'altId'           => BFTD_GHL::location(),
			'altType'         => 'location',
			'name'            => 'Tutoring sessions',
			'title'           => 'INVOICE',
			'businessDetails' => array( 'name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			'currency'        => $s['currency'],
			'items'           => array( array(
				'name' => 'Tutoring sessions', 'description' => $desc, 'currency' => $s['currency'],
				'amount' => (float) $inv['rate'], 'qty' => (int) $inv['qty'], 'type' => 'one_time',
			) ),
			'discount'        => array( 'type' => 'percentage', 'value' => 0 ),
			'contactDetails'  => array( 'id' => $contact['id'], 'name' => $contact['name'], 'phoneNo' => (string) $contact['phone'], 'email' => $contact['email'] ),
			'issueDate'       => $today,
			'dueDate'         => $inv['due'] < $today ? $today : $inv['due'],
			'sentTo'          => array( 'email' => array( $contact['email'] ) ),
			'termsNotes'      => esc_html( (string) $inv['note'] ),
			'liveMode'        => true,
		);
	}

	/** Create the invoice in GoHighLevel and send it by email. */
	public static function send( $id ) {
		$inv = self::get( $id );
		if ( ! $inv || 'draft' !== $inv['status'] ) return new WP_Error( 'bftd_invoice', 'Only a draft can be sent.' );
		$why = self::blocked( $inv );
		if ( '' !== $why ) return new WP_Error( 'bftd_invoice', $why );
		$contact = self::bill_to( (int) $inv['student'] );
		$today   = BFTD_Time::today();
		// Made once: a send that failed after creating it sends the same one again.
		if ( '' === (string) $inv['ghl_id'] ) {
			list( $code, $data, $err ) = BFTD_GHL::request( 'POST', '/invoices/', array(), self::ghl_body( $inv, $contact, $today ), '2021-07-28' );
			if ( '' !== $err || empty( $data['_id'] ) ) return self::failed( $inv, $code, $err ?: 'GoHighLevel did not say which invoice it made.' );
			$inv['ghl_id'] = (string) $data['_id'];
			$inv['number'] = (string) ( $data['invoiceNumber'] ?? '' );
			self::put( $inv );
		}
		list( $code, $data, $err ) = BFTD_GHL::request( 'POST', '/invoices/' . rawurlencode( $inv['ghl_id'] ) . '/send', array(), array(
			'altId' => BFTD_GHL::location(), 'altType' => 'location', 'userId' => self::settings()['sender'], 'action' => 'email', 'liveMode' => true,
		), '2021-07-28' );
		if ( '' !== $err ) return self::failed( $inv, $code, $err );
		$inv['status'] = 'sent';
		$inv['sent']   = time();
		$inv['to']     = $contact['email'];
		$inv['error']  = '';
		self::put( $inv );
		if ( class_exists( 'BFTD_Audit' ) ) BFTD_Audit::log( 'invoice_sent', array( 'post_id' => (int) $inv['student'], 'student_id' => (int) $inv['student'], 'invoice' => (int) $inv['id'] ) );
		return $inv;
	}

	private static function failed( $inv, $code, $err ) {
		$msg = ( 401 === (int) $code || 403 === (int) $code )
			? 'GoHighLevel will not let the portal make invoices yet. Add the invoices permissions (invoices.write and invoices.readonly) to the portal\'s token.'
			: 'GoHighLevel said: ' . $err;
		$inv['error'] = $msg;
		self::put( $inv );
		return new WP_Error( 'bftd_invoice', $msg );
	}

	/**
	 * Ask GoHighLevel about each invoice sent and not yet paid. Paid adds its
	 * sessions to Sessions purchased, once; void marks it discarded.
	 */
	public static function check_paid() {
		if ( ! class_exists( 'BFTD_GHL' ) || ! BFTD_GHL::configured() ) return 0;
		$n = 0;
		foreach ( self::all() as $inv ) {
			if ( 'sent' !== $inv['status'] || '' === (string) $inv['ghl_id'] ) continue;
			list( $code, $data, $err ) = BFTD_GHL::request( 'GET', '/invoices/' . rawurlencode( $inv['ghl_id'] ), array( 'altId' => BFTD_GHL::location(), 'altType' => 'location' ), null, '2021-07-28' );
			if ( '' !== $err ) continue;
			$st = (string) ( $data['status'] ?? ( $data['invoice']['status'] ?? '' ) );
			if ( 'paid' === $st ) { self::mark_paid( $inv['id'] ); $n++; }
			elseif ( 'void' === $st ) { $inv['status'] = 'void'; self::put( $inv ); }
		}
		return $n;
	}

	public static function mark_paid( $id ) {
		$inv = self::get( $id );
		if ( ! $inv || 'paid' === $inv['status'] ) return $inv;
		$sid = (int) $inv['student'];
		$inv['status'] = 'paid';
		$inv['paid']   = time();
		self::put( $inv );   // first, so a second run never adds the sessions twice
		$had = (int) BFTD_Fields::get( $sid, 'student', 'lessons_bank' );
		BFTD_Fields::set( $sid, 'student', 'lessons_bank', (string) ( $had + (int) $inv['qty'] ) );
		if ( class_exists( 'BFTD_Forecast' ) ) BFTD_Forecast::apply( $sid );
		if ( class_exists( 'BFTD_Audit' ) ) BFTD_Audit::log( 'invoice_paid', array( 'post_id' => $sid, 'student_id' => $sid, 'invoice' => (int) $inv['id'] ) );
		return $inv;
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public static function can() {
		return BFTD_Roles::can_administer();
	}

	public static function menu() {
		$n = count( self::with_status( 'draft' ) );
		$label = 'Invoices' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
		add_submenu_page( BFTD_Admin::MENU_SLUG, 'Invoices', $label, BFTD_Roles::ADMIN_CAP, self::SLUG, array( __CLASS__, 'render_page' ) );
	}

	public static function with_status( $status ) {
		$out = array_filter( self::all(), function ( $i ) use ( $status ) { return $status === $i['status']; } );
		uasort( $out, function ( $a, $b ) { return strcmp( $a['end'], $b['end'] ); } );
		return $out;
	}

	public static function banner() {
		if ( ! self::can() ) return;
		if ( isset( $_GET['page'] ) && self::SLUG === $_GET['page'] ) return;
		$n = count( self::with_status( 'draft' ) );
		if ( ! $n ) return;
		echo '<div class="notice notice-info bftd-inv-banner"><p>' . esc_html( $n . ' renewal ' . ( 1 === $n ? 'invoice is' : 'invoices are' ) . ' waiting to be reviewed and sent.' )
			. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Review ' . ( 1 === $n ? 'it' : 'them' ) . '</a></p></div>';
	}

	public static function handle() {
		check_admin_referer( 'bftd_invoice' );
		if ( ! self::can() ) wp_die( 'Only senior managers and administrators can work on invoices.', 403 );
		$id = absint( $_POST['id'] ?? 0 );
		$do = sanitize_key( $_POST['do'] ?? 'save' );
		$in = array(
			'qty'  => absint( $_POST['qty'] ?? 0 ),
			'rate' => sanitize_text_field( wp_unslash( $_POST['rate'] ?? '' ) ),
			'due'  => sanitize_text_field( wp_unslash( $_POST['due'] ?? '' ) ),
			'note' => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		);
		$say = '';
		if ( 'discard' === $do ) { $r = self::discard( $id ); $say = 'Discarded.'; }
		else {
			$r = self::edit( $id, $in );
			$say = 'Saved.';
			if ( ! is_wp_error( $r ) && 'send' === $do ) {
				$r = self::send( $id );
				if ( ! is_wp_error( $r ) ) $say = 'Sent through GoHighLevel' . ( '' !== (string) $r['number'] ? ', invoice ' . $r['number'] : '' ) . ', to ' . $r['to'] . '.';
			}
		}
		$args = is_wp_error( $r ) ? array( 'bftd_err' => rawurlencode( $r->get_error_message() ) ) : array( 'bftd_msg' => rawurlencode( $say ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	public static function save_settings() {
		check_admin_referer( 'bftd_invoice_settings' );
		if ( ! self::can() ) wp_die( 'Only senior managers and administrators can change invoice settings.', 403 );
		$cur = sanitize_text_field( wp_unslash( $_POST['currency'] ?? '' ) );
		$snd = sanitize_text_field( wp_unslash( $_POST['sender'] ?? '' ) );
		update_option( self::SETTINGS, array(
			'currency' => isset( self::currencies()[ $cur ] ) ? $cur : '',
			'sender'   => preg_match( '/^[A-Za-z0-9]{1,64}$/', $snd ) ? $snd : '',
		), false );
		wp_safe_redirect( add_query_arg( 'bftd_msg', rawurlencode( 'Invoice settings saved.' ), admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	/** GoHighLevel staff seen on any student, id => name: who invoices can be sent from. */
	public static function senders() {
		$out = array();
		if ( ! class_exists( 'BFTD_GHL' ) ) return $out;
		foreach ( array_keys( BFTD_GHL::linked_students() ) as $sid ) {
			$p = BFTD_GHL::people( (int) $sid );
			foreach ( (array) ( $p['staff'] ?? array() ) as $u ) if ( '' !== (string) $u['id'] ) $out[ (string) $u['id'] ] = $u['name'] . ( '' !== $u['email'] ? ' (' . $u['email'] . ')' : '' );
		}
		asort( $out );
		return $out;
	}

	public static function render_page() {
		if ( ! self::can() ) return;
		$s = self::settings();
		?>
		<div class="wrap bftd-wrap bftd-invoices">
			<h1>Invoices</h1>
			<?php if ( isset( $_GET['bftd_msg'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['bftd_msg'] ) ) ) ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['bftd_err'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['bftd_err'] ) ) ) ); ?></p></div><?php endif; ?>
			<p class="description">A week before a family's last session (Attending to), a renewal draft is made here: the sessions they bought last time, at their Rate per session, due on that last session. Change anything, then send it through GoHighLevel, which emails it to the family. When GoHighLevel shows it paid, its sessions are added to Sessions purchased. Students a school pays for are not included.</p>

			<h2>To review</h2>
			<?php $drafts = self::with_status( 'draft' ); ?>
			<?php if ( ! $drafts ) : ?><p>Nothing to review.</p><?php endif; ?>
			<?php foreach ( $drafts as $inv ) self::draft_card( $inv ); ?>

			<h2>Sent and paid</h2>
			<?php self::history(); ?>

			<h2 id="settings">Invoice settings</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bftd-inv-settings">
				<input type="hidden" name="action" value="bftd_invoice_settings">
				<?php wp_nonce_field( 'bftd_invoice_settings' ); ?>
				<p><label for="bftd-inv-cur">Currency</label>
					<select id="bftd-inv-cur" name="currency"><option value="">Choose</option>
						<?php foreach ( self::currencies() as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $s['currency'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select></p>
				<?php $senders = self::senders(); ?>
				<p><label for="bftd-inv-from">Send from</label>
					<select id="bftd-inv-from" name="sender"><option value="">Choose a GoHighLevel user</option>
						<?php foreach ( $senders as $k => $label ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $s['sender'] ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
					</select>
					<span class="description">GoHighLevel sends each invoice as one of its users, who gets the replies.<?php echo $senders ? '' : ' None found yet: the GoHighLevel sync lists the staff on linked students.'; ?></span></p>
				<p><button class="button button-primary">Save settings</button></p>
			</form>
		</div>
		<?php
	}

	private static function draft_card( $inv ) {
		$sid   = (int) $inv['student'];
		$c     = self::bill_to( $sid );
		$why   = self::blocked( $inv );
		$total = self::total( $inv );
		$i     = (int) $inv['id'];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bftd-inv-card">
			<input type="hidden" name="action" value="bftd_invoice">
			<input type="hidden" name="id" value="<?php echo $i; ?>">
			<?php wp_nonce_field( 'bftd_invoice' ); ?>
			<h3><a href="<?php echo esc_url( get_edit_post_link( $sid ) ); ?>"><?php echo esc_html( get_the_title( $sid ) ); ?></a></h3>
			<p class="bftd-inv-meta">Last session <?php echo esc_html( BFTD_Time::day( $inv['end'], 'j M Y' ) ); ?>.
				Bill to: <?php echo $c ? esc_html( $c['name'] . ' (' . $c['email'] . ')' ) : '<em>no GoHighLevel contact with an email</em>'; ?></p>
			<div class="bftd-inv-fields">
				<p><label for="bftd-inv-q<?php echo $i; ?>">Sessions</label><input type="number" min="1" max="500" class="small-text" id="bftd-inv-q<?php echo $i; ?>" name="qty" value="<?php echo (int) $inv['qty']; ?>"></p>
				<p><label for="bftd-inv-r<?php echo $i; ?>">Rate per session, $</label><input type="number" min="0" step="0.01" class="small-text" id="bftd-inv-r<?php echo $i; ?>" name="rate" value="<?php echo esc_attr( $inv['rate'] ); ?>"></p>
				<p><label for="bftd-inv-d<?php echo $i; ?>">Due</label><input type="date" id="bftd-inv-d<?php echo $i; ?>" name="due" value="<?php echo esc_attr( $inv['due'] ); ?>"></p>
				<p class="bftd-inv-total"><span class="bftd-label">Total</span><strong><?php echo null === $total ? 'Set a rate' : esc_html( '$' . number_format( $total, 2 ) ); ?></strong></p>
			</div>
			<p><label for="bftd-inv-n<?php echo $i; ?>">Note on the invoice</label><textarea id="bftd-inv-n<?php echo $i; ?>" name="note" rows="2"><?php echo esc_textarea( $inv['note'] ); ?></textarea></p>
			<?php if ( '' !== (string) $inv['error'] ) : ?><p class="bftd-appt-err" role="alert"><?php echo esc_html( $inv['error'] ); ?></p><?php endif; ?>
			<p class="bftd-inv-actions">
				<button class="button" name="do" value="save">Save</button>
				<button class="button button-primary" name="do" value="send" <?php disabled( '' !== $why ); ?>>Send through GoHighLevel</button>
				<button class="button-link bftd-sch-del" name="do" value="discard">Discard</button>
				<?php if ( '' !== $why ) : ?><span class="description"><?php echo esc_html( $why ); ?></span><?php endif; ?>
			</p>
		</form>
		<?php
	}

	private static function history() {
		$rows = array_filter( self::all(), function ( $i ) { return in_array( $i['status'], array( 'sent', 'paid', 'void' ), true ); } );
		uasort( $rows, function ( $a, $b ) { return max( $b['sent'], $b['made'] ) <=> max( $a['sent'], $a['made'] ); } );
		$rows = array_slice( $rows, 0, 50, true );
		if ( ! $rows ) { echo '<p>None yet.</p>'; return; }
		?>
		<table class="widefat striped bftd-inv-table">
			<thead><tr><th scope="col">Student</th><th scope="col">Invoice</th><th scope="col">Sessions</th><th scope="col">Total</th><th scope="col">Due</th><th scope="col">Status</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $inv ) : $t = self::total( $inv ); ?>
				<tr><td><?php echo esc_html( get_the_title( (int) $inv['student'] ) ); ?></td>
					<td><?php echo '' !== (string) $inv['number'] ? esc_html( '#' . $inv['number'] ) : '&ndash;'; ?></td>
					<td><?php echo (int) $inv['qty']; ?></td>
					<td><?php echo null === $t ? '&ndash;' : esc_html( '$' . number_format( $t, 2 ) ); ?></td>
					<td><?php echo esc_html( BFTD_Time::day( $inv['due'], 'j M Y' ) ); ?></td>
					<td><?php echo esc_html( self::words( $inv['status'] ) . ( 'paid' === $inv['status'] && $inv['paid'] ? ', ' . (int) $inv['qty'] . ' sessions added' : '' ) ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
