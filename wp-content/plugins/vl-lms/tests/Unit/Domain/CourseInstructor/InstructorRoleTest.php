<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\CourseInstructor;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\CourseInstructor\InstructorRole;

final class InstructorRoleTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: InstructorRole}>
	 */
	public static function valid_strings(): array {
		return [
			'lead'          => [ 'lead', InstructorRole::LEAD ],
			'co_instructor' => [ 'co_instructor', InstructorRole::CO_INSTRUCTOR ],
			'assistant'     => [ 'assistant', InstructorRole::ASSISTANT ],
			'guest'         => [ 'guest', InstructorRole::GUEST ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, InstructorRole $expected ): void {
		self::assertSame( $expected, InstructorRole::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown instructor role "mentor"' );

		InstructorRole::from_string( 'mentor' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			InstructorRole::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( InstructorRole::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
