<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Order;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\OrderStatus;

final class OrderStatusTest extends TestCase {

	public function test_case_values_are_stable_strings(): void {
		self::assertSame( 'pending', OrderStatus::PENDING->value );
		self::assertSame( 'awaiting_payment', OrderStatus::AWAITING_PAYMENT->value );
		self::assertSame( 'paid', OrderStatus::PAID->value );
		self::assertSame( 'failed', OrderStatus::FAILED->value );
		self::assertSame( 'cancelled', OrderStatus::CANCELLED->value );
		self::assertSame( 'expired', OrderStatus::EXPIRED->value );
		self::assertSame( 'refunded', OrderStatus::REFUNDED->value );
	}

	public function test_from_string_throws_for_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );

		OrderStatus::from_string( 'not-a-status' );
	}

	public function test_from_string_returns_case_for_valid_value(): void {
		self::assertSame( OrderStatus::PAID, OrderStatus::from_string( 'paid' ) );
	}

	public function test_try_from_returns_null_for_unknown_value(): void {
		self::assertNull( OrderStatus::tryFrom( 'not-a-status' ) );
	}

	public function test_is_terminal_correct_across_all_cases(): void {
		self::assertFalse( OrderStatus::PENDING->is_terminal() );
		self::assertFalse( OrderStatus::AWAITING_PAYMENT->is_terminal() );
		self::assertTrue( OrderStatus::PAID->is_terminal() );
		self::assertTrue( OrderStatus::FAILED->is_terminal() );
		self::assertTrue( OrderStatus::CANCELLED->is_terminal() );
		self::assertTrue( OrderStatus::EXPIRED->is_terminal() );
		self::assertTrue( OrderStatus::REFUNDED->is_terminal() );
	}

	public function test_is_open_correct_across_all_cases(): void {
		self::assertTrue( OrderStatus::PENDING->is_open() );
		self::assertTrue( OrderStatus::AWAITING_PAYMENT->is_open() );
		self::assertFalse( OrderStatus::PAID->is_open() );
		self::assertFalse( OrderStatus::FAILED->is_open() );
		self::assertFalse( OrderStatus::CANCELLED->is_open() );
		self::assertFalse( OrderStatus::EXPIRED->is_open() );
		self::assertFalse( OrderStatus::REFUNDED->is_open() );
	}

	public function test_is_successful_only_paid(): void {
		self::assertTrue( OrderStatus::PAID->is_successful() );
		foreach ( OrderStatus::cases() as $case ) {
			if ( OrderStatus::PAID === $case ) {
				continue;
			}
			self::assertFalse( $case->is_successful(), sprintf( '%s should not be successful', $case->value ) );
		}
	}

	public function test_is_refundable_only_paid(): void {
		self::assertTrue( OrderStatus::PAID->is_refundable() );
		foreach ( OrderStatus::cases() as $case ) {
			if ( OrderStatus::PAID === $case ) {
				continue;
			}
			self::assertFalse( $case->is_refundable(), sprintf( '%s should not be refundable', $case->value ) );
		}
	}
}
