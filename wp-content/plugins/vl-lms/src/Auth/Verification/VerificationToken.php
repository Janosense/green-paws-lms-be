<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Verification;

/**
 * Immutable value object representing a single verification token.
 *
 * Encapsulates the three related pieces: the plain token (for email
 * delivery), its sha256 hash (for DB storage — mirrors the invariant in
 * `vl-jwt-auth` that raw tokens never touch the database), and the
 * absolute expiry timestamp. Callers MUST NOT persist `$plain`.
 *
 * {@see self::generate()} is the supported constructor for new tokens;
 * the primary constructor is kept public only to let tests reconstitute
 * a token from known parts.
 *
 * @author Tymofii Synianskyi
 */
final class VerificationToken {

	public function __construct(
		public readonly string $plain,
		public readonly string $hash,
		public readonly int $expires_at
	) {
	}

	/**
	 * Issue a fresh token with `$ttl_seconds` until expiry.
	 *
	 * Plain token format: 64 URL-safe characters from
	 * {@see wp_generate_password()} (~380 bits of entropy — overkill for
	 * short-lived single-use tokens, but zero-risk). Hash: sha256 hex —
	 * matches `vl-jwt-auth`'s `Support\Hasher`.
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
