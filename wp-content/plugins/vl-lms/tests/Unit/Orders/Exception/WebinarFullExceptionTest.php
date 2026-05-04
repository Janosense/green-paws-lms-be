<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\WebinarFullException;

final class WebinarFullExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new WebinarFullException( 'capacity reached' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'capacity reached', $ex->getMessage() );
	}
}
