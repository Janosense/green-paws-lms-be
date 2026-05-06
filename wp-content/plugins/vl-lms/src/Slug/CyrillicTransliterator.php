<?php

declare(strict_types=1);

namespace VL\LMS\Slug;

/**
 * Pure Ukrainian/Russian Cyrillic → Latin transliterator for slug generation.
 *
 * Why this lives here, not in WordPress core: WP's `sanitize_title_with_dashes`
 * lowercases and replaces whitespace with hyphens, but it leaves Cyrillic
 * characters untouched — `"Камери серця"` becomes `"камери-серця"`, not the
 * ASCII slug we want. Without an opinion on transliteration WP can't make one
 * for us; every locale has its own rules.
 *
 * The mapping below is a slug-friendly variant of the Ukrainian "passport"
 * standard (Resolution 55 of the Cabinet of Ministers, 2010 — also DSTU
 * 9112:2021). The official rules carry word-position alternations
 * (`є → ye` at word start vs `ie` elsewhere; same for `ї`, `й`, `ю`, `я`),
 * which are useful for proper names but produce noisier slugs and are awkward
 * to apply consistently across composite words. We collapse to the
 * non-initial form everywhere so a single string-translation pass produces
 * stable output: `"Сергій Іванов"` → `"sergii-ivanov"`. Russian-specific
 * letters (`ё`, `ы`, `ъ`, `э`) are mapped permissively in case mixed-locale
 * content slips in.
 *
 * Pure logic — no WordPress, no `$wpdb`, no globals. Lives under `src/Slug/`
 * rather than `src/Domain/` because it isn't a domain concept; it's
 * infrastructure for the slug-sanitization listener that wraps it. Direct
 * unit tests under `tests/Unit/Slug/`.
 *
 * @author Tymofii Synianskyi
 */
final class CyrillicTransliterator {

	/**
	 * Lowercase-only transliteration map. WP's slug pipeline lowercases the
	 * input before our filter runs (see `sanitize_title_with_dashes`), so we
	 * never need to handle uppercase here. Mixing in uppercase would risk
	 * surprising mid-string capitalisation if the upstream sanitizer changes.
	 *
	 * @var array<string, string>
	 */
	private const MAP = [
		// Ukrainian + Russian shared
		'а' => 'a',
		'б' => 'b',
		'в' => 'v',
		'г' => 'h', // Ukrainian convention; Russian г → g, but Ukrainian content is the dominant case here
		'д' => 'd',
		'е' => 'e',
		'ж' => 'zh',
		'з' => 'z',
		'к' => 'k',
		'л' => 'l',
		'м' => 'm',
		'н' => 'n',
		'о' => 'o',
		'п' => 'p',
		'р' => 'r',
		'с' => 's',
		'т' => 't',
		'у' => 'u',
		'ф' => 'f',
		'х' => 'kh',
		'ц' => 'ts',
		'ч' => 'ch',
		'ш' => 'sh',
		'щ' => 'shch',
		// Ukrainian-specific
		'ґ' => 'g',
		'є' => 'ie',
		'и' => 'y',
		'і' => 'i',
		'ї' => 'i',
		'й' => 'i',
		'ю' => 'iu',
		'я' => 'ia',
		// Russian-specific (permissive coverage for mixed content)
		'ё' => 'e',
		'ъ' => '',
		'ы' => 'y',
		'э' => 'e',
		'ь' => '',
		// Apostrophe variants used in Ukrainian (ʼ, ', ’) are dropped
		'\'' => '',
		'ʼ'  => '',
		'’'  => '',
	];

	/**
	 * Transliterate any Cyrillic in the input to lowercase ASCII slug form
	 * and discard anything else that is not slug-safe.
	 *
	 * The function is idempotent: applying it twice yields the same result.
	 * Whitespace, hyphens, and percent-encoded characters in the input are
	 * normalised to single hyphens; leading and trailing hyphens are
	 * trimmed.
	 */
	public function slug( string $input ): string {
		// `urldecode` covers the case where a slug arrives already
		// percent-encoded (e.g. from a paste of an existing URL).
		$decoded = rawurldecode( $input );

		// strtr does not lowercase; lowercase first so the map's lowercase
		// keys can match. mb_strtolower handles Cyrillic case folding,
		// strtolower would silently leave Cyrillic uppercase.
		$lower = function_exists( 'mb_strtolower' )
			? mb_strtolower( $decoded, 'UTF-8' )
			: strtolower( $decoded );

		$translated = strtr( $lower, self::MAP );

		// At this point any remaining non-ASCII is unmapped (Greek, CJK,
		// etc.). Replace anything outside `[a-z0-9-]` with a hyphen, then
		// collapse runs of hyphens.
		$ascii_only = preg_replace( '/[^a-z0-9-]+/u', '-', $translated );
		if ( ! is_string( $ascii_only ) ) {
			$ascii_only = '';
		}

		$collapsed = preg_replace( '/-+/', '-', $ascii_only );
		if ( ! is_string( $collapsed ) ) {
			$collapsed = '';
		}

		return trim( $collapsed, '-' );
	}
}
