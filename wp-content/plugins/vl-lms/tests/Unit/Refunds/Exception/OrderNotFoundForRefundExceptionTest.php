<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Refunds\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Refunds\Exception\OrderNotFoundForRefundException;

final class OrderNotFoundForRefundExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new OrderNotFoundForRefundException( 'No order with uuid x' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'No order with uuid x', $ex->getMessage() );
	}
}
