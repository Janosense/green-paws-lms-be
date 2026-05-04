<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Order;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;

final class OrderTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function pending_order(): Order {
		return new Order(
			null,
			'00000000-0000-4000-8000-000000000000',
			7,
			OrderStatus::PENDING,
			'liqpay',
			null,
			PurchasableEntityType::COURSE,
			500,
			'intro-to-veterinary-medicine',
			'Intro to Veterinary Medicine',
			Money::from_major_decimal( '1500.00', 'UAH' ),
			self::utc( '2026-05-01 12:00:00' ),
			self::utc( '2026-05-02 12:00:00' )
		);
	}

	public function test_with_id_attaches_pk_when_id_is_null(): void {
		$order = self::pending_order();

		$persisted = $order->with_id( 42 );

		self::assertNull( $order->id, 'original instance must remain unchanged' );
		self::assertSame( 42, $persisted->id );
	}

	public function test_with_id_throws_when_already_persisted(): void {
		$order = self::pending_order()->with_id( 1 );

		$this->expectException( \DomainException::class );

		$order->with_id( 2 );
	}

	public function test_with_provider_reference_sets_liqpay_id_on_pending(): void {
		$order = self::pending_order();

		$updated = $order->with_provider_reference( 'lp-ref-123' );

		self::assertNull( $order->liqpay_order_id );
		self::assertSame( 'lp-ref-123', $updated->liqpay_order_id );
	}

	public function test_with_provider_reference_throws_when_not_pending(): void {
		$order = self::pending_order()->mark_awaiting_payment( self::utc( '2026-05-01 12:05:00' ) );

		$this->expectException( \DomainException::class );

		$order->with_provider_reference( 'lp-ref-123' );
	}

	public function test_happy_path_lifecycle(): void {
		$order = self::pending_order()->with_id( 1 );

		$awaiting = $order->mark_awaiting_payment( self::utc( '2026-05-01 12:05:00' ) );
		self::assertSame( OrderStatus::AWAITING_PAYMENT, $awaiting->status );
		self::assertSame( OrderStatus::PENDING, $order->status, 'original must not mutate' );

		$paid = $awaiting->mark_paid( self::utc( '2026-05-01 12:10:00' ) );
		self::assertSame( OrderStatus::PAID, $paid->status );
		self::assertEquals( self::utc( '2026-05-01 12:10:00' ), $paid->paid_at );

		$refunded = $paid->mark_refunded( self::utc( '2026-05-05 09:00:00' ) );
		self::assertSame( OrderStatus::REFUNDED, $refunded->status );
		self::assertEquals( self::utc( '2026-05-05 09:00:00' ), $refunded->refunded_at );
		self::assertEquals( self::utc( '2026-05-01 12:10:00' ), $refunded->paid_at, 'paid_at must be preserved' );
	}

	public function test_mark_paid_accepts_pending_for_defensive_callback_path(): void {
		$order = self::pending_order();

		$paid = $order->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		self::assertSame( OrderStatus::PAID, $paid->status );
	}

	public function test_mark_paid_throws_on_terminal_status(): void {
		$order = self::pending_order()->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		$this->expectException( \DomainException::class );

		$order->mark_paid( self::utc( '2026-05-01 12:11:00' ) );
	}

	public function test_mark_failed_accepts_pending_and_awaiting(): void {
		$pending = self::pending_order();
		$failed1 = $pending->mark_failed( self::utc( '2026-05-01 12:10:00' ) );
		self::assertSame( OrderStatus::FAILED, $failed1->status );

		$awaiting = self::pending_order()->mark_awaiting_payment( self::utc( '2026-05-01 12:05:00' ) );
		$failed2  = $awaiting->mark_failed( self::utc( '2026-05-01 12:11:00' ) );
		self::assertSame( OrderStatus::FAILED, $failed2->status );
	}

	public function test_mark_failed_throws_on_paid(): void {
		$paid = self::pending_order()->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		$this->expectException( \DomainException::class );

		$paid->mark_failed( self::utc( '2026-05-01 12:11:00' ) );
	}

	public function test_mark_cancelled_sets_cancelled_at_on_pending(): void {
		$cancelled = self::pending_order()->mark_cancelled( self::utc( '2026-05-01 12:30:00' ) );

		self::assertSame( OrderStatus::CANCELLED, $cancelled->status );
		self::assertEquals( self::utc( '2026-05-01 12:30:00' ), $cancelled->cancelled_at );
	}

	public function test_mark_cancelled_throws_on_paid(): void {
		$paid = self::pending_order()->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		$this->expectException( \DomainException::class );

		$paid->mark_cancelled( self::utc( '2026-05-01 12:11:00' ) );
	}

	public function test_mark_expired_works_on_pending_and_awaiting(): void {
		$expired1 = self::pending_order()->mark_expired( self::utc( '2026-05-02 13:00:00' ) );
		self::assertSame( OrderStatus::EXPIRED, $expired1->status );

		$awaiting = self::pending_order()->mark_awaiting_payment( self::utc( '2026-05-01 12:05:00' ) );
		$expired2 = $awaiting->mark_expired( self::utc( '2026-05-02 13:00:00' ) );
		self::assertSame( OrderStatus::EXPIRED, $expired2->status );
	}

	public function test_mark_expired_throws_on_terminal(): void {
		$paid = self::pending_order()->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		$this->expectException( \DomainException::class );

		$paid->mark_expired( self::utc( '2026-05-02 13:00:00' ) );
	}

	public function test_mark_refunded_only_legal_on_paid(): void {
		$pending = self::pending_order();

		$this->expectException( \DomainException::class );

		$pending->mark_refunded( self::utc( '2026-05-05 09:00:00' ) );
	}

	public function test_invalid_transition_message_includes_current_and_requested(): void {
		$order = self::pending_order();

		try {
			$order->mark_refunded( self::utc( '2026-05-05 09:00:00' ) );
			self::fail( 'Expected DomainException' );
		} catch ( \DomainException $e ) {
			self::assertStringContainsString( 'mark_refunded', $e->getMessage() );
			self::assertStringContainsString( 'pending', $e->getMessage() );
		}
	}

	public function test_mark_paid_returns_new_instance_and_does_not_mutate_receiver(): void {
		$order = self::pending_order();

		$initial_status  = $order->status;
		$initial_paid_at = $order->paid_at;

		$paid = $order->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		self::assertSame( $initial_status, $order->status );
		self::assertSame( $initial_paid_at, $order->paid_at );
		self::assertNotSame( $order, $paid );
	}

	public function test_mark_awaiting_payment_throws_on_paid(): void {
		$paid = self::pending_order()->mark_paid( self::utc( '2026-05-01 12:10:00' ) );

		$this->expectException( \DomainException::class );

		$paid->mark_awaiting_payment( self::utc( '2026-05-01 12:11:00' ) );
	}
}
