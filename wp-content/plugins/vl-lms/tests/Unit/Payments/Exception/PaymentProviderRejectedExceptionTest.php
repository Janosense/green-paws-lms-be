<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;

final class PaymentProviderRejectedExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new PaymentProviderRejectedException( 'rejected', 'failure', 'err_amount' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'rejected', $ex->getMessage() );
		self::assertSame( 'failure', $ex->provider_status() );
		self::assertSame( 'err_amount', $ex->provider_err_code() );
	}

	public function test_err_code_is_optional(): void {
		$ex = new PaymentProviderRejectedException( 'rejected', 'error' );

		self::assertSame( 'error', $ex->provider_status() );
		self::assertNull( $ex->provider_err_code() );
	}

	public function test_chains_previous(): void {
		$root = new \RuntimeException( 'root' );
		$ex   = new PaymentProviderRejectedException( 'wrapped', 'failure', null, $root );

		self::assertSame( $root, $ex->getPrevious() );
	}
}
