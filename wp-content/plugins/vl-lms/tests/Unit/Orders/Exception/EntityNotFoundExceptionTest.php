<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\EntityNotFoundException;

final class EntityNotFoundExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new EntityNotFoundException( 'no such course' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'no such course', $ex->getMessage() );
	}
}
