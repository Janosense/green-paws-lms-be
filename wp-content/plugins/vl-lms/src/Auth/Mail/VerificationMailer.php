<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Mail;

use VL\LMS\Support\Logger;
use WP_User;

/**
 * Sends the verification-link email via `wp_mail`.
 *
 * Content-type is scoped to a single send: the `wp_mail_content_type`
 * filter is attached, `wp_mail` is called, and the filter is removed in
 * a `finally` block — so other plugins that also use `wp_mail` in the
 * same request aren't affected by an accidentally-leaky `text/html`
 * header.
 *
 * The verification URL points the browser at the frontend's
 * `/verify-email?token=…` route (frontend base configured via the
 * `VL_LMS_APP_URL` PHP constant). The frontend then calls
 * `POST /wp-json/vl/v1/auth/verify-email` with the token in the body.
 *
 * Translation seam: subject and body both pass through the filters
 * `vl_lms_verification_email_subject` and `vl_lms_verification_email_body`.
 * Ukrainian copy will drop in via those filters during Phase 2.B without
 * requiring changes to this class.
 *
 * @author Tymofii Synianskyi
 */
class VerificationMailer {

	public function __construct(
		private readonly Logger $logger
	) {
	}

	/**
	 * Deliver the verification email to `$user`. Returns whatever
	 * `wp_mail` returns — `true` on successful enqueue, `false`
	 * otherwise. Callers typically do not surface this up; a silent
	 * failure is the right UX for a security-adjacent operation
	 * (see idempotent/generic response rules for verification endpoints).
	 */
	public function send( WP_User $user, string $plain_token ): bool {
		$verification_url = $this->build_verification_url( $plain_token );

		/**
		 * Filter the subject line of the verification email.
		 *
		 * @param string $subject Default subject.
		 * @param int    $user_id The recipient's user ID.
		 */
		$subject = (string) apply_filters(
			'vl_lms_verification_email_subject',
			__( 'Verify your email address', 'vl-lms' ),
			(int) $user->ID
		);

		/**
		 * Filter the HTML body of the verification email.
		 *
		 * @param string $body             Default HTML body.
		 * @param int    $user_id          The recipient's user ID.
		 * @param string $verification_url Absolute URL to the frontend verify page.
		 * @param string $plain_token      The plaintext token, also embedded in the URL.
		 */
		$body = (string) apply_filters(
			'vl_lms_verification_email_body',
			$this->default_body( $user, $verification_url, $plain_token ),
			(int) $user->ID,
			$verification_url,
			$plain_token
		);

		$content_type_callback = static fn (): string => 'text/html; charset=UTF-8';
		add_filter( 'wp_mail_content_type', $content_type_callback );

		try {
			return (bool) wp_mail( (string) $user->user_email, $subject, $body, $this->default_headers() );
		} finally {
			remove_filter( 'wp_mail_content_type', $content_type_callback );
		}
	}

	/**
	 * Build the default header set for outbound mail. Sources the `From:`
	 * header from the `VL_LMS_EMAIL_FROM` constant (set in `wp-config.php`);
	 * if the constant is missing or empty, no `From` header is sent and
	 * WordPress falls back to its own default.
	 *
	 * @return array<int, string>
	 */
	private function default_headers(): array {
		$headers = array();
		if ( defined( 'VL_LMS_EMAIL_FROM' ) && '' !== (string) constant( 'VL_LMS_EMAIL_FROM' ) ) {
			$headers[] = 'From: ' . (string) constant( 'VL_LMS_EMAIL_FROM' );
		}
		return $headers;
	}

	private function build_verification_url( string $plain_token ): string {
		return rtrim( $this->app_url(), '/' ) . '/verify-email?token=' . rawurlencode( $plain_token );
	}

	private function app_url(): string {
		if ( defined( 'VL_LMS_APP_URL' ) && '' !== (string) constant( 'VL_LMS_APP_URL' ) ) {
			return (string) constant( 'VL_LMS_APP_URL' );
		}
		$this->logger->warning(
			'VL_LMS_APP_URL is not defined; verification links will point at home_url().'
		);
		return (string) home_url();
	}

	private function default_body( WP_User $user, string $url, string $plain_token ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;

		return sprintf(
			'<p>%s %s,</p>'
			. '<p>%s</p>'
			. '<p><a href="%s">%s</a></p>'
			. '<p>%s</p>'
			. '<p><code>%s</code></p>'
			. '<p>%s</p>',
			esc_html__( 'Hello', 'vl-lms' ),
			esc_html( $greeting_name ),
			esc_html__( 'Verify your email to finish setting up your account. This link expires in 24 hours.', 'vl-lms' ),
			esc_url( $url ),
			esc_html__( 'Verify my email', 'vl-lms' ),
			esc_html__( 'If you prefer to paste the token manually, copy and paste this into the verification form:', 'vl-lms' ),
			esc_html( $plain_token ),
			esc_html__( 'If you did not create an account, you can safely ignore this message.', 'vl-lms' )
		);
	}
}
