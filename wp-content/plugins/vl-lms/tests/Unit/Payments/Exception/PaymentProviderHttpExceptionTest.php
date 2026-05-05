<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Payments\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;

final class PaymentProviderHttpExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new PaymentProviderHttpException( 'transport failed' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'transport failed', $ex->getMessage() );
		self::assertNull( $ex->http_status() );
		self::assertNull( $ex->response_body() );
	}

	public function test_carries_status_and_body(): void {
		$ex = new PaymentProviderHttpException( 'HTTP 500', 500, '{"error":"server"}' );

		self::assertSame( 500, $ex->http_status() );
		self::assertSame( '{"error":"server"}', $ex->response_body() );
	}

	public function test_chains_previous(): void {
		$root = new \RuntimeException( 'root cause' );
		$ex   = new PaymentProviderHttpException( 'wrapped', null, null, $root );

		self::assertSame( $root, $ex->getPrevious() );
	}
}
