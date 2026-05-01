<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom\Sync;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\Sync\PasswordGenerator;

final class PasswordGeneratorTest extends TestCase {

	public function test_generated_password_is_ten_alphanumeric_characters(): void {
		$gen = new PasswordGenerator();
		$pw  = $gen->generate();

		self::assertSame( 10, strlen( $pw ) );
		self::assertMatchesRegularExpression( '/^[A-Za-z0-9]{10}$/', $pw );
	}

	public function test_generated_passwords_vary_across_calls(): void {
		$gen      = new PasswordGenerator();
		$attempts = 12;
		$seen     = [];
		for ( $i = 0; $i < $attempts; $i++ ) {
			$seen[] = $gen->generate();
		}
		// Even with a 62-char alphabet at length 10 the collision odds
		// across 12 draws are astronomically low; 6+ unique values is a
		// very loose floor that still catches a stuck RNG.
		self::assertGreaterThanOrEqual( 6, count( array_unique( $seen ) ) );
	}

	public function test_subclass_can_pin_random_source(): void {
		$gen = new class() extends PasswordGenerator {
			public int $calls = 0;
			protected function random_index( int $min, int $max ): int {
				++$this->calls;
				return 0;
			}
		};

		self::assertSame( 'AAAAAAAAAA', $gen->generate() );
		self::assertSame( 10, $gen->calls );
	}
}
