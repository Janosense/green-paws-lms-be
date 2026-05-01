<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Zoom;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\Zoom\AccessToken;

final class AccessTokenTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public function test_is_expired_false_when_well_before_expiry(): void {
		$token = new AccessToken( 'tok', self::utc( '2026-04-23 10:30:00' ) );
		$now   = self::utc( '2026-04-23 10:00:00' );

		self::assertFalse( $token->is_expired( $now ) );
	}

	public function test_is_expired_true_at_expiry(): void {
		$token = new AccessToken( 'tok', self::utc( '2026-04-23 10:00:00' ) );
		$now   = self::utc( '2026-04-23 10:00:00' );

		self::assertTrue( $token->is_expired( $now ) );
	}

	public function test_is_expired_true_within_skew_window(): void {
		$token = new AccessToken( 'tok', self::utc( '2026-04-23 10:00:30' ) );
		$now   = self::utc( '2026-04-23 10:00:00' );

		// Default skew is 60s. 30s before expiry is inside the skew window.
		self::assertTrue( $token->is_expired( $now ) );
	}

	public function test_is_expired_respects_custom_skew(): void {
		$token = new AccessToken( 'tok', self::utc( '2026-04-23 10:00:30' ) );
		$now   = self::utc( '2026-04-23 10:00:00' );

		self::assertFalse( $token->is_expired( $now, 10 ) );
	}

	public function test_is_expired_false_outside_skew_window(): void {
		$token = new AccessToken( 'tok', self::utc( '2026-04-23 10:02:00' ) );
		$now   = self::utc( '2026-04-23 10:00:00' );

		self::assertFalse( $token->is_expired( $now ) );
	}
}
