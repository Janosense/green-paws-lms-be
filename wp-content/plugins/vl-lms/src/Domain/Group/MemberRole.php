<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Group;

/**
 * Role a user plays inside a group.
 *
 * `MANAGER` reserves permissions for organization admins who manage
 * members on behalf of the group owner — the plugin's role / capability
 * system still gates what they can actually do.
 *
 * @author Tymofii Synianskyi
 */
enum MemberRole: string {

	case MEMBER  = 'member';
	case MANAGER = 'manager';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Unknown group member role "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
