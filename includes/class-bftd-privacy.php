<?php
/**
 * The portal's privacy policy (Karl, 1.220.0, after a parent asked how
 * student data and session recordings are protected).
 *
 * The policy is an ordinary WordPress page, set as the site's privacy policy
 * page, so it can be edited in the editor like any other page. The plugin
 * writes the first draft once and never overwrites it: the wording is the
 * practice's to keep. Nothing links to it until somebody publishes it, so a
 * draft nobody has read cannot reach a family.
 *
 * Written for PIPEDA. Recordings are kept 30 days, which is also what the
 * portal's recording window is moved to here.
 */

defined( 'ABSPATH' ) || exit;

class BFTD_Privacy {

	const OPTION_SETUP   = 'bftd_privacy_setup';
	const RECORDING_DAYS = 30;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_setup' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_filter( 'do_shortcode_tag', array( __CLASS__, 'portal_footer' ), 10, 2 );
	}

	/** The policy page, published or not, or 0. */
	public static function page_id() {
		$id = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		return ( $id && get_post( $id ) ) ? $id : 0;
	}

	/** The link to show families, only once the policy is published. */
	public static function url() {
		$id = self::page_id();
		if ( ! $id || 'publish' !== get_post_status( $id ) ) return '';
		return (string) get_permalink( $id );
	}

	/**
	 * Once, for an administrator: write the draft and move the recording
	 * window to 30 days. The flag is set first so a failure part way cannot
	 * repeat it on every screen.
	 */
	public static function maybe_setup() {
		if ( get_option( self::OPTION_SETUP ) ) return;
		if ( ! current_user_can( 'manage_options' ) ) return;
		update_option( self::OPTION_SETUP, BFTD_VERSION, false );
		self::recordings_to_thirty();
		self::create_draft();
	}

	/**
	 * The policy promises 30 days. Fourteen was the shipped default and no
	 * session has been recorded yet, so a 14 anywhere is the old default
	 * rather than somebody's choice. Any other figure is left alone.
	 */
	public static function recordings_to_thirty() {
		$saved = get_option( BFTD_Settings::OPTION_PORTAL, array() );
		if ( is_array( $saved ) && isset( $saved['recording_days'] ) && 14 === (int) $saved['recording_days'] ) {
			$saved['recording_days'] = self::RECORDING_DAYS;
			update_option( BFTD_Settings::OPTION_PORTAL, $saved );
		}
		$key = BFTD_Schema::meta_key( 'student', 'recording_days' );
		$ids = get_posts( array(
			'post_type'      => BFTD_CPT::STUDENT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => $key,
			'meta_value'     => '14',
		) );
		foreach ( $ids as $id ) update_post_meta( $id, $key, (string) self::RECORDING_DAYS );
		return count( $ids );
	}

	/**
	 * A draft page holding the policy. It becomes the site's privacy page
	 * unless a published one is already set, which is left as it is.
	 */
	public static function create_draft() {
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_title'   => 'Privacy policy',
			'post_name'    => 'privacy-policy',
			'post_content' => self::content(),
			'meta_input'   => array( '_bftd_written_by' => 'bftd_privacy' ),
		) );
		if ( ! $id || is_wp_error( $id ) ) return 0;
		$current = (int) get_option( 'wp_page_for_privacy_policy', 0 );
		if ( ! $current || 'publish' !== get_post_status( $current ) ) {
			update_option( 'wp_page_for_privacy_policy', (int) $id );
		}
		return (int) $id;
	}

	/** Reminder to administrators while the policy is still a draft. */
	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$id = self::page_id();
		if ( ! $id || 'publish' === get_post_status( $id ) ) return;
		if ( 'bftd_privacy' !== get_post_meta( $id, '_bftd_written_by', true ) ) return;
		printf(
			'<div class="notice notice-warning"><p><strong>The portal privacy policy is a draft.</strong> Check it says what you do, then publish it. Families see a link to it in the portal and on the sign-in screen once it is published. <a href="%s">Review the draft</a></p></div>',
			esc_url( get_edit_post_link( $id ) )
		);
	}

	/** A link under every portal screen, once the policy is published. */
	public static function portal_footer( $output, $tag ) {
		if ( BFTD_Dashboard::SHORTCODE !== $tag ) return $output;
		$url = self::url();
		if ( '' === $url ) return $output;
		return $output . '<p class="bf-privacy-link"><a href="' . esc_url( $url ) . '">Privacy policy</a></p>';
	}

	/* ------------------------------------------------------------------ */
	/* The wording                                                         */
	/* ------------------------------------------------------------------ */

	/** The policy as editor blocks. */
	public static function content() {
		$out = '';
		foreach ( self::sections() as $part ) {
			list( $kind, $body ) = $part;
			if ( 'h' === $kind ) {
				$out .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $body ) . "</h2>\n<!-- /wp:heading -->\n\n";
			} elseif ( 'ul' === $kind ) {
				$out .= "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
				foreach ( $body as $li ) $out .= "<!-- wp:list-item -->\n<li>" . wp_kses_post( $li ) . "</li>\n<!-- /wp:list-item -->";
				$out .= "</ul>\n<!-- /wp:list -->\n\n";
			} else {
				$out .= "<!-- wp:paragraph -->\n<p>" . wp_kses_post( $body ) . "</p>\n<!-- /wp:paragraph -->\n\n";
			}
		}
		return $out;
	}

	public static function sections() {
		$contact = '(778) 718-3661 or through <a href="https://bftutoring.com/contact/">bftutoring.com/contact</a>';
		return array(
			array( 'p', '<em>Last updated ' . date_i18n( 'F j, Y' ) . '</em>' ),
			array( 'p', 'Brilliant Futures Tutoring runs this portal so families can follow their child\'s reading progress. This policy explains what personal information we collect, why we collect it, who we share it with, how long we keep it, and how to reach us. We follow Canada\'s Personal Information Protection and Electronic Documents Act (PIPEDA).' ),

			array( 'h', 'Who is responsible' ),
			array( 'p', 'Our privacy officer is responsible for how we handle personal information. You can reach them at ' . $contact . '.' ),

			array( 'h', 'What we collect' ),
			array( 'ul', array(
				'<strong>About parents and guardians:</strong> your name, email address, phone number, the students you are linked to, and the messages you send us in the portal.',
				'<strong>About students:</strong> name, birth date, grade, school, reading assessment results, progress reports, session notes, activities, attendance and session schedule.',
				'<strong>Billing:</strong> sessions purchased, the rate per session, invoices and whether they are paid. Payments are handled by our billing provider. Card numbers are never stored in the portal.',
				'<strong>Session recordings:</strong> once we begin recording sessions. We will tell families before that starts.',
				'<strong>Account information:</strong> your sign-in details, a record of which staff member viewed or changed a student\'s information and when, and the cookies that keep you signed in. We do not use advertising or tracking cookies.',
			) ),

			array( 'h', 'Why we use it' ),
			array( 'ul', array(
				'To plan and deliver tutoring, and to assess and report on your child\'s progress.',
				'To schedule sessions and send reminders.',
				'To bill for sessions and keep financial records.',
				'To answer your questions and keep you informed.',
				'For quality control and to train our tutors, using session recordings.',
				'To keep the portal and your information secure.',
			) ),
			array( 'p', 'We do not sell or rent personal information, and we do not use it for advertising.' ),

			array( 'h', 'Consent' ),
			array( 'p', 'A parent or guardian gives consent on behalf of a child. We use personal information only for the purposes above, and we will ask before using it for anything new. You can withdraw your consent at any time, although we may then be unable to provide some services.' ),

			array( 'h', 'Session recordings' ),
			array( 'ul', array(
				'We will notify families before we begin recording sessions.',
				'Recordings are stored in our private Dropbox account and shared only through a link in your portal.',
				'Each recording is removed from the portal and deleted from storage 30 days after the session.',
				'Recordings are used for quality control, for training our tutors, and so you can watch your child\'s session. They are never published or shared outside our team.',
			) ),

			array( 'h', 'Who we share it with' ),
			array( 'p', 'We share personal information only with the services we use to run the practice, and only what each needs to do its job:' ),
			array( 'ul', array(
				'<strong>GoHighLevel:</strong> our client records, messages, emails and invoices.',
				'<strong>Dropbox:</strong> session recordings.',
				'<strong>GridPane and its hosting provider:</strong> the servers the portal and its database run on.',
				'<strong>Google Fonts:</strong> the lettering the portal uses. Loading it shares your device\'s internet address with Google.',
				'<strong>AI writing tools:</strong> our staff sometimes use them to help draft reports, session notes and messages. We share only what the task needs, and a staff member checks everything before it reaches you.',
			) ),
			array( 'p', 'When a school pays for a student\'s tutoring, we share that student\'s attendance and session counts with the school for billing.' ),
			array( 'p', 'We may also disclose information where the law requires it, or where someone\'s safety is at risk.' ),
			array( 'p', 'Some of these services store information outside Canada, mainly in the United States. Information stored there is subject to that country\'s laws and may be accessed by its courts and authorities.' ),

			array( 'h', 'How we protect it' ),
			array( 'ul', array(
				'Every staff member has their own sign-in, and their role decides what they can see. Tutors see only the students they work with.',
				'Families see only their own children.',
				'The portal is reached over an encrypted connection.',
				'We keep a record of who viewed or changed a student\'s information.',
			) ),

			array( 'h', 'How long we keep it' ),
			array( 'ul', array(
				'Session recordings: 30 days after the session.',
				'Student records and reports: while your child is tutored with us and for two years after their last session. Then we delete them or remove anything that identifies your child.',
				'Billing records: six years, as Canadian tax law requires.',
			) ),

			array( 'h', 'Your rights' ),
			array( 'p', 'You can ask to see the personal information we hold about you or your child, ask us to correct it, or withdraw your consent. Contact our privacy officer at ' . $contact . '. We will reply within 30 days.' ),
			array( 'p', 'If you are not satisfied with our answer, you can contact the Office of the Privacy Commissioner of Canada at <a href="https://www.priv.gc.ca">priv.gc.ca</a>, or the privacy commissioner in your province.' ),

			array( 'h', 'If something goes wrong' ),
			array( 'p', 'If a privacy breach creates a real risk of significant harm, we will tell the people affected and report it to the Privacy Commissioner of Canada, as the law requires.' ),

			array( 'h', 'Changes to this policy' ),
			array( 'p', 'We will post any changes on this page and tell families about significant ones.' ),
		);
	}
}
