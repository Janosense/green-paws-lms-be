<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Parses an inbound LiqPay callback into a typed {@see CallbackPayload}.
 *
 * Phase 8.2 — single entry-point used by `Api\PaymentsController`.
 * Returns `null` for every parse / verify failure so the controller has
 * one uniform "absorb and 200 OK" branch (LiqPay's retry policy hammers
 * non-2xx aggressively, so the controller absorbs garbage and lets ops
 * monitor warning rates instead).
 *
 * The parser also normalizes the wire-level shape:
 *   - `amount` arrives as a JSON number, not a string. We canonicalize to a
 *     2-decimal string so downstream comparison with `Money::to_major_decimal()`
 *     never trips on float-vs-string equality.
 *   - `payment_id` arrives as an integer. We string-cast at the boundary
 *     since {@see \VL\LMS\Domain\Payment\Payment::$provider_payment_id} is
 *     a `?string`.
 *
 * @author Tymofii Synianskyi
 */
class CallbackParser {

	public function __construct( private readonly SignatureVerifier $verifier ) {
	}

	public function parse( string $base64_data, string $signature ): ?CallbackPayload {
		if ( ! $this->verifier->verify( $base64_data, $signature ) ) {
			return null;
		}

		$decoded = base64_decode( $base64_data, true );
		if ( false === $decoded ) {
			return null;
		}

		$payload = json_decode( $decoded, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}

		$order_id = $this->require_string( $payload, 'order_id' );
		$status   = $this->require_string( $payload, 'status' );
		$action   = $this->require_string( $payload, 'action' );
		$currency = $this->require_string( $payload, 'currency' );
		if ( null === $order_id || null === $status || null === $action || null === $currency ) {
			return null;
		}

		$amount = $this->normalize_amount( $payload['amount'] ?? null );
		if ( null === $amount ) {
			return null;
		}

		$payment_id = $this->normalize_payment_id( $payload['payment_id'] ?? null );

		return new CallbackPayload(
			order_id: $order_id,
			status: $status,
			action: $action,
			payment_id: $payment_id,
			amount: $amount,
			currency: $currency,
			raw_payload_json: $decoded,
			raw_payload: $payload
		);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function require_string( array $payload, string $key ): ?string {
		if ( ! isset( $payload[ $key ] ) || ! is_string( $payload[ $key ] ) || '' === $payload[ $key ] ) {
			return null;
		}
		return $payload[ $key ];
	}

	private function normalize_amount( mixed $raw ): ?string {
		if ( is_int( $raw ) || is_float( $raw ) ) {
			return number_format( (float) $raw, 2, '.', '' );
		}
		if ( is_string( $raw ) && '' !== $raw && is_numeric( $raw ) ) {
			return number_format( (float) $raw, 2, '.', '' );
		}
		return null;
	}

	private function normalize_payment_id( mixed $raw ): ?string {
		if ( is_int( $raw ) ) {
			return (string) $raw;
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			return $raw;
		}
		return null;
	}
}
