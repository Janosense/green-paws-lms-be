<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Enrollment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;

final class EnrollmentStatusTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: EnrollmentStatus}>
	 */
	public static function valid_strings(): array {
		return [
			'active'    => [ 'active', EnrollmentStatus::ACTIVE ],
			'completed' => [ 'completed', EnrollmentStatus::COMPLETED ],
			'expired'   => [ 'expired', EnrollmentStatus::EXPIRED ],
			'revoked'   => [ 'revoked', EnrollmentStatus::REVOKED ],
			'refunded'  => [ 'refunded', EnrollmentStatus::REFUNDED ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, EnrollmentStatus $expected ): void {
		self::assertSame( $expected, EnrollmentStatus::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown enrollment status "pending"' );

		EnrollmentStatus::from_string( 'pending' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			EnrollmentStatus::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( EnrollmentStatus::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
