<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Orders\Exception\OrderNotCancellableException;

final class OrderNotCancellableExceptionTest extends TestCase {

	public function test_carries_current_status(): void {
		$ex = new OrderNotCancellableException( OrderStatus::PAID );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( OrderStatus::PAID, $ex->current_status() );
		self::assertSame( 'Order is in a terminal state and cannot be cancelled.', $ex->getMessage() );
	}
}
