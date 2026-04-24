<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\GroupStatus;

final class GroupStatusTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: GroupStatus}>
	 */
	public static function valid_strings(): array {
		return [
			'active'   => [ 'active', GroupStatus::ACTIVE ],
			'archived' => [ 'archived', GroupStatus::ARCHIVED ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, GroupStatus $expected ): void {
		self::assertSame( $expected, GroupStatus::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown group status "deleted"' );

		GroupStatus::from_string( 'deleted' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			GroupStatus::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( GroupStatus::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
