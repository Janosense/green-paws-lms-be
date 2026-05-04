<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\InvalidEntityTypeException;

final class InvalidEntityTypeExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new InvalidEntityTypeException( 'unknown type "tutorial"' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'unknown type "tutorial"', $ex->getMessage() );
	}
}
