<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\CourseInstructor;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;

final class InstructorEntityTypeTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: InstructorEntityType}>
	 */
	public static function valid_strings(): array {
		return [
			'course'  => [ 'course', InstructorEntityType::COURSE ],
			'webinar' => [ 'webinar', InstructorEntityType::WEBINAR ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, InstructorEntityType $expected ): void {
		self::assertSame( $expected, InstructorEntityType::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown instructor entity type "lesson"' );

		InstructorEntityType::from_string( 'lesson' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			InstructorEntityType::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( InstructorEntityType::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
