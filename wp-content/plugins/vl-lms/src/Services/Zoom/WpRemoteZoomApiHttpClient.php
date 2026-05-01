<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Exception\ZoomApiException;

/**
 * Production {@see ZoomApiHttpClient} backed by `wp_remote_request`.
 *
 * Network failures (`is_wp_error`) surface as
 * {@see ZoomApiException}. Non-2xx HTTP responses pass through
 * unchanged so {@see HttpZoomClient} can switch on the status code
 * (401 retry path, 4xx/5xx error parsing).
 *
 * @author Tymofii Synianskyi
 */
class WpRemoteZoomApiHttpClient implements ZoomApiHttpClient {

	/**
	 * @param array<string, string> $headers
	 *
	 * @return array{status: int, body: string}
	 */
	public function request( string $method, string $url, array $headers, ?string $body ): array {
		$args = [
			'method'  => $method,
			'headers' => $headers,
			'timeout' => 30,
		];
		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			throw new ZoomApiException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message.
				sprintf( 'Zoom API request failed: %s', $response->get_error_message() ),
				0
			);
		}

		return [
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => (string) wp_remote_retrieve_body( $response ),
		];
	}
}
