<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Services\Progress;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Services\Progress\ProgressEventResult;

final class ProgressEventResultTest extends TestCase {

	public function test_constructor_exposes_all_fields(): void {
		$progress = new Progress(
			id: 1,
			user_id: 10,
			entity_type: EntityType::LESSON,
			entity_id: 100,
			course_id: 50,
			status: ProgressStatus::IN_PROGRESS,
			position_seconds: 240,
			completed_at: null,
			last_seen_at: new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) ),
			created_at: new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) ),
			updated_at: new \DateTimeImmutable( '2026-04-28 12:00:00', new \DateTimeZone( 'UTC' ) )
		);

		$result = new ProgressEventResult(
			view_id: 999,
			progress: $progress,
			lesson_completed: true,
			module_completed: false,
			course_progress_pct: 42,
			course_completed: false
		);

		self::assertSame( 999, $result->view_id );
		self::assertSame( $progress, $result->progress );
		self::assertTrue( $result->lesson_completed );
		self::assertFalse( $result->module_completed );
		self::assertSame( 42, $result->course_progress_pct );
		self::assertFalse( $result->course_completed );
	}
}
