<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

/**
 * Reason discriminator for {@see WebinarAccessGate} decisions.
 *
 * - `OK`                       — caller is allowed; redirect URL is set.
 * - `NOT_REGISTERED`           — no active registration row.
 * - `JOIN_WINDOW_NOT_OPEN`     — too early; carries `opens_at`.
 * - `JOIN_WINDOW_CLOSED`       — too late.
 * - `MEETING_NOT_PROVISIONED`  — Zoom join URL not yet populated on the post.
 * - `RECORDING_NOT_AVAILABLE`  — no recording URL yet, or recording disabled.
 * - `RECORDING_WINDOW_EXPIRED` — recording access window ended.
 *
 * @author Tymofii Synianskyi
 */
enum WebinarAccessReason: string {

	case OK                       = 'ok';
	case NOT_REGISTERED           = 'not_registered';
	case JOIN_WINDOW_NOT_OPEN     = 'join_window_not_open';
	case JOIN_WINDOW_CLOSED       = 'join_window_closed';
	case MEETING_NOT_PROVISIONED  = 'meeting_not_provisioned';
	case RECORDING_NOT_AVAILABLE  = 'recording_not_available';
	case RECORDING_WINDOW_EXPIRED = 'recording_window_expired';
}
