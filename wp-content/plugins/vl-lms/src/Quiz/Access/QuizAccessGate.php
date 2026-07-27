<?php

declare(strict_types=1);

namespace VL\LMS\Quiz\Access;

use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Learn\Access\AccessDecision;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\ProgressionGate;
use VL\LMS\Quiz\QuizCourseResolver;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Post;

/**
 * Gatekeeper for quiz-attempt operations.
 *
 * Three evaluation paths share the same shape but ask different questions:
 * `evaluate_for_read()` runs before a non-mutating quiz-scoped read
 * (`GET /attempts` history) and checks publish status, course resolution,
 * and enrollment. `evaluate_for_start()` runs before a fresh
 * `POST /attempts`, delegating to `evaluate_for_read()` and adding the
 * `_vl_quiz_max_attempts` ceiling on top. `evaluate_for_attempt_action()`
 * runs before any in-flight action (`GET / PATCH save / POST submit`) and
 * checks attempt ownership plus continued enrollment.
 *
 * Progression locks are checked in `evaluate_for_start()` **only**, and
 * the placement of that check is load-bearing in two directions:
 *
 * - Not in `evaluate_for_read()`, for the same reason the max-attempts
 *   ceiling is not there. Attempt history is a record of work the
 *   learner already did; an editor enabling a gate upstream must not
 *   retroactively 403 it away.
 * - Not in `evaluate_for_attempt_action()`, because that would strand an
 *   in-flight attempt — `save` and `submit` would both start failing on
 *   an attempt the server itself issued. Relatedly,
 *   {@see \VL\LMS\Quiz\QuizAttemptService::start()} short-circuits on an
 *   existing in-progress attempt before ever reaching this gate, so a
 *   learner who was mid-attempt when a gate went up can still finish.
 *
 * Within `evaluate_for_start()` the lock is checked *before* the
 * max-attempts ceiling: "this is not open to you yet" is the more
 * informative answer for a quiz the learner was never allowed to begin.
 *
 * Time-limit expiry is intentionally NOT checked here — the service owns
 * that path because it must mutate the attempt's status (auto-finalize)
 * rather than just returning a verdict. The gate's contract is "should
 * we proceed?", not "did the clock run out?".
 *
 * The "no active in-progress attempt" exclusion in
 * {@see self::evaluate_for_start()} only matters when the caller has
 * already checked for an active attempt and skipped this gate — in
 * production the service short-circuits with the existing attempt before
 * ever reaching the max-attempts arm.
 *
 * Reuses {@see AccessDecision} so the surrounding REST mapping treats
 * quiz and lesson denials uniformly.
 *
 * @author Tymofii Synianskyi
 */
class QuizAccessGate {

	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly QuizAttemptRepository $attempts,
		private readonly QuizCourseResolver $resolver,
		private readonly ProgressionGate $progression
	) {
	}

	/**
	 * Read-only variant: publish status, course resolution, and enrollment,
	 * but deliberately *not* the `_vl_quiz_max_attempts` ceiling.
	 *
	 * Attempt history is exactly the surface a learner wants once their
	 * attempts are spent, so reusing {@see self::evaluate_for_start()} here
	 * would deny with `attempts_exhausted` precisely when the read matters
	 * most. {@see self::evaluate_for_start()} delegates here and then layers
	 * the ceiling on top, so the two paths cannot drift.
	 */
	public function evaluate_for_read( int $user_id, int $quiz_id, WP_Post $quiz ): AccessDecision {
		if ( 'publish' !== $quiz->post_status ) {
			return AccessDecision::deny( 'quiz_not_published' );
		}

		$course_id = $this->resolver->find_course_id_for_quiz( $quiz_id );
		if ( null === $course_id ) {
			return AccessDecision::deny( 'quiz_not_in_course' );
		}

		if ( ! $this->enrollments->has_active_access( $user_id, $course_id ) ) {
			return AccessDecision::deny( 'not_enrolled', $course_id );
		}

		return AccessDecision::allow( $course_id, false );
	}

	public function evaluate_for_start( int $user_id, int $quiz_id, WP_Post $quiz ): AccessDecision {
		$decision = $this->evaluate_for_read( $user_id, $quiz_id, $quiz );
		if ( ! $decision->allowed ) {
			return $decision;
		}

		$lock = $this->progression->check(
			$user_id,
			$decision->course_id,
			CurriculumStop::KIND_QUIZ,
			$quiz_id
		);
		if ( null !== $lock ) {
			return AccessDecision::deny_locked( $lock, $decision->course_id );
		}

		$max_attempts = $this->read_max_attempts( $quiz_id );
		if ( $max_attempts > 0 ) {
			$used = $this->attempts->count_for_user_in_quiz( $user_id, $quiz_id );
			if ( $used >= $max_attempts ) {
				return AccessDecision::deny( 'attempts_exhausted', $decision->course_id );
			}
		}

		return $decision;
	}

	public function evaluate_for_attempt_action( int $user_id, QuizAttempt $attempt ): AccessDecision {
		if ( $attempt->user_id !== $user_id ) {
			return AccessDecision::deny( 'forbidden', $attempt->course_id );
		}
		if ( ! $this->enrollments->has_active_access( $user_id, $attempt->course_id ) ) {
			return AccessDecision::deny( 'not_enrolled', $attempt->course_id );
		}
		return AccessDecision::allow( $attempt->course_id, false );
	}

	/**
	 * `_vl_quiz_max_attempts` is stored as a string. Default `0` (unlimited)
	 * if missing or not a positive integer.
	 */
	private function read_max_attempts( int $quiz_id ): int {
		$raw = get_post_meta( $quiz_id, '_vl_quiz_max_attempts', true );
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return 0;
		}
		$value = (int) $raw;
		return $value > 0 ? $value : 0;
	}
}
