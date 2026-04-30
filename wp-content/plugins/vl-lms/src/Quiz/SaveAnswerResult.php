<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use VL\LMS\Domain\Quiz\QuizAnswer;
use VL\LMS\Domain\Quiz\QuizAttempt;

/**
 * Service-level outcome of `save_answer()`.
 *
 * `expired=false` is the happy path: `answer` is the upserted
 * {@see QuizAnswer}. `expired=true` is the auto-finalize path —
 * `answer` is `null`, `attempt` is the freshly-finalized version, and
 * the controller can pivot the client straight to the results screen
 * without an extra round-trip.
 *
 * @author Tymofii Synianskyi
 */
final class SaveAnswerResult {

	public function __construct(
		public readonly QuizAttempt $attempt,
		public readonly ?QuizAnswer $answer,
		public readonly bool $expired
	) {
	}
}
