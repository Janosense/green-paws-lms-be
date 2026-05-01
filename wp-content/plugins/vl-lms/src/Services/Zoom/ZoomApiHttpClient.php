<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

/**
 * Test seam isolating the Zoom REST API HTTP transport from
 * {@see HttpZoomClient}. The production implementation
 * ({@see WpRemoteZoomApiHttpClient}) wraps `wp_remote_request`.
 *
 * Implementations MUST return the raw HTTP envelope (status + body
 * string) without throwing on 4xx/5xx — `HttpZoomClient` parses status
 * codes itself so it can drive the 401 retry path uniformly.
 *
 * @author Tymofii Synianskyi
 */
interface ZoomApiHttpClient {

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array{status: int, body: string}
	 *
	 * @throws \VL\LMS\Services\Zoom\Exception\ZoomApiException On `is_wp_error` network failures.
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): array;
}
