<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Progress\Progress;

/**
 * Service-level result returned by {@see ProgressService::record()}.
 *
 * Carries everything the controller needs to assemble the success envelope
 * for `POST /vl/v1/progress`:
 *
 * - The `vl_lesson_views.id` of the journal row that was inserted.
 * - The post-write `Progress` value object (status, position, timestamps).
 * - The fan-up flags computed by {@see CompletionPropagator} (or all-false
 *   placeholders for non-`complete` events).
 * - The current `vl_enrollments.progress_pct` — recomputed on `complete`,
 *   passed through unchanged on every other event type.
 *
 * @author Tymofii Synianskyi
 */
final class ProgressEventResult {

	public function __construct(
		public readonly int $view_id,
		public readonly Progress $progress,
		public readonly bool $lesson_completed,
		public readonly bool $module_completed,
		public readonly int $course_progress_pct,
		public readonly bool $course_completed
	) {
	}
}
