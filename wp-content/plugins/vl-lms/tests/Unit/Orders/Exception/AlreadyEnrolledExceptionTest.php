<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\AlreadyEnrolledException;

final class AlreadyEnrolledExceptionTest extends TestCase {

	public function test_carries_existing_enrollment_id(): void {
		$ex = new AlreadyEnrolledException( 42 );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 42, $ex->existing_enrollment_id() );
		self::assertSame( 'Learner already has an active enrollment for this course.', $ex->getMessage() );
	}

	public function test_supports_custom_message(): void {
		$ex = new AlreadyEnrolledException( 7, 'custom' );

		self::assertSame( 'custom', $ex->getMessage() );
		self::assertSame( 7, $ex->existing_enrollment_id() );
	}
}
