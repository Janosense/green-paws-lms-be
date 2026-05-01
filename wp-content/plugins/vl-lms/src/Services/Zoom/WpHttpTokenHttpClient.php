<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom;

use VL\LMS\Services\Zoom\Exception\ZoomAuthException;
use VL\LMS\Services\Zoom\Settings\ZoomCredentials;

/**
 * Production {@see TokenHttpClient} on top of `wp_remote_post`.
 *
 * Endpoint: `POST https://zoom.us/oauth/token` with
 * `grant_type=account_credentials&account_id={account_id}` in the query
 * string and `Authorization: Basic base64(client_id:client_secret)`.
 *
 * Network failures (`is_wp_error`) and any non-200 response surface as
 * {@see ZoomAuthException}. Keeping the auth/network failure shape
 * unified here lets {@see TokenProvider} stay narrowly focused on
 * caching.
 *
 * @author Tymofii Synianskyi
 */
class WpHttpTokenHttpClient implements TokenHttpClient {

	private const string TOKEN_ENDPOINT = 'https://zoom.us/oauth/token';

	/**
	 * @return array<string, mixed>
	 */
	public function request_token( ZoomCredentials $creds ): array {
		$url = self::TOKEN_ENDPOINT . '?grant_type=account_credentials&account_id=' . rawurlencode( $creds->account_id );

		$response = wp_remote_post(
			$url,
			[
				'headers' => [
					'Authorization' => 'Basic ' . base64_encode( $creds->client_id . ':' . $creds->client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			throw new ZoomAuthException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message.
				sprintf( 'Zoom token request failed: %s', $response->get_error_message() )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( 200 !== $status ) {
			throw new ZoomAuthException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message.
				sprintf( 'Zoom token request returned HTTP %d.', $status )
			);
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new ZoomAuthException( 'Zoom token response did not parse as JSON.' );
		}

		return $decoded;
	}
}
