<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Enrollment;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;

final class EnrollmentTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'              => '42',
			'user_id'         => '7',
			'course_id'       => '123',
			'status'          => 'active',
			'source'          => 'manual',
			'source_group_id' => null,
			'source_order_id' => null,
			'enrolled_at'     => '2026-04-23 10:00:00',
			'started_at'      => null,
			'completed_at'    => null,
			'expires_at'      => null,
			'revoked_at'      => null,
			'revoked_by'      => null,
			'revoke_reason'   => null,
			'progress_pct'    => '0',
			'created_at'      => '2026-04-23 10:00:00',
			'updated_at'      => '2026-04-23 10:00:00',
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row = self::sample_row();
		$row = array_merge(
			$row,
			[
				'source_group_id' => '5',
				'source_order_id' => '99',
				'started_at'      => '2026-04-24 12:00:00',
				'completed_at'    => '2026-04-30 18:00:00',
				'expires_at'      => '2027-04-23 00:00:00',
				'revoked_at'      => '2026-05-01 09:00:00',
				'revoked_by'      => '2',
				'revoke_reason'   => 'Refund requested',
				'progress_pct'    => '75',
				'status'          => 'completed',
				'source'          => 'purchase',
			]
		);

		$enrollment = Enrollment::from_row( $row );

		self::assertSame( 42, $enrollment->id );
		self::assertSame( 7, $enrollment->user_id );
		self::assertSame( 123, $enrollment->course_id );
		self::assertSame( EnrollmentStatus::COMPLETED, $enrollment->status );
		self::assertSame( EnrollmentSource::PURCHASE, $enrollment->source );
		self::assertSame( 5, $enrollment->source_group_id );
		self::assertSame( 99, $enrollment->source_order_id );
		self::assertSame( '2026-04-23 10:00:00', $enrollment->enrolled_at );
		self::assertSame( '2026-04-24 12:00:00', $enrollment->started_at );
		self::assertSame( '2026-04-30 18:00:00', $enrollment->completed_at );
		self::assertSame( '2027-04-23 00:00:00', $enrollment->expires_at );
		self::assertSame( '2026-05-01 09:00:00', $enrollment->revoked_at );
		self::assertSame( 2, $enrollment->revoked_by );
		self::assertSame( 'Refund requested', $enrollment->revoke_reason );
		self::assertSame( 75, $enrollment->progress_pct );
		self::assertSame( '2026-04-23 10:00:00', $enrollment->created_at );
		self::assertSame( '2026-04-23 10:00:00', $enrollment->updated_at );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$row = self::sample_row();

		$enrollment = Enrollment::from_row( $row );

		self::assertIsInt( $enrollment->id );
		self::assertIsInt( $enrollment->user_id );
		self::assertIsInt( $enrollment->course_id );
		self::assertIsInt( $enrollment->progress_pct );
	}

	public function test_from_row_preserves_null_in_nullable_fields(): void {
		$enrollment = Enrollment::from_row( self::sample_row() );

		self::assertNull( $enrollment->source_group_id );
		self::assertNull( $enrollment->source_order_id );
		self::assertNull( $enrollment->started_at );
		self::assertNull( $enrollment->completed_at );
		self::assertNull( $enrollment->expires_at );
		self::assertNull( $enrollment->revoked_at );
		self::assertNull( $enrollment->revoked_by );
		self::assertNull( $enrollment->revoke_reason );
	}

	public function test_from_row_clamps_progress_pct_high(): void {
		$row                 = self::sample_row();
		$row['progress_pct'] = 150;

		$enrollment = Enrollment::from_row( $row );

		self::assertSame( 100, $enrollment->progress_pct );
	}

	public function test_from_row_clamps_progress_pct_low(): void {
		$row                 = self::sample_row();
		$row['progress_pct'] = -5;

		$enrollment = Enrollment::from_row( $row );

		self::assertSame( 0, $enrollment->progress_pct );
	}

	public function test_from_row_rejects_unknown_status(): void {
		$row           = self::sample_row();
		$row['status'] = 'pending';

		$this->expectException( \InvalidArgumentException::class );

		Enrollment::from_row( $row );
	}

	public function test_from_row_rejects_unknown_source(): void {
		$row           = self::sample_row();
		$row['source'] = 'affiliate';

		$this->expectException( \InvalidArgumentException::class );

		Enrollment::from_row( $row );
	}
}
