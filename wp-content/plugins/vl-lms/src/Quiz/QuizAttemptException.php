<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Learn\Progression\LockState;

/**
 * Domain exception for {@see QuizAttemptService}.
 *
 * Carries a machine-readable `code` (which the controller maps into the
 * REST error-code table from §7.3 of the spec), plus two optional
 * payloads that let the client act on the failure without a re-fetch:
 *
 * - `attempt` — the finalized attempt, for the `attempt_expired` /
 *   `attempt_already_finalized` paths, so the player can pivot straight
 *   to the results screen.
 * - `lock` — the progression verdict, for the two gating denials, so the
 *   player can name the quiz that needs passing rather than saying only
 *   "locked".
 *
 * @author Tymofii Synianskyi
 */
final class QuizAttemptException extends \RuntimeException {

	public function __construct(
		public readonly string $error_code,
		string $message = '',
		public readonly ?QuizAttempt $attempt = null,
		public readonly ?LockState $lock = null
	) {
		parent::__construct( '' === $message ? $error_code : $message );
	}
}
