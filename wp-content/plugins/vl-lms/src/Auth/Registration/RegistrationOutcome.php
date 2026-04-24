<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Registration;

/**
 * Classifies the outcome of a {@see RegistrationService::register()} call.
 *
 * The public REST response intentionally collapses all three outcomes to
 * the same body — this enum exists so internal callers (tests, future
 * audit logging) can still distinguish real signup from duplicate-email
 * resends and no-op silent successes.
 *
 * @author Tymofii Synianskyi
 */
enum RegistrationOutcome: string {

	case CREATED          = 'created';
	case RESENT           = 'resent';
	case ALREADY_VERIFIED = 'already_verified';
}
