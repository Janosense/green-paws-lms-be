<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Settings\ZoomCredentials;

/**
 * Test seam isolating the token endpoint HTTP call from
 * {@see TokenProvider}. The production implementation
 * ({@see WpHttpTokenHttpClient}) wraps `wp_remote_post`; tests pass a
 * fake that returns canned responses without touching `wp_remote_*`.
 *
 * Implementations must throw
 * {@see \VL\LMS\Services\Zoom\Exception\ZoomAuthException} on network or
 * non-200 responses — the calling token provider does not retry token
 * fetches.
 *
 * @author Tymofii Synianskyi
 */
interface TokenHttpClient {

	/**
	 * Performs the S2S-OAuth token request and returns Zoom's parsed JSON
	 * body: `{ access_token, expires_in, token_type, scope }`.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomAuthException When the request fails or returns non-200.
	 */
	public function request_token( ZoomCredentials $creds ): array;
}
