<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Progress;

/**
 * Kind of post a {@see Progress} row tracks.
 *
 * Stored as the `entity_type` column in `{prefix}vl_progress` and as the
 * partner column in `{prefix}vl_lesson_views`. The DB column is `VARCHAR(20)`
 * (not MySQL `ENUM`) so adding a new case in a future phase is a code change
 * only — no migration needed.
 *
 * @author Tymofii Synianskyi
 */
enum EntityType: string {

	case LESSON = 'lesson';
	case TOPIC  = 'topic';
	case MODULE = 'module';

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
				sprintf( 'Unknown entity type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}

	/**
	 * Maps a CPT slug to its progress-tracking entity type.
	 *
	 * Used by the lesson-view REST controllers (5.1+) to translate the
	 * post type of an incoming entity into the canonical progress row key.
	 * The mapping is deliberately strict — unknown post types raise
	 * `\ValueError` so an upstream coding mistake never silently writes a
	 * progress row against a non-LMS post.
	 *
	 * Method name is camelCase to match the convention of PHP's built-in
	 * `BackedEnum::from()` / `tryFrom()` factories rather than the
	 * codebase's snake_case static methods.
	 *
	 * @throws \ValueError When `$post_type` is not one of the three trackable LMS CPTs.
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Mirrors enum factory naming convention; see docblock.
	public static function fromPostType( string $post_type ): self {
		return match ( $post_type ) {
			'vl_lesson' => self::LESSON,
			'vl_topic'  => self::TOPIC,
			'vl_module' => self::MODULE,
			default     => throw new \ValueError(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, message never surfaces to end users.
				sprintf( 'Post type "%s" does not map to a progress entity type.', $post_type )
			),
		};
	}
}
