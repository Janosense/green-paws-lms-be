<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Provenance of a group-access row.
 *
 * `PURCHASED_BY_ORG` distinguishes corporate buy-for-team orders from
 * regular grants so billing and refund flows can target them.
 *
 * @author Tymofii Synianskyi
 */
enum AccessType: string {

	case GRANTED          = 'granted';
	case PURCHASED_BY_ORG = 'purchased_by_org';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown access type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
