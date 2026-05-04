<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Orders\Exception;

use PHPUnit\Framework\TestCase;
use VL\LMS\Orders\Exception\AlreadyRegisteredException;

final class AlreadyRegisteredExceptionTest extends TestCase {

	public function test_carries_existing_registration_id(): void {
		$ex = new AlreadyRegisteredException( 99 );

		self::assertInstanceOf( \RuntimeException::class, $ex );
		self::assertSame( 99, $ex->existing_registration_id() );
		self::assertSame( 'Learner already has an active registration for this webinar.', $ex->getMessage() );
	}
}
