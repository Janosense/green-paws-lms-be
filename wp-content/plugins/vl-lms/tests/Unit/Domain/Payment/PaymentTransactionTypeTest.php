<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Payment\PaymentTransactionType;

final class PaymentTransactionTypeTest extends TestCase {

	public function test_case_values_are_stable_strings(): void {
		self::assertSame( 'charge', PaymentTransactionType::CHARGE->value );
		self::assertSame( 'refund', PaymentTransactionType::REFUND->value );
	}

	public function test_is_refund_true_only_for_refund(): void {
		self::assertFalse( PaymentTransactionType::CHARGE->is_refund() );
		self::assertTrue( PaymentTransactionType::REFUND->is_refund() );
	}

	public function test_from_string_returns_case_for_known_value(): void {
		self::assertSame( PaymentTransactionType::CHARGE, PaymentTransactionType::from_string( 'charge' ) );
	}

	public function test_from_string_throws_for_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );

		PaymentTransactionType::from_string( 'authorize' );
	}
}
