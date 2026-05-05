<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

use VL\LMS\Payments\Exception\PaymentProviderHttpException;

/**
 * Phase 8.3 — parses LiqPay's refund response body into {@see RefundResponse}.
 *
 * Successful refund: `{"status": "reversed", "payment_id": 12345, ...}`.
 * Provider rejection: `{"status": "error", "err_code": "...", "err_description": "..."}`.
 * Payment-state issues: `{"status": "failure", ...}`.
 *
 * Malformed payloads (non-JSON, missing `status`) surface as
 * {@see PaymentProviderHttpException} — same surface as transport-level
 * failures, since both indicate "we can't trust this response."
 *
 * Concrete (not final).
 *
 * @author Tymofii Synianskyi
 */
class RefundResponseParser {

	/**
	 * @throws PaymentProviderHttpException When the body is not a JSON object
	 *                                      or `status` is missing.
	 */
	public function parse( string $body ): RefundResponse {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new PaymentProviderHttpException( 'LiqPay refund response is not a JSON object', null, $body );
		}

		$status = $decoded['status'] ?? null;
		if ( ! is_string( $status ) || '' === $status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new PaymentProviderHttpException( 'LiqPay refund response missing required `status` field', null, $body );
		}

		return new RefundResponse(
			status: $status,
			payment_id: $this->normalize_payment_id( $decoded['payment_id'] ?? null ),
			err_code: $this->nullable_string( $decoded['err_code'] ?? null ),
			err_description: $this->nullable_string( $decoded['err_description'] ?? null ),
			raw: $decoded
		);
	}

	private function normalize_payment_id( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return '' === $value ? null : $value;
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		return null;
	}

	private function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return '' === $value ? null : $value;
		}
		return null;
	}
}
