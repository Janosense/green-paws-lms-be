<?php

declare(strict_types=1);

namespace VLJwtAuth\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use VLJwtAuth\Support\Hasher;

final class HasherTest extends TestCase {

	public function test_hash_is_deterministic_for_same_input(): void {
		$value = 'refresh-token-opaque-value';

		$first  = Hasher::hash( $value );
		$second = Hasher::hash( $value );

		$this->assertSame( $first, $second );
	}

	public function test_hash_differs_for_different_inputs(): void {
		$this->assertNotSame(
			Hasher::hash( 'token-a' ),
			Hasher::hash( 'token-b' )
		);
	}

	public function test_hash_returns_64_lowercase_hex_chars(): void {
		$hash = Hasher::hash( 'any-input' );

		$this->assertSame( 64, strlen( $hash ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash );
	}
}
