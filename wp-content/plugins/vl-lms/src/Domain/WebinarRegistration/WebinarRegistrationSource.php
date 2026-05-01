<?php

declare(strict_types=1);

namespace VL\LMS\Domain\WebinarRegistration;

/**
 * Provenance of a {@see WebinarRegistration} row.
 *
 * Mirrors {@see \VL\LMS\Domain\Enrollment\EnrollmentSource} but is
 * deliberately a separate enum so registrations are not coupled to
 * enrollments at the type level — a webinar registration is its own
 * entity, distinct from a course enrollment.
 *
 * @author Tymofii Synianskyi
 */
enum WebinarRegistrationSource: string {

	case SELF_SIGNUP = 'self_signup';
	case MANUAL      = 'manual';
	case PURCHASE    = 'purchase';
	case GIFT        = 'gift';
	case GRANT       = 'grant';

	/**
	 * Strict parser. Rejects unknown values with a descriptive exception so
	 * callers never silently mis-type a row.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown webinar registration source "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
