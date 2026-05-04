<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Domain\ZoomWebhook;

use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\ZoomWebhook\WebhookEvent;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;

final class WebhookEventTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private static function sample_row(): array {
		return [
			'id'                => '1',
			'tracking_id'       => 'track-abc-123',
			'event_type'        => 'meeting.started',
			'event_ts'          => '1714000000000',
			'object_id'         => '987654321',
			'payload'           => '{"event":"meeting.started"}',
			'received_at'       => '2026-04-23 10:00:00',
			'processed_at'      => null,
			'processing_status' => 'pending',
			'processing_error'  => null,
		];
	}

	public function test_from_row_builds_object_with_known_event_type(): void {
		$event = WebhookEvent::from_row( self::sample_row() );

		self::assertSame( 1, $event->id );
		self::assertSame( 'track-abc-123', $event->tracking_id );
		self::assertSame( WebhookEventType::MEETING_STARTED, $event->event_type );
		self::assertSame( 1714000000000, $event->event_ts_ms );
		self::assertSame( '987654321', $event->object_id );
		self::assertSame( '{"event":"meeting.started"}', $event->payload_json );
		self::assertSame( '2026-04-23 10:00:00', $event->received_at );
		self::assertNull( $event->processed_at );
		self::assertSame( WebhookProcessingStatus::PENDING, $event->processing_status );
		self::assertNull( $event->processing_error );
	}

	public function test_from_row_keeps_unknown_event_type_as_string(): void {
		$row               = self::sample_row();
		$row['event_type'] = 'something.weird';

		$event = WebhookEvent::from_row( $row );

		self::assertSame( 'something.weird', $event->event_type );
	}

	public function test_from_row_preserves_processed_state(): void {
		$row                      = self::sample_row();
		$row['processing_status'] = 'processed';
		$row['processed_at']      = '2026-04-23 10:00:05';

		$event = WebhookEvent::from_row( $row );

		self::assertSame( WebhookProcessingStatus::PROCESSED, $event->processing_status );
		self::assertSame( '2026-04-23 10:00:05', $event->processed_at );
	}

	public function test_from_row_preserves_failed_with_error(): void {
		$row                      = self::sample_row();
		$row['processing_status'] = 'failed';
		$row['processing_error']  = 'boom';
		$row['processed_at']      = '2026-04-23 10:00:05';

		$event = WebhookEvent::from_row( $row );

		self::assertSame( WebhookProcessingStatus::FAILED, $event->processing_status );
		self::assertSame( 'boom', $event->processing_error );
	}

	public function test_from_row_rejects_unknown_processing_status(): void {
		$row                      = self::sample_row();
		$row['processing_status'] = 'half-baked';

		$this->expectException( \InvalidArgumentException::class );

		WebhookEvent::from_row( $row );
	}
}
