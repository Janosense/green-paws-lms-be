<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Repositories\PaymentAlreadyRecordedException;
use VL\LMS\Repositories\PaymentRepository;
use VL\LMS\Support\Logger;

/**
 * Phase 8.2 — runs the LiqPay callback state machine. Phase 8.3 matures the
 * `reversed` branch from short-circuit to confirmation.
 *
 * Decision table (per the spec's locked decisions):
 *
 *   | LiqPay status                                       | Row | Order transition       | `vl_lms_order_paid` |
 *   | --------------------------------------------------- | --- | ---------------------- | ------------------- |
 *   | `success`, `sandbox`                                | yes | → PAID                 | fires               |
 *   | `failure`, `error`                                  | yes | → FAILED               | does not fire       |
 *   | `wait_*`, `processing`                              | yes | none                   | does not fire       |
 *   | `reversed` (matching prior REFUND row)              | dup | none (already REFUNDED) | does not fire       |
 *   | `reversed` (orphan; no prior REFUND row)            | no  | none                   | does not fire       |
 *   | `other` (unknown)                                   | yes | none                   | does not fire       |
 *
 * Currency or amount mismatch short-circuits the table: no row, no transition.
 * Unknown order_id short-circuits: no row, no transition. A duplicate
 * callback (idempotency-key collision) is caught and reported as
 * {@see CallbackOutcome::OK_DUPLICATE}.
 *
 * Concrete (not final). Mockery-mockable in unit tests; the only seam is
 * `protected now()` for clock control.
 *
 * @author Tymofii Synianskyi
 */
