<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Orders\OrdersListTable;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;

final class OrdersListTableTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryOrderRepository $orders;

	private OrdersListTable $table;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn ( string $format, int $ts ): string => gmdate( $format, $ts ) );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, string $url ): string {
				return $url . '?' . http_build_query( is_array( $args ) ? $args : [] );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $path = '' ): string => 'http://example.test/wp-admin/' . $path );
		Functions\when( 'selected' )->alias(
			static fn ( $a, $b ): string => ( (string) $a === (string) $b ) ? ' selected="selected"' : ''
		);
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'get_userdata' )->justReturn( false );

		$this->orders = new InMemoryOrderRepository();
		$this->table  = new OrdersListTable( $this->orders );

		// Reset request superglobal for each test.
		$_REQUEST = [];
	}

	protected function tearDown(): void {
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private function seed_paid_order( int $user_id = 7 ): Order {
		return new Order(
			1,
			'00000000-0000-4000-8000-000000000001',
			$user_id,
			OrderStatus::PAID,
			'liqpay',
			'lp-1',
			PurchasableEntityType::COURSE,
			500,
			'sample-course',
			'Sample Course',
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( '2026-05-01 12:00:00' ),
			self::utc( '2026-05-02 12:00:00' )
		);
	}

	public function test_get_columns_returns_eight_documented_columns(): void {
		$columns = $this->table->get_columns();

		self::assertSame(
			[ 'cb', 'created_at', 'uuid', 'user_email', 'entity_title_snapshot', 'entity_type', 'amount', 'status' ],
			array_keys( $columns )
		);
	}

	public function test_get_sortable_columns_returns_four_documented_sortables(): void {
		$sortables = $this->table->get_sortable_columns();

		self::assertSame(
			[ 'created_at', 'amount', 'status', 'user_email' ],
			array_keys( $sortables )
		);
	}

	public function test_prepare_items_passes_filters_and_sort_to_repository(): void {
		$this->orders->seed( [ 'status' => OrderStatus::PAID->value ] );
		$this->orders->seed( [ 'status' => OrderStatus::PENDING->value ] );

		$_REQUEST = [
			'status'      => 'paid',
			'entity_type' => 'course',
			's'           => 'Sample',
			'orderby'     => 'amount',
			'order'       => 'asc',
			'paged'       => '1',
		];

		$this->table->prepare_items();

		// Only the PAID seeded order should round-trip through.
		self::assertCount( 1, $this->table->items );
	}

	public function test_prepare_items_falls_back_when_orderby_not_whitelisted(): void {
		$this->orders->seed();

		$_REQUEST = [ 'orderby' => 'malicious; DROP TABLE' ];

		$this->table->prepare_items();

		self::assertCount( 1, $this->table->items );
	}

	public function test_column_uuid_renders_link_to_detail_screen(): void {
		$html = $this->table->column_uuid( $this->seed_paid_order() );

		self::assertStringContainsString( 'page=vl-lms-order-detail', $html );
		self::assertStringContainsString( '00000000-0000-4000-8000-000000000001', $html );
		self::assertStringContainsString( '00000000…', $html );
	}

	public function test_column_user_email_caches_lookups(): void {
		$call_count = 0;
		Functions\when( 'get_userdata' )->alias(
			static function ( int $user_id ) use ( &$call_count ): \WP_User {
				++$call_count;
				$user             = new \WP_User();
				$user->ID         = $user_id;
				$user->user_email = 'cached@example.com';
				return $user;
			}
		);

		$order_a = $this->seed_paid_order( 7 );
		$order_b = $this->seed_paid_order( 7 );

		$this->table->column_user_email( $order_a );
		$this->table->column_user_email( $order_b );

		self::assertSame( 1, $call_count, 'get_userdata should be cached per request' );
	}

	public function test_column_user_email_returns_deleted_marker_when_user_missing(): void {
		Functions\when( 'get_userdata' )->justReturn( false );

		$html = $this->table->column_user_email( $this->seed_paid_order() );

		self::assertStringContainsString( 'видалено', $html );
	}

	public function test_column_amount_formats_major_decimal_and_currency(): void {
		$html = $this->table->column_amount( $this->seed_paid_order() );

		self::assertSame( '1500.00 UAH', $html );
	}

	public function test_column_status_renders_badge_with_status_class(): void {
		$html = $this->table->column_status( $this->seed_paid_order() );

		self::assertStringContainsString( 'vl-status-paid', $html );
		self::assertStringContainsString( 'Оплачено', $html );
	}

	public function test_column_entity_type_returns_ukrainian_label(): void {
		self::assertSame( 'Курс', $this->table->column_entity_type( $this->seed_paid_order() ) );
	}

	public function test_get_bulk_actions_is_empty(): void {
		self::assertSame( [], $this->table->get_bulk_actions() );
	}
}
