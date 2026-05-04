<?php

declare(strict_types=1);

namespace VL\LMS\Domain\ZoomWebhook;

/**
 * The exact set of Zoom webhook event names the dispatcher (Phase 7.2)
 * routes. {@see self::from_string()} returns `null` for unknown event
 * names so the dispatcher can short-circuit with `processing_status =
 * ignored` rather than throwing.
 *
 * @author Tymofii Synianskyi
 */
enum WebhookEventType: string {

	case MEETING_STARTED            = 'meeting.started';
	case MEETING_ENDED              = 'meeting.ended';
	case MEETING_PARTICIPANT_JOINED = 'meeting.participant_joined';
	case MEETING_PARTICIPANT_LEFT   = 'meeting.participant_left';
	case RECORDING_COMPLETED        = 'recording.completed';
	case ENDPOINT_URL_VALIDATION    = 'endpoint.url_validation';

	/**
	 * Lenient parser — returns `null` for any value not in
	 * {@see self::cases()}. Used by the dispatcher to recognize unknown
	 * event names and mark the row `ignored`.
	 */
	public static function from_string( string $value ): ?self {
		return self::tryFrom( $value );
	}
}
