<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Auth\Verification;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Auth\Verification\VerificationToken;

final class VerificationTokenTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_constructor_preserves_fields(): void {
		$token = new VerificationToken( plain: 'abc', hash: 'def', expires_at: 1_700_000_000 );

		self::assertSame( 'abc', $token->plain );
		self::assertSame( 'def', $token->hash );
		self::assertSame( 1_700_000_000, $token->expires_at );
	}

	public function test_generate_returns_hash_that_matches_sha256_of_plain(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'supersecretvalue' );

		$token = VerificationToken::generate( 3600 );

		self::assertSame( 'supersecretvalue', $token->plain );
		self::assertSame( hash( 'sha256', 'supersecretvalue' ), $token->hash );
		self::assertGreaterThan( time() - 5, $token->expires_at );
		self::assertLessThanOrEqual( time() + 3600, $token->expires_at );
	}

	public function test_generate_clamps_ttl_to_at_least_60_seconds(): void {
		Functions\when( 'wp_generate_password' )->justReturn( 'x' );

		$token = VerificationToken::generate( 0 );

		self::assertGreaterThanOrEqual( time() + 60 - 5, $token->expires_at );
	}
}
