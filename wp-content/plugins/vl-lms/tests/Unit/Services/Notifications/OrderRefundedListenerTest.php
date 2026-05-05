<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Notifications;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Mail\OrderRefundedMailer;
use VL\LMS\Services\Notifications\OrderRefundedListener;
use VL\LMS\Support\Logger;

final class OrderRefundedListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&OrderRefundedMailer */
	private $mailer;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private OrderRefundedListener $listener;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->mailer = Mockery::mock( OrderRefundedMailer::class );
		$this->logger = Mockery::mock( Logger::class );
		$this->logger->shouldIgnoreMissing();

		$this->listener = new OrderRefundedListener( $this->mailer, $this->logger );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_invokes_mailer_with_order_and_refund_payment(): void {
		$order  = $this->order();
		$refund = $this->refund_payment( $order );

		$this->mailer->shouldReceive( 'send' )
			->once()
			->with( $order, $refund )
			->andReturn( true );

		$this->listener->on_order_refunded( $order, $refund );
	}

	public function test_logs_when_mailer_returns_false(): void {
		$order  = $this->order();
		$refund = $this->refund_payment( $order );

		$this->mailer->shouldReceive( 'send' )->once()->andReturn( false );

		$this->listener->on_order_refunded( $order, $refund );
		self::assertTrue( true );
	}

	public function test_mailer_exception_bubbles_up(): void {
		$order  = $this->order();
		$refund = $this->refund_payment( $order );

		$this->mailer->shouldReceive( 'send' )
			->andThrow( new \RuntimeException( 'wp_mail down' ) );

		$this->expectException( \RuntimeException::class );
		$this->listener->on_order_refunded( $order, $refund );
	}

	private function order(): Order {
		return new Order(
			id: 42,
			uuid: '11111111-1111-4111-8111-111111111111',
			user_id: 7,
			status: OrderStatus::REFUNDED,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'sample-course',
			entity_title_snapshot: 'Sample Course',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01T12:00:00Z' ),
			expires_at: new \DateTimeImmutable( '2026-05-02T12:00:00Z' ),
			paid_at: new \DateTimeImmutable( '2026-05-01T12:30:00Z' ),
			refunded_at: new \DateTimeImmutable( '2026-05-04T12:00:00Z' )
		);
	}

	private function refund_payment( Order $order ): Payment {
		return new Payment(
			id: 2,
			order_id: (int) $order->id,
			provider: PaymentProvider::LIQPAY,
			provider_payment_id: '999',
			provider_action: 'refund',
			status: PaymentStatus::REVERSED,
			raw_provider_status: 'reversed',
			transaction_type: PaymentTransactionType::REFUND,
			amount: $order->amount,
			raw_payload: '{}',
			received_at: new \DateTimeImmutable( '2026-05-04T12:00:00Z' ),
			idempotency_key: 'liqpay:999:refund:reversed'
		);
	}
}
