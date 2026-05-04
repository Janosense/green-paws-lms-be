<?php

declare(strict_types=1);

namespace VL\LMS\Services\Enrollment;

use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentSource;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\EnrollmentRepository;

/**
 * Business operations for the enrollment aggregate.
 *
 * The repository knows about rows; this service knows what an enrollment
 * *means* — when a re-enroll is a no-op, when it resurrects a revoked row,
 * how expiry interacts with access, and which transitions are illegal.
 *
 * Stateless. Safe to construct per-request; no internal caching.
 *
 * Concrete (not final) for DI / testability — Phase 8.2's
 * `OrderEnrollmentFanout` listener and `OrderService` both depend on the
 * service and need it Mockery-mockable in unit tests.
 *
 * @author Tymofii Synianskyi
 */
class EnrollmentService {

	public function __construct( private readonly EnrollmentRepository $repository ) {
	}

	/**
	 * Enroll `$user_id` into `$course_id` idempotently.
	 *
	 * - No existing row → INSERT as `ACTIVE`.
	 * - Existing `ACTIVE` / `COMPLETED` → return as-is (already enrolled).
	 * - Existing `REVOKED` / `EXPIRED` / `REFUNDED` → UPDATE back to
	 *   `ACTIVE`, clear revoke / expiry metadata, rewrite the source
	 *   fields to the new values, and preserve the original `enrolled_at`
	 *   so the row keeps the user's first-enrollment timestamp for audit.
	 */
	public function enroll(
		int $user_id,
		int $course_id,
		EnrollmentSource $source = EnrollmentSource::MANUAL,
		?int $source_group_id = null,
		?int $source_order_id = null
	): Enrollment {
		$existing = $this->repository->find_for_user_and_course( $user_id, $course_id );

		if ( null !== $existing ) {
			if ( EnrollmentStatus::ACTIVE === $existing->status || EnrollmentStatus::COMPLETED === $existing->status ) {
				return $existing;
			}

			$this->repository->update(
				$existing->id,
				[
					'status'          => EnrollmentStatus::ACTIVE->value,
					'source'          => $source->value,
					'source_group_id' => $source_group_id,
					'source_order_id' => $source_order_id,
					'expires_at'      => null,
					'revoked_at'      => null,
					'revoked_by'      => null,
					'revoke_reason'   => null,
				]
			);

			return $this->require_by_id( $existing->id );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = $this->repository->insert(
			[
				'user_id'         => $user_id,
				'course_id'       => $course_id,
				'status'          => EnrollmentStatus::ACTIVE->value,
				'source'          => $source->value,
				'source_group_id' => $source_group_id,
				'source_order_id' => $source_order_id,
				'enrolled_at'     => $now,
				'progress_pct'    => 0,
			]
		);

		return $this->require_by_id( $id );
	}

	/**
	 * Mark an enrollment as revoked. Never deletes the row — revocation is
	 * a first-class state so downstream reports can still see revoked users.
	 *
	 * @throws \RuntimeException When no row with `$enrollment_id` exists.
	 */
	public function revoke( int $enrollment_id, int $revoked_by, ?string $reason = null ): Enrollment {
		$existing = $this->repository->find_by_id( $enrollment_id );
		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Enrollment %d not found.', $enrollment_id ) );
		}

		$this->repository->update(
			$existing->id,
			[
				'status'        => EnrollmentStatus::REVOKED->value,
				'revoked_at'    => gmdate( 'Y-m-d H:i:s' ),
				'revoked_by'    => $revoked_by,
				'revoke_reason' => $reason,
			]
		);

		/**
		 * Fires after an enrollment is revoked. Phase 6.3
		 * {@see \VL\LMS\Certificate\CertificateRevoker} listens to this
		 * to soft-revoke any certificates issued for the enrollment.
		 *
		 * @param int         $enrollment_id The revoked enrollment row's ID.
		 * @param string|null $reason        Optional human-readable reason.
		 */
		do_action( 'vl_lms_enrollment_revoked', $existing->id, $reason );

		return $this->require_by_id( $existing->id );
	}

	/**
	 * Whether `$user_id` currently has access to `$course_id`.
	 *
	 * Access requires an `ACTIVE` or `COMPLETED` row AND that `expires_at`
	 * is either `NULL` (no expiry) or still in the future (UTC).
	 * Revoked / expired / refunded rows return `false`, as does a missing row.
	 */
	public function has_active_access( int $user_id, int $course_id ): bool {
		$enrollment = $this->repository->find_for_user_and_course( $user_id, $course_id );
		if ( null === $enrollment ) {
			return false;
		}

		if ( EnrollmentStatus::ACTIVE !== $enrollment->status && EnrollmentStatus::COMPLETED !== $enrollment->status ) {
			return false;
		}

		if ( null === $enrollment->expires_at ) {
			return true;
		}

		return $enrollment->expires_at > gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Mark an enrollment as completed. Leaves `progress_pct` alone — the
	 * progress subsystem (Phase 1.5) owns that column.
	 *
	 * @throws \RuntimeException When the row is missing, revoked, or refunded.
	 */
	public function mark_completed( int $enrollment_id ): Enrollment {
		$existing = $this->repository->find_by_id( $enrollment_id );
		if ( null === $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Enrollment %d not found.', $enrollment_id ) );
		}

		if ( EnrollmentStatus::REVOKED === $existing->status || EnrollmentStatus::REFUNDED === $existing->status ) {
			$current_status = $existing->status->value;
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Enrollment %d cannot be completed while in status "%s".', $enrollment_id, $current_status )
			);
		}

		$this->repository->update(
			$existing->id,
			[
				'status'       => EnrollmentStatus::COMPLETED->value,
				'completed_at' => gmdate( 'Y-m-d H:i:s' ),
			]
		);

		return $this->require_by_id( $existing->id );
	}

	/**
	 * Phase 8.1 — used by `OrderService::create_for_purchase` to detect a
	 * pre-existing enrollment that should block a fresh course purchase.
	 *
	 * Thin pass-through to the repository; lives on the service so the
	 * order-orchestration layer never has to depend on `EnrollmentRepository`
	 * directly.
	 */
	public function find_for_user_and_course( int $user_id, int $course_id ): ?Enrollment {
		return $this->repository->find_for_user_and_course( $user_id, $course_id );
	}

	/**
	 * Reload a row we know to exist — belt-and-braces safeguard against a
	 * repository/DB inconsistency leaving us with an `Enrollment|null`.
	 *
	 * @throws \RuntimeException When the row vanished between write and read.
	 */
	private function require_by_id( int $id ): Enrollment {
		$row = $this->repository->find_by_id( $id );
		if ( null === $row ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new \RuntimeException( sprintf( 'Enrollment %d disappeared after write.', $id ) );
		}
		return $row;
	}
}
