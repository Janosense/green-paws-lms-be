<?php

declare(strict_types=1);

namespace VL\LMS\Domain\ZoomWebhook;

/**
 * Immutable data carrier for one row of `{prefix}vl_zoom_webhook_events`.
 *
 * The receiver writes the row in `pending` status; the dispatcher (Phase
 * 7.2) advances `processing_status` and stamps `processed_at`. The
 * unique `tracking_id` index is the idempotency seam — duplicate
 * deliveries by Zoom short-circuit with `processing_status = ignored`.
 *
 * `event_ts` is preserved as Zoom delivers it (millisecond epoch) so we
 * can correlate events against their original wall-clock without losing
 * sub-second precision.
 *
 * @author Tymofii Synianskyi
 */
class WebhookEvent {

	public function __construct(
		public readonly int $id,
		public readonly string $tracking_id,
		public readonly WebhookEventType|string $event_type,
		public readonly int $event_ts_ms,
		public readonly ?string $object_id,
		public readonly string $payload_json,
		public readonly string $received_at,
		public readonly ?string $processed_at,
		public readonly WebhookProcessingStatus $processing_status,
		public readonly ?string $processing_error
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * `event_type` is preserved as a typed enum case when recognized and
	 * as the raw string otherwise — this matches the dispatcher's "unknown
	 * event names are still recorded but marked ignored" contract.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `processing_status` carries an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		$event_type_raw  = (string) $row['event_type'];
		$event_type_enum = WebhookEventType::from_string( $event_type_raw );

		return new self(
			(int) $row['id'],
			(string) $row['tracking_id'],
			$event_type_enum ?? $event_type_raw,
			(int) $row['event_ts'],
			self::nullable_string( $row['object_id'] ?? null ),
			(string) $row['payload'],
			(string) $row['received_at'],
			self::nullable_string( $row['processed_at'] ?? null ),
			WebhookProcessingStatus::from_string( (string) $row['processing_status'] ),
			self::nullable_string( $row['processing_error'] ?? null )
		);
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}
}
