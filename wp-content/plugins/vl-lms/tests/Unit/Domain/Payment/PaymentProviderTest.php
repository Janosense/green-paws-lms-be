<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Payment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Payment\PaymentProvider;

final class PaymentProviderTest extends TestCase {

	public function test_liqpay_value_is_stable_string(): void {
		self::assertSame( 'liqpay', PaymentProvider::LIQPAY->value );
	}

	public function test_from_string_returns_case_for_known_value(): void {
		self::assertSame( PaymentProvider::LIQPAY, PaymentProvider::from_string( 'liqpay' ) );
	}

	public function test_from_string_throws_for_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );

		PaymentProvider::from_string( 'stripe' );
	}
}