class CallbackHandler {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly PaymentRepository $payments,
		private readonly Logger $logger
	) {
	}

	public function handle( CallbackPayload $payload ): CallbackOutcome {
		$order = $this->find_order( $payload );
		if ( null === $order ) {
			$this->logger->warning(
				'LiqPay callback for unknown order',
				[
					'order_id'   => $payload->order_id(),
					'status'     => $payload->status(),
					'payment_id' => $payload->payment_id(),
				]
			);
			return CallbackOutcome::OK_UNKNOWN_ORDER;
		}

		if ( $payload->currency() !== $order->amount->currency() ) {
			$this->logger->warning(
				'LiqPay callback currency mismatch',
				[
					'order_uuid'        => $order->uuid,
					'expected_currency' => $order->amount->currency(),
					'received_currency' => $payload->currency(),
				]
			);
			return CallbackOutcome::OK_CURRENCY_MISMATCH;
		}

		if ( $payload->amount() !== $order->amount->to_major_decimal() ) {
			$this->logger->warning(
				'LiqPay callback amount mismatch',
				[
					'order_uuid'      => $order->uuid,
					'expected_amount' => $order->amount->to_major_decimal(),
					'received_amount' => $payload->amount(),
				]
			);
			return CallbackOutcome::OK_AMOUNT_MISMATCH;
		}

		if ( 'reversed' === $payload->status() ) {
			return $this->handle_reversed( $order, $payload );
		}

		if ( null === $payload->payment_id() ) {
			$this->logger->warning(
				'LiqPay callback missing payment_id — cannot construct idempotency key',
				[
					'order_uuid' => $order->uuid,
					'status'     => $payload->status(),
				]
			);
			return CallbackOutcome::OK_NO_OP;
		}

		$payment_status = PaymentStatus::try_from_liqpay( $payload->status() );

		$payment = new Payment(
			id: null,
			order_id: (int) $order->id,
			provider: PaymentProvider::LIQPAY,
			provider_payment_id: $payload->payment_id(),
			provider_action: $payload->action(),
			status: $payment_status,
			raw_provider_status: $payload->status(),
			transaction_type: PaymentTransactionType::CHARGE,
			amount: $order->amount,
			raw_payload: $payload->raw_payload_json(),
			received_at: $this->now(),
			idempotency_key: $payload->to_idempotency_key()
		);

		try {
			$payment_id = $this->payments->insert( $payment );
		} catch ( PaymentAlreadyRecordedException ) {
			$this->logger->info(
				'LiqPay callback duplicate (idempotency-key collision)',
				[
					'order_uuid'      => $order->uuid,
					'idempotency_key' => $payment->idempotency_key,
				]
			);
			return CallbackOutcome::OK_DUPLICATE;
		}

		$persisted_payment = $payment->with_id( $payment_id );

		$reloaded = $this->orders->find_by_id( (int) $order->id );
		if ( null === $reloaded ) {
			$this->logger->warning(
				'LiqPay callback: order disappeared after payment insert',
				[ 'order_uuid' => $order->uuid ]
			);
			return CallbackOutcome::OK_PROCESSED;
		}

		$transitioned_order = $this->maybe_transition( $reloaded, $payment_status, $payload->status() );

		if ( null !== $transitioned_order && OrderStatus::PAID === $transitioned_order->status ) {
			/**
			 * Fires after a LiqPay callback flips an order to PAID.
			 *
			 * Listeners include the Phase 8.2 fan-out
			 * ({@see \VL\LMS\Orders\OrderEnrollmentFanout}) at priority 10.
			 *
			 * @param Order   $order   The post-transition order, freshly reloaded.
			 * @param Payment $payment The persisted payment row that triggered the transition.
			 */
			do_action( 'vl_lms_order_paid', $transitioned_order, $persisted_payment );
		}

		return CallbackOutcome::OK_PROCESSED;
	}

	/**
	 * Phase 8.3 — handle a `reversed` callback.
	 *
	 * The sync refund flow ({@see \VL\LMS\Refunds\RefundService}) records a
	 * REFUND row with idempotency key `liqpay:{payment_id}:refund:reversed`
	 * BEFORE LiqPay's matching `reversed` callback arrives. The callback
	 * tries to insert the same row, hits the UNIQUE constraint, and surfaces
	 * as {@see PaymentAlreadyRecordedException} — which is the expected
	 * confirmation case here, returning {@see CallbackOutcome::OK_DUPLICATE}.
	 *
	 * If no prior REFUND row exists at all, this is an "orphan reversed":
	 * LiqPay reversed a charge we did not initiate. We log a warning and
	 * return `OK_NO_OP` (no row, no transition — the order may still be in
	 * PAID, and the operator must investigate manually).
	 */
	private function handle_reversed( Order $order, CallbackPayload $payload ): CallbackOutcome {
		if ( null === $payload->payment_id() ) {
			$this->logger->warning(
				'LiqPay `reversed` callback missing payment_id',
				[ 'order_uuid' => $order->uuid ]
			);
			return CallbackOutcome::OK_NO_OP;
		}

		$prior_refund = $this->find_prior_refund( $payload->payment_id() );
		if ( null === $prior_refund ) {
			$this->logger->warning(
				'Orphan LiqPay `reversed` callback — no matching refund row in DB',
				[
					'order_uuid' => $order->uuid,
					'payment_id' => $payload->payment_id(),
				]
			);
			return CallbackOutcome::OK_NO_OP;
		}

		$confirmation = new Payment(
			id: null,
			order_id: (int) $order->id,
			provider: PaymentProvider::LIQPAY,
			provider_payment_id: $payload->payment_id(),
			provider_action: $payload->action(),
			status: PaymentStatus::REVERSED,
			raw_provider_status: $payload->status(),
			transaction_type: PaymentTransactionType::REFUND,
			amount: $order->amount,
			raw_payload: $payload->raw_payload_json(),
			received_at: $this->now(),
			idempotency_key: $payload->to_idempotency_key()
		);

		try {
			$this->payments->insert( $confirmation );
		} catch ( PaymentAlreadyRecordedException ) {
			$this->logger->info(
				'LiqPay `reversed` callback confirmed prior sync refund (idempotency-key collision)',
				[
					'order_uuid'      => $order->uuid,
					'idempotency_key' => $confirmation->idempotency_key,
				]
			);
			return CallbackOutcome::OK_DUPLICATE;
		}

		// Reached only if the prior REFUND row carried a different idempotency
		// key (e.g. a future code path that diverges them). Defensive — we
		// still record the confirmation but do not transition.
		return CallbackOutcome::OK_PROCESSED;
	}

	private function find_prior_refund( string $provider_payment_id ): ?Payment {
		$matches = $this->payments->list_by_provider_payment_id( 'liqpay', $provider_payment_id );
		foreach ( $matches as $row ) {
			if ( PaymentTransactionType::REFUND === $row->transaction_type
				&& PaymentStatus::REVERSED === $row->status
			) {
				return $row;
			}
		}
		return null;
	}

	private function find_order( CallbackPayload $payload ): ?Order {
		$order = $this->orders->find_by_provider_reference( 'liqpay', $payload->order_id() );
		if ( null !== $order ) {
			return $order;
		}
		// Defensive fallback: in 8.1, `liqpay_order_id` is always set to the
		// order uuid, so a uuid lookup is equivalent. Kept so any data drift
		// (e.g. an order that lost its `liqpay_order_id` and was later
		// repaired) can still be matched on the wire-id alone.
		return $this->orders->find_by_uuid( $payload->order_id() );
	}

	private function maybe_transition(
		Order $order,
		PaymentStatus $payment_status,
		string $raw_status
	): ?Order {
		if ( PaymentStatus::SUCCESS === $payment_status || PaymentStatus::SANDBOX === $payment_status ) {
			return $this->transition_to_paid( $order );
		}
		if ( PaymentStatus::FAILURE === $payment_status || PaymentStatus::ERROR === $payment_status ) {
			return $this->transition_to_failed( $order, $raw_status );
		}
		// `wait_*`, `processing`, `other`: payment row already recorded, no transition.
		return null;
	}

	private function transition_to_paid( Order $order ): ?Order {
		if ( OrderStatus::PAID === $order->status ) {
			// Already PAID — likely a LiqPay retry that re-emits `success`
			// with a fresh `payment_id`. The new row is recorded; no
			// re-firing of `vl_lms_order_paid`.
			return null;
		}
		if ( OrderStatus::PENDING !== $order->status && OrderStatus::AWAITING_PAYMENT !== $order->status ) {
			$this->logger->warning(
				'LiqPay success callback for non-payable order — ignoring transition',
				[
					'order_uuid'     => $order->uuid,
					'current_status' => $order->status->value,
				]
			);
			return null;
		}

		$now = $this->now();
		$this->orders->update_status( (int) $order->id, OrderStatus::PAID, $now );

		$reloaded = $this->orders->find_by_id( (int) $order->id );
		return $reloaded ?? $order->mark_paid( $now );
	}

	private function transition_to_failed( Order $order, string $raw_status ): ?Order {
		if ( OrderStatus::PENDING !== $order->status && OrderStatus::AWAITING_PAYMENT !== $order->status ) {
			$this->logger->warning(
				'LiqPay failure callback for non-payable order — ignoring transition',
				[
					'order_uuid'     => $order->uuid,
					'current_status' => $order->status->value,
					'raw_status'     => $raw_status,
				]
			);
			return null;
		}

		$now = $this->now();
		$this->orders->update_status( (int) $order->id, OrderStatus::FAILED, $now );

		$reloaded = $this->orders->find_by_id( (int) $order->id );
		return $reloaded ?? $order->mark_failed( $now );
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
