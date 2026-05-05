<?php

declare(strict_types=1);

namespace VL\LMS\Refunds;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider as DomainPaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Payments\RefundCapableProvider;
use VL\LMS\Refunds\Exception\OrderNotFoundForRefundException;
use VL\LMS\Refunds\Exception\OrderNotRefundableException;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Repositories\PaymentAlreadyRecordedException;
use VL\LMS\Repositories\PaymentRepository;
use VL\LMS\Support\Logger;

/**
 * Phase 8.3 — orchestrates the full refund flow.
 *
 * Responsibilities:
 * 1. Validate the order exists and is in a refundable state (PAID).
 * 2. Short-circuit on already-REFUNDED (idempotent).
 * 3. Call the {@see RefundCapableProvider} for the synchronous LiqPay HTTP round-trip.
 * 4. On provider HTTP failure / rejection: persist an ERROR audit row, leave
 *    the order in PAID, re-throw so the controller can surface 502.
 * 5. On success: persist the REFUND payment row, transition the order to
 *    REFUNDED, fire `vl_lms_order_refunded` for downstream listeners.
 *
 * The locked symmetry with {@see \VL\LMS\Payments\LiqPay\CallbackHandler} —
 * record-then-decide — keeps the audit trail complete even when something
 * later in the chain fails.
 *
 * Concrete (not final). Mockable in tests; `protected now()` is the only
 * non-DI seam.
 *
 * @author Tymofii Synianskyi
 */
class RefundService {

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly PaymentRepository $payments,
		private readonly RefundCapableProvider $refund_provider,
		private readonly Logger $logger
	) {
	}

	/**
	 * @throws OrderNotFoundForRefundException     When no order has the given uuid.
	 * @throws OrderNotRefundableException         When the order is not in PAID.
	 * @throws PaymentProviderUnavailableException When LiqPay credentials are missing.
	 * @throws PaymentProviderHttpException        On transport / non-2xx failure (audit row written).
	 * @throws PaymentProviderRejectedException    On LiqPay rejection (audit row written).
	 */
	public function refund_order( string $uuid ): Order {
		$order = $this->orders->find_by_uuid( $uuid );
		if ( null === $order ) {
			throw new OrderNotFoundForRefundException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing.
				sprintf( 'No order found with uuid "%s".', $uuid )
			);
		}

		// Idempotent return — admin clicked refund twice or a parallel job
		// already handled it. No LiqPay call, no audit row, no action firing.
		if ( OrderStatus::REFUNDED === $order->status ) {
			return $order;
		}

		if ( ! $order->status->is_refundable() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new OrderNotRefundableException( $order->status );
		}

		try {
			$refund_payment = $this->refund_provider->refund_payment( $order );
		} catch ( PaymentProviderHttpException | PaymentProviderRejectedException $ex ) {
			$this->record_error_row( $order, $ex );
			throw $ex;
		}
		// PaymentProviderUnavailableException intentionally NOT caught — the
		// LiqPay call never happened, so writing an audit row would be noise.

		try {
			$payment_id = $this->payments->insert( $refund_payment );
		} catch ( PaymentAlreadyRecordedException $ex ) {
			// Idempotency-key collision: somebody (a concurrent process, or
			// a `reversed` callback that arrived before this thread finished)
			// already recorded an equivalent refund row. Reload and return
			// the order without re-firing the action.
			$this->logger->info(
				'Refund insert raced — payment row already exists',
				[
					'order_uuid'      => $order->uuid,
					'idempotency_key' => $refund_payment->idempotency_key,
				]
			);
			return $this->reload_or_self( $order );
		}

		$persisted_payment = $refund_payment->with_id( $payment_id );

		$now = $this->now();
		$this->orders->update_status( (int) $order->id, OrderStatus::REFUNDED, $now );

		$reloaded = $this->orders->find_by_id( (int) $order->id );
		if ( null === $reloaded ) {
			$reloaded = $order->mark_refunded( $now );
		}

		/**
		 * Fires after a successful refund flips an order to REFUNDED.
		 *
		 * Listeners include {@see \VL\LMS\Refunds\OrderRefundEnrollmentRevoker}
		 * at priority 10 (which revokes the underlying enrollment / webinar
		 * registration). Future mailers should subscribe at priority ≥ 20.
		 *
		 * @param Order   $order           The post-transition order, freshly reloaded.
		 * @param Payment $refund_payment  The persisted REFUND payment row.
		 */
		do_action( 'vl_lms_order_refunded', $reloaded, $persisted_payment );

		return $reloaded;
	}

	private function record_error_row( Order $order, \Throwable $ex ): void {
		$payload = [
			'error_class'   => $ex::class,
			'error_message' => $ex->getMessage(),
		];
		if ( $ex instanceof PaymentProviderHttpException ) {
			$payload['http_status']   = $ex->http_status();
			$payload['response_body'] = $ex->response_body();
		}
		if ( $ex instanceof PaymentProviderRejectedException ) {
			$payload['provider_status']   = $ex->provider_status();
			$payload['provider_err_code'] = $ex->provider_err_code();
		}

		$encoded = wp_json_encode( $payload );
		if ( ! is_string( $encoded ) ) {
			$encoded = '';
		}

		$now             = $this->now();
		$idempotency_key = sprintf(
			'refund-error:%s:%s',
			$order->uuid,
			$now->format( 'YmdHis' ) . sprintf( '%06d', random_int( 0, 999999 ) )
		);

		$audit = new Payment(
			id: null,
			order_id: (int) $order->id,
			provider: DomainPaymentProvider::LIQPAY,
			provider_payment_id: null,
			provider_action: 'refund',
			status: PaymentStatus::ERROR,
			raw_provider_status: 'error',
			transaction_type: PaymentTransactionType::REFUND,
			amount: $order->amount,
			raw_payload: $encoded,
			received_at: $now,
			idempotency_key: $idempotency_key
		);

		try {
			$this->payments->insert( $audit );
		} catch ( PaymentAlreadyRecordedException ) {
			// Theoretically unreachable — idempotency key includes microsecond-
			// quality randomness — but defensively absorb so the original
			// exception still propagates with priority.
			$this->logger->warning(
				'Refund-error audit row collision (unexpected)',
				[ 'order_uuid' => $order->uuid ]
			);
		}
	}

	private function reload_or_self( Order $order ): Order {
		$reloaded = $this->orders->find_by_id( (int) $order->id );
		return $reloaded ?? $order;
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
