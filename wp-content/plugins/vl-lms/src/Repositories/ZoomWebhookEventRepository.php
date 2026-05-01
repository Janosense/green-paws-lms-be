<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\ZoomWebhook\WebhookEvent;
use VL\LMS\Domain\ZoomWebhook\WebhookEventType;
use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;

/**
 * Primitive data-access layer for `{prefix}vl_zoom_webhook_events`.
 *
 * `record` INSERTs in `pending`; the unique `tracking_id` index throws
 * the duplicate-row error path that callers translate into the
 * already-seen short-circuit. The `mark_*` methods drive the lifecycle
 * the dispatcher (Phase 7.2) advances.
 *
 * @author Tymofii Synianskyi
 */
class ZoomWebhookEventRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * INSERT a webhook event row in `pending` status.
	 *
	 * Throws {@see \RuntimeException} when the underlying INSERT fails —
	 * the most likely cause is the unique `tracking_id` constraint
	 * tripping on a Zoom redelivery. Callers catch and treat as
	 * already-seen.
	 */
	public function record(
		string $tracking_id,
		WebhookEventType $event_type,
		int $event_ts_ms,
		?string $object_id,
		string $payload_json,
		\DateTimeImmutable $received_at
	): WebhookEvent {
		$wpdb = $this->wpdb();

		$data = [
			'tracking_id'       => $tracking_id,
			'event_type'        => $event_type->value,
			'event_ts'          => $event_ts_ms,
			'object_id'         => $object_id,
			'payload'           => $payload_json,
			'received_at'       => $received_at->format( self::DATETIME_FORMAT ),
			'processing_status' => WebhookProcessingStatus::PENDING->value,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table(), $data );

		if ( false === $result ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Failed to record zoom webhook event for tracking_id "%s".', $tracking_id )
			);
		}

		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Duplicate zoom webhook event for tracking_id "%s".', $tracking_id )
			);
		}

		$row = $this->find_row_by_id( $id );
		if ( null === $row ) {
			throw new \RuntimeException( 'Failed to read back zoom webhook event row.' );
		}
		return WebhookEvent::from_row( $row );
	}

	public function mark_processed( int $id, \DateTimeImmutable $processed_at ): void {
		$this->update_processing_state(
			$id,
			WebhookProcessingStatus::PROCESSED,
			$processed_at,
			null
		);
	}

	public function mark_failed( int $id, string $error, \DateTimeImmutable $processed_at ): void {
		$this->update_processing_state(
			$id,
			WebhookProcessingStatus::FAILED,
			$processed_at,
			$error
		);
	}

	public function mark_ignored( int $id, \DateTimeImmutable $processed_at ): void {
		$this->update_processing_state(
			$id,
			WebhookProcessingStatus::IGNORED,
			$processed_at,
			null
		);
	}

	public function find_by_tracking_id( string $tracking_id ): ?WebhookEvent {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE tracking_id = %s",
			$tracking_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return WebhookEvent::from_row( $row );
	}

	public function count_by_status( WebhookProcessingStatus $status ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE processing_status = %s",
			$status->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	private function update_processing_state(
		int $id,
		WebhookProcessingStatus $status,
		\DateTimeImmutable $processed_at,
		?string $error
	): void {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->table(),
			[
				'processing_status' => $status->value,
				'processed_at'      => $processed_at->format( self::DATETIME_FORMAT ),
				'processing_error'  => $error,
			],
			[ 'id' => $id ]
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_row_by_id( int $id ): ?array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE id = %d",
			$id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	private function table(): string {
		return SchemaManager::zoom_webhook_events_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
