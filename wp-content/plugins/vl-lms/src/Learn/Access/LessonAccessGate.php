<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Learn\Progression\CurriculumStop;
use VL\LMS\Learn\Progression\ProgressionGate;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Post;

/**
 * Gatekeeper for lesson and topic playback.
 *
 * Evaluates a fixed five-step ladder (parent resolution, course publish
 * status, preview bypass, active enrollment, progression lock). The
 * first failure wins; the verdict is returned as an immutable
 * {@see AccessDecision}.
 *
 * The "active enrollment" primitive is delegated to
 * {@see EnrollmentService::has_active_access()} — it owns the canonical
 * status + expiry logic, and reimplementing it here would drift over
 * time. The repository alone exposes only row finders, not the access
 * verdict.
 *
 * The progression step is deliberately **last**. Ordering it after
 * enrollment means an unenrolled learner gets the actionable
 * `not_enrolled` rather than being told a lesson is locked behind a quiz
 * they could not sit anyway; ordering it after the preview bypass keeps
 * free marketing previews open regardless of where they sit in the
 * curriculum. Editor bypass lives inside
 * {@see ProgressionGate}, so previewing costs no queries here.
 *
 * Note this is not the prerequisite check that commit `e3ce4ef` removed.
 * That one keyed implicitly off the *previous sibling lesson's*
 * `_vl_lesson_requires_completion` meta with no editor opt-in;
 * `_vl_lesson_requires_completion` remains read by nothing. This gate is
 * driven by explicit per-quiz flags and a whole-course ordering.
 *
 * @author Tymofii Synianskyi
 */
class LessonAccessGate {

	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly EntityHierarchy $hierarchy,
		private readonly ProgressionGate $progression
	) {
	}

	public function check( int $user_id, WP_Post $lesson_or_topic ): AccessDecision {
		$course = $this->hierarchy->resolveCourse( $lesson_or_topic );
		if ( null === $course ) {
			return AccessDecision::deny( 'parent_not_found' );
		}

		if ( 'publish' !== $course->post_status ) {
			return AccessDecision::deny( 'course_unpublished', (int) $course->ID );
		}

		$course_id = (int) $course->ID;

		if ( 'vl_lesson' === $lesson_or_topic->post_type && $this->is_preview( (int) $lesson_or_topic->ID ) ) {
			return AccessDecision::allow( $course_id, true );
		}

		if ( ! $this->enrollments->has_active_access( $user_id, $course_id ) ) {
			return AccessDecision::deny( 'not_enrolled', $course_id );
		}

		$kind = 'vl_topic' === $lesson_or_topic->post_type
			? CurriculumStop::KIND_TOPIC
			: CurriculumStop::KIND_LESSON;

		$lock = $this->progression->check( $user_id, $course_id, $kind, (int) $lesson_or_topic->ID );
		if ( null !== $lock ) {
			return AccessDecision::deny_locked( $lock, $course_id );
		}

		return AccessDecision::allow( $course_id, false );
	}

	private function is_preview( int $lesson_id ): bool {
		$flag = get_post_meta( $lesson_id, '_vl_lesson_is_preview', true );
		return ! empty( $flag );
	}
}
