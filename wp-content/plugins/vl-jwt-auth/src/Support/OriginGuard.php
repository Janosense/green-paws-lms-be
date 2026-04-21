<?php

declare(strict_types=1);

namespace VLJwtAuth\Support;

use WP_REST_Request;

/**
 * Origin allowlist check for cookie-bearing endpoints.
 *
 * This is the MVP's CSRF defense for /token/refresh and /logout on a
 * fully cross-origin SPA setup: browsers reliably attach `Origin` on
 * cross-site POST/DELETE, and we reject anything that doesn't match.
 *
 * An empty allowlist means "not configured" — the guard returns true so
 * local/dev environments work out of the box without ceremony. In
 * production, populate `allowed_origins` in the plugin settings.
 */
final class OriginGuard {

	public function __construct(
		private Settings $settings
	) {
	}

	public function is_allowed( WP_REST_Request $request ): bool {
		$allowed = $this->settings->allowed_origins();
		if ( [] === $allowed ) {
			return true;
		}

		$origin = $this->normalize( (string) $request->get_header( 'origin' ) );
		if ( '' === $origin ) {
			// Some clients omit Origin but send Referer; fall back to its origin component.
			$origin = $this->origin_from_url( (string) $request->get_header( 'referer' ) );
		}
		if ( '' === $origin ) {
			return false;
		}

		return in_array( $origin, $allowed, true );
	}

	private function normalize( string $origin ): string {
		return rtrim( $origin, '/' );
	}

	private function origin_from_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = $parts['scheme'] . '://' . $parts['host'];
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}
}
