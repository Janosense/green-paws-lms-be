<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Enrollment;

/**
 * Lifecycle status of a {@see Enrollment} row.
 *
 * Stored as the `status` column in `{prefix}vl_enrollments`. Mirrors the
 * string enum declared in the schema — the DB column is `VARCHAR(20)`
 * rather than MySQL `ENUM` so migrations stay cheap.
 *
 * @author Tymofii Synianskyi
 */
enum EnrollmentStatus: string {

	case ACTIVE    = 'active';
	case COMPLETED = 'completed';
	case EXPIRED   = 'expired';
	case REVOKED   = 'revoked';
	case REFUNDED  = 'refunded';

	/**
	 * Strict parser. Unlike {@see self::tryFrom()}, rejects unknown values
	 * with a descriptive exception so callers never silently mis-type a row.
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown enrollment status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
