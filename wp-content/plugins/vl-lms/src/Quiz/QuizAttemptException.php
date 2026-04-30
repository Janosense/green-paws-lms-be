<?php

declare(strict_types=1);

namespace VL\LMS\Quiz;

use VL\LMS\Domain\Quiz\QuizAttempt;

/**
 * Domain exception for {@see QuizAttemptService}.
 *
 * Carries a machine-readable `code` (which the controller maps into the
 * REST error-code table from §7.3 of the spec), plus an optional
 * pre-finalized `attempt` payload so the `attempt_expired` /
 * `attempt_already_finalized` paths can return the finalized attempt in
 * the error envelope without a re-fetch.
 *
 * @author Tymofii Synianskyi
 */
final class QuizAttemptException extends \RuntimeException {

	public function __construct(
		public readonly string $error_code,
		string $message = '',
		public readonly ?QuizAttempt $attempt = null
	) {
		parent::__construct( '' === $message ? $error_code : $message );
	}
}
