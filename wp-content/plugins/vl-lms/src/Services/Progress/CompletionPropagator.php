<?php

declare(strict_types=1);

namespace VL\LMS\Services\Progress;

use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\ProgressRepository;
use VL\LMS\Repositories\QuizAttemptRepository;
use WP_Post;
use WP_Query;

/**
 * Synchronous fan-up after a `complete` event.
 *
 * Walks the curriculum from the just-completed entity upward, promoting any
 * ancestor whose siblings are all complete. Topic completions can promote
 * the parent lesson; lesson completions can promote the parent module (or
 * skip it when the lesson is course-direct); both finally drive a course
 * `progress_pct` recomputation through {@see CourseProgressCalculator}.
 *
 * Course-status flips are gated by the **course-completion contract (E2)**
 * — both arms must hold:
 *
 * - **Lesson-progress arm.** {@see CourseProgressCalculator} reports
 *   `progress_pct === 100`.
 * - **Final-exam arm.** Either the course has no `_vl_quiz_is_final_exam`
 *   quiz (arm trivially passes), or the user has at least one passed
 *   final-exam attempt for this course in `vl_quiz_attempts`.
 *
 * Both `propagate()` (driven by lesson-`complete` events) and
 * `reevaluate_course_completion()` (driven by quiz `submit`) route
 * through {@see self::check_course_completion_gate()} so the contract
 * lives in exactly one place.
 *
 * Several `WP_Query` round trips are isolated as `protected` seam methods
 * so unit tests can subclass and supply fixtures without booting
 * WordPress.
 *
 * @author Tymofii Synianskyi
 */
class CompletionPropagator {

	public function __construct(
		private readonly ProgressRepository $progress,
		private readonly EntityHierarchy $hierarchy,
		private readonly CourseProgressCalculator $course_calc,
		private readonly EnrollmentRepository $enrollments,
		private readonly QuizAttemptRepository $quiz_attempts
	) {
	}

	public function propagate(
		int $user_id,
		int $course_id,
		WP_Post $completed_entity
	): PropagationResult {
		$lesson_completed = false;
		$module_completed = false;

		$lesson_post = match ( $completed_entity->post_type ) {
			'vl_topic'  => $this->maybe_promote_lesson( $user_id, $course_id, $completed_entity, $lesson_completed ),
			'vl_lesson' => $completed_entity,
			default     => null,
		};

		if ( $lesson_post instanceof WP_Post ) {
			$module_completed = $this->maybe_promote_module( $user_id, $course_id, $lesson_post );
		}

		$progress_pct     = $this->course_calc->recompute( $user_id, $course_id );
		$course_completed = false;

		if ( 100 === $progress_pct ) {
			$course_completed = $this->maybe_flip_if_both_arms_pass( $user_id, $course_id, $progress_pct );
		}

		return new PropagationResult(
			$lesson_completed,
			$module_completed,
			$progress_pct,
			$course_completed
		);
	}

	/**
	 * Re-check both arms of the course-completion gate (E2) and flip the
	 * enrollment to `COMPLETED` if both pass.
	 *
	 * Idempotent — already-completed enrollments and missing rows are
	 * no-ops. Used by the quiz submit path on a passing final-exam to
	 * close the loop after the lesson-progress side was already at 100%.
	 *
	 * Returns `true` if the enrollment ended (or already was)
	 * `COMPLETED` after this call, `false` otherwise.
	 */
	public function reevaluate_course_completion( int $user_id, int $course_id ): bool {
		$pct = $this->course_calc->recompute( $user_id, $course_id );
		return $this->maybe_flip_if_both_arms_pass( $user_id, $course_id, $pct );
	}

	/**
	 * Apply the two-arm E2 gate and flip if both pass. Caller is
	 * responsible for supplying a freshly-recomputed `$progress_pct`.
	 */
	private function maybe_flip_if_both_arms_pass( int $user_id, int $course_id, int $progress_pct ): bool {
		if ( 100 !== $progress_pct ) {
			return false;
		}
		if ( ! $this->check_final_exam_arm( $user_id, $course_id ) ) {
			return false;
		}
		return $this->maybe_complete_enrollment( $user_id, $course_id );
	}

