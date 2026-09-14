<?php

declare(strict_types=1);

namespace VL\LMS\Import\Plan;

use VL\LMS\Import\Issue\IssueList;

/**
 * The outcome of analysing one uploaded `course.md`: its issues always, the
 * plan only when none of them is an error.
 *
 * @author Tymofii Synianskyi
 */
final readonly class AnalysisResult {

	public function __construct(
		public IssueList $issues,
		public ?ImportPlan $plan
	) {
	}
}
