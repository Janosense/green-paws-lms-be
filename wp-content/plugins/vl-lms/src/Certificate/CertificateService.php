<?php

declare(strict_types=1);

namespace VL\LMS\Certificate;

use VL\LMS\Domain\Certificate\Certificate;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\CertificateRepository;
use VL\LMS\Repositories\EnrollmentRepository;
use VL\LMS\Repositories\QuizAttemptRepository;
use VL\LMS\Support\Logger;
use WP_Post;
use WP_Query;

/**
 * Phase 6.3 — orchestrates certificate issuance and revocation.
 *
 * Issuance is idempotent: if an active certificate exists for the
 * `(user, course)` pair, the service returns it instead of inserting a
 * new row. The auto-issuer ({@see CertificateAutoIssuer}) calls this
 * service from the `vl_lms_course_completed` action handler, but the
 * public surface is also safe for manual / admin invocation.
 *
 * Snapshot is built fresh at issuance time via {@see SnapshotBuilder}
 * and stored on the row — once persisted, no live data is read at
 * render time. This is what makes future course-title or instructor
 * changes invisible to historical certificates.
 *
 * The final-exam quiz lookup that powers `final_score` snapshotting
 * mirrors `CompletionPropagator::find_final_exam_quiz_ids_in_course()`.
 * It's re-implemented as a `protected` seam here rather than extracted
 * to a shared helper — both are tiny `WP_Query` wrappers, and keeping
 * each subsystem self-contained is cheaper than coupling them through a
 * new shared utility.
 *
 * @author Tymofii Synianskyi
 */
class CertificateService {

	public function __construct(
		private readonly CertificateRepository $certificates,
		private readonly EnrollmentRepository $enrollments,
		private readonly SnapshotBuilder $snapshot_builder,
		private readonly QuizAttemptRepository $quiz_attempts,
		private readonly Logger $logger
	) {
	}

	/**
	 * Issue a certificate for `$enrollment_id` if eligible. Idempotent.
	 *
	 * @see IssueResult for the four valid outcome shapes.
	 */
	public function issue_for_enrollment( int $enrollment_id ): IssueResult {
		$enrollment = $this->enrollments->find_by_id( $enrollment_id );
		if ( null === $enrollment ) {
			$this->logger->warning( 'CertificateService: enrollment not found', [ 'enrollment_id' => $enrollment_id ] );
			return IssueResult::failure( 'enrollment_not_found', 'Enrollment ' . $enrollment_id . ' not found.' );
		}

		if ( EnrollmentStatus::COMPLETED !== $enrollment->status ) {
			return IssueResult::failure(
				'enrollment_not_completed',
				'Enrollment ' . $enrollment_id . ' is not in completed status.'
			);
		}

		if ( ! $this->course_certificate_enabled( $enrollment->course_id ) ) {
			return IssueResult::skipped(
				'certificates_disabled',
				'Certificates are not enabled on course ' . $enrollment->course_id . '.'
			);
		}

		$existing = $this->certificates->find_active_for_user_in_course(
			$enrollment->user_id,
			$enrollment->course_id
		);
		if ( null !== $existing ) {
			return IssueResult::idempotent( $existing );
		}

		[ $final_score, $final_max_score ] = $this->resolve_final_score(
			$enrollment->user_id,
			$enrollment->course_id
		);

		$now      = $this->current_time_utc();
		$snapshot = $this->snapshot_builder->build_snapshot(
			$enrollment->user_id,
			$enrollment->course_id,
			$final_score,
			$final_max_score,
			$now
		);

		$candidate = new Certificate(
			0,
			$this->generate_uuid_v4(),
			$enrollment->user_id,
			$enrollment->course_id,
			$enrollment->id,
			$now,
			null,
			$final_score,
			$final_max_score,
			$snapshot,
			null,
			$now,
			$now
		);

		try {
			$id = $this->certificates->insert( $candidate );
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'CertificateService: insert failed',
				[
					'enrollment_id' => $enrollment_id,
					'error'         => $e->getMessage(),
				]
			);
			return IssueResult::failure( 'internal_error', 'Failed to persist certificate.' );
		}

		$persisted = $this->certificates->find( $id );
		if ( null === $persisted ) {
			return IssueResult::failure( 'internal_error', 'Failed to read back inserted certificate.' );
		}

		return IssueResult::issued( $persisted );
	}

	/**
	 * @return list<Certificate>
	 */
	public function find_for_user( int $user_id ): array {
		return $this->certificates->list_for_user( $user_id );
	}

	public function find_by_uuid( string $uuid ): ?Certificate {
		return $this->certificates->find_by_uuid( $uuid );
	}

	/**
	 * Set `revoked_at` on a certificate. Returns `true` only when the
	 * row actually transitions; `false` when already revoked or missing.
	 */
	public function revoke( int $certificate_id, ?\DateTimeImmutable $when = null ): bool {
		$cert = $this->certificates->find( $certificate_id );
		if ( null === $cert ) {
			return false;
		}
		if ( null !== $cert->revoked_at ) {
			return false;
		}
		return $this->certificates->update_revocation(
			$certificate_id,
			$when ?? $this->current_time_utc()
		);
	}

	/**
	 * Resolve the highest-scoring passed final-exam attempt for the
	 * (user, course) pair. Returns [null, null] when no final exam exists.
	 *
	 * @return array{0: int|null, 1: int|null}
	 */
	private function resolve_final_score( int $user_id, int $course_id ): array {
		$best_score = null;
		$best_max   = null;

		foreach ( $this->find_final_exam_quiz_ids_in_course( $course_id ) as $quiz_id ) {
			$attempt = $this->quiz_attempts->find_passed_final_exam_for_user_in_course(
				$user_id,
				$course_id,
				$quiz_id
			);
			if ( null === $attempt ) {
				continue;
			}
			if ( null === $best_score || ( $attempt->score ?? 0 ) > $best_score ) {
				$best_score = $attempt->score;
				$best_max   = $attempt->max_score;
			}
		}

		return [ $best_score, $best_max ];
	}

	/**
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
			$resolved_course = $this->resolve_course_id_for_quiz( $quiz );
			if ( null !== $resolved_course && $resolved_course === $course_id ) {
				$ids[] = (int) $quiz->ID;
			}
		}
		return $ids;
	}

	/**
	 * Walks `post_parent` until a `vl_course` is found. Bounded to 5
	 * hops to mirror `Quiz\QuizCourseResolver` semantics without
	 * coupling the certificate subsystem to it.
	 */
	private function resolve_course_id_for_quiz( WP_Post $quiz ): ?int {
		$current = $quiz;
		for ( $hop = 0; $hop < 5; $hop++ ) {
			$parent_id = (int) $current->post_parent;
			if ( $parent_id <= 0 ) {
				return null;
			}
			$parent = get_post( $parent_id );
			if ( ! $parent instanceof WP_Post ) {
				return null;
			}
			if ( 'publish' !== $parent->post_status ) {
				return null;
			}
			if ( 'vl_course' === $parent->post_type ) {
				return (int) $parent->ID;
			}
			$current = $parent;
		}
		return null;
	}

	private function course_certificate_enabled( int $course_id ): bool {
		$flag = get_post_meta( $course_id, '_vl_course_certificate_enabled', true );
		return '1' === (string) $flag;
	}

	/**
	 * RFC 4122 v4 UUID. Uses `wp_generate_uuid4` when available (it's a
	 * thin wrapper over `random_bytes`), else falls back to a local
	 * implementation using the same source so unit tests run without WP.
	 */
	private function generate_uuid_v4(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0F ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3F ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	protected function current_time_utc(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
