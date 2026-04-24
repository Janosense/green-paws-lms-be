<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Registration;

use VL\LMS\Auth\Mail\VerificationMailer;
use VL\LMS\Auth\Verification\VerificationToken;
use WP_Error;
use WP_User;

/**
 * Orchestrates new-user creation + initial verification-email dispatch.
 *
 * Responsibilities:
 * - Validate email syntax and password policy.
 * - Apply idempotent signup semantics (existing unverified accounts get
 *   a fresh verification email; existing verified accounts are silent
 *   no-ops) so the REST response cannot be used for email enumeration.
 * - Assign the WP `student` role unconditionally and persist the
 *   `_vl_account_kind` meta for future kind-based routing.
 * - Generate a single-use verification token, store only its hash, and
 *   hand the plain token to the mailer.
 *
 * All persistence goes through standard WP user functions; no direct
 * `$wpdb` access is needed.
 *
 * Stateless. Safe to instantiate once per request.
 *
 * @author Tymofii Synianskyi
 */
class RegistrationService {

	public const int DEFAULT_MIN_PASSWORD_LENGTH = 8;

	public const int DEFAULT_TOKEN_TTL_SECONDS = 86400;

	public function __construct(
		private readonly VerificationMailer $mailer
	) {
	}

	/**
	 * Idempotent registration entrypoint.
	 *
	 * @throws RegistrationException When email/password policy rejects the input or WP user creation fails.
	 */
	public function register( RegistrationRequest $request ): RegistrationResult {
		$this->validate_email( $request->email );
		$this->validate_password( $request->password );

		$existing = get_user_by( 'email', $request->email );
		if ( $existing instanceof WP_User ) {
			return $this->handle_existing_user( $existing );
		}

		$user_id = $this->create_user( $request );

		update_user_meta( $user_id, '_vl_email_verified', '0' );
		update_user_meta( $user_id, '_vl_account_kind', $request->account_kind );

		$token = $this->issue_token_for( $user_id );

		$fresh_user = get_user_by( 'id', $user_id );
		if ( $fresh_user instanceof WP_User ) {
			$this->mailer->send( $fresh_user, $token->plain );
		}

		return new RegistrationResult(
			user_id: $user_id,
			plain_verification_token: $token->plain,
			outcome: RegistrationOutcome::CREATED
		);
	}

	private function handle_existing_user( WP_User $user ): RegistrationResult {
		$verified = '1' === (string) get_user_meta( (int) $user->ID, '_vl_email_verified', true );
		if ( $verified ) {
			return new RegistrationResult(
				user_id: (int) $user->ID,
				plain_verification_token: null,
				outcome: RegistrationOutcome::ALREADY_VERIFIED
			);
		}

		$token = $this->issue_token_for( (int) $user->ID );
		$this->mailer->send( $user, $token->plain );

		return new RegistrationResult(
			user_id: (int) $user->ID,
			plain_verification_token: $token->plain,
			outcome: RegistrationOutcome::RESENT
		);
	}

	private function validate_email( string $email ): void {
		if ( ! is_email( $email ) ) {
			throw RegistrationException::invalid_email();
		}
	}

	private function validate_password( string $password ): void {
		/**
		 * Filter the minimum password length enforced at registration.
		 *
		 * @param int $length Default minimum length.
		 */
		$raw = apply_filters( 'vl_lms_min_password_length', self::DEFAULT_MIN_PASSWORD_LENGTH );
		$min = max( 1, (int) $raw );

		if ( strlen( $password ) < $min ) {
			throw RegistrationException::weak_password( $min ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * @throws RegistrationException When `wp_insert_user` fails.
	 */
	private function create_user( RegistrationRequest $request ): int {
		$display_name = trim( $request->first_name . ' ' . $request->last_name );

		$result = wp_insert_user(
			[
				'user_login'   => $this->derive_login( $request->email ),
				'user_email'   => $request->email,
				'user_pass'    => $request->password,
				'first_name'   => $request->first_name,
				'last_name'    => $request->last_name,
				'display_name' => $display_name,
				'role'         => 'student',
			]
		);

		if ( $result instanceof WP_Error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception propagated to REST layer, not rendered in HTML.
			throw RegistrationException::insertion_failed( (string) $result->get_error_message() );
		}

		return (int) $result;
	}

	/**
	 * Persist a fresh verification token for `$user_id`, replacing any
	 * previously issued token. Returns the plain + hashed VO so the
	 * mailer can send the plain part.
	 */
	private function issue_token_for( int $user_id ): VerificationToken {
		/**
		 * Filter the lifetime (seconds) of a verification token.
		 *
		 * @param int $ttl Default TTL — 24 hours.
		 */
		$ttl = (int) apply_filters( 'vl_lms_verification_token_ttl', self::DEFAULT_TOKEN_TTL_SECONDS );

		$token = VerificationToken::generate( $ttl );

		update_user_meta( $user_id, '_vl_verification_token_hash', $token->hash );
		update_user_meta( $user_id, '_vl_verification_token_expires', (string) $token->expires_at );

		return $token;
	}

	/**
	 * Derive a WP `user_login` from the email's local-part, falling back
	 * to a suffixed variant on collision. Last-resort returns the full
	 * email — `wp_insert_user` accepts it.
	 */
	private function derive_login( string $email ): string {
		$local = strstr( $email, '@', true );
		$base  = sanitize_user( false !== $local ? $local : $email, true );

		if ( '' === $base ) {
			$base = 'user';
		}

		if ( ! username_exists( $base ) ) {
			return $base;
		}

		for ( $i = 0; $i < 10; $i++ ) {
			$candidate = $base . '_' . wp_generate_password( 6, false, false );
			if ( ! username_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return $email;
	}
}
