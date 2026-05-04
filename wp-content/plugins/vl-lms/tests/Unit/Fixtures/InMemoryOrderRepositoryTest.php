<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;

final class InMemoryOrderRepositoryTest extends TestCase {

	private InMemoryOrderRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new InMemoryOrderRepository();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function pending_order( int $user_id = 7, int $entity_id = 500, string $created_at = '2026-05-01 12:00:00' ): Order {
		return new Order(
			null,
			'00000000-0000-4000-8000-' . str_pad( (string) $entity_id, 12, '0', STR_PAD_LEFT ),
			$user_id,
			OrderStatus::PENDING,
			'liqpay',
			null,
			PurchasableEntityType::COURSE,
			$entity_id,
			'sample-course',
			'Sample Course',
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( $created_at ),
			self::utc( '2026-05-02 12:00:00' )
		);
	}

	public function test_insert_assigns_id_and_round_trips(): void {
		$order = self::pending_order();

		$id = $this->repo->insert( $order );

		self::assertSame( 1, $id );
		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( 7, $loaded->user_id );
		self::assertSame( 500, $loaded->entity_id );
		self::assertSame( '1500.00', $loaded->amount->to_major_decimal() );
		self::assertSame( 'UAH', $loaded->amount->currency() );
		self::assertSame( OrderStatus::PENDING, $loaded->status );
	}

	public function test_insert_rejects_already_persisted_order(): void {
		$order = self::pending_order()->with_id( 99 );

		$this->expectException( \DomainException::class );

		$this->repo->insert( $order );
	}

	public function test_find_by_id_returns_null_for_unknown_id(): void {
		self::assertNull( $this->repo->find_by_id( 999 ) );
	}

	public function test_find_by_uuid_returns_null_for_unknown_uuid(): void {
		self::assertNull( $this->repo->find_by_uuid( 'no-such-uuid' ) );
	}

	public function test_find_by_uuid_round_trips(): void {
		$order = self::pending_order();
		$id    = $this->repo->insert( $order );

		$loaded = $this->repo->find_by_uuid( $order->uuid );

		self::assertNotNull( $loaded );
		self::assertSame( $id, $loaded->id );
	}

	public function test_update_replaces_columns(): void {
		$id    = $this->repo->insert( self::pending_order() );
		$order = $this->repo->find_by_id( $id );
		self::assertNotNull( $order );

		$paid = $order->mark_paid( self::utc( '2026-05-01 12:30:00' ) );

		self::assertTrue( $this->repo->update( $paid ) );

		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( OrderStatus::PAID, $loaded->status );
		self::assertEquals( self::utc( '2026-05-01 12:30:00' ), $loaded->paid_at );
	}

	public function test_update_returns_false_for_unknown_id(): void {
		$order = self::pending_order()->with_id( 999 );

		self::assertFalse( $this->repo->update( $order ) );
	}

	public function test_update_provider_reference_sets_liqpay_id(): void {
		$id = $this->repo->insert( self::pending_order() );

		self::assertTrue( $this->repo->update_provider_reference( $id, 'lp-ref-123' ) );

		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( 'lp-ref-123', $loaded->liqpay_order_id );
	}

	public function test_find_by_provider_reference_round_trips(): void {
		$id = $this->repo->insert( self::pending_order() );
		$this->repo->update_provider_reference( $id, 'lp-ref-123' );

		$loaded = $this->repo->find_by_provider_reference( 'liqpay', 'lp-ref-123' );

		self::assertNotNull( $loaded );
		self::assertSame( $id, $loaded->id );
	}

	public function test_find_by_provider_reference_returns_null_for_missing(): void {
		self::assertNull( $this->repo->find_by_provider_reference( 'liqpay', 'no-such-ref' ) );
	}

	public function test_update_status_to_paid_sets_paid_at_with_supplied_timestamp(): void {
		$id = $this->repo->insert( self::pending_order() );

		self::assertTrue(
			$this->repo->update_status(
				$id,
				OrderStatus::PAID,
				self::utc( '2026-05-01 13:00:00' )
			)
		);

		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( OrderStatus::PAID, $loaded->status );
		self::assertEquals( self::utc( '2026-05-01 13:00:00' ), $loaded->paid_at );
	}

