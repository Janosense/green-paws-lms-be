<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\GroupType;

final class GroupTypeTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: GroupType}>
	 */
	public static function valid_strings(): array {
		return [
			'cohort'       => [ 'cohort', GroupType::COHORT ],
			'organization' => [ 'organization', GroupType::ORGANIZATION ],
			'ad_hoc'       => [ 'ad_hoc', GroupType::AD_HOC ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, GroupType $expected ): void {
		self::assertSame( $expected, GroupType::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown group type "team"' );

		GroupType::from_string( 'team' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			GroupType::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( GroupType::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
