<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A tutor's own terms, on their own record.
 *
 * Two rates and a classification. It sits on the WordPress user rather than
 * in a table of its own because that is where the person is: one place to
 * look, and it goes when they go.
 *
 * Only somebody who can approve pay can see this panel, and only they can
 * change it. A tutor able to edit their own rate is not a permission model,
 * and WordPress lets a user edit their own profile by default, so the check
 * is on the capability to approve pay and not on whose profile it is.
 */
class BFTD_Pay_Profile {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'panel' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'panel' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
	}

	/**
	 * Is this somebody the practice pays?
	 *
	 * Staff who have been given terms. Not everyone with a staff capability:
	 * a manager who has never taught a lesson does not want an empty card
	 * with their name on it every fortnight, and a screen full of those is a
	 * screen where the tutor who has nothing this period stops standing out.
	 *
	 * Terms means somebody has opened their profile and answered the two
	 * questions. Until then they are staff who are not on the payroll, which
	 * is a real state and the one everybody starts in.
	 */
	public static function is_paid_staff( $user ) {
		$id = is_object( $user ) ? (int) $user->ID : (int) $user;
		if ( ! BFTD_Roles::is_staff( $id ) ) return false;

		foreach ( array( BFTD_Pay::META_KIND, BFTD_Pay::META_SESSION, BFTD_Pay::META_DIAGNOSIS, BFTD_Pay::META_STARTED ) as $k ) {
			if ( '' !== trim( (string) get_user_meta( $id, $k, true ) ) ) return true;
		}
		return false;
	}

	public static function panel( $user ) {
		if ( ! BFTD_Pay::can_approve() ) return;
		if ( ! self::is_paid_staff( $user ) ) return;

		$kind   = BFTD_Pay::employment( $user->ID );
		$began  = BFTD_Stat_Pay::started( $user->ID );
		$session = get_user_meta( $user->ID, BFTD_Pay::META_SESSION, true );
		$diag   = get_user_meta( $user->ID, BFTD_Pay::META_DIAGNOSIS, true );
		?>
		<h2>Pay</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="bftd_pay_kind">Engaged as</label></th>
				<td>
					<select name="bftd_pay_kind" id="bftd_pay_kind">
						<?php foreach ( BFTD_Pay::employments() as $k => $label ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $kind, $k ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						Decides what comes off their pay. An employee has CPP, EI and income tax
						withheld and is paid vacation pay; a contractor is paid the gross and
						accounts for their own. This records a decision made elsewhere, on the
						facts of the working relationship, rather than making one.
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="bftd_started_on">Started on</label></th>
				<td>
					<input type="date" id="bftd_started_on" name="bftd_started_on" value="<?php echo esc_attr( $began ); ?>">
					<p class="description">
						The Employment Standards Act asks for thirty calendar days of service before
						an employee qualifies for a statutory holiday. Left empty, no statutory
						holiday can be worked out for them and the time card says so rather than
						guessing.
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="bftd_rate_session">Rate per session</label></th>
				<td>
					<input type="text" inputmode="decimal" class="regular-text" id="bftd_rate_session"
						name="bftd_rate_session" value="<?php echo esc_attr( self::shown( $session ) ); ?>" placeholder="<?php echo esc_attr( self::shown( get_option( BFTD_Pay::OPT_SESSION, '' ) ) ); ?>">
					<p class="description">Left empty, the practice default is used. Also what a cancelled or moved session pays, once approved.</p>
				</td>
			</tr>
			<tr>
				<th><label for="bftd_rate_diagnostic">Rate per reading diagnostic</label></th>
				<td>
					<input type="text" inputmode="decimal" class="regular-text" id="bftd_rate_diagnostic"
						name="bftd_rate_diagnostic" value="<?php echo esc_attr( self::shown( $diag ) ); ?>" placeholder="<?php echo esc_attr( self::shown( get_option( BFTD_Pay::OPT_DIAGNOSIS, '' ) ) ); ?>">
					<p class="description">Left empty, the practice default is used.</p>
				</td>
			</tr>
		</table>
		<p class="description">
			A rate is copied onto each entry when it is made, so changing one here
			affects work from now on and never repays what is already on a time card.
		</p>
		<?php
	}

	/** Cents in the database, dollars in the box. */
	private static function shown( $cents ) {
		$cents = trim( (string) $cents );
		return ( '' === $cents ) ? '' : number_format( ( (int) $cents ) / 100, 2, '.', '' );
	}

	public static function save( $user_id ) {
		if ( ! BFTD_Pay::can_approve() ) return;
		if ( ! current_user_can( 'edit_user', $user_id ) ) return;

		if ( isset( $_POST['bftd_pay_kind'] ) ) {
			$kind = sanitize_key( wp_unslash( $_POST['bftd_pay_kind'] ) );
			if ( array_key_exists( $kind, BFTD_Pay::employments() ) ) {
				update_user_meta( $user_id, BFTD_Pay::META_KIND, $kind );
			}
		}

		if ( isset( $_POST['bftd_started_on'] ) ) {
			$began = sanitize_text_field( wp_unslash( $_POST['bftd_started_on'] ) );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $began ) ) {
				update_user_meta( $user_id, BFTD_Pay::META_STARTED, $began );
			} elseif ( '' === $began ) {
				delete_user_meta( $user_id, BFTD_Pay::META_STARTED );
			}
		}

		foreach ( array( 'bftd_rate_session' => BFTD_Pay::META_SESSION, 'bftd_rate_diagnostic' => BFTD_Pay::META_DIAGNOSIS ) as $field => $meta ) {
			if ( ! isset( $_POST[ $field ] ) ) continue;
			$raw = trim( (string) wp_unslash( $_POST[ $field ] ) );
			if ( '' === $raw ) { delete_user_meta( $user_id, $meta ); continue; }
			// Cents, as an integer. Holding "75.00" and converting it on
			// every read invites one of those reads to use a float, and one
			// place doing that is a cent a session for as long as nobody
			// checks.
			update_user_meta( $user_id, $meta, (string) BFTD_Pay::cents( $raw ) );
		}
	}
}
