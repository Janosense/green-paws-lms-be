<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Refunds;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Domain\Payment\PaymentProvider as DomainPaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Refunds\OrderRefundEnrollmentRevoker;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use VL\LMS\Support\Logger;

final class OrderRefundEnrollmentRevokerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&EnrollmentService */
	private $enrollments;

	/** @var Mockery\MockInterface&WebinarRegistrationService */
	private $webinars;

	private OrderRefundEnrollmentRevoker $revoker;

	protected function setUp(): void {
		parent::setUp();
		$this->enrollments = Mockery::mock( EnrollmentService::class );
		$this->webinars    = Mockery::mock( WebinarRegistrationService::class );

		$logger = Mockery::mock( Logger::class );
		$logger->shouldIgnoreMissing();

		$this->revoker = new OrderRefundEnrollmentRevoker( $this->enrollments, $this->webinars, $logger );
	}

	public function test_course_refund_revokes_active_enrollment(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->once()
			->with( 7, 100 )
			->andReturn( $this->enrollment( EnrollmentStatus::ACTIVE ) );

		$this->enrollments->shouldReceive( 'revoke' )
			->once()
			->with( 11, 0, 'order_refunded' )
			->andReturn( $this->enrollment( EnrollmentStatus::REVOKED ) );

		$this->webinars->shouldNotReceive( 'revoke_for_refund' );

		$this->revoker->on_order_refunded( $order, $payment );
	}

	public function test_course_refund_no_op_when_enrollment_missing(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->once()
			->andReturn( null );

		$this->enrollments->shouldNotReceive( 'revoke' );

		$this->revoker->on_order_refunded( $order, $payment );
		self::assertTrue( true );
	}

	public function test_course_refund_no_op_when_enrollment_already_revoked(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->once()
			->andReturn( $this->enrollment( EnrollmentStatus::REVOKED ) );

		$this->enrollments->shouldNotReceive( 'revoke' );

		$this->revoker->on_order_refunded( $order, $payment );
		self::assertTrue( true );
	}

	public function test_course_refund_revokes_completed_enrollment(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->once()
			->andReturn( $this->enrollment( EnrollmentStatus::COMPLETED ) );

		$this->enrollments->shouldReceive( 'revoke' )
			->once()
			->with( 11, 0, 'order_refunded' )
			->andReturn( $this->enrollment( EnrollmentStatus::REVOKED ) );

		$this->revoker->on_order_refunded( $order, $payment );
	}

	public function test_webinar_refund_calls_revoke_for_refund(): void {
		$order   = $this->order( PurchasableEntityType::WEBINAR, 200, user_id: 9, id: 51 );
		$payment = $this->payment( $order );

		$this->webinars->shouldReceive( 'revoke_for_refund' )
			->once()
			->with( 9, 200, 51 )
			->andReturn( true );

		$this->enrollments->shouldNotReceive( 'find_for_user_and_course' );
		$this->enrollments->shouldNotReceive( 'revoke' );

		$this->revoker->on_order_refunded( $order, $payment );
	}

	public function test_webinar_refund_handles_idempotent_no_op(): void {
		$order   = $this->order( PurchasableEntityType::WEBINAR, 200, user_id: 9, id: 51 );
		$payment = $this->payment( $order );

		$this->webinars->shouldReceive( 'revoke_for_refund' )
			->once()
			->andReturn( false );

		$this->revoker->on_order_refunded( $order, $payment );
		self::assertTrue( true );
	}

	public function test_service_exception_bubbles_up(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->andReturn( $this->enrollment( EnrollmentStatus::ACTIVE ) );
		$this->enrollments->shouldReceive( 'revoke' )
			->andThrow( new \RuntimeException( 'database down' ) );

		$this->expectException( \RuntimeException::class );
		$this->revoker->on_order_refunded( $order, $payment );
	}

	private function order(
		PurchasableEntityType $type,
		int $entity_id,
		int $user_id,
		int $id
	): Order {
		return new Order(
			id: $id,
			uuid: '11111111-1111-4111-8111-111111111111',
			user_id: $user_id,
			status: OrderStatus::REFUNDED,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: $type,
			entity_id: $entity_id,
			entity_slug: 'sample',
			entity_title_snapshot: 'Sample',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01T12:00:00Z' ),
			expires_at: new \DateTimeImmutable( '2026-05-02T12:00:00Z' ),
			paid_at: new \DateTimeImmutable( '2026-05-01T12:30:00Z' ),
			refunded_at: new \DateTimeImmutable( '2026-05-04T12:00:00Z' )
		);
	}

	private function payment( Order $order ): Payment {
		return new Payment(
			id: 1,
			order_id: (int) $order->id,
			provider: DomainPaymentProvider::LIQPAY,
			provider_payment_id: '987654',
			provider_action: 'refund',
			status: PaymentStatus::REVERSED,
			raw_provider_status: 'reversed',
			transaction_type: PaymentTransactionType::REFUND,
			amount: $order->amount,
			raw_payload: '{}',
			received_at: new \DateTimeImmutable( '2026-05-04T12:00:00Z' ),
			idempotency_key: 'liqpay:987654:refund:reversed'
		);
	}

	private function enrollment( EnrollmentStatus $status ): Enrollment {
		return new Enrollment(
			id: 11,
			user_id: 7,
			course_id: 100,
			status: $status,
			source: EnrollmentSource::PURCHASE,
			source_group_id: null,
			source_order_id: 42,
			enrolled_at: '2026-05-01 12:30:00',
			started_at: null,
			completed_at: null,
			expires_at: null,
			revoked_at: null,
			revoked_by: null,
			revoke_reason: null,
			progress_pct: 0,
			created_at: '2026-05-01 12:30:00',
			updated_at: '2026-05-01 12:30:00'
		);
	}
}
