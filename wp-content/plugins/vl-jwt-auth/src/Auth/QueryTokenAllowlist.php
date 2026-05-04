<?php

declare(strict_types=1);

namespace VLJwtAuth\Auth;

/**
 * Path allowlist for the `?token=` query-parameter auth fallback.
 *
 * The fallback exists for redirect-style endpoints the frontend invokes via
 * a top-level `window.location.assign()` (Zoom join / recording / cohort
 * session links). Bare browser navigation cannot attach an `Authorization`
 * header, and we do not want the bearer token in cookies — the token in the
 * query string is the narrowest workable carrier. This VO encodes which
 * paths are eligible: the fallback never applies elsewhere.
 *
 * Patterns are anchored regular expressions matched against the request
 * route path (e.g. `/vl/v1/webinars/foo-slug/join`). The `{slug}` macro
 * expands to a slug-safe character class. Order does not matter.
 *
 * @author Tymofii Synianskyi
 */
final class QueryTokenAllowlist {

	private const string SLUG_CHARS = '[A-Za-z0-9_-]+';

	/**
	 * @var list<string>
	 */
	private array $compiled;

	/**
	 * @param list<string> $patterns Path patterns. `{slug}` is expanded to a slug character class.
	 */
	public function __construct( array $patterns ) {
		$this->compiled = array_map(
			static fn ( string $pattern ): string => self::compile( $pattern ),
			$patterns
		);
	}

	public static function default(): self {
		return new self(
			[
				'/vl/v1/webinars/{slug}/join',
				'/vl/v1/webinars/{slug}/recording',
				'/vl/v1/learn/sessions/{slug}/join',
				'/vl/v1/learn/sessions/{slug}/recording',
			]
		);
	}

	public function matches( string $path ): bool {
		foreach ( $this->compiled as $regex ) {
			if ( 1 === preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}

	private static function compile( string $pattern ): string {
		$escaped = str_replace( '\\{slug\\}', self::SLUG_CHARS, preg_quote( $pattern, '#' ) );
		return '#^' . $escaped . '$#';
	}
}
