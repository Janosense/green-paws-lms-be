<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\Progress;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;

final class ProgressTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'               => '11',
			'user_id'          => '5',
			'entity_type'      => 'lesson',
			'entity_id'        => '101',
			'course_id'        => '7',
			'status'           => 'in_progress',
			'position_seconds' => '42',
			'completed_at'     => null,
			'last_seen_at'     => '2026-04-28 10:00:00',
			'created_at'       => '2026-04-27 09:00:00',
			'updated_at'       => '2026-04-28 10:00:00',
		];
	}

	public function test_constructor_assigns_every_property(): void {
		$created = new \DateTimeImmutable( '2026-04-27 09:00:00', new \DateTimeZone( 'UTC' ) );
		$updated = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );

		$progress = new Progress(
			11,
			5,
			EntityType::LESSON,
			101,
			7,
			ProgressStatus::IN_PROGRESS,
			42,
			null,
			$updated,
			$created,
			$updated
		);

		self::assertSame( 11, $progress->id );
		self::assertSame( 5, $progress->user_id );
		self::assertSame( EntityType::LESSON, $progress->entity_type );
		self::assertSame( 101, $progress->entity_id );
		self::assertSame( 7, $progress->course_id );
		self::assertSame( ProgressStatus::IN_PROGRESS, $progress->status );
		self::assertSame( 42, $progress->position_seconds );
		self::assertNull( $progress->completed_at );
		self::assertSame( $updated, $progress->last_seen_at );
		self::assertSame( $created, $progress->created_at );
		self::assertSame( $updated, $progress->updated_at );
	}

	public function test_from_row_coerces_numeric_strings_to_int(): void {
		$progress = Progress::from_row( self::sample_row() );

		self::assertIsInt( $progress->id );
		self::assertIsInt( $progress->user_id );
		self::assertIsInt( $progress->entity_id );
		self::assertIsInt( $progress->course_id );
		self::assertIsInt( $progress->position_seconds );
	}

	public function test_from_row_parses_datetime_columns_as_utc(): void {
		$progress = Progress::from_row( self::sample_row() );

		self::assertSame( '2026-04-27 09:00:00', $progress->created_at->format( 'Y-m-d H:i:s' ) );
		self::assertSame( 'UTC', $progress->created_at->getTimezone()->getName() );
		self::assertSame( '2026-04-28 10:00:00', $progress->last_seen_at?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_from_row_preserves_null_in_nullable_columns(): void {
		$progress = Progress::from_row( self::sample_row() );

		self::assertNull( $progress->completed_at );
	}

	public function test_from_row_handles_null_position_seconds_for_non_video(): void {
		$row                     = self::sample_row();
		$row['position_seconds'] = null;

		$progress = Progress::from_row( $row );

		self::assertNull( $progress->position_seconds );
	}

	public function test_from_row_decodes_completed_status(): void {
		$row                 = self::sample_row();
		$row['status']       = 'completed';
		$row['completed_at'] = '2026-04-28 11:00:00';

		$progress = Progress::from_row( $row );

		self::assertSame( ProgressStatus::COMPLETED, $progress->status );
		self::assertSame( '2026-04-28 11:00:00', $progress->completed_at?->format( 'Y-m-d H:i:s' ) );
	}

	public function test_from_row_rejects_unknown_status(): void {
		$row           = self::sample_row();
		$row['status'] = 'paused';

		$this->expectException( \InvalidArgumentException::class );
		Progress::from_row( $row );
	}

	public function test_from_row_rejects_unknown_entity_type(): void {
		$row                = self::sample_row();
		$row['entity_type'] = 'quiz';

		$this->expectException( \InvalidArgumentException::class );
		Progress::from_row( $row );
	}

	public function test_properties_are_readonly(): void {
		$progress = Progress::from_row( self::sample_row() );

		$this->expectException( \Error::class );
		// @phpstan-ignore-next-line Property.ReadOnlyAssignNotInScope
		$progress->status = ProgressStatus::COMPLETED;
	}
}
