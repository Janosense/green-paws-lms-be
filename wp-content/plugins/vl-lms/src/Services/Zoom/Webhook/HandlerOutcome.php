<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Successful, intentional outcome of a {@see EventHandler::handle()}
 * call. Failure is signalled by {@see HandlerException}, never by an
 * outcome.
 *
 * `action_label` is a short slug (e.g. `'status_advanced_to_live'`,
 * `'unknown_meeting_id'`) used by the dispatcher for log correlation
 * and the controller's response payload.
 *
 * `was_no_op` distinguishes "ran cleanly but didn't change state" from
 * a real apply — the dispatcher still flips the row to `processed`,
 * but downstream observers can filter out no-ops.
 *
 * @author Tymofii Synianskyi
 */
final class HandlerOutcome {

	public function __construct(
		public readonly string $action_label,
		public readonly bool $was_no_op,
		public readonly string $message
	) {
	}

	public static function applied( string $label, string $message ): self {
		return new self( $label, false, $message );
	}

	public static function noop( string $label, string $message ): self {
		return new self( $label, true, $message );
	}
}
