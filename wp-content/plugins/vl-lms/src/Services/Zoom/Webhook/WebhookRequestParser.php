<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

use WP_REST_Request;

/**
 * Decodes a `WP_REST_Request` into a typed {@see WebhookRequest}.
 *
 * Validation failures throw {@see WebhookRequestException} carrying a
 * stable `reason_code()` — the controller maps these to the 400-response
 * payload without coupling to the human-readable message.
 *
 * Concrete (not final) so unit tests can subclass for header-stub seams
 * if needed; default behaviour reads headers directly from the request.
 *
 * @author Tymofii Synianskyi
 */
class WebhookRequestParser {

	public function parse( WP_REST_Request $request ): WebhookRequest {
		$raw_body = (string) $request->get_body();

		try {
			/** @var mixed $decoded */
			$decoded = json_decode( $raw_body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new WebhookRequestException(
				'invalid_json',
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				'Webhook body is not valid JSON: ' . $e->getMessage()
			);
		}

		if ( ! is_array( $decoded ) ) {
			throw new WebhookRequestException( 'invalid_json', 'Webhook body must decode to an object.' );
		}

		$event = isset( $decoded['event'] ) && is_string( $decoded['event'] ) ? $decoded['event'] : '';
		if ( '' === $event ) {
			throw new WebhookRequestException( 'missing_event', 'Webhook envelope is missing the "event" field.' );
		}

		if ( ! isset( $decoded['payload'] ) || ! is_array( $decoded['payload'] ) ) {
			throw new WebhookRequestException( 'invalid_payload', 'Webhook "payload" must be an object.' );
		}
		/** @var array<string, mixed> $payload_envelope */
		$payload_envelope = $decoded['payload'];

		$account_id = isset( $payload_envelope['account_id'] ) && is_string( $payload_envelope['account_id'] )
			? $payload_envelope['account_id']
			: '';

		if ( 'endpoint.url_validation' === $event ) {
			$plain_token = isset( $payload_envelope['plainToken'] ) && is_string( $payload_envelope['plainToken'] )
				? $payload_envelope['plainToken']
				: '';
			if ( '' === $plain_token ) {
				throw new WebhookRequestException( 'missing_plain_token', 'url_validation payload must carry a non-empty plainToken.' );
			}

			return new WebhookRequest(
				$event,
				[],
				$account_id,
				'',
				0,
				$raw_body,
				$plain_token
			);
		}

		if ( ! isset( $payload_envelope['object'] ) || ! is_array( $payload_envelope['object'] ) ) {
			throw new WebhookRequestException( 'invalid_payload_object', 'Operational webhooks must carry "payload.object" as an object.' );
		}
		/** @var array<string, mixed> $payload_object */
		$payload_object = $payload_envelope['object'];

		$tracking_header = $request->get_header( 'x-zm-trackingid' );
		$tracking_id     = is_string( $tracking_header ) ? trim( $tracking_header ) : '';
		if ( '' === $tracking_id ) {
			throw new WebhookRequestException( 'missing_tracking_id', 'Operational webhooks must carry a non-empty x-zm-trackingid header.' );
		}

		if ( ! isset( $decoded['event_ts'] ) ) {
			throw new WebhookRequestException( 'missing_event_ts', 'Operational webhooks must carry the top-level event_ts.' );
		}
		$event_ts_raw = $decoded['event_ts'];
		if ( ! is_int( $event_ts_raw ) && ! ( is_string( $event_ts_raw ) && '' !== $event_ts_raw && ctype_digit( $event_ts_raw ) ) ) {
			throw new WebhookRequestException( 'missing_event_ts', 'Operational webhooks must carry an integer event_ts.' );
		}
		$event_ts_ms = (int) $event_ts_raw;

		return new WebhookRequest(
			$event,
			$payload_object,
			$account_id,
			$tracking_id,
			$event_ts_ms,
			$raw_body,
			''
		);
	}
}