	/**
	 * Final-exam arm: either no final-exam quiz exists in the course (the
	 * arm passes by default) or the user has at least one passed
	 * final-exam attempt for this course.
	 */
	private function check_final_exam_arm( int $user_id, int $course_id ): bool {
		$final_exam_ids = $this->find_final_exam_quiz_ids_in_course( $course_id );
		if ( [] === $final_exam_ids ) {
			return true;
		}
		foreach ( $final_exam_ids as $quiz_id ) {
			$pass = $this->quiz_attempts->find_passed_final_exam_for_user_in_course(
				$user_id,
				$course_id,
				$quiz_id
			);
			if ( null !== $pass ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Topic-level fan-up: when every sibling topic of `$topic_post` is
	 * `COMPLETED` (including the just-completed one), promote the parent
	 * lesson to `COMPLETED` and return the lesson `WP_Post` so the next step
	 * can consider promoting its module.
	 *
	 * Returns `null` when the lesson cannot be resolved or when at least one
	 * sibling topic is still incomplete.
	 *
	 * `$lesson_completed` is captured by reference so the caller can surface
	 * the flag in {@see PropagationResult} without a second resolution.
	 */
	private function maybe_promote_lesson(
		int $user_id,
		int $course_id,
		WP_Post $topic_post,
		bool &$lesson_completed
	): ?WP_Post {
		$lesson = $this->hierarchy->resolveLesson( $topic_post );
		if ( ! $lesson instanceof WP_Post ) {
			return null;
		}

		$siblings = $this->query_sibling_topics( (int) $lesson->ID );
		if ( ! $this->all_siblings_completed( $user_id, EntityType::TOPIC, $siblings, (int) $topic_post->ID ) ) {
			return null;
		}

		$current = $this->progress->find( $user_id, EntityType::LESSON, (int) $lesson->ID );

		// Preserve a prior position on a re-promotion (re-watch path).
		$position = $current?->position_seconds;
		// Preserve the original `completed_at` if the lesson was already
		// marked completed in a prior cycle — never overwrite the original
		// completion timestamp on a retroactive promotion.
		$completed_at = ( null !== $current && ProgressStatus::COMPLETED === $current->status && null !== $current->completed_at )
			? $current->completed_at
			: $this->now();

		$this->progress->upsert(
			$user_id,
			EntityType::LESSON,
			(int) $lesson->ID,
			$course_id,
			ProgressStatus::COMPLETED,
			$position,
			$completed_at,
			$this->now()
		);

		$lesson_completed = true;
		return $lesson;
	}

	/**
	 * Lesson-level fan-up: when every sibling lesson of `$lesson_post` is
	 * `COMPLETED`, promote the parent module. Course-direct lessons (those
	 * whose parent is the course itself) skip this step entirely.
	 */
	private function maybe_promote_module(
		int $user_id,
		int $course_id,
		WP_Post $lesson_post
	): bool {
		$module = $this->hierarchy->resolveModule( $lesson_post );
		if ( ! $module instanceof WP_Post ) {
			return false;
		}

		$siblings = $this->query_sibling_lessons( (int) $module->ID );
		if ( ! $this->all_siblings_completed( $user_id, EntityType::LESSON, $siblings, (int) $lesson_post->ID ) ) {
			return false;
		}

		$current      = $this->progress->find( $user_id, EntityType::MODULE, (int) $module->ID );
		$completed_at = ( null !== $current && ProgressStatus::COMPLETED === $current->status && null !== $current->completed_at )
			? $current->completed_at
			: $this->now();

		$this->progress->upsert(
			$user_id,
			EntityType::MODULE,
			(int) $module->ID,
			$course_id,
			ProgressStatus::COMPLETED,
			null,
			$completed_at,
			$this->now()
		);

		return true;
	}

	private function maybe_complete_enrollment( int $user_id, int $course_id ): bool {
		$existing = $this->enrollments->find_for_user_and_course( $user_id, $course_id );
		if ( null === $existing ) {
			return false;
		}
		if ( EnrollmentStatus::COMPLETED === $existing->status ) {
			// Already completed; preserve the original completed_at.
			return true;
		}
		if ( EnrollmentStatus::ACTIVE !== $existing->status ) {
			// Don't resurrect revoked / refunded / expired enrollments.
			return false;
		}

		$now = $this->now();
		$this->enrollments->update_progress_state(
			$user_id,
			$course_id,
			100,
			EnrollmentStatus::COMPLETED,
			$now,
			$now
		);

		return true;
	}

	/**
	 * @param list<WP_Post> $siblings
	 */
	private function all_siblings_completed(
		int $user_id,
		EntityType $entity_type,
		array $siblings,
		int $just_completed_id
	): bool {
		if ( [] === $siblings ) {
			return false;
		}

		foreach ( $siblings as $sibling ) {
			$sibling_id = (int) $sibling->ID;
			if ( $sibling_id === $just_completed_id ) {
				// The triggering entity has already been upserted to
				// COMPLETED before propagate() was invoked.
				continue;
			}
			$row = $this->progress->find( $user_id, $entity_type, $sibling_id );
			if ( null === $row || ProgressStatus::COMPLETED !== $row->status ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function query_sibling_topics( int $lesson_id ): array {
		return $this->query_published_children( $lesson_id, 'vl_topic' );
	}

	/**
	 * @return list<WP_Post>
	 */
	protected function query_sibling_lessons( int $parent_id ): array {
		return $this->query_published_children( $parent_id, 'vl_lesson' );
	}

	/**
	 * IDs of every published `vl_quiz` post flagged
	 * `_vl_quiz_is_final_exam=1` whose parent chain resolves to
	 * `$course_id`.
	 *
	 * The query scans every `vl_quiz` with the meta flag in a single
	 * `WP_Query`, then walks each one's parent chain to the course via
	 * {@see EntityHierarchy::resolveCourse()}. `O(n_final_exams_in_system)`
	 * — acceptable until courses commonly host many final exams.
	 *
	 * Isolated as a `protected` seam so tests can subclass and supply a
	 * deterministic curriculum without instantiating `WP_Query`.
	 *
	 * @return list<int>
	 */
	protected function find_final_exam_quiz_ids_in_course( int $course_id ): array {
		$query = new WP_Query(
			[
				'post_type'              => 'vl_quiz',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => [
					[
						'key'   => '_vl_quiz_is_final_exam',
						'value' => '1',
					],
				],
			]
		);

		$ids = [];
		foreach ( $query->posts as $quiz ) {
			if ( ! $quiz instanceof WP_Post ) {
				continue;
			}
			$course = $this->hierarchy->resolveCourse( $quiz );
			if ( $course instanceof WP_Post && (int) $course->ID === $course_id ) {
				$ids[] = (int) $quiz->ID;
			}
		}
		return $ids;
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
