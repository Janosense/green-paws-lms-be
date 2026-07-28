<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Repositories\QuizAttemptRepository;

/**
 * Self-service course progress reset — "fresh start with preserved history".
 *
 * A reset stamps `vl_enrollments.progress_reset_at` and reactivates the
 * enrollment at 0% / `completed_at = NULL`, hard-deletes the learner's
 * `vl_progress` rows for the course, and flips their in-flight quiz
 * attempts to ABANDONED. Everything else is deliberately untouched:
 *
 * - **Quiz attempts stay.** They remain visible in the attempt-history
 *   endpoint, but the counting reads in {@see QuizAttemptRepository}
 *   exclude attempts started before the epoch — so progression locks
 *   re-engage, the max-attempts ceiling resets, and the E2 final-exam
 *   arm re-arms.
 * - **Certificates stay.** This service never fires
 *   `vl_lms_enrollment_revoked` (whose listener revokes certificates);
 *   on re-completion `CertificateService` idempotency prevents a
 *   duplicate issue.
 * - **`vl_lesson_views`, session attendance, and assignment submissions
 *   stay** — append-only history. Kept attendance means cohort session
 *   leaves re-complete on the next recompute.
 *
 * Write ordering (no transactions, matching the rest of the codebase):
 * abandon first (harmless alone), stamp the epoch second (the semantic
 * core — if the wipe then fails, the gates are already reset and a retry
 * fixes the leftover rows), wipe third, action last so
 * `vl_lms_progress_reset` only ever announces a fully-applied reset.
 *
 * @author Tymofii Synianskyi
 */
class ProgressResetService {

	public function __construct(
		private readonly EnrollmentRepository $enrollments,
		private readonly ProgressRepository $progress,
		private readonly QuizAttemptRepository $quiz_attempts
	) {
	}

	/**
	 * Resets one learner's progress in one course.
	 *
	 * Returns the refreshed enrollment, or `null` when there is no row for
	 * the pair or its status is not ACTIVE / COMPLETED (REVOKED, REFUNDED,
	 * and EXPIRED enrollments have nothing a learner may reset). The REST
	 * controller pre-guards the same conditions; the re-check here keeps
	 * the service safe for any future caller.
	 */
	public function reset( int $user_id, int $course_id ): ?Enrollment {
		$existing = $this->enrollments->find_for_user_and_course( $user_id, $course_id );
		if ( ! $existing instanceof Enrollment ) {
			return null;
		}
		if ( EnrollmentStatus::ACTIVE !== $existing->status && EnrollmentStatus::COMPLETED !== $existing->status ) {
			return null;
		}

		$this->quiz_attempts->abandon_in_progress_for_user_in_course( $user_id, $course_id );

		if ( ! $this->enrollments->mark_progress_reset( $user_id, $course_id, $this->now() ) ) {
			return null;
		}

		$this->progress->delete_for_user_in_course( $user_id, $course_id );

		/**
		 * Fires after a learner's course progress has been fully reset.
		 *
		 * @param int $user_id       Learner user ID.
		 * @param int $course_id     Course post ID.
		 * @param int $enrollment_id Enrollment row ID.
		 */
		do_action( 'vl_lms_progress_reset', $user_id, $course_id, $existing->id );

		return $this->enrollments->find_for_user_and_course( $user_id, $course_id );
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
