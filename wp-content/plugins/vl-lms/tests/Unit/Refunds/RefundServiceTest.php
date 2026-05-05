<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Refunds;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
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
use VL\LMS\Refunds\RefundService;
use VL\LMS\Repositories\PaymentAlreadyRecordedException;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;
use VL\LMS\Tests\Fixtures\InMemoryPaymentRepository;

final class RefundServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryOrderRepository $orders;
	private InMemoryPaymentRepository $payments;

	/** @var Mockery\MockInterface&RefundCapableProvider */
	private $provider;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private RefundService $service;
	private \DateTimeImmutable $now;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias(
			static fn ( mixed $value ): string => (string) json_encode( $value )
		);

		$this->orders   = new InMemoryOrderRepository();
		$this->payments = new InMemoryPaymentRepository();
		$this->provider = Mockery::mock( RefundCapableProvider::class );
		$this->logger   = Mockery::mock( Logger::class );
		$this->logger->shouldIgnoreMissing();

		$this->now = new \DateTimeImmutable( '2026-05-04T12:00:00Z' );

		$now           = $this->now;
		$this->service = new class( $this->orders, $this->payments, $this->provider, $this->logger, $now ) extends RefundService {

			public function __construct(
				$orders,
				$payments,
				$provider,
				$logger,
				private readonly \DateTimeImmutable $clock
			) {
				parent::__construct( $orders, $payments, $provider, $logger );
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

	public function test_happy_path_inserts_refund_row_transitions_order_and_fires_action(): void {
		$id = $this->seed_paid_order( 'aaa-uuid' );

		$refund_payment = $this->refund_payment_vo( $id );
		$this->provider->shouldReceive( 'refund_payment' )
			->once()
			->andReturn( $refund_payment );

		Actions\expectDone( 'vl_lms_order_refunded' )->once();

		$result = $this->service->refund_order( 'aaa-uuid' );

		self::assertSame( OrderStatus::REFUNDED, $result->status );

		$persisted = $this->payments->list_for_order( $id );
		self::assertCount( 1, $persisted );
		self::assertSame( PaymentTransactionType::REFUND, $persisted[0]->transaction_type );
		self::assertSame( PaymentStatus::REVERSED, $persisted[0]->status );
	}

	public function test_already_refunded_short_circuits_without_provider_call(): void {
		$this->orders->seed(
			[
				'uuid'        => 'bbb-uuid',
				'status'      => OrderStatus::REFUNDED->value,
				'paid_at'     => '2026-05-01 12:00:00',
				'refunded_at' => '2026-05-03 12:00:00',
			]
		);

		$this->provider->shouldNotReceive( 'refund_payment' );
		Actions\expectDone( 'vl_lms_order_refunded' )->never();

		$result = $this->service->refund_order( 'bbb-uuid' );

		self::assertSame( OrderStatus::REFUNDED, $result->status );
		self::assertSame( [], $this->payments->list_for_order( 1 ) );
	}

	public function test_pending_order_throws_not_refundable(): void {
		$this->orders->seed(
			[
				'uuid'   => 'pending-uuid',
				'status' => OrderStatus::PENDING->value,
			]
		);

		$this->provider->shouldNotReceive( 'refund_payment' );

		try {
			$this->service->refund_order( 'pending-uuid' );
			self::fail( 'expected OrderNotRefundableException' );
		} catch ( OrderNotRefundableException $ex ) {
			self::assertSame( OrderStatus::PENDING, $ex->current_status() );
		}
	}

	public function test_failed_order_throws_not_refundable(): void {
		$this->orders->seed(
			[
				'uuid'   => 'failed-uuid',
				'status' => OrderStatus::FAILED->value,
			]
		);

		$this->expectException( OrderNotRefundableException::class );
		$this->service->refund_order( 'failed-uuid' );
	}

	public function test_cancelled_order_throws_not_refundable(): void {
		$this->orders->seed(
			[
				'uuid'   => 'cancelled-uuid',
				'status' => OrderStatus::CANCELLED->value,
			]
		);

		$this->expectException( OrderNotRefundableException::class );
		$this->service->refund_order( 'cancelled-uuid' );
	}

	public function test_expired_order_throws_not_refundable(): void {
		$this->orders->seed(
			[
				'uuid'   => 'expired-uuid',
				'status' => OrderStatus::EXPIRED->value,
			]
		);

		$this->expectException( OrderNotRefundableException::class );
		$this->service->refund_order( 'expired-uuid' );
	}

	public function test_unknown_uuid_throws_not_found(): void {
		$this->expectException( OrderNotFoundForRefundException::class );
		$this->service->refund_order( 'no-such-uuid' );
	}

	public function test_http_failure_writes_error_row_and_rethrows(): void {
		$id = $this->seed_paid_order( 'http-fail-uuid' );

		$this->provider->shouldReceive( 'refund_payment' )
			->andThrow( new PaymentProviderHttpException( 'timeout', 504, 'gateway timeout' ) );

		Actions\expectDone( 'vl_lms_order_refunded' )->never();

		try {
			$this->service->refund_order( 'http-fail-uuid' );
			self::fail( 'expected exception' );
		} catch ( PaymentProviderHttpException ) {
			// Order should remain PAID.
			$reloaded = $this->orders->find_by_uuid( 'http-fail-uuid' );
			self::assertNotNull( $reloaded );
			self::assertSame( OrderStatus::PAID, $reloaded->status );

			// An ERROR audit row should be present.
			$audits = $this->payments->list_for_order( $id );
			self::assertCount( 1, $audits );
			self::assertSame( PaymentStatus::ERROR, $audits[0]->status );
			self::assertSame( PaymentTransactionType::REFUND, $audits[0]->transaction_type );
		}
	}

	public function test_rejection_writes_error_row_and_rethrows(): void {
		$id = $this->seed_paid_order( 'reject-uuid' );

		$this->provider->shouldReceive( 'refund_payment' )
			->andThrow( new PaymentProviderRejectedException( 'rejected', 'failure', 'err_amount' ) );

		try {
			$this->service->refund_order( 'reject-uuid' );
			self::fail( 'expected exception' );
		} catch ( PaymentProviderRejectedException ) {
			$reloaded = $this->orders->find_by_uuid( 'reject-uuid' );
			self::assertNotNull( $reloaded );
			self::assertSame( OrderStatus::PAID, $reloaded->status );

			$audits = $this->payments->list_for_order( $id );
			self::assertCount( 1, $audits );
			self::assertSame( PaymentStatus::ERROR, $audits[0]->status );
		}
	}

	public function test_unavailable_provider_does_not_write_audit_row(): void {
		$id = $this->seed_paid_order( 'unavail-uuid' );

		$this->provider->shouldReceive( 'refund_payment' )
			->andThrow( new PaymentProviderUnavailableException( 'creds missing' ) );

		try {
			$this->service->refund_order( 'unavail-uuid' );
			self::fail( 'expected exception' );
		} catch ( PaymentProviderUnavailableException ) {
			$reloaded = $this->orders->find_by_uuid( 'unavail-uuid' );
			self::assertNotNull( $reloaded );
			self::assertSame( OrderStatus::PAID, $reloaded->status );

			self::assertSame( [], $this->payments->list_for_order( $id ) );
		}
	}

	public function test_idempotency_collision_on_insert_returns_order_without_refire(): void {
		$id = $this->seed_paid_order( 'collision-uuid' );

		$payments_throw = new class() extends \VL\LMS\Repositories\PaymentRepository {

			public function insert( \VL\LMS\Domain\Payment\Payment $payment ): int {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception in test fixture.
				throw new PaymentAlreadyRecordedException( $payment->idempotency_key );
			}
		};

		$service = new class( $this->orders, $payments_throw, $this->provider, $this->logger, $this->now ) extends RefundService {

			public function __construct(
				$orders,
				$payments,
				$provider,
				$logger,
				private readonly \DateTimeImmutable $clock
			) {
				parent::__construct( $orders, $payments, $provider, $logger );
			}

			protected function now(): \DateTimeImmutable {
				return $this->clock;
			}
		};

		$this->provider->shouldReceive( 'refund_payment' )
			->once()
			->andReturn( $this->refund_payment_vo( $id ) );

		Actions\expectDone( 'vl_lms_order_refunded' )->never();

		$order = $service->refund_order( 'collision-uuid' );
		// Order remains PAID — the duplicate-insert short-circuits before the transition.
		self::assertSame( OrderStatus::PAID, $order->status );
	}

	private function seed_paid_order( string $uuid ): int {
		return $this->orders->seed(
			[
				'uuid'    => $uuid,
				'status'  => OrderStatus::PAID->value,
				'paid_at' => '2026-05-01 12:00:00',
			]
		);
	}

	private function refund_payment_vo( int $order_id ): Payment {
		return new Payment(
			id: null,
			order_id: $order_id,
			provider: DomainPaymentProvider::LIQPAY,
			provider_payment_id: '987654',
			provider_action: 'refund',
			status: PaymentStatus::REVERSED,
			raw_provider_status: 'reversed',
			transaction_type: PaymentTransactionType::REFUND,
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			raw_payload: '{"status":"reversed","payment_id":987654}',
			received_at: $this->now,
			idempotency_key: 'liqpay:987654:refund:reversed'
		);
	}
}
