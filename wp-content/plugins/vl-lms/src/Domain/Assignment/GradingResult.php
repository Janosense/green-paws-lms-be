<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Assignment;

/**
 * Outcome of {@see \VL\LMS\Services\Assignments\AssignmentSubmissionService::grade()}.
 *
 * Holds the freshly-graded {@see Submission} and a precomputed pass flag
 * (`score >= passing_score`). The service inspects `is_passing()` to decide
 * whether to fire `vl_lms_assignment_graded` — the action signals
 * "completion-relevant grade", not "any grade", so the listener never has
 * to re-derive the threshold.
 *
 * @author Tymofii Synianskyi
 */
class GradingResult {

	public function __construct(
		public readonly Submission $submission,
		public readonly bool $is_passing
	) {
	}
}
