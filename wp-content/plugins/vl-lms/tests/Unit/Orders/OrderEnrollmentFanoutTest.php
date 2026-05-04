<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders;

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
use VL\LMS\Domain\Payment\PaymentProvider;
use VL\LMS\Domain\Payment\PaymentStatus;
use VL\LMS\Domain\Payment\PaymentTransactionType;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Orders\OrderEnrollmentFanout;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use VL\LMS\Support\Logger;

final class OrderEnrollmentFanoutTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var Mockery\MockInterface&EnrollmentService */
	private $enrollments;

	/** @var Mockery\MockInterface&WebinarRegistrationService */
	private $webinars;

	private OrderEnrollmentFanout $fanout;

	protected function setUp(): void {
		parent::setUp();
		$this->enrollments = Mockery::mock( EnrollmentService::class );
		$this->webinars    = Mockery::mock( WebinarRegistrationService::class );

		$logger = Mockery::mock( Logger::class );
		$logger->shouldIgnoreMissing();

		$this->fanout = new OrderEnrollmentFanout( $this->enrollments, $this->webinars, $logger );
	}

	public function test_course_order_calls_enroll_with_purchase_source(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'enroll' )
			->once()
			->with(
				Mockery::on(
					static function ( int $user_id ): bool {
						return 7 === $user_id;
					}
				),
				Mockery::on(
					static function ( int $course_id ): bool {
						return 100 === $course_id;
					}
				),
				EnrollmentSource::PURCHASE,
				null,
				42
			)
			->andReturn( $this->enrollment_row() );
		$this->webinars->shouldNotReceive( 'register_for_purchase' );

		$this->fanout->on_order_paid( $order, $payment );
	}

	public function test_webinar_order_calls_register_for_purchase(): void {
		$order   = $this->order( PurchasableEntityType::WEBINAR, 200, user_id: 9, id: 51 );
		$payment = $this->payment( $order );

		$this->webinars->shouldReceive( 'register_for_purchase' )
			->once()
			->with( 9, 200, 51 )
			->andReturn( $this->webinar_registration_row() );
		$this->enrollments->shouldNotReceive( 'enroll' );

		$this->fanout->on_order_paid( $order, $payment );
	}

	public function test_service_exception_bubbles_up(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'enroll' )
			->andThrow( new \RuntimeException( 'database down' ) );

		$this->expectException( \RuntimeException::class );
		$this->fanout->on_order_paid( $order, $payment );
	}

	public function test_payment_argument_is_accepted_but_not_consumed(): void {
		$order   = $this->order( PurchasableEntityType::COURSE, 100, user_id: 7, id: 42 );
		$payment = $this->payment( $order );

		$this->enrollments->shouldReceive( 'enroll' )->andReturn( $this->enrollment_row() );

		// Should not throw despite the listener not consuming `$payment`.
		$this->fanout->on_order_paid( $order, $payment );
		self::assertTrue( true );
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
			status: OrderStatus::PAID,
			payment_provider: 'liqpay',
			liqpay_order_id: '11111111-1111-4111-8111-111111111111',
			entity_type: $type,
			entity_id: $entity_id,
			entity_slug: 'sample',
			entity_title_snapshot: 'Sample',
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

	private function enrollment_row(): Enrollment {
		return new Enrollment(
			id: 1,
			user_id: 7,
			course_id: 100,
			status: EnrollmentStatus::ACTIVE,
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

	private function webinar_registration_row(): WebinarRegistration {
		return new WebinarRegistration(
			id: 1,
			webinar_id: 200,
			user_id: 9,
			status: WebinarRegistrationStatus::ACTIVE,
			source: WebinarRegistrationSource::PURCHASE,
			registered_at: '2026-05-01 12:30:00',
			cancelled_at: null,
			attended: false,
			attended_duration_seconds: 0,
			created_at: '2026-05-01 12:30:00',
			updated_at: '2026-05-01 12:30:00'
		);
	}
}
