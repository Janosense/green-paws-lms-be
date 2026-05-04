<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Immutable value object carrying a parsed LiqPay callback.
 *
 * Phase 8.2 — produced by {@see CallbackParser} after signature verification
 * and base64+JSON decoding. Holds the canonical-typed projection of the
 * payload alongside the verbatim JSON string so the audit trail in
 * `vl_payments.raw_payload` keeps the original wire form.
 *
 * The `to_idempotency_key()` method encodes the deduplication identity used
 * by `vl_payments.idempotency_key`'s UNIQUE constraint:
 * `liqpay:{payment_id}:{action}:{status}`. A missing `payment_id` is
 * unrecoverable — the caller must short-circuit before reaching the key
 * builder.
 *
 * @author Tymofii Synianskyi
 */
class CallbackPayload {

	/**
	 * @param array<string, mixed> $raw_payload
	 */
	public function __construct(
		public readonly string $order_id,
		public readonly string $status,
		public readonly string $action,
		public readonly ?string $payment_id,
		public readonly string $amount,
		public readonly string $currency,
		public readonly string $raw_payload_json,
		public readonly array $raw_payload
	) {
	}

	public function order_id(): string {
		return $this->order_id;
	}

	public function status(): string {
		return $this->status;
	}

	public function action(): string {
		return $this->action;
	}

	public function payment_id(): ?string {
		return $this->payment_id;
	}

	public function amount(): string {
		return $this->amount;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function raw_payload_json(): string {
		return $this->raw_payload_json;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function raw_payload(): array {
		return $this->raw_payload;
	}

	/**
	 * @throws \LogicException When `$payment_id` is null. Callers must guard
	 *                         this branch — an idempotency key without a
	 *                         stable provider id has no deduplication value.
	 */
	public function to_idempotency_key(): string {
		if ( null === $this->payment_id ) {
			throw new \LogicException(
				'Cannot build idempotency key — LiqPay callback is missing payment_id.'
			);
		}
		return sprintf( 'liqpay:%s:%s:%s', $this->payment_id, $this->action, $this->status );
	}
}
