<?php

declare(strict_types=1);

namespace VL\LMS\Services\Assignments;

use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Progress\CompletionPropagator;
use WP_Post;

/**
 * Phase 9.4 — bridges a passing assignment grade into the completion-propagation chain.
 *
 * Listens on `vl_lms_assignment_graded` (priority 10). The action is fired
 * by {@see AssignmentSubmissionService::grade()} only when the score meets
 * the passing threshold, so the listener does not need to re-evaluate the
 * gate — every invocation is "this submission counts toward course
 * completion." The work delegates to
 * {@see CompletionPropagator::reevaluate_course_completion()}, the same
 * endpoint quiz submission and session attendance feed into.
 *
 * Not declared `final` — unit tests subclass for hook assertions.
 *
 * @author Tymofii Synianskyi
 */
class AssignmentCompletionListener {

	public function __construct(
		private readonly EntityHierarchy $entity_hierarchy,
		private readonly CompletionPropagator $completion_propagator
	) {
	}

	public function handle( Submission $submission, int $score ): void {
		unset( $score ); // The propagator re-derives pass-state from progress + final-exam arms.

		$course_id = $this->resolve_course_id( $submission->assignment_id );
		if ( null === $course_id ) {
			return;
		}
		$this->completion_propagator->reevaluate_course_completion( $submission->user_id, $course_id );
	}

	protected function resolve_course_id( int $assignment_id ): ?int {
		$post = $this->get_post( $assignment_id );
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		$course = $this->entity_hierarchy->resolveCourse( $post );
		if ( ! $course instanceof WP_Post ) {
			return null;
		}
		return (int) $course->ID;
	}

	protected function get_post( int $id ): ?WP_Post {
		$post = get_post( $id );
		return $post instanceof WP_Post ? $post : null;
	}
}
