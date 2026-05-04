<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use VL\LMS\Api\OrderTransformer;
use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;

final class OrderTransformerTest extends TestCase {

	public function test_transforms_pending_order_with_all_fields(): void {
		$order = $this->order(
			status: OrderStatus::PENDING,
			paid_at: null,
			cancelled_at: null,
			refunded_at: null
		);

		$result = ( new OrderTransformer() )->transform( $order );

		self::assertSame( 'cafebabe-cafe-4cab-8cab-cafebabecafe', $result['uuid'] );
		self::assertSame( 'pending', $result['status'] );
		self::assertSame( 'liqpay', $result['payment_provider'] );
		self::assertSame( 'course', $result['entity_type'] );
		self::assertSame( 100, $result['entity_id'] );
		self::assertSame( 'web-design', $result['entity_slug'] );
		self::assertSame( 'Web Design', $result['entity_title_snapshot'] );
		self::assertSame(
			[
				'major'       => '1500.00',
				'minor_units' => 150000,
				'currency'    => 'UAH',
			],
			$result['amount']
		);
		self::assertSame( '2026-05-01T12:00:00+00:00', $result['created_at'] );
		self::assertSame( '2026-05-02T12:00:00+00:00', $result['expires_at'] );
		self::assertNull( $result['paid_at'] );
		self::assertNull( $result['cancelled_at'] );
		self::assertNull( $result['refunded_at'] );
	}

	public function test_excludes_internal_fields(): void {
		$result = ( new OrderTransformer() )->transform( $this->order() );

		self::assertArrayNotHasKey( 'id', $result );
		self::assertArrayNotHasKey( 'liqpay_order_id', $result );
		self::assertArrayNotHasKey( 'metadata', $result );
	}

	public function test_emits_iso_timestamps_for_terminal_dates(): void {
		$paid_at      = new \DateTimeImmutable( '2026-05-01 13:00:00', new \DateTimeZone( 'UTC' ) );
		$cancelled_at = new \DateTimeImmutable( '2026-05-01 14:00:00', new \DateTimeZone( 'UTC' ) );
		$refunded_at  = new \DateTimeImmutable( '2026-05-03 15:00:00', new \DateTimeZone( 'UTC' ) );

		$result = ( new OrderTransformer() )->transform(
			$this->order(
				status: OrderStatus::REFUNDED,
				paid_at: $paid_at,
				cancelled_at: $cancelled_at,
				refunded_at: $refunded_at
			)
		);

		self::assertSame( '2026-05-01T13:00:00+00:00', $result['paid_at'] );
		self::assertSame( '2026-05-01T14:00:00+00:00', $result['cancelled_at'] );
		self::assertSame( '2026-05-03T15:00:00+00:00', $result['refunded_at'] );
	}

	private function order(
		OrderStatus $status = OrderStatus::PENDING,
		?\DateTimeImmutable $paid_at = null,
		?\DateTimeImmutable $cancelled_at = null,
		?\DateTimeImmutable $refunded_at = null
	): Order {
		return new Order(
			id: 11,
			uuid: 'cafebabe-cafe-4cab-8cab-cafebabecafe',
			user_id: 7,
			status: $status,
			payment_provider: 'liqpay',
			liqpay_order_id: 'cafebabe-cafe-4cab-8cab-cafebabecafe',
			entity_type: PurchasableEntityType::COURSE,
			entity_id: 100,
			entity_slug: 'web-design',
			entity_title_snapshot: 'Web Design',
			amount: Money::from_major_decimal( '1500.00', 'UAH' ),
			created_at: new \DateTimeImmutable( '2026-05-01 12:00:00', new \DateTimeZone( 'UTC' ) ),
			expires_at: new \DateTimeImmutable( '2026-05-02 12:00:00', new \DateTimeZone( 'UTC' ) ),
			paid_at: $paid_at,
			cancelled_at: $cancelled_at,
			refunded_at: $refunded_at,
			metadata: [ 'secret' => 'not-emitted' ]
		);
	}
}
