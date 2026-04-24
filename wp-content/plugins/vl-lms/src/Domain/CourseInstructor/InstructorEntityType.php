<?php

declare(strict_types=1);

namespace VL\LMS\Domain\CourseInstructor;

/**
 * What kind of content an instructor assignment points at.
 *
 * Kept as a separate enum from
 * {@see \VL\LMS\Domain\Group\AccessEntityType} to preserve domain
 * boundaries — the two enums happen to share their cases in Phase 1 but
 * have independent concerns (group grants vs. authoring assignments),
 * and one could diverge without the other needing to follow.
 *
 * @author Tymofii Synianskyi
 */
enum InstructorEntityType: string {

	case COURSE  = 'course';
	case WEBINAR = 'webinar';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown instructor entity type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