	public function test_update_status_to_cancelled_sets_cancelled_at(): void {
		$id = $this->repo->insert( self::pending_order() );

		$this->repo->update_status( $id, OrderStatus::CANCELLED, self::utc( '2026-05-01 13:00:00' ) );

		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( OrderStatus::CANCELLED, $loaded->status );
		self::assertEquals( self::utc( '2026-05-01 13:00:00' ), $loaded->cancelled_at );
	}

	public function test_update_status_to_refunded_sets_refunded_at(): void {
		$id = $this->repo->seed( [ 'status' => OrderStatus::PAID->value ] );

		$this->repo->update_status( $id, OrderStatus::REFUNDED, self::utc( '2026-05-05 09:00:00' ) );

		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( OrderStatus::REFUNDED, $loaded->status );
		self::assertEquals( self::utc( '2026-05-05 09:00:00' ), $loaded->refunded_at );
	}

	public function test_update_status_to_failed_only_changes_status(): void {
		$id = $this->repo->insert( self::pending_order() );

		$this->repo->update_status( $id, OrderStatus::FAILED );

		$loaded = $this->repo->find_by_id( $id );
		self::assertNotNull( $loaded );
		self::assertSame( OrderStatus::FAILED, $loaded->status );
		self::assertNull( $loaded->paid_at );
		self::assertNull( $loaded->cancelled_at );
		self::assertNull( $loaded->refunded_at );
	}

	public function test_update_status_returns_false_for_unknown_id(): void {
		self::assertFalse( $this->repo->update_status( 999, OrderStatus::PAID ) );
	}

	public function test_list_for_user_with_null_status_filter_returns_all(): void {
		$this->repo->seed(
			[
				'user_id'    => 1,
				'status'     => OrderStatus::PAID->value,
				'created_at' => '2026-05-01 10:00:00',
			]
		);
		$this->repo->seed(
			[
				'user_id'    => 1,
				'status'     => OrderStatus::PENDING->value,
				'created_at' => '2026-05-02 10:00:00',
			]
		);
		$this->repo->seed(
			[
				'user_id'    => 2,
				'status'     => OrderStatus::PAID->value,
				'created_at' => '2026-05-01 11:00:00',
			]
		);

		$result = $this->repo->list_for_user( 1, null, 1, 10 );

		self::assertSame( 2, $result['total'] );
		self::assertCount( 2, $result['items'] );
		// Newest first.
		self::assertSame( OrderStatus::PENDING, $result['items'][0]->status );
		self::assertSame( OrderStatus::PAID, $result['items'][1]->status );
	}

	public function test_list_for_user_filters_by_statuses(): void {
		$this->repo->seed(
			[
				'user_id' => 1,
				'status'  => OrderStatus::PAID->value,
			]
		);
		$this->repo->seed(
			[
				'user_id' => 1,
				'status'  => OrderStatus::PENDING->value,
			]
		);
		$this->repo->seed(
			[
				'user_id' => 1,
				'status'  => OrderStatus::CANCELLED->value,
			]
		);

		$result = $this->repo->list_for_user( 1, [ OrderStatus::PAID, OrderStatus::CANCELLED ], 1, 10 );

		self::assertSame( 2, $result['total'] );
		$statuses = array_map( static fn ( Order $o ): OrderStatus => $o->status, $result['items'] );
		self::assertContains( OrderStatus::PAID, $statuses );
		self::assertContains( OrderStatus::CANCELLED, $statuses );
	}

	public function test_list_for_user_paginates(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->repo->seed(
				[
					'user_id'    => 1,
					'created_at' => sprintf( '2026-05-0%d 10:00:00', $i ),
				]
			);
		}

		$page1 = $this->repo->list_for_user( 1, null, 1, 2 );
		$page2 = $this->repo->list_for_user( 1, null, 2, 2 );
		$page3 = $this->repo->list_for_user( 1, null, 3, 2 );

