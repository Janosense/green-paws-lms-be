<?php

declare(strict_types=1);

namespace VL\LMS\Auth\PasswordReset;

use VL\LMS\Auth\Mail\PasswordResetMailer;
use VL\LMS\Auth\PasswordPolicy;
use VLJwtAuth\Support\RateLimiter;
use WP_User;

/**
 * Orchestrates password-reset request + confirm.
 *
 * Architecture mirrors
 * {@see \VL\LMS\Auth\Verification\EmailVerificationService}:
 * single-active + single-use token, sha256-hashed lookup on user meta,
 * generic no-op response on unknown inputs so the REST layer can surface
 * an enumeration-safe body. The TTL is tighter (1 hour vs verification's
 * 24 hours) because the stakes are higher — a leaked reset token permits
 * account takeover — and password-reset links are typically opened
 * within a few minutes of the email arriving.
 *
 * Works for both verified and unverified users: a user who never
 * verified their email still needs a recovery path, and on successful
 * reset they are marked verified (proof-of-email-access is proof-of-email-access
 * regardless of which flow produced it — see PHASE-2-AUDIT.md §13).
 *
 * Session revocation is delegated to `vl-jwt-auth`:
 * {@see reset_password()} (WP core) fires the `password_reset` action,
 * which `\VLJwtAuth\Repository\RefreshTokenRepository::revoke_user()`
 * listens to. Using {@see wp_set_password()} directly instead of
 * `reset_password()` would bypass that chain — so `reset_password()`
 * is the required call.
 *
 * @author Tymofii Synianskyi
 */
class PasswordResetService {

	public const int DEFAULT_TOKEN_TTL_SECONDS = 3600;

	public const int REQUEST_RATE_LIMIT_EMAIL = 5;

	public const int REQUEST_RATE_LIMIT_IP = 20;

	public const int REQUEST_RATE_WINDOW_SECONDS = 3600;

	public function __construct(
		private readonly PasswordResetMailer $mailer,
		private readonly RateLimiter $rate_limiter,
		private readonly PasswordPolicy $password_policy
	) {
	}

