<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\OrderNotFoundException;

final class OrderNotFoundExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new OrderNotFoundException( 'no such order' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'no such order', $ex->getMessage() );
	}
}
