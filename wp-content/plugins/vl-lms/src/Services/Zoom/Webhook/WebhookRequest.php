<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Parsed Zoom webhook envelope.
 *
 * `payload` is `payload.object` from the Zoom envelope (one level deeper
 * than the raw body) — every operational handler expects to read fields
 * like `id`, `participant.*`, `recording_files` directly from this array.
 * For the `endpoint.url_validation` event there is no `payload.object`;
 * `url_validation_plain_token` carries the challenge instead.
 *
 * `tracking_id` is the `x-zm-trackingid` header. Required for every
 * operational event — it is the idempotency key the dispatcher uses
 * against `vl_zoom_webhook_events`. Empty string for url_validation,
 * which never reaches the dispatcher.
 *
 * `raw_body` is preserved verbatim so the dispatcher can persist it for
 * audit / replay without re-encoding.
 *
 * @author Tymofii Synianskyi
 */
final class WebhookRequest {

	/**
	 * @param array<string, mixed> $payload
	 */
	public function __construct(
		public readonly string $event,
		public readonly array $payload,
		public readonly string $account_id,
		public readonly string $tracking_id,
		public readonly int $event_ts_ms,
		public readonly string $raw_body,
		public readonly string $url_validation_plain_token
	) {
	}

	public function is_url_validation(): bool {
		return 'endpoint.url_validation' === $this->event;
	}

	/**
	 * `payload.object.id` cast to string. Empty string when the event
	 * has no canonical object id (url_validation) or the field is missing.
	 */
	public function object_id(): string {
		if ( ! isset( $this->payload['id'] ) ) {
			return '';
		}
		$id = $this->payload['id'];
		if ( is_string( $id ) ) {
			return $id;
		}
		if ( is_int( $id ) || is_float( $id ) ) {
			return (string) $id;
		}
		return '';
	}
}
