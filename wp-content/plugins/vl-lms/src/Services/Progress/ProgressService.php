<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Domain\Progress\ViewEventType;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\LessonViewRepository;
use VL\LMS\Repositories\ProgressRepository;
use WP_Post;

/**
 * Orchestrates a single `POST /vl/v1/progress` event.
 *
 * Resolves the target post, applies the position-write rule, computes the
 * new status, upserts `vl_progress`, appends one row to `vl_lesson_views`,
 * and (only on `event_type=complete`) hands off to the
 * {@see CompletionPropagator} for the synchronous fan-up.
 *
 * The controller does its own pre-flight resolution (post + course +
 * enrollment) for precise error mapping; this service repeats the entity
 * lookup defensively in case of races but trusts the caller's enrollment
 * gate.
 *
 * `class` (not `final class`) so {@see \VL\LMS\Tests\Unit\Api\ProgressControllerTest}
 * can mock it with Mockery.
 *
 * @author Tymofii Synianskyi
 */
class ProgressService {

	public function __construct(
		private readonly ProgressRepository $progress,
		private readonly LessonViewRepository $views,
		private readonly EntityHierarchy $hierarchy,
		private readonly PositionWriteRule $position_rule,
		private readonly CompletionPropagator $propagator,
		private readonly EnrollmentRepository $enrollments
	) {
	}

	/**
	 * Persist one progress event and return the materialized result.
	 *
	 * @throws \RuntimeException With a stable message code on
	 *                           `entity_not_found` or `hierarchy_failure`.
	 */
	public function record( int $user_id, ProgressEventRequest $request ): ProgressEventResult {
		$entity = $this->resolve_entity( $request );
		$course = $this->hierarchy->resolveCourse( $entity );
		if ( ! $course instanceof WP_Post ) {
			throw new \RuntimeException( 'hierarchy_failure' );
		}
		$course_id = (int) $course->ID;

		$current = $this->progress->find( $user_id, $request->entity_type, $request->entity_id );

		$position_to_write = $this->position_rule->apply( $current, $request, $entity );

		$now                       = $this->now();
		$is_complete               = ViewEventType::COMPLETE === $request->event_type;
		[ $status, $completed_at ] = $this->resolve_status_and_completion( $current, $is_complete, $now );

		$progress_row = $this->progress->upsert(
			$user_id,
			$request->entity_type,
			$request->entity_id,
			$course_id,
			$status,
			$position_to_write,
			$completed_at,
			$now
		);

		$lesson_id = $this->resolve_journal_lesson_id( $entity, $request );
		$topic_id  = EntityType::TOPIC === $request->entity_type ? $request->entity_id : null;

		$view = $this->views->insert(
			$user_id,
			$lesson_id,
			$topic_id,
			$request->session_uuid,
			$request->event_type,
			$request->position_seconds,
			$request->payload,
			$now
		);

		if ( ! $is_complete ) {
			return new ProgressEventResult(
				view_id: $view->id,
				progress: $progress_row,
				lesson_completed: false,
				module_completed: false,
				course_progress_pct: $this->current_course_pct( $user_id, $course_id ),
				course_completed: false
			);
		}

		$propagation = $this->propagator->propagate( $user_id, $course_id, $entity );

		return new ProgressEventResult(
			view_id: $view->id,
			progress: $progress_row,
			lesson_completed: $propagation->lesson_completed,
			module_completed: $propagation->module_completed,
			course_progress_pct: $propagation->course_progress_pct,
			course_completed: $propagation->course_completed
		);
	}

	private function resolve_entity( ProgressEventRequest $request ): WP_Post {
		$post = get_post( $request->entity_id );
		if ( ! $post instanceof WP_Post ) {
			throw new \RuntimeException( 'entity_not_found' );
		}
		if ( 'publish' !== $post->post_status ) {
			throw new \RuntimeException( 'entity_not_found' );
		}
		$expected = match ( $request->entity_type ) {
			EntityType::LESSON => 'vl_lesson',
			EntityType::TOPIC  => 'vl_topic',
			default            => null,
		};
		if ( null === $expected || $post->post_type !== $expected ) {
			throw new \RuntimeException( 'entity_not_found' );
		}
		return $post;
	}

	/**
	 * @return array{0: ProgressStatus, 1: ?\DateTimeImmutable}
	 */
	private function resolve_status_and_completion(
		?Progress $current,
		bool $is_complete,
		\DateTimeImmutable $now
	): array {
		if ( $is_complete ) {
			// Re-completion preserves the original `completed_at` so the
			// audit timestamp reflects the FIRST time the user finished.
			if ( null !== $current && ProgressStatus::COMPLETED === $current->status && null !== $current->completed_at ) {
				return [ ProgressStatus::COMPLETED, $current->completed_at ];
			}
			return [ ProgressStatus::COMPLETED, $now ];
		}

		if ( null === $current || ProgressStatus::NOT_STARTED === $current->status ) {
			return [ ProgressStatus::IN_PROGRESS, null ];
		}

		// Already in_progress or completed — preserve the existing status
		// and any prior `completed_at`. A `progress` event after completion
		// is a re-watch; don't downgrade.
		return [ $current->status, $current->completed_at ];
	}

	private function resolve_journal_lesson_id( WP_Post $entity, ProgressEventRequest $request ): int {
		if ( EntityType::LESSON === $request->entity_type ) {
			return $request->entity_id;
		}

		$lesson = $this->hierarchy->resolveLesson( $entity );
		if ( ! $lesson instanceof WP_Post ) {
			throw new \RuntimeException( 'hierarchy_failure' );
		}
		return (int) $lesson->ID;
	}

	private function current_course_pct( int $user_id, int $course_id ): int {
		$row = $this->enrollments->find_for_user_and_course( $user_id, $course_id );
		return null === $row ? 0 : $row->progress_pct;
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
