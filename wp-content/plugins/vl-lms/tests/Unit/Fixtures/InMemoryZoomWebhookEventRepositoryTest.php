<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;
use VL\LMS\Tests\Fixtures\InMemoryZoomWebhookEventRepository;

final class InMemoryZoomWebhookEventRepositoryTest extends TestCase {

	private InMemoryZoomWebhookEventRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		$this->repo = new InMemoryZoomWebhookEventRepository();
	}

	private static function utc( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public function test_record_inserts_pending_row(): void {
		$event = $this->repo->record(
			'track-1',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			'987',
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);

		self::assertSame( 'track-1', $event->tracking_id );
		self::assertSame( WebhookProcessingStatus::PENDING, $event->processing_status );
	}

	public function test_record_throws_on_duplicate_tracking_id(): void {
		$this->repo->record(
			'track-1',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);

		$this->expectException( \RuntimeException::class );

		$this->repo->record(
			'track-1',
			WebhookEventType::MEETING_ENDED,
			1714000000001,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);
	}

	public function test_mark_processed_advances_state(): void {
		$event = $this->repo->record(
			'track-1',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);

		$this->repo->mark_processed( $event->id, self::utc( '2026-04-23 10:00:05' ) );

		$reread = $this->repo->find_by_tracking_id( 'track-1' );
		self::assertNotNull( $reread );
		self::assertSame( WebhookProcessingStatus::PROCESSED, $reread->processing_status );
		self::assertSame( '2026-04-23 10:00:05', $reread->processed_at );
	}

	public function test_mark_failed_records_error(): void {
		$event = $this->repo->record(
			'track-1',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);

		$this->repo->mark_failed( $event->id, 'boom', self::utc( '2026-04-23 10:00:05' ) );

		$reread = $this->repo->find_by_tracking_id( 'track-1' );
		self::assertNotNull( $reread );
		self::assertSame( WebhookProcessingStatus::FAILED, $reread->processing_status );
		self::assertSame( 'boom', $reread->processing_error );
	}

	public function test_count_by_status_partitions_correctly(): void {
		$a = $this->repo->record(
			'track-a',
			WebhookEventType::MEETING_STARTED,
			1714000000000,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);
		$this->repo->record(
			'track-b',
			WebhookEventType::MEETING_ENDED,
			1714000000001,
			null,
			'{}',
			self::utc( '2026-04-23 10:00:00' )
		);
		$this->repo->mark_processed( $a->id, self::utc( '2026-04-23 10:00:05' ) );

		self::assertSame( 1, $this->repo->count_by_status( WebhookProcessingStatus::PENDING ) );
		self::assertSame( 1, $this->repo->count_by_status( WebhookProcessingStatus::PROCESSED ) );
	}

	public function test_find_by_tracking_id_returns_null_for_missing(): void {
		self::assertNull( $this->repo->find_by_tracking_id( 'no-match' ) );
	}
}
