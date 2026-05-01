<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\WebinarRegistration;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;

final class WebinarRegistrationStatusTest extends TestCase {

	public function test_from_string_resolves_active(): void {
		self::assertSame( WebinarRegistrationStatus::ACTIVE, WebinarRegistrationStatus::from_string( 'active' ) );
	}

	public function test_from_string_resolves_cancelled(): void {
		self::assertSame( WebinarRegistrationStatus::CANCELLED, WebinarRegistrationStatus::from_string( 'cancelled' ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		WebinarRegistrationStatus::from_string( 'pending' );
	}
}
