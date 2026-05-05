<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Refunds\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Refunds\Exception\RefundAlreadyExistsException;

final class RefundAlreadyExistsExceptionTest extends TestCase {

	public function test_extends_runtime_exception(): void {
		$ex = new RefundAlreadyExistsException( 'duplicate refund detected' );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 'duplicate refund detected', $ex->getMessage() );
	}
}
