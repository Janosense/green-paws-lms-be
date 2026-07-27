<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Progression;

use VL\LMS\Learn\QuizStatusOverlay;
use VL\LMS\Repositories\QuizAttemptRepository;

/**
 * The seam the access gates and the curriculum transformer call to ask
 * "what is closed to this learner in this course?".
 *
 * Composes {@see CurriculumOrder} (canonical order),
 * {@see QuizStatusOverlay} (the learner's quiz standing) and
 * {@see ProgressionPolicy} (the rules), and memoises the resulting
 * {@see LockMap} per user/course for the life of the request — a lesson
 * read asks once, the curriculum endpoint asks once, and nothing
 * re-derives.
 *
 * ## Cost
 *
 * The access gates run on content reads that build no curriculum tree
 * today, so this class is careful about what it issues:
 *
 *   - three to five `WP_Query` calls to build the order, fixed
 *     regardless of course size (see {@see CurriculumOrder});
 *   - **only if** that order contains a quiz carrying a gating flag, one
 *     further `status_map_for_user_in_course()` round trip.
 *
 * So a course that never enables the feature — every course that exists
 * today — pays the bounded order build and nothing else, and the
 * per-learner attempt aggregation is skipped entirely.
 *
 * A site-wide `meta_query` pre-check was considered as a cheaper fast
 * path and rejected: it scales with the number of gated quizzes across
 * *all* courses and then needs a `post_parent` walk per hit to find out
 * which course each belongs to, which overtakes the bounded order build
 * as soon as a handful of courses adopt the feature.
 *
 * ## Editor bypass
 *
 * Users who can edit the course are never locked. Instructors walk their
 * own courses through the `?preview=1` flow and must be able to reach
 * every entity without first passing its quizzes. This is checked before
 * any query runs, so previewing costs nothing.
 *
 * @author Tymofii Synianskyi
 */
class ProgressionGate {

	/** @var array<string, LockMap> */
	private array $memo = [];

	public function __construct(
		private readonly CurriculumOrder $order,
		private readonly QuizAttemptRepository $attempts
	) {
	}

	/**
	 * Lock state for one entity, or `null` when it is open.
	 *
	 * `$kind` is one of the {@see CurriculumStop} `KIND_*` constants.
	 */
	public function check( int $user_id, int $course_id, string $kind, int $entity_id ): ?LockState {
		return $this->lock_map( $user_id, $course_id )->for_entity( $kind, $entity_id );
	}

	/**
	 * Every lock in the course for this learner, for callers that stamp a
	 * whole tree at once.
	 */
	public function lock_map( int $user_id, int $course_id ): LockMap {
		$key = $user_id . ':' . $course_id;

		if ( ! isset( $this->memo[ $key ] ) ) {
			$this->memo[ $key ] = $this->compute( $user_id, $course_id );
		}

		return $this->memo[ $key ];
	}

	private function compute( int $user_id, int $course_id ): LockMap {
		if ( $this->can_edit_course( $user_id, $course_id ) ) {
			return LockMap::empty();
		}

		$stops = $this->order->for_course( $course_id );
		if ( ! $this->order->has_gated_quizzes( $stops ) ) {
			return LockMap::empty();
		}

		$overlay = QuizStatusOverlay::fromMap(
			$this->attempts->status_map_for_user_in_course( $user_id, $course_id )
		);

		return ( new ProgressionPolicy() )->evaluate( $stops, $overlay );
	}

	/**
	 * Isolated so tests can bypass WordPress's capability stack.
	 */
	protected function can_edit_course( int $user_id, int $course_id ): bool {
		return user_can( $user_id, 'edit_post', $course_id );
	}
}
