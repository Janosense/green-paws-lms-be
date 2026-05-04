<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

/**
 * Phase 7.4 — bridges Zoom-side session attendance into the lesson-player
 * progress fan-up.
 *
 * `ParticipantJoinedHandler` (Phase 7.2) fires `vl_lms_session_attendance_recorded`
 * after recording a join row. This listener reacts by recomputing
 * `vl_enrollments.progress_pct` (which now treats sessions as leaves for
 * cohort courses) and re-evaluating the E2 course-completion contract —
 * meaning a session that closes out the leaf set can flip the cohort
 * enrollment to `COMPLETED` directly from the webhook path, without the
 * user touching the lesson-player progress endpoint.
 *
 * Anonymous joiners (no resolved WP user_id) are no-ops — there's no
 * progress row to attribute the attendance to.
 *
 * @author Tymofii Synianskyi
 */
final class SessionAttendanceProgressListener {

	public function __construct(
		private readonly CourseProgressCalculator $calculator,
		private readonly CompletionPropagator $propagator,
	) {
	}

	/**
	 * Hook registration. Idempotent — `add_action` deduplicates by
	 * `[function]:[priority]` automatically.
	 */
	public function register(): void {
		add_action(
			'vl_lms_session_attendance_recorded',
			[ $this, 'on_attendance_recorded' ],
			10,
			3
		);
	}

	public function on_attendance_recorded( int $session_id, ?int $user_id, int $course_id ): void {
		// `session_id` is informational here — the calculator re-enumerates
		// leaves from the course directly.
		unset( $session_id );

		if ( null === $user_id || $user_id <= 0 ) {
			return;
		}
		if ( $course_id <= 0 ) {
			return;
		}

		$this->calculator->recompute( $user_id, $course_id );
		$this->propagator->reevaluate_course_completion( $user_id, $course_id );
	}
}
