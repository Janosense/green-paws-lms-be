<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\SessionAttendance;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\SessionAttendance\SessionAttendance;

final class SessionAttendanceTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'                    => '5',
			'session_id'            => '101',
			'user_id'               => '7',
			'zoom_participant_uuid' => 'abc-uuid-123',
			'participant_email'     => 'p@example.com',
			'participant_name'      => 'Pat',
			'joined_at'             => '2026-04-23 10:00:00',
			'left_at'               => null,
			'duration_seconds'      => null,
			'created_at'            => '2026-04-23 10:00:00',
			'updated_at'            => '2026-04-23 10:00:00',
		];
	}

	public function test_from_row_builds_object_from_complete_row(): void {
		$row = array_merge(
			self::sample_row(),
			[
				'left_at'          => '2026-04-23 10:45:00',
				'duration_seconds' => '2700',
			]
		);

		$attendance = SessionAttendance::from_row( $row );

		self::assertSame( 5, $attendance->id );
		self::assertSame( 101, $attendance->session_id );
		self::assertSame( 7, $attendance->user_id );
		self::assertSame( 'abc-uuid-123', $attendance->zoom_participant_uuid );
		self::assertSame( 'p@example.com', $attendance->participant_email );
		self::assertSame( 'Pat', $attendance->participant_name );
		self::assertSame( '2026-04-23 10:00:00', $attendance->joined_at );
		self::assertSame( '2026-04-23 10:45:00', $attendance->left_at );
		self::assertSame( 2700, $attendance->duration_seconds );
	}

	public function test_from_row_preserves_null_in_nullable_fields(): void {
		$row                      = self::sample_row();
		$row['user_id']           = null;
		$row['participant_email'] = null;
		$row['participant_name']  = null;

		$attendance = SessionAttendance::from_row( $row );

		self::assertNull( $attendance->user_id );
		self::assertNull( $attendance->participant_email );
		self::assertNull( $attendance->participant_name );
		self::assertNull( $attendance->left_at );
		self::assertNull( $attendance->duration_seconds );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$attendance = SessionAttendance::from_row( self::sample_row() );

		self::assertIsInt( $attendance->id );
		self::assertIsInt( $attendance->session_id );
		self::assertIsInt( $attendance->user_id );
	}
}
