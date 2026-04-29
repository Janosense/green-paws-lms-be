<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Certificate;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Domain\Certificate\CertificateStatus;

final class CertificateTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'              => '11',
			'uuid'            => '8e2c4d2a-0000-4000-8000-000000000001',
			'user_id'         => '5',
			'course_id'       => '7',
			'enrollment_id'   => '21',
			'issued_at'       => '2026-04-28 10:00:00',
			'revoked_at'      => null,
			'final_score'     => '85',
			'final_max_score' => '100',
			'snapshot_data'   => '{"course_title":"Course","learner_full_name":"Alex"}',
			'pdf_path'        => null,
			'created_at'      => '2026-04-28 10:00:00',
			'updated_at'      => '2026-04-28 10:00:00',
		];
	}

	public function test_from_array_hydrates_every_field(): void {
		$cert = Certificate::from_array( self::sample_row() );

		self::assertSame( 11, $cert->id );
		self::assertSame( '8e2c4d2a-0000-4000-8000-000000000001', $cert->uuid );
		self::assertSame( 5, $cert->user_id );
		self::assertSame( 7, $cert->course_id );
		self::assertSame( 21, $cert->enrollment_id );
		self::assertSame( 85, $cert->final_score );
		self::assertSame( 100, $cert->final_max_score );
		self::assertSame( 'Course', $cert->snapshot_data['course_title'] );
		self::assertSame( 'Alex', $cert->snapshot_data['learner_full_name'] );
		self::assertNull( $cert->pdf_path );
		self::assertNull( $cert->revoked_at );
	}

	public function test_uuid_passes_through_unchanged(): void {
		$cert = Certificate::from_array( self::sample_row() );
		self::assertSame( '8e2c4d2a-0000-4000-8000-000000000001', $cert->uuid );
	}

	public function test_status_accessor_reflects_revoked_at(): void {
		$active = Certificate::from_array( self::sample_row() );
		self::assertSame( CertificateStatus::ACTIVE, $active->status() );

		$row               = self::sample_row();
		$row['revoked_at'] = '2026-04-28 11:00:00';
		$revoked           = Certificate::from_array( $row );
		self::assertSame( CertificateStatus::REVOKED, $revoked->status() );
		self::assertSame( '2026-04-28 11:00:00', $revoked->revoked_at?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_round_trip_preserves_snapshot_data(): void {
		$cert    = Certificate::from_array( self::sample_row() );
		$rebuilt = Certificate::from_array( $cert->to_array() );

		self::assertSame( $cert->id, $rebuilt->id );
		self::assertSame( $cert->uuid, $rebuilt->uuid );
		self::assertSame( $cert->snapshot_data, $rebuilt->snapshot_data );
		self::assertSame( $cert->status(), $rebuilt->status() );
	}

	public function test_pdf_path_can_be_set(): void {
		$row             = self::sample_row();
		$row['pdf_path'] = 'certificates/2026/cert-abc.pdf';

		$cert = Certificate::from_array( $row );
		self::assertSame( 'certificates/2026/cert-abc.pdf', $cert->pdf_path );
	}

	public function test_final_score_can_be_null_for_no_final_exam_courses(): void {
		$row                    = self::sample_row();
		$row['final_score']     = null;
		$row['final_max_score'] = null;

		$cert = Certificate::from_array( $row );
		self::assertNull( $cert->final_score );
		self::assertNull( $cert->final_max_score );
	}

	public function test_properties_are_readonly(): void {
		$cert = Certificate::from_array( self::sample_row() );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line Property.ReadOnlyAssignNotInScope
		$cert->uuid = 'different';
	}
}
