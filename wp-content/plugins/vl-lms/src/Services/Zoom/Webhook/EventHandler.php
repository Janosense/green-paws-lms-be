<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Per-event-type handler contract.
 *
 * Handlers MUST NOT throw across this boundary except via
 * {@see HandlerException} — generic Throwables are still caught by the
 * dispatcher, but {@see HandlerException} is the documented signal for
 * real failures (recorded as `processing_status = failed`).
 *
 * @author Tymofii Synianskyi
 */
interface EventHandler {

	public function handle( WebhookRequest $request ): HandlerOutcome;
}
