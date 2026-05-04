<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\EntityNotPurchasableException;

final class EntityNotPurchasableExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new EntityNotPurchasableException( 'price is zero' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'price is zero', $ex->getMessage() );
	}
}
