<?php

declare(strict_types=1);

namespace VL\LMS\Services\Assignments\Exception;

/**
 * Thrown when grading or rejection targets a submission id that doesn't exist.
 *
 * @author Tymofii Synianskyi
 */
class SubmissionNotFoundException extends \RuntimeException {

	public function __construct( public readonly int $submission_id ) {
		parent::__construct( sprintf( 'Submission %d not found.', $submission_id ) );
	}
}
