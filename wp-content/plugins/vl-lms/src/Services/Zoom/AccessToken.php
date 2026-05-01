<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

/**
 * Immutable carrier for a Zoom OAuth access token plus its expiration.
 * Lifetime is owned by {@see TokenProvider} (which writes the transient
 * cache and invalidates on 401). The skew on `is_expired()` keeps us
 * from racing the wire — a token that is "good for 60 more seconds" is
 * already considered expired.
 *
 * @author Tymofii Synianskyi
 */
final class AccessToken {

	public function __construct(
		public readonly string $token,
		public readonly \DateTimeImmutable $expires_at
	) {
	}

	public function is_expired( \DateTimeImmutable $now, int $skew_seconds = 60 ): bool {
		$threshold = $this->expires_at->getTimestamp() - $skew_seconds;
		return $now->getTimestamp() >= $threshold;
	}
}
