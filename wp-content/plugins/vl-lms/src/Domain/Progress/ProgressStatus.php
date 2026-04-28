<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Progress;

/**
 * Lifecycle status of a {@see Progress} row.
 *
 * Stored as the `status` column in `{prefix}vl_progress`. `not_started` is
 * the column default — rows materialize that way the first time a user
 * touches the entity, then advance to `in_progress` once any meaningful
 * watch-time is recorded, then to `completed` on the explicit completion
 * event raised in 5.3.
 *
 * @author Tymofii Synianskyi
 */
enum ProgressStatus: string {

	case NOT_STARTED = 'not_started';
	case IN_PROGRESS = 'in_progress';
	case COMPLETED   = 'completed';

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
				sprintf( 'Unknown progress status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
