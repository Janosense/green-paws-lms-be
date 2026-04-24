<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Classification of a {@see Group}.
 *
 * Drives UI affordances and access expectations — `ORGANIZATION` groups
 * have managers and billing tied to a company, `COHORT` groups travel
 * through a course together, `AD_HOC` is the catch-all for
 * teacher-created sets.
 *
 * @author Tymofii Synianskyi
 */
enum GroupType: string {

	case COHORT       = 'cohort';
	case ORGANIZATION = 'organization';
	case AD_HOC       = 'ad_hoc';

	/**
	 * Strict parser. See {@see \VL\LMS\Domain\Enrollment\EnrollmentStatus::from_string()}.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown group type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
