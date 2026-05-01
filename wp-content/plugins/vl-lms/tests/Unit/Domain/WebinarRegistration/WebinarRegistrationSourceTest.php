<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\WebinarRegistration;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;

final class WebinarRegistrationSourceTest extends TestCase {

	/**
	 * @return list<array{string, WebinarRegistrationSource}>
	 */
	public static function known_values(): array {
		return [
			[ 'self_signup', WebinarRegistrationSource::SELF_SIGNUP ],
			[ 'manual', WebinarRegistrationSource::MANUAL ],
			[ 'purchase', WebinarRegistrationSource::PURCHASE ],
			[ 'gift', WebinarRegistrationSource::GIFT ],
			[ 'grant', WebinarRegistrationSource::GRANT ],
		];
	}

	/**
	 * @dataProvider known_values
	 */
	public function test_from_string_resolves_known_value( string $value, WebinarRegistrationSource $expected ): void {
		self::assertSame( $expected, WebinarRegistrationSource::from_string( $value ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		WebinarRegistrationSource::from_string( 'affiliate' );
	}
}
