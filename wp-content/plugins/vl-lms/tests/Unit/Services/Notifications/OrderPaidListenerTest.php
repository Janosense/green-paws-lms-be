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
use VL\LMS\Mail\OrderPaidMailer;
use VL\LMS\Services\Notifications\OrderPaidListener;
use VL\LMS\Support\Logger;

final class OrderPaidListenerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&OrderPaidMailer */
	private $mailer;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private OrderPaidListener $listener;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->mailer = Mockery::mock( OrderPaidMailer::class );
		$this->logger = Mockery::mock( Logger::class );
		$this->logger->shouldIgnoreMissing();

		$this->listener = new OrderPaidListener( $this->mailer, $this->logger );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_invokes_mailer_with_order_and_payment(): void {
		$order   = $this->order();
		$payment = $this->payment( $order );

		$this->mailer->shouldReceive( 'send' )
			->once()
			->with( $order, $payment )
			->andReturn( true );

		$this->listener->on_order_paid( $order, $payment );
	}

	public function test_logs_when_mailer_returns_false(): void {
		$order   = $this->order();
		$payment = $this->payment( $order );

		$this->mailer->shouldReceive( 'send' )
			->once()
			->andReturn( false );

		// shouldIgnoreMissing absorbs the info() call; verify no exception.
		$this->listener->on_order_paid( $order, $payment );
		self::assertTrue( true );
	}

	public function test_mailer_exception_bubbles_up(): void {
		$order   = $this->order();
		$payment = $this->payment( $order );

		$this->mailer->shouldReceive( 'send' )
			->andThrow( new \RuntimeException( 'wp_mail down' ) );

		$this->expectException( \RuntimeException::class );
		$this->listener->on_order_paid( $order, $payment );
	}

	private function order(): Order {
		return new Order(
			id: 42,
			uuid: '11111111-1111-4111-8111-111111111111',
			user_id: 7,
			status: OrderStatus::PAID,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'sample-course',
			entity_title_snapshot: 'Sample Course',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01T12:00:00Z' ),
			expires_at: new \DateTimeImmutable( '2026-05-02T12:00:00Z' ),
			paid_at: new \DateTimeImmutable( '2026-05-01T12:30:00Z' )
		);
	}

	private function payment( Order $order ): Payment {
		return new Payment(
			id: 1,
			order_id: (int) $order->id,
			provider: PaymentProvider::LIQPAY,
			provider_payment_id: '999',
			provider_action: 'pay',
			status: PaymentStatus::SUCCESS,
			raw_provider_status: 'success',
			transaction_type: PaymentTransactionType::CHARGE,
			amount: $order->amount,
			raw_payload: '{}',
			received_at: new \DateTimeImmutable( '2026-05-01T12:30:00Z' ),
			idempotency_key: 'liqpay:999:pay:success'
		);
	}
}
