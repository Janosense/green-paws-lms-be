<?php

declare(strict_types=1);

namespace VL\LMS\Auth\PasswordReset;

/**
 * Immutable value object representing a single password-reset token.
 *
 * Mirrors {@see \VL\LMS\Auth\Verification\VerificationToken}: plain
 * token for the email body, sha256 hash for DB storage, absolute
 * expiry. Callers MUST NOT persist `$plain`.
 *
 * {@see self::generate()} is the supported constructor for new tokens;
 * the primary constructor is kept public only so tests can reconstitute
 * a token from known parts.
 *
 * @author Tymofii Synianskyi
 */
final class PasswordResetToken {

	public function __construct(
		public readonly string $plain,
		public readonly string $hash,
		public readonly int $expires_at
	) {
	}

	/**
	 * Issue a fresh reset token with `$ttl_seconds` until expiry.
	 *
	 * Plain token format: 64 URL-safe characters from
	 * {@see wp_generate_password()} — same strategy as VerificationToken.
	 * Hash: sha256 hex — matches the `vl-jwt-auth` Support\Hasher invariant.
	 */
	public static function generate( int $ttl_seconds ): self {
		$plain = wp_generate_password( 64, false, false );
		return new self(
			plain: $plain,
			hash: hash( 'sha256', $plain ),
			expires_at: time() + max( 60, $ttl_seconds )
		);
	}
}