		self::assertSame( 5, $page1['total'] );
		self::assertCount( 2, $page1['items'] );
		self::assertCount( 2, $page2['items'] );
		self::assertCount( 1, $page3['items'] );
	}

	public function test_list_for_user_with_empty_statuses_returns_empty_page(): void {
		$this->repo->seed( [ 'user_id' => 1 ] );

		$result = $this->repo->list_for_user( 1, [], 1, 10 );

		self::assertSame( 0, $result['total'] );
		self::assertSame( [], $result['items'] );
	}

	public function test_find_open_for_user_and_entity_returns_null_when_no_open_order(): void {
		$this->repo->seed(
			[
				'user_id'   => 1,
				'entity_id' => 500,
				'status'    => OrderStatus::PAID->value,
			]
		);

		$result = $this->repo->find_open_for_user_and_entity(
			1,
			PurchasableEntityType::COURSE,
			500
		);

		self::assertNull( $result );
	}

	public function test_find_open_for_user_and_entity_returns_pending(): void {
		$this->repo->seed(
			[
				'user_id'    => 1,
				'entity_id'  => 500,
				'status'     => OrderStatus::PENDING->value,
				'created_at' => '2026-05-01 12:00:00',
			]
		);

		$result = $this->repo->find_open_for_user_and_entity(
			1,
			PurchasableEntityType::COURSE,
			500
		);

		self::assertNotNull( $result );
		self::assertSame( OrderStatus::PENDING, $result->status );
	}

	public function test_find_open_for_user_and_entity_returns_most_recent_when_multiple(): void {
		$this->repo->seed(
			[
				'user_id'    => 1,
				'entity_id'  => 500,
				'status'     => OrderStatus::PENDING->value,
				'created_at' => '2026-05-01 12:00:00',
			]
		);
		$newer_id = $this->repo->seed(
			[
				'user_id'    => 1,
				'entity_id'  => 500,
				'status'     => OrderStatus::AWAITING_PAYMENT->value,
				'created_at' => '2026-05-01 14:00:00',
			]
		);

		$result = $this->repo->find_open_for_user_and_entity(
			1,
			PurchasableEntityType::COURSE,
			500
		);

		self::assertNotNull( $result );
		self::assertSame( $newer_id, $result->id );
		self::assertSame( OrderStatus::AWAITING_PAYMENT, $result->status );
	}

	public function test_list_expired_open_returns_only_open_past_expiry(): void {
		$now = self::utc( '2026-05-03 00:00:00' );

		$expired_pending  = $this->repo->seed(
			[
				'status'     => OrderStatus::PENDING->value,
				'expires_at' => '2026-05-01 12:00:00',
			]
		);
		$expired_awaiting = $this->repo->seed(
			[
				'status'     => OrderStatus::AWAITING_PAYMENT->value,
				'expires_at' => '2026-05-02 12:00:00',
			]
		);
		$this->repo->seed(
			[
				'status'     => OrderStatus::PENDING->value,
				'expires_at' => '2026-05-04 12:00:00', // future
			]
		);
		$this->repo->seed(
			[
				'status'     => OrderStatus::PAID->value,
				'expires_at' => '2026-05-01 12:00:00', // expired but terminal
			]
		);

		$result = $this->repo->list_expired_open( $now, 10 );

		self::assertCount( 2, $result );
		// expires_at ASC: pending first.
		self::assertSame( $expired_pending, $result[0]->id );
		self::assertSame( $expired_awaiting, $result[1]->id );
	}

	public function test_list_expired_open_respects_limit(): void {
		$now = self::utc( '2026-05-03 00:00:00' );

		for ( $i = 1; $i <= 5; $i++ ) {
			$this->repo->seed(
				[
					'status'     => OrderStatus::PENDING->value,
					'expires_at' => sprintf( '2026-05-0%d 00:00:00', $i ),
				]
			);
		}

		$result = $this->repo->list_expired_open( $now, 2 );

		self::assertCount( 2, $result );
	}
}
