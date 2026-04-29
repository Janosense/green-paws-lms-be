<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Certificate;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Domain\Certificate\CertificateStatus;

final class CertificateStatusTest extends TestCase {

	public function test_cases_have_expected_values(): void {
		self::assertSame( 'active', CertificateStatus::ACTIVE->value );
		self::assertSame( 'revoked', CertificateStatus::REVOKED->value );
	}

	public function test_status_is_active_when_revoked_at_is_null(): void {
		$cert = self::build( null );
		self::assertSame( CertificateStatus::ACTIVE, $cert->status() );
	}

	public function test_status_is_revoked_when_revoked_at_is_set(): void {
		$cert = self::build( new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) ) );
		self::assertSame( CertificateStatus::REVOKED, $cert->status() );
	}

	private static function build( ?\DateTimeImmutable $revoked_at ): Certificate {
		$now = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new Certificate(
			1,
			'8e2c4d2a-0000-4000-8000-000000000001',
			5,
			7,
			11,
			$now,
			$revoked_at,
			null,
			null,
			[ 'course_title' => 'Course' ],
			null,
			$now,
			$now
		);
	}
}
