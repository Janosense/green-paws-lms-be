<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\OrderNotOwnedException;

final class OrderNotOwnedExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new OrderNotOwnedException( 'belongs to user 5' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'belongs to user 5', $ex->getMessage() );
	}
}
