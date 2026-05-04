<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

/**
 * Outcome discriminator for {@see WebinarRegistrationService} decisions.
 *
 * - `REGISTERED`        — fresh row inserted.
 * - `RE_REGISTERED`     — prior `cancelled` row flipped back to `active`.
 * - `ALREADY_ACTIVE`    — idempotent re-call against an existing active row.
 * - `CANCELLED`         — active row flipped to `cancelled`.
 * - `ALREADY_CANCELLED` — cancel called against a row that was already cancelled.
 * - `FAILED`            — validation pipeline rejected the request.
 *
 * @author Tymofii Synianskyi
 */
enum WebinarRegistrationDecisionType: string {

	case REGISTERED        = 'registered';
	case RE_REGISTERED     = 're_registered';
	case ALREADY_ACTIVE    = 'already_active';
	case CANCELLED         = 'cancelled';
	case ALREADY_CANCELLED = 'already_cancelled';
	case FAILED            = 'failed';
}
