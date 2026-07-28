<?php

declare(strict_types=1);

namespace VL\LMS\Services\Enrollment;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Learn\Progression\CurriculumOrder;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Repositories\QuizAttemptRepository;

/**
 * Per-course curriculum stats for the dashboard enrollment records:
 * modules / lessons / topics totals + completed, quiz total + passed, and
 * whether the course carries a final exam.
 *
 * Totals follow {@see CurriculumOrder} stop semantics (see
 * {@see \VL\LMS\Learn\Progression\CurriculumCounts}); completed counts are
 * `vl_progress` COMPLETED rows, which {@see \VL\LMS\Services\Progress\CompletionPropagator}
 * fans up to lessons and modules; passed quizzes are the epoch-filtered
 * counting read, so a progress reset zeroes the card alongside
 * `progress_pct`. Completed/passed are capped at their totals — orphan
 * rows for since-deleted entities must never surface `completed > total`.
 *
 * The whole batch costs two GROUP BY reads plus the memoised
 * `CurriculumOrder` build per course. Deliberately does not touch
 * `CourseProgressCalculator::recompute()` (it persists — wrong for a GET)
 * or `CompletionPropagator::find_final_exam_quiz_ids_in_course()` (a
 * site-wide scan; the stop list already carries the flag).
 *
 * @author Tymofii Synianskyi
 */
// Not `final`: controller-level unit tests mock this service via Mockery,
// which cannot replace methods on final classes. There are no production
// subclasses today and none planned.
class EnrollmentStatsService {

	public function __construct(
		private readonly CurriculumOrder $order,
		private readonly ProgressRepository $progress,
		private readonly QuizAttemptRepository $attempts,
	) {
	}

	/**
	 * Stats for one learner across many courses, keyed by course id. Every
	 * requested id is present, zero-filled when the learner has no rows.
	 *
	 * @param list<int> $course_ids
	 * @return array<int, array{
	 *     modules: array{total: int, completed: int},
	 *     lessons: array{total: int, completed: int},
	 *     topics: array{total: int, completed: int},
	 *     quizzes: array{total: int, passed: int, has_final_exam: bool}
	 * }>
	 */
	public function for_user_in_courses( int $user_id, array $course_ids ): array {
		if ( [] === $course_ids ) {
			return [];
		}

		$completed = $this->progress->completed_counts_for_user_in_courses( $user_id, $course_ids );
		$passed    = $this->attempts->passed_quiz_counts_for_user_in_courses( $user_id, $course_ids );

		$out = [];
		foreach ( $course_ids as $course_id ) {
			$counts = $this->order->counts_for( $course_id );
			$done   = $completed[ $course_id ] ?? [];

			$out[ $course_id ] = [
				'modules' => [
					'total'     => $counts->modules,
					'completed' => min( $done[ EntityType::MODULE->value ] ?? 0, $counts->modules ),
				],
				'lessons' => [
					'total'     => $counts->lessons,
					'completed' => min( $done[ EntityType::LESSON->value ] ?? 0, $counts->lessons ),
				],
				'topics'  => [
					'total'     => $counts->topics,
					'completed' => min( $done[ EntityType::TOPIC->value ] ?? 0, $counts->topics ),
				],
				'quizzes' => [
					'total'          => $counts->quizzes,
					'passed'         => min( $passed[ $course_id ] ?? 0, $counts->quizzes ),
					'has_final_exam' => $counts->has_final_exam,
				],
			];
		}
		return $out;
	}

	/**
	 * Single-course convenience for the single-record handlers (enroll,
	 * self-revoke, progress reset) — the frontend store upserts their
	 * responses into the same list the dashboard renders, so they must
	 * carry the same stats block.
	 *
	 * @return array{
	 *     modules: array{total: int, completed: int},
	 *     lessons: array{total: int, completed: int},
	 *     topics: array{total: int, completed: int},
	 *     quizzes: array{total: int, passed: int, has_final_exam: bool}
	 * }
	 */
	public function for_user_in_course( int $user_id, int $course_id ): array {
		$stats = $this->for_user_in_courses( $user_id, [ $course_id ] );
		return $stats[ $course_id ];
	}
}
