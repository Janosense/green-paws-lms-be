<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Post;

/**
 * Gatekeeper for lesson and topic playback.
 *
 * Evaluates a fixed four-step ladder (parent resolution, course publish
 * status, preview bypass, active enrollment). The first failure wins;
 * the verdict is returned as an immutable {@see AccessDecision}.
 *
 * The "active enrollment" primitive is delegated to
 * {@see EnrollmentService::has_active_access()} — it owns the canonical
 * status + expiry logic, and reimplementing it here would drift over
 * time. The repository alone exposes only row finders, not the access
 * verdict.
 *
 * @author Tymofii Synianskyi
 */
class LessonAccessGate {

	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly EntityHierarchy $hierarchy
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

		return AccessDecision::allow( $course_id, false );
	}

	private function is_preview( int $lesson_id ): bool {
		$flag = get_post_meta( $lesson_id, '_vl_lesson_is_preview', true );
		return ! empty( $flag );
	}
}
