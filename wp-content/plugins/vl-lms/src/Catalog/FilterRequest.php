<?php

declare(strict_types=1);

namespace VL\LMS\Catalog;

/**
 * Parsed and sanitized filter inputs for a catalog list request.
 *
 * Constructed from a raw associative array (typically the result of
 * `WP_REST_Request::get_params()`). Validation rules:
 *
 * - `q` is trimmed and truncated at 200 chars (silent truncation by design).
 * - `category[]` / `specialty[]` / `difficulty[]` / `tag[]` accept only
 *   non-empty strings; each is run through `sanitize_title` and empty
 *   results are dropped.
 * - `page` is at least 1.
 * - `per_page` is clamped to `[1, 50]`.
 * - `sort` is delegated to {@see SortOrder::from_string()} which throws on
 *   unknown values for the current post type. The controller catches that
 *   and maps it to a 400 REST error.
 *
 * @author Tymofii Synianskyi
 */
final class FilterRequest {

	public const int Q_MAX_LENGTH     = 200;
	public const int PER_PAGE_MIN     = 1;
	public const int PER_PAGE_MAX     = 50;
	public const int PER_PAGE_DEFAULT = 12;

	/**
	 * @param list<string> $categories
	 * @param list<string> $specialties
	 * @param list<string> $difficulties
	 * @param list<string> $tags
	 */
	public function __construct(
		public readonly PostType $post_type,
		public readonly string $q,
		public readonly array $categories,
		public readonly array $specialties,
		public readonly array $difficulties,
		public readonly array $tags,
		public readonly int $page,
		public readonly int $per_page,
		public readonly SortOrder $sort,
	) {
	}

	/**
	 * @param array<string, mixed> $raw
	 *
	 * @throws \InvalidArgumentException When `sort` is not valid for `$post_type`.
	 */
	public static function from_array( PostType $post_type, array $raw ): self {
		return new self(
			post_type: $post_type,
			q: self::parse_q( $raw['q'] ?? '' ),
			categories: self::parse_slug_list( $raw['category'] ?? null ),
			specialties: self::parse_slug_list( $raw['specialty'] ?? null ),
			difficulties: self::parse_slug_list( $raw['difficulty'] ?? null ),
			tags: self::parse_slug_list( $raw['tag'] ?? null ),
			page: self::parse_page( $raw['page'] ?? 1 ),
			per_page: self::parse_per_page( $raw['per_page'] ?? self::PER_PAGE_DEFAULT ),
			sort: isset( $raw['sort'] ) && '' !== (string) $raw['sort']
				? SortOrder::from_string( (string) $raw['sort'], $post_type )
				: SortOrder::default_for( $post_type ),
		);
	}

	private static function parse_q( mixed $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$clean = trim( sanitize_text_field( $value ) );
		if ( strlen( $clean ) > self::Q_MAX_LENGTH ) {
			$clean = substr( $clean, 0, self::Q_MAX_LENGTH );
		}
		return $clean;
	}

	/**
	 * @return list<string>
	 */
	private static function parse_slug_list( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return [];
		}
		$items = is_array( $value ) ? $value : [ $value ];

		$out = [];
		foreach ( $items as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$slug = sanitize_title( $item );
			if ( '' !== $slug ) {
				$out[] = $slug;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function parse_page( mixed $value ): int {
		$page = is_numeric( $value ) ? (int) $value : 1;
		return $page < 1 ? 1 : $page;
	}

	private static function parse_per_page( mixed $value ): int {
		$per_page = is_numeric( $value ) ? (int) $value : self::PER_PAGE_DEFAULT;
		if ( $per_page < self::PER_PAGE_MIN ) {
			return self::PER_PAGE_MIN;
		}
		if ( $per_page > self::PER_PAGE_MAX ) {
			return self::PER_PAGE_MAX;
		}
		return $per_page;
	}
}
