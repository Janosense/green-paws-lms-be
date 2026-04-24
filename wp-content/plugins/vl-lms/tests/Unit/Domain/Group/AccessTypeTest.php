<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Group;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Group\AccessType;

final class AccessTypeTest extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: AccessType}>
	 */
	public static function valid_strings(): array {
		return [
			'granted'          => [ 'granted', AccessType::GRANTED ],
			'purchased_by_org' => [ 'purchased_by_org', AccessType::PURCHASED_BY_ORG ],
		];
	}

	/**
	 * @dataProvider valid_strings
	 */
	public function test_from_string_resolves_every_case( string $input, AccessType $expected ): void {
		self::assertSame( $expected, AccessType::from_string( $input ) );
	}

	public function test_from_string_throws_on_unknown_value(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown access type "gifted"' );

		AccessType::from_string( 'gifted' );
	}

	public function test_exception_message_lists_valid_options(): void {
		try {
			AccessType::from_string( 'bogus' );
			self::fail( 'Expected InvalidArgumentException was not thrown.' );
		} catch ( \InvalidArgumentException $e ) {
			foreach ( AccessType::cases() as $case ) {
				self::assertStringContainsString( $case->value, $e->getMessage() );
			}
		}
	}
}
