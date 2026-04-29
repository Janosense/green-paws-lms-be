<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Quiz;

/**
 * When the quiz UI may reveal correct-answer markers to the learner.
 *
 * Stored on the quiz CPT under `_vl_show_correct_answers`. Consumed by the
 * Phase 6.1 attempt-result transformer, which decides whether to include
 * per-answer correctness flags in the response payload. `AFTER_PASS` lets
 * instructors gate the answer key behind a pass — useful for high-stakes
 * exams where retake-after-fail should keep the answer key concealed.
 *
 * @author Tymofii Synianskyi
 */
enum ShowCorrectAnswersPolicy: string {

	case NEVER        = 'never';
	case AFTER_SUBMIT = 'after_submit';
	case AFTER_PASS   = 'after_pass';

	/**
	 * Lenient parser for the `_vl_show_correct_answers` CPT meta value.
	 * Defaults to `AFTER_SUBMIT` — matches the typical instructor expectation.
	 */
	public static function from_meta_value( string $raw ): self {
		return self::tryFrom( $raw ) ?? self::AFTER_SUBMIT;
	}
}
