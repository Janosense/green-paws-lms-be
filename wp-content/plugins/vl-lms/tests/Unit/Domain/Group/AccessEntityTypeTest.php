<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessEntityType;

final class AccessEntityTypeTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: AccessEntityType}>
	 */
	public static function valid_strings(): array {
		return [
			'course'  => [ 'course', AccessEntityType::COURSE ],
			'webinar' => [ 'webinar', AccessEntityType::WEBINAR ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, AccessEntityType $expected ): void {
		self::assertSame( $expected, AccessEntityType::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown access entity type "lesson"' );

		AccessEntityType::from_string( 'lesson' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			AccessEntityType::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( AccessEntityType::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
