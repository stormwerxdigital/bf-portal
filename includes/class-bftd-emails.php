<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every email the system can send: the default copy, a settings screen to
 * customise Subject, Preview text and Message per email, merge-tag
 * replacement, per-email send rules, a full edit history for each template,
 * and the branded HTML wrapper.
 *
 * Nothing else in the plugin builds an email itself. Everything calls
 * BFTD_Emails::send() with a template key, or send_raw() for the one path
 * where a tutor has edited the copy in the review popup before sending.
 *
 * The send rules are what stop this becoming noise. Each template carries a
 * mode — always, never, or only when a condition holds — plus a quiet-hours
 * window and a per-recipient daily cap. A withheld email is logged as
 * withheld rather than silently dropped, so "why did they not get it" always
 * has an answer.
 */
class BFTD_Emails {

	const OPTION_KEY   = 'bftd_email_templates';
	const OPTION_RULES = 'bftd_email_rules';
	const OPTION_BRAND = 'bftd_email_brand';
	const HISTORY_KEY  = 'bftd_email_template_history';
	const PAGE_SLUG    = 'bftd-emails';
	const HISTORY_MAX  = 30;

	/* ------------------------------------------------------------------ */
	/* Registry                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * One entry per customisable email. "audience" decides who it can go to,
	 * "tags" is the merge tags valid for it, and "conditions" lists the extra
	 * send rules that email supports beyond always and never.
	 */
	public static function templates() {
		$common = array( 'first_name', 'student_name', 'tutor_name', 'dashboard_url', 'site_name' );

		return apply_filters( 'bftd_email_templates', array(

			/* --- family, automatic system events ------------------------ */
			'client_welcome' => array(
				'title'    => 'Family: welcome to the portal',
				'when'     => 'Sent once, when a caregiver is first linked to a student.',
				'audience' => 'client',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'set_password_url' ) ),
			),
			'client_report_published' => array(
				'title'    => 'Family: a report is ready',
				'when'     => 'Sent when a Reading Diagnostic or Progress Report is published to the family for the first time.',
				'audience' => 'client',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'report_name', 'report_type' ) ),
				'conditions' => array( 'only_if_never_seen' ),
			),
			'client_lesson_recorded' => array(
				'title'    => 'Family: session notes added',
				'when'     => 'Sent after a session record is published.',
				'audience' => 'client',
				'tags'     => array_merge( $common, array( 'session_number', 'session_date', 'session_summary' ) ),
				'conditions' => array( 'digest_daily', 'only_if_recording' ),
			),
			'client_recording_expiring' => array(
				'title'    => 'Family: session recording expires soon',
				'when'     => 'Sent two days before a session recording is removed.',
				'audience' => 'client',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'session_date', 'expires_on' ) ),
			),
			'client_new_message' => array(
				'title'    => 'Family: a reply from us',
				'when'     => 'Sent when a tutor replies in a conversation the family started, or starts one.',
				'audience' => 'client',
				'tags'     => array_merge( $common, array( 'section_label', 'message_excerpt' ) ),
				'conditions' => array( 'only_if_unread', 'digest_daily' ),
			),
			'client_items_updated' => array(
				'title'    => 'Family: something needs your attention',
				'when'     => 'Optional. Sent when you approve a Priority items or For review change.',
				'audience' => 'client',
				'tags'     => array_merge( $common, array( 'item_summary', 'item_count' ) ),
			),
			'client_lesson_reminder' => array(
				'title'    => 'Family: session reminder',
				'when'     => 'Sent ahead of a scheduled session.',
				'audience' => 'client',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'lesson_when', 'join_url' ) ),
				'conditions' => array( 'respect_quiet_hours' ),
			),
			'client_missed_lesson' => array(
				'title'    => 'Family: a session was missed',
				'when'     => 'Sent when a session is marked missed, explaining whether it counts against the allowance.',
				'audience' => 'client',
				'tags'     => array_merge( $common, array( 'session_date', 'counts_line' ) ),
			),
			'client_update_roundup' => array(
				'title'    => 'Family: a roundup of what is new',
				'when'     => 'Optional. Sent when you bundle several pending changes into one email.',
				'audience' => 'client',
				'tags'     => array_merge( $common, array( 'changes_summary' ) ),
			),

			/* --- staff --------------------------------------------------- */
			'staff_new_message' => array(
				'title'    => 'Tutor: a family has written',
				'when'     => 'Sent to the assigned tutor when a family posts in any conversation.',
				'audience' => 'staff',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'from_name', 'section_label', 'message_excerpt' ) ),
				'conditions' => array( 'digest_daily', 'respect_quiet_hours' ),
			),
			'staff_item_completed' => array(
				'title'    => 'Tutor: the family finished their list',
				'when'     => 'Sent when a family checks off the last outstanding item.',
				'audience' => 'staff',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'list_name' ) ),
			),
			'staff_assigned' => array(
				'title'    => 'Tutor: you have been assigned a student',
				'when'     => 'Sent when a tutor is added to a student.',
				'audience' => 'staff',
				'auto'     => true,
				'tags'     => $common,
			),
			'staff_report_due' => array(
				'title'    => 'Tutor: a reassessment is due',
				'when'     => 'Sent when a student reaches Activity 70 or Activity 140 with no matching diagnostic recorded.',
				'audience' => 'staff',
				'auto'     => true,
				'tags'     => array_merge( $common, array( 'milestone' ) ),
			),
			'admin_daily_digest' => array(
				'title'    => 'Admin: daily summary',
				'when'     => 'One message a day covering everything that happened across all students.',
				'audience' => 'admin',
				'tags'     => array( 'site_name', 'dashboard_url', 'changes_summary' ),
				'conditions' => array( 'digest_daily' ),
			),
		) );
	}

	public static function defaults() {
		$sig = "\n\nLaurel\nBrilliant Futures Tutoring\nbftutoring.com";

		return apply_filters( 'bftd_email_template_defaults', array(
			'client_welcome' => array(
				'subject' => 'Your Brilliant Futures portal is ready',
				'preview' => 'Everything about {{student_name}} in one place.',
				'message' => "Hi {{first_name}},\n\nYour portal is set up. It is where you will find {{student_name}}'s reports, lesson notes, recordings, and anything we ask you to look at between lessons.\n\nSet your password and take a look:\n{{set_password_url}}\n\nAnything you want to ask, you can ask it right on the section it is about, and it comes straight to me." . $sig,
			),
			'client_report_published' => array(
				'subject' => "{{student_name}}'s {{report_type}} is ready",
				'preview' => 'It is in your portal now, with the numbers explained.',
				'message' => "Hi {{first_name}},\n\n{{student_name}}'s {{report_type}} is ready to read. Every number in it has a plain explanation beside it, and you can ask me about any section right there on the page.\n\n{{dashboard_url}}" . $sig,
			),
			'client_lesson_recorded' => array(
				'subject' => 'Lesson notes from {{session_date}}',
				'preview' => "Here is what {{student_name}} worked on.",
				'message' => "Hi {{first_name}},\n\nLesson {{session_number}} notes are up:\n\n{{session_summary}}\n\n{{dashboard_url}}" . $sig,
			),
			'client_recording_expiring' => array(
				'subject' => 'The {{session_date}} lesson recording comes down soon',
				'preview' => 'It is available until {{expires_on}}.',
				'message' => "Hi {{first_name}},\n\nThe recording of {{student_name}}'s lesson on {{session_date}} is available until {{expires_on}}, then it is removed. If you wanted to watch a bit of it, now is the time.\n\n{{dashboard_url}}" . $sig,
			),
			'client_new_message' => array(
				'subject' => 'A reply about {{section_label}}',
				'preview' => '{{message_excerpt}}',
				'message' => "Hi {{first_name}},\n\nI have replied to you about {{section_label}}:\n\n\"{{message_excerpt}}\"\n\n{{dashboard_url}}" . $sig,
			),
			'client_items_updated' => array(
				'subject' => 'A couple of things for you, on {{student_name}}',
				'preview' => '{{item_count}} waiting in your portal.',
				'message' => "Hi {{first_name}},\n\nThere are {{item_count}} things waiting for you in the portal:\n\n{{item_summary}}\n\n{{dashboard_url}}" . $sig,
			),
			'client_lesson_reminder' => array(
				'subject' => "{{student_name}}'s lesson is {{lesson_when}}",
				'preview' => 'Joining link inside.',
				'message' => "Hi {{first_name}},\n\nA reminder that {{student_name}} has a lesson {{lesson_when}}.\n\nJoin here when it is time:\n{{join_url}}" . $sig,
			),
			'client_missed_lesson' => array(
				'subject' => 'About the lesson on {{session_date}}',
				'preview' => '{{counts_line}}',
				'message' => "Hi {{first_name}},\n\nWe missed {{student_name}}'s lesson on {{session_date}}. {{counts_line}}\n\nYou can see the full record here:\n{{dashboard_url}}" . $sig,
			),
			'client_update_roundup' => array(
				'subject' => "What is new for {{student_name}}",
				'preview' => 'A short roundup of everything added since you last looked.',
				'message' => "Hi {{first_name}},\n\nHere is what is new:\n\n{{changes_summary}}\n\n{{dashboard_url}}" . $sig,
			),
			'staff_new_message' => array(
				'subject' => '{{from_name}} wrote about {{section_label}}',
				'preview' => '{{message_excerpt}}',
				'message' => "{{from_name}} posted on {{student_name}}, under {{section_label}}:\n\n\"{{message_excerpt}}\"\n\nReply here:\n{{dashboard_url}}\n\nBrilliant Futures dashboard",
			),
			'staff_item_completed' => array(
				'subject' => '{{student_name}}: {{list_name}} is all done',
				'preview' => 'Nothing outstanding on that list.',
				'message' => "The family has checked off the last item on {{list_name}} for {{student_name}}.\n\n{{dashboard_url}}\n\nBrilliant Futures dashboard",
			),
			'staff_assigned' => array(
				'subject' => 'You have been assigned {{student_name}}',
				'preview' => 'Their record is ready for you.',
				'message' => "Hi {{tutor_name}},\n\nYou have been assigned {{student_name}}.\n\n{{dashboard_url}}\n\nBrilliant Futures dashboard",
			),
			'staff_report_due' => array(
				'subject' => '{{student_name}} has reached {{milestone}}',
				'preview' => 'A reassessment is due.',
				'message' => "{{student_name}} has reached {{milestone}} and there is no diagnostic recorded for it yet.\n\n{{dashboard_url}}\n\nBrilliant Futures dashboard",
			),
			'admin_daily_digest' => array(
				'subject' => 'Brilliant Futures: yesterday in one message',
				'preview' => 'Everything that happened across all students.',
				'message' => "Here is everything that happened yesterday:\n\n{{changes_summary}}\n\n{{dashboard_url}}\n\nBrilliant Futures dashboard",
			),
		) );
	}

	/* ------------------------------------------------------------------ */
	/* Send rules                                                          */
	/* ------------------------------------------------------------------ */

	public static function condition_labels() {
		return array(
			'only_if_unread'     => 'Only if they have not already read it in the portal',
			'only_if_never_seen' => 'Only the first time, never on a later edit',
			'only_if_recording'  => 'Only when the session has a recording attached',
			'digest_daily'       => 'Hold and send once a day rather than one at a time',
			'respect_quiet_hours'=> 'Never send inside quiet hours',
		);
	}

	public static function default_rules() {
		$out = array();
		foreach ( self::templates() as $key => $cfg ) {
			$out[ $key ] = array(
				'mode'       => empty( $cfg['auto'] ) ? 'review' : 'always',
				'conditions' => isset( $cfg['conditions'] ) ? array_slice( (array) $cfg['conditions'], 0, 1 ) : array(),
			);
		}
		return $out;
	}

	public static function rules() {
		$saved = get_option( self::OPTION_RULES, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = self::default_rules();
		foreach ( $out as $key => $def ) {
			if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) {
				$out[ $key ] = array(
					'mode'       => isset( $saved[ $key ]['mode'] ) ? $saved[ $key ]['mode'] : $def['mode'],
					'conditions' => isset( $saved[ $key ]['conditions'] ) ? (array) $saved[ $key ]['conditions'] : $def['conditions'],
				);
			}
		}
		return $out;
	}

	public static function rule( $key ) {
		$rules = self::rules();
		return isset( $rules[ $key ] ) ? $rules[ $key ] : array( 'mode' => 'review', 'conditions' => array() );
	}

	public static function settings() {
		$s = get_option( self::OPTION_BRAND, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), array(
			'from_name'    => 'Brilliant Futures Tutoring',
			'from_email'   => '',
			'reply_to'     => '',
			'quiet_start'  => '20:00',
			'quiet_end'    => '07:30',
			'daily_cap'    => 3,
			'footer'       => 'Brilliant Futures Tutoring &middot; Prince George, BC &middot; (778) 718-3661',
			'paused'       => 0,
		) );
	}

	/**
	 * Decides whether one email may go out right now.
	 *
	 * Returns true to send, or a string reason to withhold. Callers pass the
	 * context they have; a condition whose context is missing is treated as
	 * not met, so an unknown state never becomes an accidental send.
	 */
	public static function may_send( $key, $context = array() ) {
		$settings = self::settings();
		if ( ! empty( $settings['paused'] ) ) return 'All outgoing email is paused in settings.';

		$rule = self::rule( $key );
		if ( 'never' === $rule['mode'] ) return 'This email is switched off.';
		if ( 'review' === $rule['mode'] && empty( $context['reviewed'] ) ) {
			return 'This email only goes out when a tutor sends it from the review bar.';
		}

		foreach ( (array) $rule['conditions'] as $cond ) {
			switch ( $cond ) {
				case 'only_if_unread':
					if ( ! empty( $context['already_read'] ) ) return 'They had already read it in the portal.';
					break;
				case 'only_if_never_seen':
					if ( ! empty( $context['seen_before'] ) ) return 'They have already been told about this once.';
					break;
				case 'only_if_recording':
					if ( empty( $context['has_recording'] ) ) return 'There is no recording on that session.';
					break;
				case 'digest_daily':
					if ( empty( $context['in_digest'] ) ) return 'Held for the daily summary.';
					break;
				case 'respect_quiet_hours':
					if ( self::in_quiet_hours() ) return 'Inside quiet hours.';
					break;
			}
		}

		if ( ! empty( $context['to'] ) && self::over_daily_cap( $context['to'] ) ) {
			return 'That address has already had the most messages allowed in a day.';
		}

		return true;
	}

	public static function in_quiet_hours() {
		$s     = self::settings();
		$start = $s['quiet_start'];
		$end   = $s['quiet_end'];
		if ( ! $start || ! $end ) return false;
		$now = BFTD_Time::clock();
		if ( $start <= $end ) return ( $now >= $start && $now < $end );
		return ( $now >= $start || $now < $end ); // window crosses midnight
	}

	private static function cap_key( $email ) {
		return 'bftd_sent_' . md5( strtolower( $email ) . '|' . BFTD_Time::today() );
	}

	public static function over_daily_cap( $email ) {
		$cap = (int) self::settings()['daily_cap'];
		if ( $cap <= 0 ) return false;
		return (int) get_transient( self::cap_key( $email ) ) >= $cap;
	}

	private static function bump_daily_cap( $email ) {
		$k = self::cap_key( $email );
		set_transient( $k, (int) get_transient( $k ) + 1, DAY_IN_SECONDS );
	}

	/* ------------------------------------------------------------------ */
	/* Sending                                                             */
	/* ------------------------------------------------------------------ */

	public static function get_template( $key ) {
		$defaults = self::defaults();
		$default  = isset( $defaults[ $key ] ) ? $defaults[ $key ] : array( 'subject' => '', 'preview' => '', 'message' => '' );

		$saved = get_option( self::OPTION_KEY, array() );
		$saved = ( is_array( $saved ) && isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) ? $saved[ $key ] : array();

		$out = array();
		foreach ( array( 'subject', 'preview', 'message' ) as $f ) {
			$out[ $f ] = ( isset( $saved[ $f ] ) && '' !== trim( $saved[ $f ] ) ) ? $saved[ $f ] : $default[ $f ];
		}
		return $out;
	}

	public static function apply_tags( $text, $tags ) {
		$search = $replace = array();
		foreach ( $tags as $k => $v ) {
			$search[]  = '{{' . $k . '}}';
			$replace[] = (string) $v;
		}
		return str_replace( $search, $replace, $text );
	}

	/**
	 * Send one templated email. $context feeds may_send(); pass
	 * 'reviewed' => true for a send a tutor has explicitly clicked.
	 */
	public static function send( $key, $to_email, $tags = array(), $context = array() ) {
		if ( ! $to_email || ! is_email( $to_email ) ) return false;

		$context['to'] = $to_email;
		$allowed = self::may_send( $key, $context );
		if ( true !== $allowed ) {
			BFTD_Audit::log( 'email_suppressed', array(
				'post_id'    => isset( $context['post_id'] ) ? $context['post_id'] : 0,
				'student_id' => isset( $context['student_id'] ) ? $context['student_id'] : 0,
				'summary'    => self::title( $key ) . ' was not sent. ' . $allowed,
				'recipients' => array( $to_email ),
			) );
			return false;
		}

		$tags = array_merge( array( 'site_name' => 'Brilliant Futures Tutoring' ), $tags );
		$tpl  = self::get_template( $key );

		$subject = self::apply_tags( $tpl['subject'], $tags );
		$preview = self::apply_tags( $tpl['preview'], $tags );
		$message = self::apply_tags( $tpl['message'], $tags );

		$sent = self::deliver( $to_email, $subject, self::wrap_html( $preview, $message ) );

		BFTD_Audit::log( $sent ? 'email_sent' : 'email_failed', array(
			'post_id'    => isset( $context['post_id'] ) ? $context['post_id'] : 0,
			'student_id' => isset( $context['student_id'] ) ? $context['student_id'] : 0,
			'summary'    => self::title( $key ) . ': "' . $subject . '"',
			'recipients' => array( $to_email ),
		) );

		if ( $sent ) self::bump_daily_cap( $to_email );
		return $sent;
	}

	/**
	 * Send copy a tutor has edited in the review popup, bypassing the saved
	 * template. Still logged, still subject to the pause switch and cap.
	 */
	public static function send_raw( $to_email, $subject, $preview, $message, $context = array() ) {
		if ( ! $to_email || ! is_email( $to_email ) ) return false;
		if ( ! empty( self::settings()['paused'] ) ) return false;

		$sent = self::deliver( $to_email, $subject, self::wrap_html( $preview, $message ) );

		BFTD_Audit::log( $sent ? 'email_sent' : 'email_failed', array(
			'post_id'    => isset( $context['post_id'] ) ? $context['post_id'] : 0,
			'student_id' => isset( $context['student_id'] ) ? $context['student_id'] : 0,
			'summary'    => 'Sent by hand from the review bar: "' . $subject . '"',
			'recipients' => array( $to_email ),
		) );

		if ( $sent ) self::bump_daily_cap( $to_email );
		return $sent;
	}

	public static function html_content_type() { return 'text/html'; }

	/**
	 * Not every mail relay honours the wp_mail_content_type filter — some read
	 * the raw headers array instead and fall back to plain text. Setting both
	 * covers either integration.
	 */
	private static function deliver( $to, $subject, $html ) {
		$s       = self::settings();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( $s['from_email'] && is_email( $s['from_email'] ) ) {
			$headers[] = 'From: ' . $s['from_name'] . ' <' . $s['from_email'] . '>';
		}
		if ( $s['reply_to'] && is_email( $s['reply_to'] ) ) {
			$headers[] = 'Reply-To: ' . $s['reply_to'];
		}

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		$sent = wp_mail( $to, $subject, $html, $headers );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		return $sent;
	}

	public static function button_token( $url, $label ) {
		return '%%BFTD_BTN:' . base64_encode( $label ) . ':' . base64_encode( $url ) . '%%';
	}

	private static function button_markup( $url, $label ) {
		return '<a href="' . esc_url( $url ) . '" style="display:inline-block;background:#70567F;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-weight:700;font-size:14px;padding:13px 26px;border-radius:3px;">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Turn bare URLs into branded buttons. URLs are swapped for tokens before
	 * wpautop and wp_kses_post run and swapped back afterwards, rather than
	 * trusting kses to preserve a style attribute on an anchor it is filtering.
	 * Custom-labelled buttons are base64 encoded so an already percent-encoded
	 * reset link cannot break the pattern or get double-encoded.
	 */
	private static function linkify( $message ) {
		$links = array();

		$message = preg_replace_callback( '#%%BFTD_BTN:([A-Za-z0-9+/=]+):([A-Za-z0-9+/=]+)%%#', function ( $m ) use ( &$links ) {
			$token = '%%BFTD_TOKEN_' . count( $links ) . '%%';
			$links[ $token ] = self::button_markup( base64_decode( $m[2] ), base64_decode( $m[1] ) );
			return $token;
		}, $message );

		$message = preg_replace_callback( '#https?://[^\s<>"]+#i', function ( $m ) use ( &$links ) {
			$token = '%%BFTD_TOKEN_' . count( $links ) . '%%';
			$links[ $token ] = self::button_markup( $m[0], 'Open the portal' );
			return $token;
		}, $message );

		$html = wp_kses_post( wpautop( $message ) );
		foreach ( $links as $token => $markup ) {
			$html = str_replace( $token, $markup, $html );
		}
		return $html;
	}

	public static function wrap_html( $preview, $message ) {
		$body    = self::linkify( $message );
		$pre     = esc_html( $preview );
		$footer  = wp_kses_post( self::settings()['footer'] );
		return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:0;background:#F9F4F2;font-family:Arial,Helvetica,sans-serif;">
	<span style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F9F4F2;">{$pre}</span>
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F9F4F2;padding:32px 16px;">
		<tr><td align="center">
			<table role="presentation" width="100%" style="max-width:540px;background:#ffffff;border:1px solid #E2D9E6;border-radius:8px;overflow:hidden;">
				<tr><td style="background:#70567F;padding:22px 28px;">
					<span style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-weight:700;letter-spacing:2px;font-size:14px;">BRILLIANT FUTURES</span>
					<span style="display:block;color:#D9CDE1;font-size:10px;letter-spacing:4px;margin-top:3px;">TUTORING</span>
				</td></tr>
				<tr><td style="padding:28px;color:#241E2A;font-size:15px;line-height:1.65;">
					{$body}
				</td></tr>
				<tr><td style="padding:0 28px 26px;">
					<p style="margin:0;padding-top:16px;border-top:1px solid #E2D9E6;color:#5F5769;font-size:12px;line-height:1.6;">{$footer}</p>
				</td></tr>
			</table>
		</td></tr>
	</table>
</body>
</html>
HTML;
	}

	public static function title( $key ) {
		$t = self::templates();
		return isset( $t[ $key ] ) ? $t[ $key ]['title'] : $key;
	}

	/* ------------------------------------------------------------------ */
	/* Template edit history                                               */
	/* ------------------------------------------------------------------ */

	public static function history( $key = '' ) {
		$all = get_option( self::HISTORY_KEY, array() );
		$all = is_array( $all ) ? $all : array();
		if ( ! $key ) return $all;
		return isset( $all[ $key ] ) ? $all[ $key ] : array();
	}

	private static function record_history( $key, $before, $after ) {
		$changed = BFTD_Audit::diff( $before, $after, array(
			'subject' => 'Subject', 'preview' => 'Preview text', 'message' => 'Message',
		) );
		if ( ! $changed ) return false;

		$user = wp_get_current_user();
		$all  = self::history();
		if ( ! isset( $all[ $key ] ) ) $all[ $key ] = array();

		array_unshift( $all[ $key ], array(
			'at'      => BFTD_Time::mysql(),
			'by'      => $user && $user->exists() ? $user->display_name : 'System',
			'changes' => $changed,
			'snapshot'=> $before,
		) );
		$all[ $key ] = array_slice( $all[ $key ], 0, self::HISTORY_MAX );
		update_option( self::HISTORY_KEY, $all, false );

		BFTD_Audit::log( 'template_updated', array(
			'summary' => self::title( $key ) . ' was edited.',
			'changes' => $changed,
		) );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	public static function menu() {
		add_submenu_page(
			BFTD_Admin::MENU_SLUG,
			'Emails',
			'Emails',
			BFTD_Roles::ADMIN_CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_post() {
		if ( empty( $_POST['bftd_emails_nonce'] ) ) return;
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bftd_emails_nonce'] ) ), 'bftd_save_emails' ) ) return;
		if ( ! BFTD_Roles::can_manage() ) return;

		$known = self::templates();

		/* restore one template to its default */
		if ( ! empty( $_POST['bftd_restore'] ) ) {
			$key = sanitize_key( wp_unslash( $_POST['bftd_restore'] ) );
			if ( isset( $known[ $key ] ) ) {
				$saved  = get_option( self::OPTION_KEY, array() );
				$before = self::get_template( $key );
				unset( $saved[ $key ] );
				update_option( self::OPTION_KEY, $saved, false );
				BFTD_Audit::log( 'template_restored', array(
					'summary' => self::title( $key ) . ' was restored to its default wording.',
					'changes' => BFTD_Audit::diff( $before, self::get_template( $key ), array(
						'subject' => 'Subject', 'preview' => 'Preview text', 'message' => 'Message',
					) ),
				) );
				add_settings_error( 'bftd_emails', 'restored', self::title( $key ) . ' is back to its default wording.', 'updated' );
			}
			return;
		}

		/* templates */
		$saved   = get_option( self::OPTION_KEY, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$posted  = isset( $_POST['bftd_tpl'] ) ? (array) wp_unslash( $_POST['bftd_tpl'] ) : array();
		$edited  = 0;

		foreach ( $posted as $key => $vals ) {
			$key = sanitize_key( $key );
			if ( ! isset( $known[ $key ] ) ) continue;

			$before = self::get_template( $key );
			$after  = array(
				'subject' => sanitize_text_field( isset( $vals['subject'] ) ? $vals['subject'] : '' ),
				'preview' => sanitize_text_field( isset( $vals['preview'] ) ? $vals['preview'] : '' ),
				'message' => sanitize_textarea_field( isset( $vals['message'] ) ? $vals['message'] : '' ),
			);
			$saved[ $key ] = $after;
			if ( self::record_history( $key, $before, $after ) ) $edited++;
		}
		update_option( self::OPTION_KEY, $saved, false );

		/* rules */
		$rules_before = self::rules();
		$rules        = array();
		$posted_rules = isset( $_POST['bftd_rule'] ) ? (array) wp_unslash( $_POST['bftd_rule'] ) : array();
		foreach ( $known as $key => $cfg ) {
			$mode  = isset( $posted_rules[ $key ]['mode'] ) ? sanitize_key( $posted_rules[ $key ]['mode'] ) : 'review';
			if ( ! in_array( $mode, array( 'always', 'review', 'never' ), true ) ) $mode = 'review';
			$conds = isset( $posted_rules[ $key ]['conditions'] ) ? array_map( 'sanitize_key', (array) $posted_rules[ $key ]['conditions'] ) : array();
			$conds = array_values( array_intersect( $conds, isset( $cfg['conditions'] ) ? (array) $cfg['conditions'] : array() ) );
			$rules[ $key ] = array( 'mode' => $mode, 'conditions' => $conds );
		}
		update_option( self::OPTION_RULES, $rules, false );

		foreach ( $rules as $key => $r ) {
			$was = isset( $rules_before[ $key ] ) ? $rules_before[ $key ] : array();
			if ( maybe_serialize( $was ) === maybe_serialize( $r ) ) continue;
			BFTD_Audit::log( 'rule_updated', array(
				'summary' => self::title( $key ) . ' send rule changed.',
				'changes' => BFTD_Audit::diff(
					array( 'mode' => isset( $was['mode'] ) ? $was['mode'] : '', 'conditions' => isset( $was['conditions'] ) ? $was['conditions'] : array() ),
					array( 'mode' => $r['mode'], 'conditions' => $r['conditions'] ),
					array( 'mode' => 'When it sends', 'conditions' => 'Conditions' )
				),
			) );
		}

		/* global settings */
		if ( isset( $_POST['bftd_settings'] ) ) {
			$in     = (array) wp_unslash( $_POST['bftd_settings'] );
			$before = self::settings();
			$after  = array(
				'from_name'   => sanitize_text_field( isset( $in['from_name'] ) ? $in['from_name'] : '' ),
				'from_email'  => sanitize_email( isset( $in['from_email'] ) ? $in['from_email'] : '' ),
				'reply_to'    => sanitize_email( isset( $in['reply_to'] ) ? $in['reply_to'] : '' ),
				'quiet_start' => sanitize_text_field( isset( $in['quiet_start'] ) ? $in['quiet_start'] : '' ),
				'quiet_end'   => sanitize_text_field( isset( $in['quiet_end'] ) ? $in['quiet_end'] : '' ),
				'daily_cap'   => max( 0, (int) ( isset( $in['daily_cap'] ) ? $in['daily_cap'] : 0 ) ),
				'footer'      => wp_kses_post( isset( $in['footer'] ) ? $in['footer'] : '' ),
				'paused'      => empty( $in['paused'] ) ? 0 : 1,
			);
			update_option( self::OPTION_BRAND, $after, false );

			$changed = BFTD_Audit::diff( $before, $after, array(
				'from_name' => 'From name', 'from_email' => 'From address', 'reply_to' => 'Reply to',
				'quiet_start' => 'Quiet hours start', 'quiet_end' => 'Quiet hours end',
				'daily_cap' => 'Daily cap per address', 'footer' => 'Footer', 'paused' => 'All email paused',
			) );
			if ( $changed ) {
				BFTD_Audit::log( 'settings_updated', array( 'summary' => 'Email settings changed.', 'changes' => $changed ) );
			}
		}

		add_settings_error( 'bftd_emails', 'saved', $edited ? 'Saved. ' . $edited . ' template(s) changed, and the change is in the activity log.' : 'Saved.', 'updated' );
	}

	public static function render_page() {
		if ( ! BFTD_Roles::can_manage() ) return;

		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'templates';
		$base  = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		$rules = self::rules();
		$s     = self::settings();
		$conds = self::condition_labels();
		?>
		<div class="wrap bftd-wrap">
			<h1>Emails</h1>
			<p class="bftd-lede">The wording, and the rules for when each one is allowed to go out. Every edit is kept, and every send, and every one withheld by a rule, is in the Activity Log.</p>
			<?php settings_errors( 'bftd_emails' ); ?>

			<h2 class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'templates' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base ); ?>">Wording</a>
				<a class="nav-tab <?php echo 'rules' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'rules', $base ) ); ?>">When they send</a>
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base ) ); ?>">Settings</a>
				<a class="nav-tab <?php echo 'history' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', 'history', $base ) ); ?>">Edit history</a>
			</h2>

			<?php if ( 'history' === $tab ) : self::render_history_tab(); return; ?>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'bftd_save_emails', 'bftd_emails_nonce' ); ?>

				<?php if ( 'settings' === $tab ) : ?>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="bftd_from_name">From name</label></th>
							<td><input class="regular-text" id="bftd_from_name" type="text" name="bftd_settings[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>"></td></tr>
						<tr><th scope="row"><label for="bftd_from_email">From address</label></th>
							<td><input class="regular-text" id="bftd_from_email" type="email" name="bftd_settings[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>">
							<p class="description">Leave blank to use whatever WordPress is already configured to send as.</p></td></tr>
						<tr><th scope="row"><label for="bftd_reply_to">Reply to</label></th>
							<td><input class="regular-text" id="bftd_reply_to" type="email" name="bftd_settings[reply_to]" value="<?php echo esc_attr( $s['reply_to'] ); ?>">
							<p class="description">Where a family's reply lands. Parents do reply to these.</p></td></tr>
						<tr><th scope="row">Quiet hours</th>
							<td>
								<input type="time" name="bftd_settings[quiet_start]" value="<?php echo esc_attr( $s['quiet_start'] ); ?>"> to
								<input type="time" name="bftd_settings[quiet_end]" value="<?php echo esc_attr( $s['quiet_end'] ); ?>">
								<p class="description">Emails whose rule respects quiet hours are held rather than sent in this window.</p>
							</td></tr>
						<tr><th scope="row"><label for="bftd_cap">Daily cap per address</label></th>
							<td><input id="bftd_cap" type="number" min="0" name="bftd_settings[daily_cap]" value="<?php echo (int) $s['daily_cap']; ?>" class="small-text">
							<p class="description">The most messages any one person can receive in a day. 0 removes the cap.</p></td></tr>
						<tr><th scope="row"><label for="bftd_footer">Footer</label></th>
							<td><input class="large-text" id="bftd_footer" type="text" name="bftd_settings[footer]" value="<?php echo esc_attr( $s['footer'] ); ?>"></td></tr>
						<tr><th scope="row">Pause everything</th>
							<td><label><input type="checkbox" name="bftd_settings[paused]" value="1" <?php checked( $s['paused'], 1 ); ?>> Hold all outgoing email</label>
							<p class="description">Useful while setting up. Withheld messages are logged, so you can see what would have gone.</p></td></tr>
					</table>
					<p><button class="button button-primary">Save settings</button></p>

				<?php elseif ( 'rules' === $tab ) : ?>
					<table class="widefat striped bftd-rules">
						<thead><tr><th style="width:280px;">Email</th><th style="width:280px;">When it sends</th><th>Only when</th></tr></thead>
						<tbody>
						<?php foreach ( self::templates() as $key => $cfg ) : $r = $rules[ $key ]; ?>
							<tr>
								<td><strong><?php echo esc_html( $cfg['title'] ); ?></strong><br><span class="description"><?php echo esc_html( $cfg['when'] ); ?></span></td>
								<td>
									<?php foreach ( array( 'always' => 'Send automatically', 'review' => 'Hold for a tutor to review and send', 'never' => 'Never send' ) as $mode => $label ) : ?>
										<label class="bftd-radio"><input type="radio" name="bftd_rule[<?php echo esc_attr( $key ); ?>][mode]" value="<?php echo esc_attr( $mode ); ?>" <?php checked( $r['mode'], $mode ); ?>> <?php echo esc_html( $label ); ?></label>
									<?php endforeach; ?>
								</td>
								<td>
									<?php if ( empty( $cfg['conditions'] ) ) : ?>
										<span class="description">No extra conditions for this one.</span>
									<?php else : foreach ( (array) $cfg['conditions'] as $c ) : ?>
										<label class="bftd-check"><input type="checkbox" name="bftd_rule[<?php echo esc_attr( $key ); ?>][conditions][]" value="<?php echo esc_attr( $c ); ?>" <?php checked( in_array( $c, (array) $r['conditions'], true ) ); ?>> <?php echo esc_html( isset( $conds[ $c ] ) ? $conds[ $c ] : $c ); ?></label>
									<?php endforeach; endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><button class="button button-primary">Save send rules</button></p>

				<?php else : ?>
					<?php foreach ( self::templates() as $key => $cfg ) : $tpl = self::get_template( $key ); $hist = self::history( $key ); ?>
						<div class="bftd-tpl">
							<h2><?php echo esc_html( $cfg['title'] ); ?></h2>
							<p class="description"><?php echo esc_html( $cfg['when'] ); ?></p>
							<table class="form-table" role="presentation">
								<tr><th scope="row"><label for="s_<?php echo esc_attr( $key ); ?>">Subject</label></th>
									<td><input class="large-text" id="s_<?php echo esc_attr( $key ); ?>" type="text" name="bftd_tpl[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $tpl['subject'] ); ?>"></td></tr>
								<tr><th scope="row"><label for="p_<?php echo esc_attr( $key ); ?>">Preview text</label></th>
									<td><input class="large-text" id="p_<?php echo esc_attr( $key ); ?>" type="text" name="bftd_tpl[<?php echo esc_attr( $key ); ?>][preview]" value="<?php echo esc_attr( $tpl['preview'] ); ?>">
									<p class="description">The snippet an inbox shows beside the subject.</p></td></tr>
								<tr><th scope="row"><label for="m_<?php echo esc_attr( $key ); ?>">Message</label></th>
									<td><textarea class="large-text" rows="8" id="m_<?php echo esc_attr( $key ); ?>" name="bftd_tpl[<?php echo esc_attr( $key ); ?>][message]"><?php echo esc_textarea( $tpl['message'] ); ?></textarea>
									<p class="description">Merge tags: <?php foreach ( $cfg['tags'] as $t ) : ?><code>{{<?php echo esc_html( $t ); ?>}}</code> <?php endforeach; ?></p>
									<?php if ( $hist ) : ?>
										<p class="description">Last edited <?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $hist[0]['at'] ) ); ?> by <?php echo esc_html( $hist[0]['by'] ); ?>.</p>
									<?php endif; ?>
									</td></tr>
							</table>
							<p><button type="submit" name="bftd_restore" value="<?php echo esc_attr( $key ); ?>" class="button">Restore default wording</button></p>
						</div>
					<?php endforeach; ?>
					<p><button class="button button-primary">Save wording</button></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	private static function render_history_tab() {
		$all = self::history();
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( ! $all ) {
			echo '<p class="description">No template has been edited yet. Once one is, every version is kept here.</p></div>';
			return;
		}
		foreach ( $all as $key => $entries ) {
			if ( ! $entries ) continue;
			echo '<h2>' . esc_html( self::title( $key ) ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th style="width:180px;">When</th><th style="width:150px;">Who</th><th>What changed</th></tr></thead><tbody>';
			foreach ( $entries as $e ) {
				echo '<tr><td>' . esc_html( mysql2date( $fmt, $e['at'] ) ) . '</td><td>' . esc_html( $e['by'] ) . '</td><td>';
				foreach ( (array) $e['changes'] as $c ) {
					echo '<div class="bftd-hist"><b>' . esc_html( $c['label'] ) . '</b><span class="was">' . esc_html( $c['from'] ) . '</span><span class="arrow" aria-hidden="true">&rarr;</span><span class="now">' . esc_html( $c['to'] ) . '</span></div>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
