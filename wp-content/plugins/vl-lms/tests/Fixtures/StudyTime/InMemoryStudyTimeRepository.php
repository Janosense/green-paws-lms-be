<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\StudyTime;

use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Domain\StudyTimeEntry;
use VL\LMS\StudyTime\Repositories\StudyTimeRepository;

/**
 * In-memory double of {@see StudyTimeRepository} for service-level tests.
 *
 * Extends the real repository but overrides every public method, so no
 * `$wpdb` call ever happens and the accumulation rules — the unique key, the
 * running `active_seconds`, the untouched `first_signal_at` — are exercised
 * for real instead of asserted on mock calls. The same shape as
 * {@see \VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository}.
 */
final class InMemoryStudyTimeRepository extends StudyTimeRepository {

	/** @var array<string, StudyTimeEntry> */
	private array $rows = [];

	private int $next_id = 1;

	public function last_signal_at_for_user_in_course( int $user_id, int $course_id ): ?\DateTimeImmutable {
		$latest = null;
		foreach ( $this->rows_for_user_in_course( $user_id, $course_id ) as $row ) {
			if ( null === $latest || $row->last_signal_at > $latest ) {
				$latest = $row->last_signal_at;
			}
		}
		return $latest;
	}

	/**
	 * @param 'lesson'|'topic' $entity_type
	 * @param int<0, max>      $seconds
	 */
	public function add_seconds(
		int $user_id,
		int $course_id,
		string $entity_type,
		int $entity_id,
		StudyKind $kind,
		int $seconds,
		\DateTimeImmutable $now
	): void {
		$key      = implode( '|', [ $user_id, $course_id, $entity_type, $entity_id, $kind->value ] );
		$at       = $now->setTimezone( new \DateTimeZone( 'UTC' ) );
		$existing = $this->rows[ $key ] ?? null;

		$this->rows[ $key ] = new StudyTimeEntry(
			$existing->id ?? $this->next_id++,
			$user_id,
			$course_id,
			$entity_type,
			$entity_id,
			$kind,
			( $existing->active_seconds ?? 0 ) + $seconds,
			$existing->first_signal_at ?? $at,
			$at
		);
	}

	/**
	 * @return list<StudyTimeEntry>
	 */
	public function rows_for_user_in_course( int $user_id, int $course_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id === $user_id && $row->course_id === $course_id ) {
				$out[] = $row;
			}
		}
		usort( $out, static fn ( StudyTimeEntry $a, StudyTimeEntry $b ): int => $a->id <=> $b->id );
		return $out;
	}

	/**
	 * @return array<int, array<string, int>>
	 */
	public function totals_for_user( int $user_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( $row->user_id !== $user_id ) {
				continue;
			}
			$out[ $row->course_id ][ $row->kind->value ] = ( $out[ $row->course_id ][ $row->kind->value ] ?? 0 ) + $row->active_seconds;
		}
		return $out;
	}

	/**
	 * Test helper: how many rows the ledger holds, for the "a refused
	 * heartbeat writes nothing" assertions.
	 */
	public function row_count(): int {
		return count( $this->rows );
	}

	/**
	 * Test helper: seed a row as if it had been written earlier.
	 *
	 * @param 'lesson'|'topic' $entity_type
	 */
	public function seed(
		int $user_id,
		int $course_id,
		string $entity_type,
		int $entity_id,
		StudyKind $kind,
		int $active_seconds,
		\DateTimeImmutable $last_signal_at
	): void {
		$key                = implode( '|', [ $user_id, $course_id, $entity_type, $entity_id, $kind->value ] );
		$this->rows[ $key ] = new StudyTimeEntry(
			$this->next_id++,
			$user_id,
			$course_id,
			$entity_type,
			$entity_id,
			$kind,
			$active_seconds,
			$last_signal_at,
			$last_signal_at
		);
	}
}
