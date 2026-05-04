<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Money;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Money\Money;

final class MoneyTest extends TestCase {

	public function test_constructor_stores_minor_units_and_currency(): void {
		$money = new Money( 150000, 'UAH' );

		self::assertSame( 150000, $money->amount_minor_units() );
		self::assertSame( 'UAH', $money->currency() );
	}

	public function test_constructor_rejects_negative_amount(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Money( -1, 'UAH' );
	}

	public function test_constructor_rejects_lowercase_currency(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Money( 100, 'uah' );
	}

	public function test_constructor_rejects_two_letter_currency(): void {
		$this->expectException( \InvalidArgumentException::class );

		new Money( 100, 'UA' );
	}

	public function test_from_major_decimal_round_trips_to_major_decimal(): void {
		$money = Money::from_major_decimal( '1500.00', 'UAH' );

		self::assertSame( '1500.00', $money->to_major_decimal() );
		self::assertSame( 150000, $money->amount_minor_units() );
	}

	public function test_from_major_decimal_accepts_no_fractional_part(): void {
		$money = Money::from_major_decimal( '42', 'EUR' );

		self::assertSame( '42.00', $money->to_major_decimal() );
		self::assertSame( 4200, $money->amount_minor_units() );
	}

	public function test_from_major_decimal_accepts_one_fractional_digit(): void {
		$money = Money::from_major_decimal( '0.5', 'USD' );

		self::assertSame( '0.50', $money->to_major_decimal() );
		self::assertSame( 50, $money->amount_minor_units() );
	}

	public function test_from_major_decimal_zero_round_trips(): void {
		$money = Money::from_major_decimal( '0.00', 'UAH' );

		self::assertTrue( $money->is_zero() );
		self::assertSame( '0.00', $money->to_major_decimal() );
	}

	public function test_from_major_decimal_rejects_three_decimal_digits(): void {
		$this->expectException( \InvalidArgumentException::class );

		Money::from_major_decimal( '1.234', 'UAH' );
	}

	public function test_from_major_decimal_rejects_empty_string(): void {
		$this->expectException( \InvalidArgumentException::class );

		Money::from_major_decimal( '', 'UAH' );
	}

	public function test_from_major_decimal_rejects_negative_string(): void {
		$this->expectException( \InvalidArgumentException::class );

		Money::from_major_decimal( '-1.00', 'UAH' );
	}

	public function test_from_major_decimal_rejects_non_numeric(): void {
		$this->expectException( \InvalidArgumentException::class );

		Money::from_major_decimal( 'abc', 'UAH' );
	}

	public function test_from_major_decimal_handles_one_paisa(): void {
		$money = Money::from_major_decimal( '0.01', 'UAH' );

		self::assertSame( 1, $money->amount_minor_units() );
		self::assertSame( '0.01', $money->to_major_decimal() );
	}

	public function test_from_major_decimal_handles_max_value(): void {
		$money = Money::from_major_decimal( '9999999999.99', 'UAH' );

		self::assertSame( 999999999999, $money->amount_minor_units() );
		self::assertSame( '9999999999.99', $money->to_major_decimal() );
	}

	public function test_equals_is_true_for_same_amount_and_currency(): void {
		$a = new Money( 150000, 'UAH' );
		$b = new Money( 150000, 'UAH' );

		self::assertTrue( $a->equals( $b ) );
	}

	public function test_equals_is_false_for_different_currency(): void {
		$a = new Money( 150000, 'UAH' );
		$b = new Money( 150000, 'USD' );

		self::assertFalse( $a->equals( $b ) );
	}

	public function test_equals_is_false_for_different_amount(): void {
		$a = new Money( 150000, 'UAH' );
		$b = new Money( 150001, 'UAH' );

		self::assertFalse( $a->equals( $b ) );
	}

	public function test_add_sums_same_currency(): void {
		$a   = new Money( 150000, 'UAH' );
		$b   = new Money( 50000, 'UAH' );
		$sum = $a->add( $b );

		self::assertSame( 200000, $sum->amount_minor_units() );
		self::assertSame( 'UAH', $sum->currency() );
	}

	public function test_add_throws_on_currency_mismatch(): void {
		$a = new Money( 100, 'UAH' );
		$b = new Money( 100, 'USD' );

		$this->expectException( \InvalidArgumentException::class );

		$a->add( $b );
	}

	public function test_is_zero_distinguishes_zero_from_one(): void {
		self::assertTrue( ( new Money( 0, 'UAH' ) )->is_zero() );
		self::assertFalse( ( new Money( 1, 'UAH' ) )->is_zero() );
	}
}
