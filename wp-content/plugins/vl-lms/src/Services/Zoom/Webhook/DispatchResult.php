<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

use VL\LMS\Domain\ZoomWebhook\WebhookProcessingStatus;

/**
 * Outcome of {@see WebhookEventDispatcher::dispatch()}.
 *
 * `status` mirrors the row's `processing_status`: `PROCESSED`, `FAILED`,
 * or `IGNORED`. (PENDING is a transient state that never escapes the
 * dispatcher.) `outcome` carries the {@see HandlerOutcome} from a
 * successful run, and `exception` carries the wrapped failure.
 *
 * @author Tymofii Synianskyi
 */
final class DispatchResult {

	public function __construct(
		public readonly WebhookProcessingStatus $status,
		public readonly ?HandlerOutcome $outcome,
		public readonly ?\Throwable $exception,
		public readonly string $message
	) {
	}

	public static function processed( HandlerOutcome $outcome ): self {
		return new self( WebhookProcessingStatus::PROCESSED, $outcome, null, $outcome->action_label );
	}

	public static function ignored( string $message ): self {
		return new self( WebhookProcessingStatus::IGNORED, null, null, $message );
	}

	public static function failed( \Throwable $exception ): self {
		return new self( WebhookProcessingStatus::FAILED, null, $exception, 'handler_threw' );
	}
}
