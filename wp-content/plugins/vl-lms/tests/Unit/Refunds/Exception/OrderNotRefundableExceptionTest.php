<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Refunds\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Refunds\Exception\OrderNotRefundableException;

final class OrderNotRefundableExceptionTest extends TestCase {

	public function test_carries_current_status(): void {
		$ex = new OrderNotRefundableException( OrderStatus::CANCELLED );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( OrderStatus::CANCELLED, $ex->current_status() );
	}

	public function test_can_construct_for_each_status(): void {
		foreach ( OrderStatus::cases() as $status ) {
			$ex = new OrderNotRefundableException( $status );
			self::assertSame( $status, $ex->current_status() );
		}
	}
}
