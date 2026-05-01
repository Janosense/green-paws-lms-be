<?php

declare(strict_types=1);

namespace VL\LMS\Domain\WebinarRegistration;

/**
 * Lifecycle status of a {@see WebinarRegistration} row.
 *
 * Stored as the `status` column in `{prefix}vl_webinar_registrations`.
 * Re-registering after a cancellation flips the row from `CANCELLED` back
 * to `ACTIVE` (and clears `cancelled_at`); we never INSERT a second row
 * for the same `(webinar_id, user_id)`.
 *
 * @author Tymofii Synianskyi
 */
enum WebinarRegistrationStatus: string {

	case ACTIVE    = 'active';
	case CANCELLED = 'cancelled';

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
				sprintf( 'Unknown webinar registration status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
