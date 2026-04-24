<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Verification;

use VL\LMS\Auth\Mail\VerificationMailer;
use VLJwtAuth\Support\RateLimiter;
use WP_User;

/**
 * Consumes verification tokens and handles "resend-verification" requests.
 *
 * Token lookup goes through `get_users()` with a sha256-hashed meta
 * value — O(1) at the volumes this plugin is built for (see
 * PHASE-2-AUDIT.md §8 "Token lookup strategy"). Moving to a custom
 * table is trivial if that ever stops being true.
 *
 * `resend()` is deliberately silent on both unknown emails and
 * already-verified accounts — the REST layer will return the same
 * generic success response in all three cases to prevent email
 * enumeration. Rate-limiting uses the same transient bucket as
 * `vl-jwt-auth` under a distinct key prefix so the two plugins can't
 * step on each other.
 *
 * Stateless.
 *
 * @author Tymofii Synianskyi
 */
class EmailVerificationService {

	public const int DEFAULT_TOKEN_TTL_SECONDS = 86400;

	public const int RESEND_RATE_LIMIT = 5;

	public const int RESEND_RATE_WINDOW_SECONDS = 900;

	public function __construct(
		private readonly VerificationMailer $mailer,
		private readonly RateLimiter $rate_limiter
	) {
	}

	/**
	 * Consume a single verification token.
	 *
	 * Returns the user id on success, clears the token meta, and marks
	 * the account verified. Throws {@see VerificationException} with a
	 * stable code for each failure mode.
	 *
	 * @throws VerificationException
	 */
	public function verify( string $plain_token ): int {
		$plain_token = trim( $plain_token );
		if ( '' === $plain_token ) {
			throw VerificationException::invalid();
		}

		$hash = hash( 'sha256', $plain_token );

		$users = get_users(
			[
				'meta_key'    => '_vl_verification_token_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- single-value indexed lookup, see PHASE-2-AUDIT.md §8.
				'meta_value'  => $hash, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- sha256 hex is high-selectivity.
				'number'      => 1,
				'count_total' => false,
			]
		);

		$user = is_array( $users ) && isset( $users[0] ) && $users[0] instanceof WP_User ? $users[0] : null;
		if ( null === $user ) {
			throw VerificationException::invalid();
		}

		if ( '1' === (string) get_user_meta( (int) $user->ID, '_vl_email_verified', true ) ) {
			// An already-verified user with a lingering matching hash shouldn't happen
			// (verify() clears both hash + expiry) but surface it as a distinct code
			// rather than silently re-verifying.
			throw VerificationException::already_verified();
		}

		$expires = (int) get_user_meta( (int) $user->ID, '_vl_verification_token_expires', true );
		if ( $expires < time() ) {
			throw VerificationException::expired();
		}

		update_user_meta( (int) $user->ID, '_vl_email_verified', '1' );
		delete_user_meta( (int) $user->ID, '_vl_verification_token_hash' );
		delete_user_meta( (int) $user->ID, '_vl_verification_token_expires' );

		return (int) $user->ID;
	}

	/**
	 * Best-effort resend of a verification email.
	 *
	 * Returns silently in every branch (no email, unknown email,
	 * already-verified, rate-limited) so the REST layer can respond
	 * with an identical generic body without branching on internal
	 * state. Generating a new token invalidates any previously issued
	 * one for the same user.
	 */
	public function resend( string $email ): void {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return;
		}

		if ( ! $this->rate_limiter->check(
			'vl_lms_verify_resend:' . $email,
			self::RESEND_RATE_LIMIT,
			self::RESEND_RATE_WINDOW_SECONDS
		) ) {
			return;
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user instanceof WP_User ) {
			return;
		}

		if ( '1' === (string) get_user_meta( (int) $user->ID, '_vl_email_verified', true ) ) {
			return;
		}

		$token = $this->issue_token_for( (int) $user->ID );
		$this->mailer->send( $user, $token->plain );
	}

	private function issue_token_for( int $user_id ): VerificationToken {
		/** This filter is documented in {@see \VL\LMS\Auth\Registration\RegistrationService}. */
		$ttl = (int) apply_filters( 'vl_lms_verification_token_ttl', self::DEFAULT_TOKEN_TTL_SECONDS );

		$token = VerificationToken::generate( $ttl );

		update_user_meta( $user_id, '_vl_verification_token_hash', $token->hash );
		update_user_meta( $user_id, '_vl_verification_token_expires', (string) $token->expires_at );

		return $token;
	}
}
