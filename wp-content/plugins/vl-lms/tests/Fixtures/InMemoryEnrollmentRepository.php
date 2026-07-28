<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Repositories\EnrollmentRepository;

/**
 * In-memory double of {@see EnrollmentRepository} for service-level tests.
 *
 * Extends the real repository but overrides every public method so no
 * `$wpdb` call ever happens. Rows live in a simple associative array keyed
 * by primary ID, which keeps the test-only surface area tiny — just enough
 * to exercise the `EnrollmentService` state machine.
 */
final class InMemoryEnrollmentRepository extends EnrollmentRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	private int $update_calls = 0;

	public function find_by_id( int $id ): ?Enrollment {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return Enrollment::from_row( $this->rows[ $id ] );
	}

	public function find_for_user_and_course( int $user_id, int $course_id ): ?Enrollment {
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] === $user_id && (int) $row['course_id'] === $course_id ) {
				return Enrollment::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<Enrollment>
	 */
	public function list_for_user( int $user_id, ?EnrollmentStatus $status = null ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( null !== $status && $row['status'] !== $status->value ) {
				continue;
			}
			$out[] = Enrollment::from_row( $row );
		}
		return $out;
	}

	/**
	 * @return list<Enrollment>
	 */
	public function list_for_course( int $course_id, ?EnrollmentStatus $status = null ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['course_id'] !== $course_id ) {
				continue;
			}
			if ( null !== $status && $row['status'] !== $status->value ) {
				continue;
			}
			$out[] = Enrollment::from_row( $row );
		}
		return $out;
	}

	public function count_for_course( int $course_id, ?EnrollmentStatus $status = null ): int {
		return count( $this->list_for_course( $course_id, $status ) );
	}

	/**
	 * @param list<int> $user_ids
	 * @return array<int, int>
	 */
	public function count_completed_for_users( array $user_ids ): array {
		if ( [] === $user_ids ) {
			return [];
		}
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( EnrollmentStatus::COMPLETED->value !== $row['status'] ) {
				continue;
			}
			$user_id = (int) $row['user_id'];
			if ( ! in_array( $user_id, $user_ids, true ) ) {
				continue;
			}
			$out[ $user_id ] = ( $out[ $user_id ] ?? 0 ) + 1;
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$now = gmdate( 'Y-m-d H:i:s' );
		$id  = $this->next_id++;

		$this->rows[ $id ] = array_merge(
			[
				'id'                => $id,
				'source_group_id'   => null,
				'source_order_id'   => null,
				'started_at'        => null,
				'completed_at'      => null,
				'expires_at'        => null,
				'revoked_at'        => null,
				'revoked_by'        => null,
				'revoke_reason'     => null,
				'progress_pct'      => 0,
				'progress_reset_at' => null,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			$data,
			[ 'id' => $id ]
		);

		return $id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		++$this->update_calls;

		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		$this->rows[ $id ]  = array_merge( $this->rows[ $id ], $data );

		return true;
	}

	public function delete( int $id ): bool {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return false;
		}
		unset( $this->rows[ $id ] );
		return true;
	}

	public function update_progress_state(
		int $user_id,
		int $course_id,
		int $progress_pct,
		EnrollmentStatus $status,
		?\DateTimeImmutable $completed_at,
		\DateTimeImmutable $now
	): bool {
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['user_id'] !== $user_id || (int) $row['course_id'] !== $course_id ) {
				continue;
			}
			$this->rows[ $id ] = array_merge(
				$row,
				[
					'progress_pct' => $progress_pct,
					'status'       => $status->value,
					'completed_at' => null === $completed_at ? null : $completed_at->format( 'Y-m-d H:i:s' ),
					'updated_at'   => $now->format( 'Y-m-d H:i:s' ),
				]
			);
			return true;
		}
		return false;
	}

	public function mark_progress_reset( int $user_id, int $course_id, \DateTimeImmutable $now ): bool {
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['user_id'] !== $user_id || (int) $row['course_id'] !== $course_id ) {
				continue;
			}
			$this->rows[ $id ] = array_merge(
				$row,
				[
					'progress_pct'      => 0,
					'status'            => EnrollmentStatus::ACTIVE->value,
					'completed_at'      => null,
					'progress_reset_at' => $now->format( 'Y-m-d H:i:s' ),
					'updated_at'        => $now->format( 'Y-m-d H:i:s' ),
				]
			);
			return true;
		}
		return false;
	}

	/**
	 * Test helper: directly seed a row. Useful for setting up state that
	 * bypasses the service (e.g., a REVOKED row with a specific
	 * `enrolled_at`).
	 *
	 * @param array<string, mixed> $overrides
	 */
	public function seed( array $overrides = [] ): int {
		$id = $this->next_id++;

		$defaults = [
			'id'                => $id,
			'user_id'           => 1,
			'course_id'         => 1,
			'status'            => EnrollmentStatus::ACTIVE->value,
			'source'            => 'manual',
			'source_group_id'   => null,
			'source_order_id'   => null,
			'enrolled_at'       => '2026-01-01 00:00:00',
			'started_at'        => null,
			'completed_at'      => null,
			'expires_at'        => null,
			'revoked_at'        => null,
			'revoked_by'        => null,
			'revoke_reason'     => null,
			'progress_pct'      => 0,
			'progress_reset_at' => null,
			'created_at'        => '2026-01-01 00:00:00',
			'updated_at'        => '2026-01-01 00:00:00',
		];

		$this->rows[ $id ] = array_merge( $defaults, $overrides, [ 'id' => $id ] );

		return $id;
	}

	public function update_call_count(): int {
		return $this->update_calls;
	}
}
