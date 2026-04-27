<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

/**
 * The two catalog-eligible CPTs that the public list endpoints expose.
 *
 * Backed by the real `vl_course` / `vl_webinar` slugs so this enum can
 * also be passed straight into `WP_Query`'s `post_type` argument. Used
 * across the catalog stack to disambiguate course-only vs webinar-only
 * concerns (sort options, card shape, query-time meta filters).
 *
 * @author Tymofii Synianskyi
 */
enum PostType: string {

	case COURSE  = 'vl_course';
	case WEBINAR = 'vl_webinar';

	/**
	 * Resolves the catalog post type from a request path segment such as
	 * `'courses'` or `'webinars'`. Throws when the segment is unknown.
	 *
	 * @throws \InvalidArgumentException When `$path_segment` is not recognised.
	 */
	public static function from_path_segment( string $path_segment ): self {
		return match ( $path_segment ) {
			'courses'  => self::COURSE,
			'webinars' => self::WEBINAR,
			default    => throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown catalog path segment "%s".', $path_segment )
			),
		};
	}

	/**
	 * Frontend permalink prefix for cards of this type.
	 */
	public function permalink_prefix(): string {
		return match ( $this ) {
			self::COURSE  => '/courses/',
			self::WEBINAR => '/webinars/',
		};
	}
}
