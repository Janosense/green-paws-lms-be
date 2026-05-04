<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Orders\OrderExpirationCron;
use VL\LMS\Support\Logger;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;

final class OrderExpirationCronTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryOrderRepository $orders;

	/** @var Mockery\MockInterface&Logger */
	private $logger;

	private \DateTimeImmutable $now;
	private OrderExpirationCron $cron;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->orders = new InMemoryOrderRepository();
		$this->logger = Mockery::mock( Logger::class );
		$this->logger->shouldIgnoreMissing();
		$this->now = new \DateTimeImmutable( '2026-05-02T13:00:00Z' );

		$now        = $this->now;
		$this->cron = new class( $this->orders, $this->logger, $now ) extends OrderExpirationCron {
			public function __construct( $orders, $logger, private readonly \DateTimeImmutable $clock ) {
				parent::__construct( $orders, $logger );
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

	public function test_on_tick_no_expired_orders_does_nothing(): void {
		Actions\expectDone( 'vl_lms_order_expired' )->never();

		$this->cron->on_tick();

		self::assertSame( 0, $this->orders->list_for_user( 1, null, 1, 100 )['total'] );
	}

	public function test_on_tick_expires_pending_orders_past_ttl(): void {
		$id_a      = $this->orders->seed(
			[
				'uuid'       => 'aaa',
				'status'     => OrderStatus::PENDING->value,
				'expires_at' => '2026-05-02 10:00:00',
			]
		);
		$id_b      = $this->orders->seed(
			[
				'uuid'       => 'bbb',
				'status'     => OrderStatus::AWAITING_PAYMENT->value,
				'expires_at' => '2026-05-02 12:00:00',
			]
		);
		$id_future = $this->orders->seed(
			[
				'uuid'       => 'ccc',
				'status'     => OrderStatus::PENDING->value,
				'expires_at' => '2026-05-03 12:00:00',
			]
		);

		Actions\expectDone( 'vl_lms_order_expired' )->twice();

		$this->cron->on_tick();

		self::assertSame(
			OrderStatus::EXPIRED,
			( $this->orders->find_by_id( $id_a ) ?? throw new \RuntimeException( 'missing' ) )->status
		);
		self::assertSame(
			OrderStatus::EXPIRED,
			( $this->orders->find_by_id( $id_b ) ?? throw new \RuntimeException( 'missing' ) )->status
		);
		self::assertSame(
			OrderStatus::PENDING,
			( $this->orders->find_by_id( $id_future ) ?? throw new \RuntimeException( 'missing' ) )->status
		);
	}

	public function test_on_tick_respects_tick_limit(): void {
		// Seed 105 expired orders; only 100 should be processed in one tick.
		$ids = [];
		for ( $i = 0; $i < 105; $i++ ) {
			$ids[] = $this->orders->seed(
				[
					'uuid'       => sprintf( 'uuid-%03d', $i ),
					'status'     => OrderStatus::PENDING->value,
					'expires_at' => '2026-05-02 10:00:00',
				]
			);
		}

		Actions\expectDone( 'vl_lms_order_expired' )->times( 100 );

		$this->cron->on_tick();

		$expired_count = 0;
		foreach ( $ids as $id ) {
			$row = $this->orders->find_by_id( $id );
			if ( null !== $row && OrderStatus::EXPIRED === $row->status ) {
				++$expired_count;
			}
		}
		self::assertSame( 100, $expired_count );
	}

	public function test_register_schedules_when_not_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( OrderExpirationCron::HOOK_NAME )
			->andReturn( false );
		Functions\expect( 'wp_schedule_event' )
			->once()
			->with( Mockery::type( 'int' ), OrderExpirationCron::SCHEDULE, OrderExpirationCron::HOOK_NAME )
			->andReturn( true );
		Functions\when( 'time' )->justReturn( 1714654800 );

		$this->cron->register();

		self::assertTrue( true );
	}

	public function test_register_skips_when_already_scheduled(): void {
		Functions\expect( 'wp_next_scheduled' )
			->once()
			->with( OrderExpirationCron::HOOK_NAME )
			->andReturn( 1714654800 );
		Functions\expect( 'wp_schedule_event' )->never();

		$this->cron->register();

		self::assertTrue( true );
	}

	public function test_unschedule_clears_hook(): void {
		Functions\expect( 'wp_clear_scheduled_hook' )
			->once()
			->with( OrderExpirationCron::HOOK_NAME );

		$this->cron->unschedule();

		self::assertTrue( true );
	}
}
