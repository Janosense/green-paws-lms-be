<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\ProgressRepository;
use WP_Post;
use WP_Query;

/**
 * Recomputes `vl_enrollments.progress_pct` for a `(user_id, course_id)`
 * pair.
 *
 * Called once per `event_type='complete'` event by the
 * {@see CompletionPropagator}. The leaf set is the union of every published
 * topic (when its lesson has any) plus every topic-less published lesson —
 * topics live "below" their lesson, so a lesson with topics never counts as
 * its own leaf even if no topic exists yet at curriculum-author time.
 *
 * Persistence is split off into a separate repository call rather than baked
 * into the calculator: the {@see CompletionPropagator} owns the status flip
 * (active → completed), so this class only writes the integer
 * `progress_pct`. The shared `update_progress_state` repository method is
 * used in both call sites to keep the write path consistent.
 *
 * The two `WP_Query` round trips are isolated in `protected` seam methods so
 * unit tests can subclass and feed deterministic curricula.
 *
 * @author Tymofii Synianskyi
 */
class CourseProgressCalculator {

	public function __construct(
		private readonly ProgressRepository $progress,
		private readonly EnrollmentRepository $enrollments
	) {
	}

	/**
	 * Recompute the percentage and persist it to `vl_enrollments`.
	 *
	 * Returns the freshly computed value so the caller (the propagator) can
	 * surface it in the response envelope without an extra repository hop.
	 * Persistence preserves the existing enrollment status — the propagator
	 * is responsible for any subsequent status flip.
	 */
	public function recompute( int $user_id, int $course_id ): int {
		$leaves       = $this->enumerate_leaves( $course_id );
		$total_leaves = count( $leaves );

		$progress_rows = $this->progress->list_for_user_in_course( $user_id, $course_id );

		$completed_keys = [];
		foreach ( $progress_rows as $row ) {
			if ( ProgressStatus::COMPLETED !== $row->status ) {
				continue;
			}
			$completed_keys[ $row->entity_type->value . ':' . $row->entity_id ] = true;
		}

		$completed_leaves = 0;
		foreach ( $leaves as $leaf_key => $_ignored ) {
			if ( isset( $completed_keys[ $leaf_key ] ) ) {
				++$completed_leaves;
			}
		}

		$pct = 0 === $total_leaves
			? 0
			: (int) floor( ( $completed_leaves / $total_leaves ) * 100 );

		$this->persist( $user_id, $course_id, $pct );

		return $pct;
	}

	/**
	 * Persist the new `progress_pct` while preserving status.
	 *
	 * Pulled into a small helper so subclasses (and the propagator) can
	 * override or short-circuit; tests assert it's called with the computed
	 * value.
	 */
	protected function persist( int $user_id, int $course_id, int $progress_pct ): void {
		$existing = $this->enrollments->find_for_user_and_course( $user_id, $course_id );
		if ( null === $existing ) {
			return;
		}

		$completed_at_str = $existing->completed_at;
		$completed_at     = null === $completed_at_str
			? null
			: new \DateTimeImmutable( $completed_at_str, new \DateTimeZone( 'UTC' ) );

		$this->enrollments->update_progress_state(
			$user_id,
			$course_id,
			$progress_pct,
			$existing->status,
			$completed_at,
			$this->now()
		);
	}

	/**
	 * Build the leaf-key set for a course.
	 *
	 * The map is keyed by `"<entity_type>:<entity_id>"` so the caller can
	 * intersect it cheaply with the user's `Progress` rows.
	 *
	 * @return array<string, true>
	 */
	private function enumerate_leaves( int $course_id ): array {
		$leaves = [];

		foreach ( $this->query_lessons_in_course( $course_id ) as $lesson ) {
			$topics = $this->query_topics_under_lesson( (int) $lesson->ID );
			if ( [] !== $topics ) {
				foreach ( $topics as $topic ) {
					$leaves[ EntityType::TOPIC->value . ':' . (int) $topic->ID ] = true;
				}
				continue;
			}
			$leaves[ EntityType::LESSON->value . ':' . (int) $lesson->ID ] = true;
		}

		return $leaves;
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function query_lessons_in_course( int $course_id ): array {
		// Two passes: lessons hung directly off the course, then lessons
		// reached via modules. Combined into a single list — order is
		// irrelevant for percentage math.
		$direct = $this->query_published_children( $course_id, 'vl_lesson' );

		$out = [];
		foreach ( $direct as $lesson ) {
			$out[] = $lesson;
		}

		$modules = $this->query_published_children( $course_id, 'vl_module' );
		foreach ( $modules as $module ) {
			foreach ( $this->query_published_children( (int) $module->ID, 'vl_lesson' ) as $lesson ) {
				$out[] = $lesson;
			}
		}

		return $out;
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function query_topics_under_lesson( int $lesson_id ): array {
		return $this->query_published_children( $lesson_id, 'vl_topic' );
	}

	/**
	 * @return list<WP_Post>
	 */
	private function query_published_children( int $parent_id, string $post_type ): array {
		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_parent'            => $parent_id,
				'post_status'            => 'publish',
				'orderby'                => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$out = [];
		foreach ( $query->posts as $child ) {
			if ( $child instanceof WP_Post ) {
				$out[] = $child;
			}
		}
		return $out;
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
