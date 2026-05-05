<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\LiqPay;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider as DomainPaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Payments\LiqPay\CallbackHandler;
use VL\LMS\Payments\LiqPay\CallbackOutcome;
use VL\LMS\Payments\LiqPay\CallbackPayload;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;
use VL\LMS\Tests\Fixtures\InMemoryPaymentRepository;

final class CallbackHandlerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryOrderRepository $orders;
	private InMemoryPaymentRepository $payments;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private \DateTimeImmutable $now;
	private CallbackHandler $handler;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->orders   = new InMemoryOrderRepository();
		$this->payments = new InMemoryPaymentRepository();
		$this->logger   = Mockery::mock( Logger::class );
		$this->logger->shouldIgnoreMissing();
		$this->now = new \DateTimeImmutable( '2026-05-01T12:00:00Z' );

		$now           = $this->now;
		$this->handler = new class( $this->orders, $this->payments, $this->logger, $now ) extends CallbackHandler {
			public function __construct( $orders, $payments, $logger, private readonly \DateTimeImmutable $clock ) {
				parent::__construct( $orders, $payments, $logger );
			}
			protected function now(): \DateTimeImmutable {
				return $this->clock;
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_success_status_flips_order_to_paid_and_fires_action(): void {
		$order_id = $this->seed_pending_order( 'uuid-success', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-success', status: 'success', payment_id: '101' );

		Actions\expectDone( 'vl_lms_order_paid' )->once();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertSame(
			OrderStatus::PAID,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
	}

	public function test_sandbox_status_treated_like_success(): void {
		$order_id = $this->seed_pending_order( 'uuid-sandbox', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-sandbox', status: 'sandbox', payment_id: '102' );

		Actions\expectDone( 'vl_lms_order_paid' )->once();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertSame(
			OrderStatus::PAID,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
	}

	public function test_failure_status_flips_order_to_failed_without_firing_action(): void {
		$order_id = $this->seed_pending_order( 'uuid-fail', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-fail', status: 'failure', payment_id: '201' );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertSame(
			OrderStatus::FAILED,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
	}

	public function test_error_status_treated_like_failure(): void {
		$order_id = $this->seed_pending_order( 'uuid-error', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-error', status: 'error', payment_id: '202' );

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertSame(
			OrderStatus::FAILED,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
	}

	public function test_wait_secure_records_row_without_transition(): void {
		$order_id = $this->seed_pending_order( 'uuid-wait', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-wait', status: 'wait_secure', payment_id: '301' );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertSame(
			OrderStatus::PENDING,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
	}

	public function test_processing_records_row_without_transition(): void {
		$order_id = $this->seed_pending_order( 'uuid-proc', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-proc', status: 'processing', payment_id: '302' );

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertSame(
			OrderStatus::PENDING,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
	}

	public function test_reversed_callback_after_sync_refund_returns_duplicate(): void {
		// Phase 8.3 — the sync refund flow has already recorded a REFUND row
		// with idempotency key `liqpay:{payment_id}:refund:reversed`. The
		// callback's identical key collides on insert, which the handler
		// interprets as confirmation.
		$order_id = $this->seed_refunded_order( 'uuid-reversed', '1500.00' );
		$this->seed_prior_refund_row( $order_id, payment_id: '401' );

		$payload = $this->payload(
			order_id: 'uuid-reversed',
			status: 'reversed',
			payment_id: '401',
			action: 'refund'
		);

		Actions\expectDone( 'vl_lms_order_paid' )->never();
		Actions\expectDone( 'vl_lms_order_refunded' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_DUPLICATE, $outcome );
		self::assertSame(
			OrderStatus::REFUNDED,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
		// Only the seeded prior row exists; no duplicate insert succeeded.
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
	}

	public function test_orphan_reversed_callback_returns_no_op_without_writing_row(): void {
		// Phase 8.3 — no prior REFUND row in DB. LiqPay reversed a charge
		// we did not initiate; we log a warning and short-circuit.
		$order_id = $this->seed_paid_order( 'uuid-orphan', '1500.00' );

		$payload = $this->payload(
			order_id: 'uuid-orphan',
			status: 'reversed',
			payment_id: '402',
			action: 'refund'
		);

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_NO_OP, $outcome );
		// Order remains PAID — operator must investigate.
		self::assertSame(
			OrderStatus::PAID,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
		self::assertSame( [], $this->payments->list_for_order( $order_id ) );
	}

	public function test_missing_payment_id_returns_no_op(): void {
		$order_id = $this->seed_pending_order( 'uuid-no-pid', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-no-pid', status: 'success', payment_id: null );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_NO_OP, $outcome );
		self::assertSame( [], $this->payments->list_for_order( $order_id ) );
	}

	public function test_unknown_order_returns_unknown_order_outcome(): void {
		$payload = $this->payload( order_id: 'uuid-missing', status: 'success', payment_id: '500' );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_UNKNOWN_ORDER, $outcome );
	}

	public function test_currency_mismatch_returns_currency_mismatch_outcome(): void {
		$order_id = $this->seed_pending_order( 'uuid-cur', '1500.00', currency: 'UAH' );
		$payload  = $this->payload(
			order_id: 'uuid-cur',
			status: 'success',
			payment_id: '600',
			amount: '1500.00',
			currency: 'USD'
		);

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_CURRENCY_MISMATCH, $outcome );
		self::assertSame(
			OrderStatus::PENDING,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
		self::assertSame( [], $this->payments->list_for_order( $order_id ) );
	}

	public function test_amount_mismatch_returns_amount_mismatch_outcome(): void {
		$order_id = $this->seed_pending_order( 'uuid-amt', '1500.00' );
		$payload  = $this->payload(
			order_id: 'uuid-amt',
			status: 'success',
			payment_id: '700',
			amount: '1.00'
		);

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_AMOUNT_MISMATCH, $outcome );
		self::assertSame( [], $this->payments->list_for_order( $order_id ) );
	}

	public function test_duplicate_idempotency_key_returns_duplicate_no_transition(): void {
		$order_id = $this->seed_pending_order( 'uuid-dup', '1500.00' );

		// First success callback flips to PAID and inserts row.
		$payload_one = $this->payload( order_id: 'uuid-dup', status: 'success', payment_id: '101' );

		Actions\expectDone( 'vl_lms_order_paid' )->once();
		$outcome_one = $this->handler->handle( $payload_one );
		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome_one );

		// LiqPay retry — same payment_id, action, status → same idempotency key.
		$payload_two = $this->payload( order_id: 'uuid-dup', status: 'success', payment_id: '101' );

		$outcome_two = $this->handler->handle( $payload_two );
		self::assertSame( CallbackOutcome::OK_DUPLICATE, $outcome_two );
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
	}

	public function test_paid_order_with_new_payment_id_records_row_without_transition(): void {
		$order_id = $this->seed_paid_order( 'uuid-already-paid', '1500.00' );

		// LiqPay sometimes emits two `success` callbacks with different payment_ids.
		$payload = $this->payload( order_id: 'uuid-already-paid', status: 'success', payment_id: '999' );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
	}

	public function test_expired_order_with_success_callback_records_row_no_transition(): void {
		$order_id = $this->orders->seed(
			[
				'uuid'   => 'uuid-expired',
				'status' => OrderStatus::EXPIRED->value,
			]
		);

		$payload = $this->payload( order_id: 'uuid-expired', status: 'success', payment_id: '888' );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );
		self::assertCount( 1, $this->payments->list_for_order( $order_id ) );
		self::assertSame(
			OrderStatus::EXPIRED,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
	}

	public function test_unknown_status_falls_back_to_other_with_no_transition(): void {
		$order_id = $this->seed_pending_order( 'uuid-other', '1500.00' );
		$payload  = $this->payload( order_id: 'uuid-other', status: 'fancy_new_status', payment_id: '950' );

		Actions\expectDone( 'vl_lms_order_paid' )->never();

		$outcome = $this->handler->handle( $payload );

		self::assertSame( CallbackOutcome::OK_PROCESSED, $outcome );

		$rows = $this->payments->list_for_order( $order_id );
		self::assertCount( 1, $rows );
		self::assertSame( PaymentStatus::OTHER, $rows[0]->status );
		self::assertSame( 'fancy_new_status', $rows[0]->raw_provider_status );
		self::assertSame(
			OrderStatus::PENDING,
			( $this->orders->find_by_id( $order_id ) ?? throw new \RuntimeException( 'order missing' ) )->status
		);
	}

	private function seed_pending_order( string $uuid, string $amount, string $currency = 'UAH' ): int {
		return $this->orders->seed(
			[
				'uuid'            => $uuid,
				'liqpay_order_id' => $uuid,
				'status'          => OrderStatus::PENDING->value,
				'amount'          => $amount,
				'currency'        => $currency,
				'entity_type'     => PurchasableEntityType::COURSE->value,
			]
		);
	}

	private function seed_refunded_order( string $uuid, string $amount, string $currency = 'UAH' ): int {
		return $this->orders->seed(
			[
				'uuid'            => $uuid,
				'liqpay_order_id' => $uuid,
				'status'          => OrderStatus::REFUNDED->value,
				'amount'          => $amount,
				'currency'        => $currency,
				'entity_type'     => PurchasableEntityType::COURSE->value,
				'paid_at'         => '2026-05-01 11:00:00',
				'refunded_at'     => '2026-05-04 11:00:00',
			]
		);
	}

	private function seed_prior_refund_row( int $order_id, string $payment_id ): void {
		$row = new Payment(
			id: null,
			order_id: $order_id,
			provider: DomainPaymentProvider::LIQPAY,
			provider_payment_id: $payment_id,
			provider_action: 'refund',
			status: PaymentStatus::REVERSED,
			raw_provider_status: 'reversed',
			transaction_type: PaymentTransactionType::REFUND,
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			raw_payload: '{"status":"reversed"}',
			received_at: $this->now,
			idempotency_key: sprintf( 'liqpay:%s:refund:reversed', $payment_id )
		);
		$this->payments->insert( $row );
	}

	private function seed_paid_order( string $uuid, string $amount, string $currency = 'UAH' ): int {
		return $this->orders->seed(
			[
				'uuid'            => $uuid,
				'liqpay_order_id' => $uuid,
				'status'          => OrderStatus::PAID->value,
				'amount'          => $amount,
				'currency'        => $currency,
				'entity_type'     => PurchasableEntityType::COURSE->value,
				'paid_at'         => '2026-05-01 11:00:00',
			]
		);
	}

	private function payload(
		string $order_id,
		string $status,
		?string $payment_id,
		string $action = 'pay',
		string $amount = '1500.00',
		string $currency = 'UAH'
	): CallbackPayload {
		$raw = [
			'order_id'   => $order_id,
			'status'     => $status,
			'action'     => $action,
			'amount'     => $amount,
			'currency'   => $currency,
			'payment_id' => $payment_id,
		];
		return new CallbackPayload(
			order_id: $order_id,
			status: $status,
			action: $action,
			payment_id: $payment_id,
			amount: $amount,
			currency: $currency,
			raw_payload_json: (string) json_encode( $raw ),
			raw_payload: $raw
		);
	}
}