	/**
	 * Kick off a password reset.
	 *
	 * Returns silently in every branch (malformed email, unknown email,
	 * rate-limited) so the REST layer can respond with a single generic
	 * body without branching on internal state. Generating a new token
	 * overwrites any previously issued one for the same account
	 * ("single-active" semantics).
	 */
	public function request( PasswordResetRequest $request ): void {
		$email = sanitize_email( $request->email );
		if ( ! is_email( $email ) ) {
			return;
		}

		if ( $this->is_rate_limited( $email, $request->ip ) ) {
			return;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		$token = $this->issue_token_for( (int) $user->ID );
		$this->mailer->send( $user, $token->plain );
	}

	/**
	 * Consume a single reset token and apply the new password.
	 *
	 * @throws PasswordResetException `invalid` (unknown token), `expired`, or `weak_password`.
	 */
	public function confirm( PasswordResetConfirmRequest $request ): int {
		$plain_token = trim( $request->token );
		if ( '' === $plain_token ) {
			throw PasswordResetException::invalid();
		}

		$hash = hash( 'sha256', $plain_token );

		$users = get_users(
			[
				'meta_key'    => '_vl_password_reset_token_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-value indexed lookup, mirrors verification flow (see PHASE-2-AUDIT.md §8).
				'meta_value'  => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- sha256 hex is high-selectivity.
				'number'      => 1,
				'count_total' => false,
			]
		);

		$user = is_array( $users ) && isset( $users[0] ) && $users[0] instanceof WP_User ? $users[0] : null;
		if ( null === $user ) {
			throw PasswordResetException::invalid();
		}

		$expires = (int) get_user_meta( (int) $user->ID, '_vl_password_reset_token_expires', true );
		if ( $expires < time() ) {
			throw PasswordResetException::expired();
		}

		if ( ! $this->password_policy->is_acceptable( $request->password ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception; REST layer surfaces only the code.
			throw PasswordResetException::weak_password( $this->password_policy->min_length() );
		}

		$this->apply_password_reset( $user, $request->password );

		delete_user_meta( (int) $user->ID, '_vl_password_reset_token_hash' );
		delete_user_meta( (int) $user->ID, '_vl_password_reset_token_expires' );

		// Proof-of-email-access is proof-of-email-access regardless of
		// which flow produced it — a successful password reset is evidence
		// the account holder controls the mailbox, so the email is
		// considered verified from this point on. Delegated decision,
		// captured in PHASE-2-AUDIT.md §13.
		update_user_meta( (int) $user->ID, '_vl_email_verified', '1' );

		return (int) $user->ID;
	}

	/**
	 * Whether the caller has exceeded the per-email or per-IP request
	 * quota within the rolling window. Kept public so the controller can
	 * short-circuit cheaply if needed — but the normal path just calls
	 * {@see self::request()}, which invokes this internally.
	 */
	public function is_rate_limited( string $email, string $ip ): bool {
		/**
		 * Filter the per-email rate limit for password-reset requests.
		 *
		 * @param int $limit Default limit per window.
		 */
		$email_limit = max( 1, (int) apply_filters( 'vl_lms_password_reset_email_limit', self::REQUEST_RATE_LIMIT_EMAIL ) );

		/**
		 * Filter the per-IP rate limit for password-reset requests.
		 *
		 * @param int $limit Default limit per window.
		 */
		$ip_limit = max( 1, (int) apply_filters( 'vl_lms_password_reset_ip_limit', self::REQUEST_RATE_LIMIT_IP ) );

		if ( ! $this->rate_limiter->check(
			'vl_lms_password_reset:' . $email,
			$email_limit,
			self::REQUEST_RATE_WINDOW_SECONDS
		) ) {
			return true;
		}

		if ( '' !== $ip && ! $this->rate_limiter->check(
			'vl_lms_password_reset:' . $ip,
			$ip_limit,
			self::REQUEST_RATE_WINDOW_SECONDS
		) ) {
			return true;
		}

		return false;
	}

	private function issue_token_for( int $user_id ): PasswordResetToken {
		/**
		 * Filter the lifetime (seconds) of a password-reset token.
		 *
		 * Default 1 hour — tighter than verification's 24-hour TTL because
		 * a leaked reset token permits account takeover.
		 *
		 * @param int $ttl Default TTL — 1 hour.
		 */
		$ttl = (int) apply_filters( 'vl_lms_password_reset_token_ttl', self::DEFAULT_TOKEN_TTL_SECONDS );

		$token = PasswordResetToken::generate( $ttl );

		update_user_meta( $user_id, '_vl_password_reset_token_hash', $token->hash );
		update_user_meta( $user_id, '_vl_password_reset_token_expires', (string) $token->expires_at );

		return $token;
	}

	/**
	 * Apply the new password via WP core's {@see reset_password()} —
	 * which fires the `password_reset` action so `vl-jwt-auth`'s
	 * `RefreshTokenRepository::revoke_user()` cascades session
	 * revocation. Do NOT replace with `wp_set_password()`: that would
	 * silently skip the hook chain and leave stale refresh tokens valid.
	 *
	 * Suppresses the default site-admin notification email for non-admin
	 * password resets (a headless LMS otherwise spams the admin inbox at
	 * scale). Admin resets still notify.
	 */
	private function apply_password_reset( WP_User $user, string $new_password ): void {
		$suppressor = null;

		if ( ! user_can( $user, 'manage_options' ) ) {
			/**
			 * @param array{to: string, subject: string, message: string, headers: string} $email
			 * @return array{to: string, subject: string, message: string, headers: string}
			 */
			$suppressor = static function ( array $email ): array {
				unset( $email );
				return [
					'to'      => '',
					'subject' => '',
					'message' => '',
					'headers' => '',
				];
			};
			add_filter( 'wp_password_change_notification_email', $suppressor );
		}

		try {
			reset_password( $user, $new_password );
		} finally {
			if ( null !== $suppressor ) {
				remove_filter( 'wp_password_change_notification_email', $suppressor );
			}
		}
	}
}
