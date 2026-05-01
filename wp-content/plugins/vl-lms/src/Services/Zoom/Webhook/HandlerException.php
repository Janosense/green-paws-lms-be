<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Thrown by an {@see EventHandler} to signal a real failure (DB error,
 * unexpected payload shape that survived parsing, etc.). The dispatcher
 * catches it and flips the `vl_zoom_webhook_events` row to
 * `processing_status = failed`.
 *
 * @author Tymofii Synianskyi
 */
final class HandlerException extends \RuntimeException {
}
