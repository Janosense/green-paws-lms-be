<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Enrollment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentSource;

final class EnrollmentSourceTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: EnrollmentSource}>
	 */
	public static function valid_strings(): array {
		return [
			'manual'      => [ 'manual', EnrollmentSource::MANUAL ],
			'purchase'    => [ 'purchase', EnrollmentSource::PURCHASE ],
			'group'       => [ 'group', EnrollmentSource::GROUP ],
			'gift'        => [ 'gift', EnrollmentSource::GIFT ],
			'grant'       => [ 'grant', EnrollmentSource::GRANT ],
			'self_signup' => [ 'self_signup', EnrollmentSource::SELF_SIGNUP ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, EnrollmentSource $expected ): void {
		self::assertSame( $expected, EnrollmentSource::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown enrollment source "affiliate"' );

		EnrollmentSource::from_string( 'affiliate' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			EnrollmentSource::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( EnrollmentSource::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
