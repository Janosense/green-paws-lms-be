<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Lifecycle status of a {@see Group}.
 *
 * Archive is the only non-active state in Phase 1 — deletion is handled
 * via uninstall or explicit row removal, not through a status transition.
 *
 * @author Tymofii Synianskyi
 */
enum GroupStatus: string {

	case ACTIVE   = 'active';
	case ARCHIVED = 'archived';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown group status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
