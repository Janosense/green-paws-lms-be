<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

use VL\LMS\Domain\ZoomWebhook\WebhookEventType;

/**
 * Maps {@see WebhookEventType} cases to their handler instances.
 *
 * `endpoint.url_validation` is intentionally NOT routed here — it's
 * handled inline in the controller before the dispatcher runs.
 *
 * Constructor takes interface-typed slots so tests can swap any
 * handler with a Mockery double of {@see EventHandler}; production
 * wires the five concrete handlers via the container.
 *
 * Concrete (not final) so unit tests can subclass for a static dummy
 * registry.
 *
 * @author Tymofii Synianskyi
 */
class HandlerRegistry {

	private EventHandler $meeting_started;

	private EventHandler $meeting_ended;

	private EventHandler $participant_joined;

	private EventHandler $participant_left;

	private EventHandler $recording_completed;

	public function __construct(
		EventHandler $meeting_started,
		EventHandler $meeting_ended,
		EventHandler $participant_joined,
		EventHandler $participant_left,
		EventHandler $recording_completed
	) {
		$this->meeting_started     = $meeting_started;
		$this->meeting_ended       = $meeting_ended;
		$this->participant_joined  = $participant_joined;
		$this->participant_left    = $participant_left;
		$this->recording_completed = $recording_completed;
	}

	public function find( WebhookEventType $event_type ): ?EventHandler {
		return match ( $event_type ) {
			WebhookEventType::MEETING_STARTED            => $this->meeting_started,
			WebhookEventType::MEETING_ENDED              => $this->meeting_ended,
			WebhookEventType::MEETING_PARTICIPANT_JOINED => $this->participant_joined,
			WebhookEventType::MEETING_PARTICIPANT_LEFT   => $this->participant_left,
			WebhookEventType::RECORDING_COMPLETED        => $this->recording_completed,
			WebhookEventType::ENDPOINT_URL_VALIDATION    => null,
		};
	}
}
