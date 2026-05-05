<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

use VL\LMS\Payments\Exception\PaymentProviderHttpException;

/**
 * Phase 8.3 — `wp_remote_post` wrapper for LiqPay's request endpoint.
 *
 * First outbound HTTP call in the payments subsystem. Mirrors the shape of
 * Phase 7.0's Zoom HTTP wrapper: `transport()` is a protected seam unit
 * tests subclass to bypass the WordPress HTTP API.
 *
 * The two arguments — `data` (base64 JSON) and `signature` (HMAC-SHA1) —
 * carry the entire authenticated request; LiqPay's request endpoint
 * inspects the `action` field inside the JSON to dispatch (refund, etc.).
 *
 * @author Tymofii Synianskyi
 */
class HttpClient {

	public const string REQUEST_URL  = 'https://www.liqpay.ua/api/request';
	public const int TIMEOUT_SECONDS = 15;

	/**
	 * POST a signed LiqPay request and return the decoded HTTP envelope.
	 *
	 * @return array{status_code: int, body: string, headers: array<string, string>}
	 *
	 * @throws PaymentProviderHttpException On transport failure or non-2xx response.
	 */
	public function post( string $base64_data, string $signature ): array {
		$args = [
			'body'        => [
				'data'      => $base64_data,
				'signature' => $signature,
			],
			'timeout'     => self::TIMEOUT_SECONDS,
			'redirection' => 0,
		];

		$response = $this->transport( self::REQUEST_URL, $args );

		if ( is_wp_error( $response ) ) {
			throw new PaymentProviderHttpException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
				sprintf( 'LiqPay HTTP request failed: %s', $response->get_error_message() )
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = (string) wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new PaymentProviderHttpException( sprintf( 'LiqPay returned HTTP %d', $status_code ), $status_code, $body );
		}

		$raw_headers = wp_remote_retrieve_headers( $response );
		$headers     = $this->normalize_headers( $raw_headers );

		return [
			'status_code' => $status_code,
			'body'        => $body,
			'headers'     => $headers,
		];
	}

	/**
	 * Indirected so unit tests can subclass and override without
	 * round-tripping through `wp_remote_post`.
	 *
	 * @param array<string, mixed> $args
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function transport( string $url, array $args ): mixed {
		return wp_remote_post( $url, $args );
	}

	/**
	 * @param mixed $raw
	 *
	 * @return array<string, string>
	 */
	private function normalize_headers( mixed $raw ): array {
		if ( is_array( $raw ) ) {
			$out = [];
			foreach ( $raw as $name => $value ) {
				if ( is_string( $name ) ) {
					$out[ $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
				}
			}
			return $out;
		}
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$all = $raw->getAll();
			if ( is_array( $all ) ) {
				$out = [];
				foreach ( $all as $name => $value ) {
					if ( is_string( $name ) ) {
						$out[ $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
					}
				}
				return $out;
			}
		}
		return [];
	}
}
