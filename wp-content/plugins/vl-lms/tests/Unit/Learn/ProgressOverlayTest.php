<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\ProgressOverlay;

final class ProgressOverlayTest extends TestCase {

	private function progress( EntityType $type, int $entity_id, ProgressStatus $status = ProgressStatus::IN_PROGRESS ): Progress {
		$now = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new Progress(
			id: $entity_id * 10,
			user_id: 5,
			entity_type: $type,
			entity_id: $entity_id,
			course_id: 100,
			status: $status,
			position_seconds: null,
			completed_at: ProgressStatus::COMPLETED === $status ? $now : null,
			last_seen_at: $now,
			created_at: $now,
			updated_at: $now,
		);
	}

	public function test_from_list_partitions_by_entity_type(): void {
		$overlay = ProgressOverlay::fromList(
			[
				$this->progress( EntityType::LESSON, 123 ),
				$this->progress( EntityType::TOPIC, 200 ),
				$this->progress( EntityType::LESSON, 124, ProgressStatus::COMPLETED ),
			]
		);

		$lesson_123 = $overlay->lesson( 123 );
		$lesson_124 = $overlay->lesson( 124 );
		$topic_200  = $overlay->topic( 200 );

		self::assertNotNull( $lesson_123 );
		self::assertSame( 123, $lesson_123->entity_id );
		self::assertNotNull( $lesson_124 );
		self::assertSame( ProgressStatus::COMPLETED, $lesson_124->status );
		self::assertNotNull( $topic_200 );
		self::assertSame( 200, $topic_200->entity_id );
	}

	public function test_lesson_lookup_returns_null_when_not_present(): void {
		$overlay = ProgressOverlay::fromList(
			[ $this->progress( EntityType::LESSON, 123 ) ]
		);

		self::assertNull( $overlay->lesson( 999 ) );
		self::assertNull( $overlay->topic( 123 ) );
	}

	public function test_topic_lookup_returns_null_when_not_present(): void {
		$overlay = ProgressOverlay::fromList(
			[ $this->progress( EntityType::TOPIC, 200 ) ]
		);

		self::assertNull( $overlay->topic( 999 ) );
		self::assertNull( $overlay->lesson( 200 ) );
	}

	public function test_module_rows_are_silently_dropped(): void {
		$overlay = ProgressOverlay::fromList(
			[
				$this->progress( EntityType::MODULE, 110 ),
				$this->progress( EntityType::LESSON, 123 ),
			]
		);

		self::assertNull( $overlay->lesson( 110 ) );
		self::assertNull( $overlay->topic( 110 ) );
		self::assertNotNull( $overlay->lesson( 123 ) );
	}

	public function test_empty_list_yields_null_lookups(): void {
		$overlay = ProgressOverlay::fromList( [] );

		self::assertNull( $overlay->lesson( 1 ) );
		self::assertNull( $overlay->topic( 1 ) );
	}
}
