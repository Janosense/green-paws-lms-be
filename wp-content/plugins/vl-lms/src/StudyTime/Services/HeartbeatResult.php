<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Services;

/**
 * What one accepted heartbeat leaves behind: the addressed row's total and
 * the learner's total in that course, both after the increment. Mirrors
 * {@see \VL\LMS\Services\Progress\ProgressEventResult} — a plain carrier the
 * REST layer turns into the response body.
 *
 * @author Tymofii Synianskyi
 */
final readonly class HeartbeatResult {

	public function __construct(
		public int $active_seconds,
		public int $course_active_seconds
	) {
	}
}
