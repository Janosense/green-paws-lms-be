<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Enrollment;

/**
 * Provenance of a {@see Enrollment} row — records how the user came to be
 * enrolled in the course.
 *
 * Drives downstream fan-out: `GROUP` enrollments are rebuilt when group
 * membership changes, `PURCHASE` enrollments are tied to order refunds, etc.
 *
 * @author Tymofii Synianskyi
 */
enum EnrollmentSource: string {

	case MANUAL      = 'manual';
	case PURCHASE    = 'purchase';
	case GROUP       = 'group';
	case GIFT        = 'gift';
	case GRANT       = 'grant';
	case SELF_SIGNUP = 'self_signup';

	/**
	 * Strict parser. See {@see EnrollmentStatus::from_string()}.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown enrollment source "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
