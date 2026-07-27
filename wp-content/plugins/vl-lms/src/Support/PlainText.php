<?php

declare(strict_types=1);

namespace VL\LMS\Support;

/**
 * Converts WordPress-rendered HTML into a plain-text string fit for JSON
 * payloads, email subjects, and any other consumer that renders the value
 * as text rather than as markup.
 *
 * `wp_strip_all_tags()` alone is not enough. `get_the_title()` and
 * `get_the_excerpt()` run their results through `wptexturize()`, which
 * emits *HTML entities* for smart punctuation — a typed `'` comes back as
 * `&#8217;`, `"` as `&#8220;`/`&#8221;`. Inside `the_content` that is
 * correct, because the browser decodes it while parsing the markup. In a
 * JSON string field it is not: the frontend interpolates it as text
 * (`{{ course.excerpt }}`), no HTML parser ever touches it, and the reader
 * sees the literal `п&#8217;ять`.
 *
 * So every plain-text boundary must strip tags *and* decode entities. The
 * decode runs last, on purpose: doing it first would resurrect `<` from a
 * deliberately escaped `&lt;` and hand it to the tag stripper. Any `<` that
 * reappears after stripping is literal data, and every consumer of this
 * helper escapes on output (Vue interpolation, `esc_html()`, mail headers).
 *
 * @author Tymofii Synianskyi
 */
final class PlainText {

	/**
	 * Strip markup, decode HTML entities, and trim surrounding whitespace.
	 */
	public static function from_html( string $html ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
