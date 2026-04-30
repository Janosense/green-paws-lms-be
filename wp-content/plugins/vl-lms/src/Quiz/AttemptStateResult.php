<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Domain\Quiz\QuizAttempt;

/**
 * Service-level outcome of `start()`, `fetch_state()`, and `submit()`.
 *
 * The triple of attempt, delivered questions, and saved answers carries
 * everything the controller needs to build the `data` envelope —
 * keeping the controller free of business logic and the service free
 * of HTTP concerns.
 *
 * `questions` is the wire shape produced by
 * {@see \VL\LMS\Quiz\QuestionDeliveryTransformer}; it includes or omits
 * reveal-time fields per the quiz policy.
 *
 * @author Tymofii Synianskyi
 */
final class AttemptStateResult {

	/**
	 * @param list<array<string, mixed>> $questions
	 * @param list<QuizAnswer>           $saved_answers
	 * @param bool                       $created `true` only when the
	 *  attempt row was inserted by the call producing this result
	 *  (the fresh-start path). `false` when the result was assembled
	 *  from an existing row (idempotent start, fetch_state, submit).
	 *  The controller uses this to choose between HTTP 201 and 200.
	 */
	public function __construct(
		public readonly QuizAttempt $attempt,
		public readonly array $questions,
		public readonly array $saved_answers,
		public readonly bool $created = false
	) {
	}
}
