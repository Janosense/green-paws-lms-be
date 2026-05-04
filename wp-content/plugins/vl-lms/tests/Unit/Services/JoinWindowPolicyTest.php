<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use VL\LMS\Services\JoinWindowPolicy;

final class JoinWindowPolicyTest extends TestCase {

	public function test_default_constants_are_15_and_60(): void {
		$policy = new JoinWindowPolicy();
		self::assertSame( 15, $policy->early_grace_minutes );
		self::assertSame( 60, $policy->late_grace_minutes );
	}

	public function test_compute_window_applies_default_grace(): void {
		$policy = new JoinWindowPolicy();
		$start  = new \DateTimeImmutable( '2026-05-15T18:00:00Z' );
		$end    = new \DateTimeImmutable( '2026-05-15T19:30:00Z' );

		[ $opens_at, $closes_at ] = $policy->compute_window( $start, $end );

		self::assertSame( '2026-05-15T17:45:00+00:00', $opens_at->format( \DateTimeInterface::ATOM ) );
		self::assertSame( '2026-05-15T20:30:00+00:00', $closes_at->format( \DateTimeInterface::ATOM ) );
	}

	public function test_compute_window_honors_overridden_constants(): void {
		$policy = new JoinWindowPolicy( early_grace_minutes: 5, late_grace_minutes: 10 );
		$start  = new \DateTimeImmutable( '2026-05-15T18:00:00Z' );
		$end    = new \DateTimeImmutable( '2026-05-15T18:30:00Z' );

		[ $opens_at, $closes_at ] = $policy->compute_window( $start, $end );

		self::assertSame( '2026-05-15T17:55:00+00:00', $opens_at->format( \DateTimeInterface::ATOM ) );
		self::assertSame( '2026-05-15T18:40:00+00:00', $closes_at->format( \DateTimeInterface::ATOM ) );
	}
}
