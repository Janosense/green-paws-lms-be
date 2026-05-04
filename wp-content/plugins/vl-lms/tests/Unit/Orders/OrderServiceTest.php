<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;
use VL\LMS\Orders\Exception\AlreadyEnrolledException;
use VL\LMS\Orders\Exception\AlreadyRegisteredException;
use VL\LMS\Orders\Exception\EntityNotFoundException;
use VL\LMS\Orders\Exception\EntityNotPurchasableException;
use VL\LMS\Orders\Exception\OrderNotCancellableException;
use VL\LMS\Orders\Exception\OrderNotFoundException;
use VL\LMS\Orders\Exception\OrderNotOwnedException;
use VL\LMS\Orders\Exception\WebinarFullException;
use VL\LMS\Orders\OrderService;
use VL\LMS\Orders\PriceResolver;
use VL\LMS\Orders\PurchasableLookup;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;
use VL\LMS\Payments\PaymentProvider;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\Webinars\WebinarRegistrationService;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;
use WP_Post;

final class OrderServiceTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryOrderRepository $orders;

	/** @var Mockery\MockInterface&EnrollmentService */
	private $enrollments;

	/** @var Mockery\MockInterface&PriceResolver */
	private $prices;

	/** @var Mockery\MockInterface&PurchasableLookup */
	private $lookup;

	/** @var Mockery\MockInterface&WebinarRegistrationService */
	private $webinars;

	/** @var Mockery\MockInterface&PaymentProvider */
	private $provider;

	private \DateTimeImmutable $now;

	private OrderService $service;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->orders      = new InMemoryOrderRepository();
		$this->enrollments = Mockery::mock( EnrollmentService::class );
		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->andReturn( null )
			->byDefault();
		$this->prices   = Mockery::mock( PriceResolver::class );
		$this->lookup   = Mockery::mock( PurchasableLookup::class );
		$this->webinars = Mockery::mock( WebinarRegistrationService::class );
		$this->provider = Mockery::mock( PaymentProvider::class );
		$this->now      = new \DateTimeImmutable( '2026-05-01 12:00:00', new \DateTimeZone( 'UTC' ) );

		$now           = $this->now;
		$uuid_seq      = 0;
		$this->service = new class(
			$this->orders,
			$this->prices,
			$this->lookup,
			$this->enrollments,
			$this->webinars,
			$this->provider,
			$now,
			$uuid_seq
		) extends OrderService {
			public function __construct(
				$orders,
				$prices,
				$lookup,
				$enrollments,
				$webinars,
				$provider,
				private readonly \DateTimeImmutable $clock,
				private int $uuid_seq
			) {
				parent::__construct( $orders, $prices, $lookup, $enrollments, $webinars, $provider );
			}

			protected function now(): \DateTimeImmutable {
				return $this->clock;
			}

			protected function generate_uuid(): string {
				++$this->uuid_seq;
				return sprintf( 'aaaaaaaa-aaaa-4aaa-8aaa-%012d', $this->uuid_seq );
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_create_for_purchase_course_happy_path(): void {
		$post = $this->course_post( 100, 'web-design', 'Web Design' );
		$this->lookup->shouldReceive( 'find' )
			->with( PurchasableEntityType::COURSE, 'web-design' )
			->andReturn( $post );
		$this->prices->shouldReceive( 'resolve' )
			->with( 100, PurchasableEntityType::COURSE )
			->andReturn( Money::from_major_decimal( '1500.00', 'UAH' ) );
		$prepared = new PreparedPayment(
			'https://liqpay.ua',
			'POST',
			[
				'data'      => 'd',
				'signature' => 's',
				'version'   => '3',
			]
		);
		$this->provider->shouldReceive( 'prepare_payment' )
			->once()
			->andReturn( $prepared );

		$result = $this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'web-design' );

		self::assertSame( 7, $result->order->user_id );
		self::assertSame( OrderStatus::PENDING, $result->order->status );
		self::assertSame( 'web-design', $result->order->entity_slug );
		self::assertSame( 'Web Design', $result->order->entity_title_snapshot );
		self::assertSame( '1500.00', $result->order->amount->to_major_decimal() );
		self::assertSame( $result->order->uuid, $result->order->liqpay_order_id );
		self::assertSame( $prepared, $result->prepared_payment );
		self::assertEquals(
			$this->now->modify( '+24 hours' )->format( 'Y-m-d H:i:s' ),
			$result->order->expires_at->format( 'Y-m-d H:i:s' )
		);
	}

	public function test_create_for_purchase_webinar_happy_path(): void {
		$post = $this->webinar_post( 200, 'live-ama', 'Live AMA' );
		$this->lookup->shouldReceive( 'find' )
			->with( PurchasableEntityType::WEBINAR, 'live-ama' )
			->andReturn( $post );
		$this->webinars->shouldReceive( 'find_active' )
			->with( 200, 7 )
			->andReturn( null );
		$this->prices->shouldReceive( 'resolve' )
			->andReturn( Money::from_major_decimal( '500.00', 'UAH' ) );
		$this->webinars->shouldReceive( 'has_capacity' )
			->with( 200 )
			->andReturn( true );
		$prepared = new PreparedPayment(
			'https://liqpay.ua',
			'POST',
			[
				'data'      => 'd',
				'signature' => 's',
				'version'   => '3',
			]
		);
		$this->provider->shouldReceive( 'prepare_payment' )->once()->andReturn( $prepared );

		$result = $this->service->create_for_purchase( 7, PurchasableEntityType::WEBINAR, 'live-ama' );

		self::assertSame( PurchasableEntityType::WEBINAR, $result->order->entity_type );
		self::assertSame( '500.00', $result->order->amount->to_major_decimal() );
	}

	public function test_idempotency_returns_existing_open_order_without_inserting(): void {
		$post        = $this->course_post( 100, 'web-design', 'Web Design' );
		$existing_id = $this->orders->seed(
			[
				'user_id'         => 7,
				'entity_type'     => PurchasableEntityType::COURSE->value,
				'entity_id'       => 100,
				'liqpay_order_id' => 'preset-uuid',
				'status'          => OrderStatus::PENDING->value,
				'uuid'            => 'preset-uuid',
			]
		);

		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$this->prices->shouldReceive( 'resolve' )->andReturn( Money::from_major_decimal( '1500.00', 'UAH' ) );
		$prepared = new PreparedPayment(
			'https://liqpay.ua',
			'POST',
			[
				'data'      => 'd',
				'signature' => 's',
				'version'   => '3',
			]
		);
		$this->provider->shouldReceive( 'prepare_payment' )
			->once()
			->andReturn( $prepared );

		$result = $this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'web-design' );

		self::assertSame( 'preset-uuid', $result->order->uuid );
		self::assertSame( $existing_id, $result->order->id );
		// No new row inserted: only the existing one exists.
		$page = $this->orders->list_for_user( 7, null, 1, 50 );
		self::assertSame( 1, $page['total'] );
	}

	public function test_idempotency_back_fills_missing_provider_reference(): void {
		$post        = $this->course_post( 100, 'web-design', 'Web Design' );
		$existing_id = $this->orders->seed(
			[
				'user_id'         => 7,
				'entity_type'     => PurchasableEntityType::COURSE->value,
				'entity_id'       => 100,
				'liqpay_order_id' => null,
				'status'          => OrderStatus::PENDING->value,
				'uuid'            => 'gap-uuid',
			]
		);

		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$this->prices->shouldReceive( 'resolve' )->andReturn( Money::from_major_decimal( '1500.00', 'UAH' ) );
		$this->provider->shouldReceive( 'prepare_payment' )
			->once()
			->andReturn(
				new PreparedPayment(
					'https://liqpay.ua',
					'POST',
					[
						'data'      => 'd',
						'signature' => 's',
						'version'   => '3',
					]
				)
			);

		$result = $this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'web-design' );

		self::assertSame( 'gap-uuid', $result->order->liqpay_order_id );
		self::assertSame( $existing_id, $result->order->id );
	}

	public function test_entity_not_found_throws(): void {
		$this->lookup->shouldReceive( 'find' )->andReturn( null );

		$this->expectException( EntityNotFoundException::class );
		$this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'missing' );
	}

	public function test_already_enrolled_throws_with_enrollment_id(): void {
		$post = $this->course_post( 100, 'web-design', 'Web Design' );
		$this->lookup->shouldReceive( 'find' )->andReturn( $post );

		$existing = $this->enrollment_row( 99, 7, 100, EnrollmentStatus::ACTIVE );
		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->with( 7, 100 )
			->andReturn( $existing );

		try {
			$this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'web-design' );
			self::fail( 'Expected AlreadyEnrolledException' );
		} catch ( AlreadyEnrolledException $e ) {
			self::assertSame( 99, $e->existing_enrollment_id() );
		}
	}

	public function test_revoked_enrollment_does_not_block_repurchase(): void {
		$post = $this->course_post( 100, 'web-design', 'Web Design' );
		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$this->enrollments->shouldReceive( 'find_for_user_and_course' )
			->with( 7, 100 )
			->andReturn( $this->enrollment_row( 50, 7, 100, EnrollmentStatus::REVOKED ) );
		$this->prices->shouldReceive( 'resolve' )->andReturn( Money::from_major_decimal( '1500.00', 'UAH' ) );
		$this->provider->shouldReceive( 'prepare_payment' )
			->andReturn(
				new PreparedPayment(
					'https://liqpay.ua',
					'POST',
					[
						'data'      => 'd',
						'signature' => 's',
						'version'   => '3',
					]
				)
			);

		$result = $this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'web-design' );

		self::assertSame( OrderStatus::PENDING, $result->order->status );
	}

	public function test_already_registered_throws_with_registration_id(): void {
		$post = $this->webinar_post( 200, 'live-ama', 'Live AMA' );
		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$registration = new WebinarRegistration(
			id: 42,
			webinar_id: 200,
			user_id: 7,
			status: WebinarRegistrationStatus::ACTIVE,
			source: WebinarRegistrationSource::SELF_SIGNUP,
			registered_at: '2026-04-01 00:00:00',
			cancelled_at: null,
			attended: false,
			attended_duration_seconds: 0,
			created_at: '2026-04-01 00:00:00',
			updated_at: '2026-04-01 00:00:00'
		);
		$this->webinars->shouldReceive( 'find_active' )
			->with( 200, 7 )
			->andReturn( $registration );

		try {
			$this->service->create_for_purchase( 7, PurchasableEntityType::WEBINAR, 'live-ama' );
			self::fail( 'Expected AlreadyRegisteredException' );
		} catch ( AlreadyRegisteredException $e ) {
			self::assertSame( 42, $e->existing_registration_id() );
		}
	}

	public function test_entity_not_purchasable_when_price_is_null(): void {
		$post = $this->course_post( 100, 'free-course', 'Free' );
		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$this->prices->shouldReceive( 'resolve' )->andReturn( null );

		$this->expectException( EntityNotPurchasableException::class );
		$this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'free-course' );
	}

	public function test_webinar_full_throws(): void {
		$post = $this->webinar_post( 200, 'live-ama', 'Live AMA' );
		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$this->webinars->shouldReceive( 'find_active' )->andReturn( null );
		$this->prices->shouldReceive( 'resolve' )->andReturn( Money::from_major_decimal( '500.00', 'UAH' ) );
		$this->webinars->shouldReceive( 'has_capacity' )->andReturn( false );

		$this->expectException( WebinarFullException::class );
		$this->service->create_for_purchase( 7, PurchasableEntityType::WEBINAR, 'live-ama' );
	}

	public function test_provider_unavailable_propagates_after_persist(): void {
		$post = $this->course_post( 100, 'web-design', 'Web Design' );
		$this->lookup->shouldReceive( 'find' )->andReturn( $post );
		$this->prices->shouldReceive( 'resolve' )->andReturn( Money::from_major_decimal( '1500.00', 'UAH' ) );
		$this->provider->shouldReceive( 'prepare_payment' )
			->once()
			->andThrow( new PaymentProviderUnavailableException( 'down' ) );

		try {
			$this->service->create_for_purchase( 7, PurchasableEntityType::COURSE, 'web-design' );
			self::fail( 'Expected PaymentProviderUnavailableException' );
		} catch ( PaymentProviderUnavailableException ) {
			// Order should be persisted in the repository even though prepare failed.
			$page = $this->orders->list_for_user( 7, null, 1, 50 );
			self::assertSame( 1, $page['total'] );
		}
	}

	public function test_cancel_pending_order_flips_to_cancelled(): void {
		$id = $this->orders->seed(
			[
				'user_id' => 7,
				'uuid'    => 'cancel-uuid',
				'status'  => OrderStatus::PENDING->value,
			]
		);

		$cancelled = $this->service->cancel( 7, 'cancel-uuid' );

		self::assertSame( OrderStatus::CANCELLED, $cancelled->status );
		self::assertSame( $id, $cancelled->id );
		self::assertNotNull( $cancelled->cancelled_at );
	}

	public function test_cancel_already_cancelled_is_idempotent(): void {
		$this->orders->seed(
			[
				'user_id'      => 7,
				'uuid'         => 'cancel-uuid',
				'status'       => OrderStatus::CANCELLED->value,
				'cancelled_at' => '2026-04-01 00:00:00',
			]
		);

		$result = $this->service->cancel( 7, 'cancel-uuid' );

		self::assertSame( OrderStatus::CANCELLED, $result->status );
		self::assertSame( '2026-04-01 00:00:00', $result->cancelled_at?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_cancel_paid_order_throws(): void {
		$this->orders->seed(
			[
				'user_id' => 7,
				'uuid'    => 'paid-uuid',
				'status'  => OrderStatus::PAID->value,
			]
		);

		try {
			$this->service->cancel( 7, 'paid-uuid' );
			self::fail( 'Expected OrderNotCancellableException' );
		} catch ( OrderNotCancellableException $e ) {
			self::assertSame( OrderStatus::PAID, $e->current_status() );
		}
	}

	public function test_cancel_expired_order_throws(): void {
		$this->orders->seed(
			[
				'user_id' => 7,
				'uuid'    => 'expired-uuid',
				'status'  => OrderStatus::EXPIRED->value,
			]
		);

		$this->expectException( OrderNotCancellableException::class );
		$this->service->cancel( 7, 'expired-uuid' );
	}

	public function test_cancel_unknown_uuid_throws(): void {
		$this->expectException( OrderNotFoundException::class );
		$this->service->cancel( 7, 'no-such-uuid' );
	}

	public function test_cancel_other_user_throws_not_owned(): void {
		$this->orders->seed(
			[
				'user_id' => 8,
				'uuid'    => 'someone-elses',
				'status'  => OrderStatus::PENDING->value,
			]
		);

		$this->expectException( OrderNotOwnedException::class );
		$this->service->cancel( 7, 'someone-elses' );
	}

	public function test_find_for_user_happy_path(): void {
		$this->orders->seed(
			[
				'user_id' => 7,
				'uuid'    => 'lookup-uuid',
			]
		);

		$order = $this->service->find_for_user( 7, 'lookup-uuid' );

		self::assertSame( 'lookup-uuid', $order->uuid );
	}

	public function test_find_for_user_not_found_throws(): void {
		$this->expectException( OrderNotFoundException::class );
		$this->service->find_for_user( 7, 'nope' );
	}

	public function test_find_for_user_owner_mismatch_throws(): void {
		$this->orders->seed(
			[
				'user_id' => 8,
				'uuid'    => 'others',
			]
		);

		$this->expectException( OrderNotOwnedException::class );
		$this->service->find_for_user( 7, 'others' );
	}

	public function test_list_for_user_clamps_per_page(): void {
		$this->orders->seed( [ 'user_id' => 7 ] );

		$page = $this->service->list_for_user( 7, null, 0, 9999 );

		self::assertSame( 1, $page['total'] );
	}

	private function enrollment_row( int $id, int $user_id, int $course_id, EnrollmentStatus $status ): Enrollment {
		return new Enrollment(
			id: $id,
			user_id: $user_id,
			course_id: $course_id,
			status: $status,
			source: EnrollmentSource::MANUAL,
			source_group_id: null,
			source_order_id: null,
			enrolled_at: '2026-01-01 00:00:00',
			started_at: null,
			completed_at: null,
			expires_at: null,
			revoked_at: null,
			revoked_by: null,
			revoke_reason: null,
			progress_pct: 0,
			created_at: '2026-01-01 00:00:00',
			updated_at: '2026-01-01 00:00:00'
		);
	}

	private function course_post( int $id, string $slug, string $title ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_title  = $title;
		$p->post_status = 'publish';
		return $p;
	}

	private function webinar_post( int $id, string $slug, string $title ): WP_Post {
		$p              = Mockery::mock( 'WP_Post' );
		$p->ID          = $id;
		$p->post_name   = $slug;
		$p->post_title  = $title;
		$p->post_status = 'publish';
		return $p;
	}
}
