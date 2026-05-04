<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Payment;

/**
 * Immutable carrier for the provider-specific payment payload the frontend
 * submits to start a checkout.
 *
 * For LiqPay, `$fields` carries the canonical trio: `data` (base64-encoded
 * JSON payload), `signature` (HMAC-SHA1 of private+data+private), and
 * `version`. The frontend builds an auto-submit form against `$action_url`.
 * No HTTP is performed server-side in Phase 8.1.
 *
 * Phase 8.1 — Order service + LiqPay outbound.
 *
 * @author Tymofii Synianskyi
 */
class PreparedPayment {

	/**
	 * @param array<string, string> $fields Provider-specific form fields.
	 *
	 * @throws \InvalidArgumentException When `$action_url` is empty, `$http_method`
	 *                                  is empty, or any `$fields` value is not a
	 *                                  non-empty string.
	 */
	public function __construct(
		public readonly string $action_url,
		public readonly string $http_method,
		public readonly array $fields
	) {
		if ( '' === $action_url ) {
			throw new \InvalidArgumentException( 'PreparedPayment action_url must not be empty.' );
		}
		if ( '' === $http_method ) {
			throw new \InvalidArgumentException( 'PreparedPayment http_method must not be empty.' );
		}
		if ( [] === $fields ) {
			throw new \InvalidArgumentException( 'PreparedPayment fields must not be empty.' );
		}
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				throw new \InvalidArgumentException( 'PreparedPayment field keys must be non-empty strings.' );
			}
			if ( ! is_string( $value ) || '' === $value ) {
				throw new \InvalidArgumentException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
					sprintf( 'PreparedPayment field "%s" must be a non-empty string.', $key )
				);
			}
		}
	}

	/**
	 * @return array{action_url: string, http_method: string, fields: array<string, string>}
	 */
	public function to_array(): array {
		return [
			'action_url'  => $this->action_url,
			'http_method' => $this->http_method,
			'fields'      => $this->fields,
		];
	}
}
