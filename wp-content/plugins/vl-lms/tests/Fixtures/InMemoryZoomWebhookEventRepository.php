<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\ZoomWebhook\WebhookEvent;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;
use VL\LMS\Repositories\ZoomWebhookEventRepository;

/**
 * In-memory double of {@see ZoomWebhookEventRepository}.
 */
final class InMemoryZoomWebhookEventRepository extends ZoomWebhookEventRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function record(
		string $tracking_id,
		WebhookEventType $event_type,
		int $event_ts_ms,
		?string $object_id,
		string $payload_json,
		\DateTimeImmutable $received_at
	): WebhookEvent {
		foreach ( $this->rows as $row ) {
			if ( $row['tracking_id'] === $tracking_id ) {
				throw new \RuntimeException(
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test-only exception, not user-facing.
					sprintf( 'Duplicate zoom webhook event for tracking_id "%s".', $tracking_id )
				);
			}
		}

		$id = $this->next_id++;

		$this->rows[ $id ] = [
			'id'                => $id,
			'tracking_id'       => $tracking_id,
			'event_type'        => $event_type->value,
			'event_ts'          => $event_ts_ms,
			'object_id'         => $object_id,
			'payload'           => $payload_json,
			'received_at'       => $received_at->format( 'Y-m-d H:i:s' ),
			'processed_at'      => null,
			'processing_status' => WebhookProcessingStatus::PENDING->value,
			'processing_error'  => null,
		];

		return WebhookEvent::from_row( $this->rows[ $id ] );
	}

	public function mark_processed( int $id, \DateTimeImmutable $processed_at ): void {
		$this->set_status( $id, WebhookProcessingStatus::PROCESSED, $processed_at, null );
	}

	public function mark_failed( int $id, string $error, \DateTimeImmutable $processed_at ): void {
		$this->set_status( $id, WebhookProcessingStatus::FAILED, $processed_at, $error );
	}

	public function mark_ignored( int $id, \DateTimeImmutable $processed_at ): void {
		$this->set_status( $id, WebhookProcessingStatus::IGNORED, $processed_at, null );
	}

	public function find_by_tracking_id( string $tracking_id ): ?WebhookEvent {
		foreach ( $this->rows as $row ) {
			if ( $row['tracking_id'] === $tracking_id ) {
				return WebhookEvent::from_row( $row );
			}
		}
		return null;
	}

	public function count_by_status( WebhookProcessingStatus $status ): int {
		$count = 0;
		foreach ( $this->rows as $row ) {
			if ( $row['processing_status'] === $status->value ) {
				++$count;
			}
		}
		return $count;
	}

	private function set_status(
		int $id,
		WebhookProcessingStatus $status,
		\DateTimeImmutable $processed_at,
		?string $error
	): void {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return;
		}
		$this->rows[ $id ]['processing_status'] = $status->value;
		$this->rows[ $id ]['processed_at']      = $processed_at->format( 'Y-m-d H:i:s' );
		$this->rows[ $id ]['processing_error']  = $error;
	}
}
