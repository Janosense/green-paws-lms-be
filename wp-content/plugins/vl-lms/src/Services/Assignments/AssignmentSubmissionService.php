<?php

declare(strict_types=1);

namespace VL\LMS\Services\Assignments;

use VL\LMS\Domain\Assignment\GradingResult;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;
use VL\LMS\Learn\EntityHierarchy;
use VL\LMS\Repositories\AssignmentSubmissionRepository;
use VL\LMS\Services\Assignments\Exception\AssignmentSubmissionFailedException;
use VL\LMS\Services\Assignments\Exception\InvalidScoreException;
use VL\LMS\Services\Assignments\Exception\SubmissionNotFoundException;
use VL\LMS\Services\Enrollment\EnrollmentService;
use WP_Post;

/**
 * Phase 9.4 — orchestrates the assignment-submission lifecycle.
 *
 * Three operations:
 *  - {@see self::submit()} — student creates / re-submits a pending row.
 *  - {@see self::grade()}  — admin scores a pending submission; fires
 *    `vl_lms_assignment_graded` only when `score >= passing_score` so the
 *    completion-propagator path stays free of failing grades.
 *  - {@see self::reject()} — admin rejects with feedback; never fires the
 *    graded action.
 *
 * Course resolution walks the assignment's `post_parent` chain via
 * {@see EntityHierarchy::resolveCourse()} — assignments may sit under
 * lesson, module, course, or session, so the helper is the single seam.
 *
 * Not declared `final` — unit tests subclass to bypass `get_post()` and
 * `get_post_meta()` without booting WP.
 *
 * @author Tymofii Synianskyi
 */
class AssignmentSubmissionService {

	public function __construct(
		private readonly AssignmentSubmissionRepository $repo,
		private readonly EnrollmentService $enrollment_service,
		private readonly EntityHierarchy $entity_hierarchy
	) {
	}

	public function submit(
		int $assignment_id,
		int $user_id,
		?string $text,
		?string $file_url,
		?string $file_name
	): Submission {
		$course_id = $this->resolve_course_id( $assignment_id );
		if ( null === $course_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new AssignmentSubmissionFailedException( AssignmentSubmissionFailedException::COURSE_NOT_RESOLVABLE, 'Assignment is not attached to a resolvable course.' );
		}

		if ( ! $this->enrollment_service->has_active_access( $user_id, $course_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new AssignmentSubmissionFailedException( AssignmentSubmissionFailedException::NOT_ENROLLED, 'User is not actively enrolled in the owning course.' );
		}

		$this->validate_payload( $assignment_id, $text, $file_url );

		$now      = $this->now();
		$existing = $this->repo->find_by_assignment_user( $assignment_id, $user_id );

		if ( null !== $existing ) {
			if ( SubmissionStatus::PENDING !== $existing->status ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
				throw new AssignmentSubmissionFailedException( AssignmentSubmissionFailedException::SUBMISSION_LOCKED, 'Submission has been graded or rejected and cannot be re-submitted.' );
			}
			$updated = $existing->with_resubmission( $text, $file_url, $file_name, $now );
			$this->repo->update( $updated );
			return $updated;
		}

		$fresh = new Submission(
			0,
			$assignment_id,
			$user_id,
			SubmissionStatus::PENDING,
			$text,
			$file_url,
			$file_name,
			null,
			null,
			null,
			$now,
			null
		);
		$id    = $this->repo->insert( $fresh );

		return new Submission(
			$id,
			$fresh->assignment_id,
			$fresh->user_id,
			$fresh->status,
			$fresh->submission_text,
			$fresh->submission_file_url,
			$fresh->submission_file_name,
			$fresh->score,
			$fresh->feedback,
			$fresh->graded_by,
			$fresh->submitted_at,
			$fresh->graded_at
		);
	}

	public function grade( int $submission_id, int $score, ?string $feedback, int $grader_user_id ): GradingResult {
		$submission = $this->repo->find( $submission_id );
		if ( null === $submission ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new SubmissionNotFoundException( $submission_id );
		}

		$max_score     = $this->meta_int( $submission->assignment_id, '_vl_assignment_max_score', 100 );
		$passing_score = $this->meta_int( $submission->assignment_id, '_vl_assignment_passing_score', 0 );

		if ( $score < 0 || $score > $max_score ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new InvalidScoreException( $score, $max_score );
		}

		$graded = $submission->with_grade( $score, $feedback, $grader_user_id, $this->now() );
		$this->repo->update( $graded );

		$is_passing = $score >= $passing_score;
		if ( $is_passing ) {
			do_action( 'vl_lms_assignment_graded', $graded, $score );
		}

		return new GradingResult( $graded, $is_passing );
	}

	public function reject( int $submission_id, string $feedback, int $grader_user_id ): Submission {
		$submission = $this->repo->find( $submission_id );
		if ( null === $submission ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new SubmissionNotFoundException( $submission_id );
		}

		$rejected = $submission->with_rejection( $feedback, $grader_user_id, $this->now() );
		$this->repo->update( $rejected );

		return $rejected;
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

	private function validate_payload( int $assignment_id, ?string $text, ?string $file_url ): void {
		$type          = $this->meta_string( $assignment_id, '_vl_assignment_submission_type', 'text' );
		$text_required = $this->meta_bool( $assignment_id, '_vl_assignment_text_required' );
		$file_required = $this->meta_bool( $assignment_id, '_vl_assignment_file_required' );

		$needs_text = ( 'text' === $type || 'both' === $type ) && $text_required;
		$needs_file = ( 'file' === $type || 'both' === $type ) && $file_required;

		if ( $needs_text && ( null === $text || '' === trim( $text ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new AssignmentSubmissionFailedException( AssignmentSubmissionFailedException::INVALID_SUBMISSION, 'Submission text is required.' );
		}
		if ( $needs_file && ( null === $file_url || '' === trim( $file_url ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing domain exception.
			throw new AssignmentSubmissionFailedException( AssignmentSubmissionFailedException::INVALID_SUBMISSION, 'Submission file is required.' );
		}
	}

	protected function get_post( int $id ): ?WP_Post {
		$post = get_post( $id );
		return $post instanceof WP_Post ? $post : null;
	}

	protected function meta_int( int $post_id, string $key, int $default ): int {
		$value = get_post_meta( $post_id, $key, true );
		if ( '' === $value || null === $value ) {
			return $default;
		}
		return (int) $value;
	}

	protected function meta_string( int $post_id, string $key, string $default ): string {
		$value = get_post_meta( $post_id, $key, true );
		if ( '' === $value || null === $value || ! is_scalar( $value ) ) {
			return $default;
		}
		return (string) $value;
	}

	protected function meta_bool( int $post_id, string $key ): bool {
		$value = get_post_meta( $post_id, $key, true );
		if ( '' === $value || null === $value ) {
			return false;
		}
		return (bool) (int) $value;
	}

	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
