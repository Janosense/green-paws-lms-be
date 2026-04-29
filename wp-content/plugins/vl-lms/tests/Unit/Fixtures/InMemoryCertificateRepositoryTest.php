<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Domain\Certificate\CertificateStatus;
use VL\LMS\Tests\Fixtures\InMemoryCertificateRepository;

final class InMemoryCertificateRepositoryTest extends TestCase {

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function cert(
		string $uuid = '8e2c4d2a-0000-4000-8000-000000000001',
		int $user_id = 5,
		int $course_id = 7,
		int $enrollment_id = 21,
		?\DateTimeImmutable $revoked_at = null,
		string $issued_at = '2026-04-28 10:00:00'
	): Certificate {
		$now = self::utc( '2026-04-28 10:00:00' );
		return new Certificate(
			0,
			$uuid,
			$user_id,
			$course_id,
			$enrollment_id,
			self::utc( $issued_at ),
			$revoked_at,
			85,
			100,
			[ 'course_title' => 'Course' ],
			null,
			$now,
			$now
		);
	}

	public function test_insert_and_find_round_trip(): void {
		$repo = new InMemoryCertificateRepository();
		$id   = $repo->insert( self::cert() );

		self::assertSame( 1, $id );
		$found = $repo->find( 1 );
		self::assertNotNull( $found );
		self::assertSame( '8e2c4d2a-0000-4000-8000-000000000001', $found->uuid );
		self::assertSame( CertificateStatus::ACTIVE, $found->status() );
	}

	public function test_find_by_uuid_returns_match(): void {
		$repo = new InMemoryCertificateRepository();
		$repo->insert( self::cert( 'uuid-a' ) );
		$repo->insert( self::cert( 'uuid-b' ) );

		$found = $repo->find_by_uuid( 'uuid-b' );
		self::assertNotNull( $found );
		self::assertSame( 'uuid-b', $found->uuid );
	}

	public function test_find_by_uuid_returns_null_when_no_match(): void {
		$repo = new InMemoryCertificateRepository();
		self::assertNull( $repo->find_by_uuid( 'missing' ) );
	}

	public function test_find_active_for_user_in_course_skips_revoked(): void {
		$repo = new InMemoryCertificateRepository();
		$repo->insert( self::cert( 'uuid-a', 5, 7, 21, self::utc( '2026-04-29 09:00:00' ), '2026-04-28 10:00:00' ) );
		$id_active = $repo->insert( self::cert( 'uuid-b', 5, 7, 21, null, '2026-04-30 10:00:00' ) );

		$found = $repo->find_active_for_user_in_course( 5, 7 );
		self::assertNotNull( $found );
		self::assertSame( $id_active, $found->id );
	}

	public function test_list_for_user_includes_revoked(): void {
		$repo = new InMemoryCertificateRepository();
		$repo->insert( self::cert( 'uuid-a', 5, 7, 21, null, '2026-04-28 10:00:00' ) );
		$repo->insert( self::cert( 'uuid-b', 5, 8, 22, self::utc( '2026-04-29 09:00:00' ), '2026-04-28 11:00:00' ) );

		$rows = $repo->list_for_user( 5 );
		self::assertCount( 2, $rows );
	}

	public function test_update_revocation_sets_and_clears_timestamp(): void {
		$repo = new InMemoryCertificateRepository();
		$id   = $repo->insert( self::cert() );

		$revoked_at = self::utc( '2026-04-29 09:00:00' );
		self::assertTrue( $repo->update_revocation( $id, $revoked_at ) );
		self::assertSame( CertificateStatus::REVOKED, $repo->find( $id )?->status() );

		self::assertTrue( $repo->update_revocation( $id, null ) );
		self::assertSame( CertificateStatus::ACTIVE, $repo->find( $id )?->status() );
	}

	public function test_update_pdf_path_writes_path(): void {
		$repo = new InMemoryCertificateRepository();
		$id   = $repo->insert( self::cert() );

		self::assertTrue( $repo->update_pdf_path( $id, 'certificates/cert.pdf' ) );
		self::assertSame( 'certificates/cert.pdf', $repo->find( $id )?->pdf_path );
	}

	public function test_update_pdf_path_returns_false_for_unknown_id(): void {
		$repo = new InMemoryCertificateRepository();
		self::assertFalse( $repo->update_pdf_path( 999, 'whatever.pdf' ) );
	}
}
