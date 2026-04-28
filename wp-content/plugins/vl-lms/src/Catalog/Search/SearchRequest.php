<?php

declare(strict_types=1);

namespace VL\LMS\Catalog\Search;

use InvalidArgumentException;

/**
 * Parsed and sanitized inputs for a `GET /vl/v1/search` request.
 *
 * Mirrors the validation rules used by the Phase 3.1 catalog list
 * endpoints so the two endpoints behave consistently when shared text
 * (`q`, `page`, `per_page`) is concerned. Specifically:
 *
 * - `q` is trimmed and truncated at 200 chars (silent truncation).
 *   An empty / whitespace-only `q` is rejected at the controller level
 *   with a 400; this value object simply refuses construction so the
 *   error is detected close to the input.
 * - `page` is clamped to `>= 1`.
 * - `per_page` is clamped to `[1, 50]`, default `12`.
 *
 * @author Tymofii Synianskyi
 */
final class SearchRequest {

	public const int Q_MAX_LENGTH     = 200;
	public const int PER_PAGE_MIN     = 1;
	public const int PER_PAGE_MAX     = 50;
	public const int PER_PAGE_DEFAULT = 12;

	public function __construct(
		public readonly string $q,
		public readonly int $page,
		public readonly int $per_page,
	) {
	}

	/**
	 * Build a {@see SearchRequest} from the raw `WP_REST_Request` params.
	 *
	 * @param array<string, mixed> $raw
	 *
	 * @throws InvalidArgumentException When `q` is missing or empty after trim.
	 */
	public static function from_array( array $raw ): self {
		$q = self::parse_q( $raw['q'] ?? '' );
		if ( '' === $q ) {
			throw new InvalidArgumentException( 'Search query is required' );
		}

		return new self(
			q: $q,
			page: self::parse_page( $raw['page'] ?? 1 ),
			per_page: self::parse_per_page( $raw['per_page'] ?? self::PER_PAGE_DEFAULT ),
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
