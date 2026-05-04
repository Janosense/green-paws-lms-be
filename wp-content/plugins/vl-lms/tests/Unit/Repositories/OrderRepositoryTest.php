<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Repositories;

use Brain\Monkey;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Repositories\OrderRepository;

final class OrderRepositoryTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private OrderRepository $repo;

	/** @var Mockery\MockInterface */
	private $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ABSPATH' ) || define( 'ABSPATH', sys_get_temp_dir() . '/' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WP core constant shim for tests.
		defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

		$this->wpdb         = Mockery::mock();
		$this->wpdb->prefix = 'wp_';
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test double for $wpdb.
		$GLOBALS['wpdb'] = $this->wpdb;

		$this->repo = new OrderRepository();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @param array<string, mixed> $overrides
	 *
	 * @return array<string, mixed>
	 */
	private static function row( array $overrides = [] ): array {
		return array_merge(
			[
				'id'                    => '1',
				'uuid'                  => '00000000-0000-4000-8000-000000000001',
				'user_id'               => '7',
				'status'                => 'pending',
				'payment_provider'      => 'liqpay',
				'liqpay_order_id'       => null,
				'entity_type'           => 'course',
				'entity_id'             => '500',
				'entity_slug'           => 'sample',
				'entity_title_snapshot' => 'Sample Course',
				'amount'                => '1500.00',
				'currency'              => 'UAH',
				'created_at'            => '2026-05-01 12:00:00',
				'expires_at'            => '2026-05-02 12:00:00',
				'paid_at'               => null,
				'cancelled_at'          => null,
				'refunded_at'           => null,
				'metadata'              => null,
			],
			$overrides
		);
	}

	private static function pending_order(): Order {
		return new Order(
			null,
			'00000000-0000-4000-8000-000000000001',
			7,
			OrderStatus::PENDING,
			'liqpay',
			null,
			PurchasableEntityType::COURSE,
			500,
			'sample',
			'Sample Course',
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( '2026-05-01 12:00:00' ),
			self::utc( '2026-05-02 12:00:00' )
		);
	}

	public function test_insert_writes_row_and_returns_insert_id(): void {
		$captured_data = null;

		$this->wpdb->shouldReceive( 'insert' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured_data ): int {
					$captured_data = $data;
					return 1;
				}
			);
		$this->wpdb->insert_id = 99;

		$id = $this->repo->insert( self::pending_order() );

		self::assertSame( 99, $id );
		self::assertSame( '1500.00', $captured_data['amount'] );
		self::assertSame( 'UAH', $captured_data['currency'] );
		self::assertSame( 'pending', $captured_data['status'] );
		self::assertSame( 'course', $captured_data['entity_type'] );
		self::assertArrayNotHasKey( 'id', $captured_data, 'insert payload must omit id' );
	}

	public function test_insert_throws_when_order_already_has_id(): void {
		$order = self::pending_order()->with_id( 5 );

		$this->expectException( \DomainException::class );

		$this->repo->insert( $order );
	}

	public function test_update_writes_full_row(): void {
		$this->wpdb->shouldReceive( 'update' )->once()->andReturn( 1 );

		$order = self::pending_order()->with_id( 7 );

		self::assertTrue( $this->repo->update( $order ) );
	}

	public function test_update_throws_when_order_id_null(): void {
		$this->expectException( \DomainException::class );

		$this->repo->update( self::pending_order() );
	}

	public function test_update_provider_reference_invokes_wpdb_update(): void {
		$captured = null;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data, array $where ) use ( &$captured ): int {
					$captured = [
						'data'  => $data,
						'where' => $where,
					];
					return 1;
				}
			);

		self::assertTrue( $this->repo->update_provider_reference( 7, 'lp-ref' ) );
		self::assertSame( 'lp-ref', $captured['data']['liqpay_order_id'] );
		self::assertSame( 7, $captured['where']['id'] );
	}

	public function test_update_status_to_paid_writes_paid_at(): void {
		$captured = null;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured ): int {
					$captured = $data;
					return 1;
				}
			);

		self::assertTrue(
			$this->repo->update_status(
				7,
				OrderStatus::PAID,
				self::utc( '2026-05-01 13:00:00' )
			)
		);
		self::assertSame( 'paid', $captured['status'] );
		self::assertSame( '2026-05-01 13:00:00', $captured['paid_at'] );
		self::assertArrayNotHasKey( 'cancelled_at', $captured );
	}

	public function test_update_status_to_cancelled_writes_cancelled_at(): void {
		$captured = null;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured ): int {
					$captured = $data;
					return 1;
				}
			);

		$this->repo->update_status( 7, OrderStatus::CANCELLED, self::utc( '2026-05-01 13:00:00' ) );

		self::assertSame( 'cancelled', $captured['status'] );
		self::assertSame( '2026-05-01 13:00:00', $captured['cancelled_at'] );
	}

	public function test_update_status_to_refunded_writes_refunded_at(): void {
		$captured = null;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured ): int {
					$captured = $data;
					return 1;
				}
			);

		$this->repo->update_status( 7, OrderStatus::REFUNDED, self::utc( '2026-05-05 09:00:00' ) );

		self::assertSame( 'refunded', $captured['status'] );
		self::assertSame( '2026-05-05 09:00:00', $captured['refunded_at'] );
	}

	public function test_update_status_to_failed_only_writes_status(): void {
		$captured = null;
		$this->wpdb->shouldReceive( 'update' )
			->once()
			->andReturnUsing(
				function ( string $table, array $data ) use ( &$captured ): int {
					$captured = $data;
					return 1;
				}
			);

		$this->repo->update_status( 7, OrderStatus::FAILED );

		self::assertSame( 'failed', $captured['status'] );
		self::assertArrayNotHasKey( 'paid_at', $captured );
		self::assertArrayNotHasKey( 'cancelled_at', $captured );
		self::assertArrayNotHasKey( 'refunded_at', $captured );
	}

	public function test_find_by_id_returns_order_when_row_exists(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$order = $this->repo->find_by_id( 1 );

		self::assertNotNull( $order );
		self::assertSame( 1, $order->id );
		self::assertSame( '1500.00', $order->amount->to_major_decimal() );
	}

	public function test_find_by_id_returns_null_when_row_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		self::assertNull( $this->repo->find_by_id( 999 ) );
	}

	public function test_find_by_uuid_round_trips(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$order = $this->repo->find_by_uuid( '00000000-0000-4000-8000-000000000001' );

		self::assertNotNull( $order );
		self::assertSame( '00000000-0000-4000-8000-000000000001', $order->uuid );
	}

	public function test_find_by_provider_reference_round_trips(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row( [ 'liqpay_order_id' => 'lp-ref' ] ) );

		$order = $this->repo->find_by_provider_reference( 'liqpay', 'lp-ref' );

		self::assertNotNull( $order );
		self::assertSame( 'lp-ref', $order->liqpay_order_id );
	}

	public function test_find_by_provider_reference_returns_null_when_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		self::assertNull( $this->repo->find_by_provider_reference( 'liqpay', 'no-ref' ) );
	}

	public function test_list_for_user_returns_items_and_total(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static fn ( string $sql ): string => $sql
		);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '5' );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [ self::row( [ 'id' => '1' ] ), self::row( [ 'id' => '2' ] ) ] );

		$result = $this->repo->list_for_user( 7, null, 1, 2 );

		self::assertSame( 5, $result['total'] );
		self::assertCount( 2, $result['items'] );
	}

	public function test_list_for_user_with_empty_status_filter_short_circuits(): void {
		$this->wpdb->shouldNotReceive( 'get_var' );
		$this->wpdb->shouldNotReceive( 'get_results' );

		$result = $this->repo->list_for_user( 7, [], 1, 10 );

		self::assertSame( 0, $result['total'] );
		self::assertSame( [], $result['items'] );
	}

	public function test_list_for_user_with_explicit_statuses_passes_them_to_query(): void {
		$captured_count = null;
		$captured_page  = null;

		$this->wpdb->shouldReceive( 'prepare' )
			->andReturnUsing(
				function ( string $sql, ...$args ) use ( &$captured_count, &$captured_page ): string {
					if ( str_contains( $sql, 'COUNT' ) ) {
						$captured_count = $args;
					} else {
						$captured_page = $args;
					}
					return $sql;
				}
			);
		$this->wpdb->shouldReceive( 'get_var' )->once()->andReturn( '1' );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( [ self::row() ] );

		$result = $this->repo->list_for_user( 7, [ OrderStatus::PAID ], 1, 10 );

		self::assertSame( 1, $result['total'] );
		self::assertSame( [ 7, 'paid' ], $captured_count );
	}

	public function test_find_open_for_user_and_entity_round_trips(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( self::row() );

		$order = $this->repo->find_open_for_user_and_entity(
			7,
			PurchasableEntityType::COURSE,
			500
		);

		self::assertNotNull( $order );
	}

	public function test_find_open_for_user_and_entity_returns_null_when_missing(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_row' )->once()->andReturn( null );

		self::assertNull(
			$this->repo->find_open_for_user_and_entity(
				7,
				PurchasableEntityType::COURSE,
				500
			)
		);
	}

	public function test_list_expired_open_returns_orders(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_results' )
			->once()
			->andReturn( [ self::row( [ 'expires_at' => '2026-04-30 00:00:00' ] ) ] );

		$result = $this->repo->list_expired_open( self::utc( '2026-05-03 00:00:00' ) );

		self::assertCount( 1, $result );
	}

	public function test_list_expired_open_returns_empty_when_no_rows(): void {
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn ( string $sql ): string => $sql );
		$this->wpdb->shouldReceive( 'get_results' )->once()->andReturn( null );

		self::assertSame( [], $this->repo->list_expired_open( self::utc( '2026-05-03 00:00:00' ) ) );
	}
}
