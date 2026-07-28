<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

/**
 * Per-course curriculum tallies derived from the canonical stop list.
 *
 * Counts follow stop semantics, not progress-leaf semantics: published
 * entities only, a lesson with topics counts as one lesson *and* its
 * topics, and session-attached quizzes count only on cohort courses
 * (self-paced builds never query sessions). Sessions themselves are not
 * counted — the dashboard stats surface has no session row. Modules are
 * not stops, so their count is recorded separately during the build.
 *
 * @author Tymofii Synianskyi
 */
final class CurriculumCounts {

	public function __construct(
		public readonly int $modules,
		public readonly int $lessons,
		public readonly int $topics,
		public readonly int $quizzes,
		public readonly bool $has_final_exam
	) {
	}
}
