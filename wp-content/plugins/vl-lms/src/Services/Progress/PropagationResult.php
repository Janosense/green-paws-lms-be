<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

/**
 * Outcome of {@see CompletionPropagator::propagate()}.
 *
 * Pure VO — never mocked, never extended. Each flag describes whether the
 * fan-up promoted the corresponding ancestor to `COMPLETED` during this
 * request; `course_progress_pct` is the recomputed value persisted to
 * `vl_enrollments.progress_pct`.
 *
 * @author Tymofii Synianskyi
 */
final class PropagationResult {

	public function __construct(
		public readonly bool $lesson_completed,
		public readonly bool $module_completed,
		public readonly int $course_progress_pct,
		public readonly bool $course_completed
	) {
	}
}
