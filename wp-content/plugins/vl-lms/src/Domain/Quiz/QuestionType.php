<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Quiz;

/**
 * Question type stored on a `vl_quiz_question` CPT and frozen into each
 * answer's `answer_data` shape.
 *
 * Mirrors the four shapes the scoring engine knows how to grade:
 * `single_choice` and `true_false` award points all-or-nothing on a single
 * selection, `multiple_choice` is partial-credit-aware, and `text` is
 * graded by {@see TextMatchMode}. The DB column is `VARCHAR` rather than a
 * MySQL `ENUM`, so adding a fifth case in a future phase is a code change
 * only.
 *
 * @author Tymofii Synianskyi
 */
enum QuestionType: string {

	case SINGLE_CHOICE   = 'single_choice';
	case MULTIPLE_CHOICE = 'multiple_choice';
	case TRUE_FALSE      = 'true_false';
	case TEXT            = 'text';

	/**
	 * Lenient parser for the `_vl_question_type` CPT meta value.
	 *
	 * Defaults to `SINGLE_CHOICE` rather than throwing — quiz authoring
	 * (Phase 6.1+) lets instructors save a question without explicitly
	 * picking a type, and the safest fallback is the simplest grading
	 * path. Use `tryFrom()` directly when a strict round-trip is needed.
	 */
	public static function from_meta_value( string $raw ): self {
		return self::tryFrom( $raw ) ?? self::SINGLE_CHOICE;
	}
}
