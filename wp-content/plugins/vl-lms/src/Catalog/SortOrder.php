<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

/**
 * Allowed `sort` values for catalog list endpoints.
 *
 * `UPCOMING` is webinar-only — selecting it for a course request is a
 * client mistake and surfaces as a 400 from the controller. Resolving
 * a sort string runs through {@see self::from_string()} which expects a
 * {@see PostType} so the enum itself owns the per-type validity.
 *
 * @author Tymofii Synianskyi
 */
enum SortOrder: string {

	case NEWEST     = 'newest';
	case OLDEST     = 'oldest';
	case TITLE_ASC  = 'title-asc';
	case TITLE_DESC = 'title-desc';
	case UPCOMING   = 'upcoming';

	/**
	 * Resolve a raw `sort` string for a given post type.
	 *
	 * @throws \InvalidArgumentException When the value is unknown for the
	 *                                   given post type. The message is
	 *                                   developer-facing; controllers map it
	 *                                   to a typed REST error.
	 */
	public static function from_string( string $value, PostType $for ): self {
		$case = self::tryFrom( $value );
		if ( null === $case || ! in_array( $case, self::allowed_for( $for ), true ) ) {
			$options = implode(
				', ',
				array_map( static fn ( self $c ): string => $c->value, self::allowed_for( $for ) )
			);
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown sort "%s" for %s. Valid options: %s.', $value, $for->value, $options )
			);
		}
		return $case;
	}

	/**
	 * @return list<self>
	 */
	public static function allowed_for( PostType $type ): array {
		return match ( $type ) {
			PostType::COURSE  => [
				self::NEWEST,
				self::OLDEST,
				self::TITLE_ASC,
				self::TITLE_DESC,
			],
			PostType::WEBINAR => [
				self::NEWEST,
				self::OLDEST,
				self::TITLE_ASC,
				self::TITLE_DESC,
				self::UPCOMING,
			],
		};
	}

	/**
	 * Default sort when the client does not specify one.
	 */
	public static function default_for( PostType $type ): self {
		unset( $type );
		return self::NEWEST;
	}
}
