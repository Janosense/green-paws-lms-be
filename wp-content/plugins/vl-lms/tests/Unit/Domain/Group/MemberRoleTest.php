<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\MemberRole;

final class MemberRoleTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: MemberRole}>
	 */
	public static function valid_strings(): array {
		return [
			'member'  => [ 'member', MemberRole::MEMBER ],
			'manager' => [ 'manager', MemberRole::MANAGER ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, MemberRole $expected ): void {
		self::assertSame( $expected, MemberRole::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown group member role "owner"' );

		MemberRole::from_string( 'owner' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			MemberRole::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( MemberRole::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
