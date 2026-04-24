<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * What kind of content a group-access row grants access to.
 *
 * The schema accepts `WEBINAR` for forward-compatibility, but the Phase 1
 * {@see \VL\LMS\Services\Groups\GroupEnrollmentService} only fans out
 * `COURSE` rows — webinar enrollment lands in Phase 3.
 *
 * @author Tymofii Synianskyi
 */
enum AccessEntityType: string {

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
				sprintf( 'Unknown access entity type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
