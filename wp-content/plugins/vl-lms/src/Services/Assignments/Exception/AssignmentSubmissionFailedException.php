<?php

declare(strict_types=1);

namespace VL\LMS\Services\Assignments\Exception;

/**
 * Phase 9.4 — submission-side validation / state failures.
 *
 * Carries a machine-readable `code` ({@see CODES}) which the REST layer
 * maps to a stable error string + HTTP status. Throw this rather than
 * generic exceptions so the controller's `catch` block can route by code
 * without parsing the message.
 *
 * @author Tymofii Synianskyi
 */
class AssignmentSubmissionFailedException extends \RuntimeException {

	public const string COURSE_NOT_RESOLVABLE = 'course_not_resolvable';
	public const string NOT_ENROLLED          = 'not_enrolled';
	public const string INVALID_SUBMISSION    = 'invalid_submission';
	public const string SUBMISSION_LOCKED     = 'submission_locked';

	public readonly string $error_code;

	public function __construct(
		string $error_code,
		string $message = '',
		?\Throwable $previous = null
	) {
		$this->error_code = $error_code;
		parent::__construct( '' === $message ? $error_code : $message, 0, $previous );
	}
}
