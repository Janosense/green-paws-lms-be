<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Exception\ZoomApiException;
use VL\LMS\Services\Zoom\Exception\ZoomAuthException;

/**
 * Production {@see ZoomClient} on top of
 * {@see TokenProvider} + {@see ZoomApiHttpClient}.
 *
 * Each call:
 *   1. Resolves a token via the provider.
 *   2. Issues the HTTP request with `Authorization: Bearer <token>`.
 *   3. On 401 — invalidates the token cache and retries once with a
 *      fresh token; if the retry also returns 401, throws
 *      {@see ZoomAuthException}.
 *   4. On any other 4xx/5xx — parses Zoom's `code` / `message` and
 *      throws {@see ZoomApiException}.
 *
 * @author Tymofii Synianskyi
 */
class HttpZoomClient implements ZoomClient {

	private const string BASE_URL = 'https://api.zoom.us/v2';

	private TokenProvider $token_provider;

	private ZoomApiHttpClient $http;

	public function __construct( TokenProvider $token_provider, ZoomApiHttpClient $http ) {
		$this->token_provider = $token_provider;
		$this->http           = $http;
	}

	/**
	 * @param array<string, mixed> $request
	 *
	 * @return array<string, mixed>
	 */
	public function create_meeting( int|string $host_user_id_or_me, array $request ): array {
		$path = '/users/' . rawurlencode( (string) $host_user_id_or_me ) . '/meetings';
		return $this->json_request( 'POST', $path, $request, true );
	}

	/**
	 * @param array<string, mixed> $request
	 */
	public function update_meeting( string $meeting_id, array $request ): void {
		$path = '/meetings/' . rawurlencode( $meeting_id );
		$this->json_request( 'PATCH', $path, $request, false );
	}

	public function delete_meeting( string $meeting_id, bool $cancel_meeting_reminder = false ): void {
		$path = '/meetings/' . rawurlencode( $meeting_id );
		if ( $cancel_meeting_reminder ) {
			$path .= '?cancel_meeting_reminder=true';
		}
		$this->json_request( 'DELETE', $path, null, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_meeting( string $meeting_id ): array {
		$path = '/meetings/' . rawurlencode( $meeting_id );
		return $this->json_request( 'GET', $path, null, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function list_recordings( string $meeting_id ): array {
		$path = '/meetings/' . rawurlencode( $meeting_id ) . '/recordings';
		return $this->json_request( 'GET', $path, null, true );
	}

	/**
	 * @param array<string, mixed>|null $body
	 *
	 * @return array<string, mixed>
	 */
	private function json_request( string $method, string $path, ?array $body, bool $expect_body ): array {
		$body_string = null === $body ? null : $this->encode( $body );

		$response = $this->execute( $method, $path, $body_string, false );

		if ( 401 === $response['status'] ) {
			$this->token_provider->invalidate();
			$response = $this->execute( $method, $path, $body_string, true );

			if ( 401 === $response['status'] ) {
				throw new ZoomAuthException( 'Zoom API returned 401 after token refresh.' );
			}
		}

		$status = $response['status'];
		$raw    = $response['body'];

		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$decoded = is_array( $decoded ) ? $decoded : null;

			$zoom_code    = is_array( $decoded ) && isset( $decoded['code'] )
				? (string) $decoded['code']
				: null;
			$zoom_message = is_array( $decoded ) && isset( $decoded['message'] )
				? (string) $decoded['message']
				: null;

			$message = sprintf(
				'Zoom API error (HTTP %d)%s.',
				$status,
				null !== $zoom_message ? ': ' . $zoom_message : ''
			);

			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception; constructor takes structured fields, not output.
			throw new ZoomApiException(
				$message,
				$status,
				$zoom_code,
				$zoom_message,
				$decoded
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		if ( ! $expect_body ) {
			return [];
		}

		if ( '' === $raw ) {
			return [];
		}

		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * @return array{status: int, body: string}
	 */
	private function execute( string $method, string $path, ?string $body, bool $is_retry ): array {
		$token = $this->token_provider->get_token();

		$headers = [
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
		];
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$url = self::BASE_URL . $path;

		// $is_retry kept as an explicit parameter so a future logger can
		// tag the second attempt — no behavior difference today.
		unset( $is_retry );

		return $this->http->request( $method, $url, $headers, $body );
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function encode( array $body ): string {
		$encoded = wp_json_encode( $body );
		if ( false === $encoded ) {
			throw new ZoomApiException( 'Failed to JSON-encode Zoom request body.', 0 );
		}
		return $encoded;
	}
}
