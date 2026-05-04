<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

/**
 * Failure reasons surfaced by {@see WebinarRegistrationService}.
 *
 * Translated to HTTP codes by the REST controller — the service itself
 * stays HTTP-agnostic.
 *
 * @author Tymofii Synianskyi
 */
enum WebinarRegistrationError: string {

	case WEBINAR_NOT_FOUND          = 'webinar_not_found';
	case NOT_PUBLISHED              = 'not_published';
	case REGISTRATION_NOT_OPEN_YET  = 'registration_not_open_yet';
	case REGISTRATION_CLOSED        = 'registration_closed';
	case PAYMENT_REQUIRED           = 'payment_required';
	case CAPACITY_REACHED           = 'capacity_reached';
	case NOT_REGISTERED             = 'not_registered';
}
