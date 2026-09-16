<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Services;

use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Repositories\StudyTimeRepository;
use VL\LMS\StudyTime\Services\Exception\HeartbeatFailedException;
use VL\LMS\StudyTime\StudyTimeConfig;
use WP_Post;

/**
 * Turns one client signal into ledger seconds.
 *
 * The increment is computed here and never taken from the client: it is the
 * time since the learner's most recent signal **anywhere in the course**,
 * capped by `cap_seconds`, so two tabs of one course can never add up to more
 * than wall-clock time (`docs/DECISIONS.md` 2026-09-15 — the cap is per
 * learner per course). A first signal in a course adds 0 and only stamps the
 * row.
 *
 * The gate is the one `POST /vl/v1/progress` uses, minus the progression
 * lock: the capability is checked by the controller's `permission_callback`,
 * the enrollment here (`docs/DECISIONS.md` 2026-09-15 — feature-owned ledger;
 * `docs/features/study-time/FEATURE.md` → Invariants).
 *
 * @author Tymofii Synianskyi
 */
class HeartbeatService {

	public function __construct(
		private readonly EntityHierarchy $hierarchy,
		private readonly EnrollmentService $enrollments,
		private readonly StudyTimeRepository $ledger,
		private readonly StudyTimeConfig $config
	) {
	}

	/**
	 * @param 'lesson'|'topic' $entity_type
	 *
	 * @throws HeartbeatFailedException When the entity cannot be addressed or the learner has no active access.
	 */
	public function record(
		int $user_id,
		string $entity_type,
		int $entity_id,
		StudyKind $kind,
		\DateTimeImmutable $now
	): HeartbeatResult {
		$post = get_post( $entity_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new HeartbeatFailedException( HeartbeatFailedException::ENTITY_NOT_FOUND, 'No published lesson or topic with this id.' );
		}

		$expected_type = 'lesson' === $entity_type ? 'vl_lesson' : 'vl_topic';
		if ( $post->post_type !== $expected_type ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new HeartbeatFailedException( HeartbeatFailedException::ENTITY_NOT_FOUND, 'The entity type does not match the post type.' );
		}

		$course = $this->hierarchy->resolveCourse( $post );
		if ( ! $course instanceof WP_Post ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new HeartbeatFailedException( HeartbeatFailedException::ENTITY_NOT_FOUND, 'The entity resolves to no published course.' );
		}
		$course_id = (int) $course->ID;

		if ( ! $this->enrollments->has_active_access( $user_id, $course_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new HeartbeatFailedException( HeartbeatFailedException::NOT_ENROLLED, 'User is not actively enrolled in the owning course.' );
		}

		$this->ledger->add_seconds(
			$user_id,
			$course_id,
			$entity_type,
			$entity_id,
			$kind,
			$this->increment( $user_id, $course_id, $now ),
			$now
		);

		return $this->totals( $user_id, $course_id, $entity_type, $entity_id, $kind );
	}

	/**
	 * Seconds this signal adds: the gap since the learner's last signal in
	 * the course, capped. `0` for a first signal, and `0` — never a negative
	 * — when the stored signal sits in the future, which two PHP workers with
	 * a slightly different clock can produce.
	 *
	 * @return int<0, max>
	 */
	private function increment( int $user_id, int $course_id, \DateTimeImmutable $now ): int {
		$last = $this->ledger->last_signal_at_for_user_in_course( $user_id, $course_id );
		if ( null === $last ) {
			return 0;
		}

		$elapsed = $now->getTimestamp() - $last->getTimestamp();
		return max( 0, min( $elapsed, $this->config->cap_seconds ) );
	}

	/**
	 * @param 'lesson'|'topic' $entity_type
	 */
	private function totals(
		int $user_id,
		int $course_id,
		string $entity_type,
		int $entity_id,
		StudyKind $kind
	): HeartbeatResult {
		$active_seconds = 0;
		$course_seconds = 0;

		foreach ( $this->ledger->rows_for_user_in_course( $user_id, $course_id ) as $row ) {
			$course_seconds += $row->active_seconds;

			if ( $row->entity_type === $entity_type && $row->entity_id === $entity_id && $row->kind === $kind ) {
				$active_seconds = $row->active_seconds;
			}
		}

		return new HeartbeatResult( $active_seconds, $course_seconds );
	}
}
