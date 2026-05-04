<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;

final class PaymentProviderUnavailableExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new PaymentProviderUnavailableException( 'liqpay credentials missing' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'liqpay credentials missing', $ex->getMessage() );
	}
}
