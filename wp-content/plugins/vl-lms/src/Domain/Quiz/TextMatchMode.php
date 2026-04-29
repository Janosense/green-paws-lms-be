<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Quiz;

/**
 * How a {@see QuestionType::TEXT} answer is matched against the configured
 * correct value(s) at scoring time.
 *
 * Stored on the question CPT under `_vl_text_match_mode`. `EXACT` is the
 * conservative default — `CASE_INSENSITIVE` widens the accepted answer set,
 * `REGEX` allows the instructor to author a pattern explicitly. Pattern
 * compilation and any sandboxing happens in the Phase 6.1 scoring engine,
 * not here.
 *
 * @author Tymofii Synianskyi
 */
enum TextMatchMode: string {

	case EXACT            = 'exact';
	case CASE_INSENSITIVE = 'case_insensitive';
	case REGEX            = 'regex';

	/**
	 * Lenient parser for the `_vl_text_match_mode` CPT meta value.
	 * Defaults to `EXACT` — the strictest mode is the safest fallback.
	 */
	public static function from_meta_value( string $raw ): self {
		return self::tryFrom( $raw ) ?? self::EXACT;
	}
}
