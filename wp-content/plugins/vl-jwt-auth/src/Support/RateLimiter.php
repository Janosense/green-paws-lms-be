<?php

declare(strict_types=1);

namespace VLJwtAuth\Support;

/**
 * Fixed-window rate limiter backed by transients.
 *
 * A single bucket tracks `count` and `reset` timestamp. Each call to
 * {@see check()} increments the count and returns whether the caller
 * is still under the limit. Buckets automatically roll over when the
 * window expires.
 *
 * Fixed-window is intentionally simple — the login/refresh endpoints
 * don't need the accuracy of a sliding-window algorithm and fixed-window
 * is one transient read + one write per request.
 */
final class RateLimiter {

	private const BUCKET_PREFIX = 'vl_jwt_auth_rl_';

	/**
	 * Record a hit for $key and return whether it is still under the cap.
	 *
	 * @return bool True when the call is allowed, false when the cap has been exceeded.
	 */
	public function check( string $key, int $limit, int $window ): bool {
		/**
		 * Short-circuit the rate-limit check.
		 *
		 * Callers can hook this to bypass the limiter entirely — used by the
		 * plugin to disable rate limiting in `local` / `development`
		 * environments so dev iteration isn't throttled by repeated logins.
		 *
		 * @param bool   $bypass Whether to skip the rate-limit check.
		 * @param string $key    The bucket key.
		 * @param int    $limit  Max hits allowed in the window.
		 * @param int    $window Window length in seconds.
		 */
		if ( (bool) apply_filters( 'vl_jwt_auth_rate_limit_bypass', false, $key, $limit, $window ) ) {
			return true;
		}

		$bucket = self::BUCKET_PREFIX . md5( $key );
		$now    = time();

		$state = get_transient( $bucket );
		if ( ! is_array( $state ) || ! isset( $state['count'], $state['reset'] ) || (int) $state['reset'] <= $now ) {
			$state = [
				'count' => 0,
				'reset' => $now + $window,
			];
		}

		$state['count'] = (int) $state['count'] + 1;

		$ttl = max( 1, (int) $state['reset'] - $now );
		set_transient( $bucket, $state, $ttl );

		return $state['count'] <= $limit;
	}

	public function reset( string $key ): void {
		delete_transient( self::BUCKET_PREFIX . md5( $key ) );
	}
}
