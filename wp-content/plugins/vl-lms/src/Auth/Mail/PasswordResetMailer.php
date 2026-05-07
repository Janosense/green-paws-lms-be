<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Mail;

use VL\LMS\Support\Logger;
use WP_User;

/**
 * Sends the password-reset email via `wp_mail`.
 *
 * Structurally parallel to {@see VerificationMailer}: content-type is
 * scoped to a single send via the `wp_mail_content_type` filter,
 * attached before the call and removed in a `finally` block so other
 * plugins that also use `wp_mail` in the same request aren't affected.
 *
 * The reset URL points the browser at the frontend's
 * `/reset-password?token=…` route (frontend base configured via the
 * `VL_LMS_APP_URL` PHP constant). The frontend then collects the new
 * password and calls `POST /wp-json/vl/v1/auth/reset-password`.
 *
 * Translation seam: subject and body pass through the filters
 * `vl_lms_password_reset_email_subject` and
 * `vl_lms_password_reset_email_body`. English copy is the default for
 * Phase 2.C; Ukrainian copy lands in Phase 2.D alongside the frontend
 * flow.
 *
 * @author Tymofii Synianskyi
 */
class PasswordResetMailer {

	private bool $app_url_warning_emitted = false;

	public function __construct(
		private readonly Logger $logger
	) {
	}

	/**
	 * Deliver the password-reset email to `$user`.
	 *
	 * Returns whatever `wp_mail` returns — `true` on successful enqueue,
	 * `false` otherwise. Callers typically do not surface this up; a
	 * silent failure is the right UX for a security-adjacent operation.
	 */
	public function send( WP_User $user, string $plain_token ): bool {
		$reset_url = $this->build_reset_url( $plain_token );

		/**
		 * Filter the subject line of the password-reset email.
		 *
		 * @param string $subject Default subject.
		 * @param int    $user_id The recipient's user ID.
		 */
		$subject = (string) apply_filters(
			'vl_lms_password_reset_email_subject',
			__( 'Reset your password', 'vl-lms' ),
			(int) $user->ID
		);

		/**
		 * Filter the HTML body of the password-reset email.
		 *
		 * @param string $body      Default HTML body.
		 * @param int    $user_id   The recipient's user ID.
		 * @param string $reset_url Absolute URL to the frontend reset page.
		 */
		$body = (string) apply_filters(
			'vl_lms_password_reset_email_body',
			$this->default_body( $user, $reset_url ),
			(int) $user->ID,
			$reset_url
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

	private function build_reset_url( string $plain_token ): string {
		return rtrim( $this->app_url(), '/' ) . '/reset-password?token=' . rawurlencode( $plain_token );
	}

	private function app_url(): string {
		if ( defined( 'VL_LMS_APP_URL' ) && '' !== (string) constant( 'VL_LMS_APP_URL' ) ) {
			return (string) constant( 'VL_LMS_APP_URL' );
		}
		if ( ! $this->app_url_warning_emitted ) {
			$this->logger->warning(
				'VL_LMS_APP_URL is not defined; password-reset links will point at home_url().'
			);
			$this->app_url_warning_emitted = true;
		}
		return (string) home_url();
	}

	private function default_body( WP_User $user, string $url ): string {
		$greeting_name = '' !== (string) $user->first_name ? (string) $user->first_name : (string) $user->user_login;

		return sprintf(
			'<p>%s %s,</p>'
			. '<p>%s</p>'
			. '<p><a href="%s">%s</a></p>'
			. '<p>%s</p>'
			. '<p>%s</p>',
			esc_html__( 'Hello', 'vl-lms' ),
			esc_html( $greeting_name ),
			esc_html__( 'We received a request to reset the password for your account. This link expires in 1 hour.', 'vl-lms' ),
			esc_url( $url ),
			esc_html__( 'Reset my password', 'vl-lms' ),
			esc_html__( 'If the button does not work, copy and paste the full link into your browser.', 'vl-lms' ),
			esc_html__( 'If you did not request a password reset, you can safely ignore this message — your password will not change.', 'vl-lms' )
		);
	}
}
