<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Repositories\ProgressRepository;

/**
 * In-memory double of {@see ProgressRepository} for service-level tests.
 *
 * Same public surface as the real repository, no `$wpdb` calls. Rows live
 * in a simple associative array keyed by primary ID. Honors the same
 * `(user_id, entity_type, entity_id)` uniqueness as the real `uniq_user_entity`
 * index — `upsert` updates the existing row instead of creating a duplicate.
 */
final class InMemoryProgressRepository extends ProgressRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	/** @var callable():\DateTimeImmutable */
	private $clock_fn;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct( ?callable $clock = null ) {
		parent::__construct( $clock );
		$this->clock_fn = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function find( int $user_id, EntityType $entity_type, int $entity_id ): ?Progress {
		foreach ( $this->rows as $row ) {
			if (
				(int) $row['user_id'] === $user_id
				&& $row['entity_type'] === $entity_type->value
				&& (int) $row['entity_id'] === $entity_id
			) {
				return Progress::from_row( $row );
			}
		}
		return null;
	}

	public function find_by_id( int $id ): ?Progress {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return Progress::from_row( $this->rows[ $id ] );
	}

	/**
	 * @return list<Progress>
	 */
	public function list_for_user_in_course( int $user_id, int $course_id ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( (int) $row['course_id'] !== $course_id ) {
				continue;
			}
			$out[] = Progress::from_row( $row );
		}
		return $out;
	}

	/**
	 * @param list<int> $course_ids
	 * @return array<int, array<string, int>>
	 */
	public function completed_counts_for_user_in_courses( int $user_id, array $course_ids ): array {
		$out = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] !== $user_id ) {
				continue;
			}
			if ( ! in_array( (int) $row['course_id'], $course_ids, true ) ) {
				continue;
			}
			if ( ProgressStatus::COMPLETED->value !== $row['status'] ) {
				continue;
			}
			$course_id   = (int) $row['course_id'];
			$entity_type = (string) $row['entity_type'];

			$out[ $course_id ][ $entity_type ] = ( $out[ $course_id ][ $entity_type ] ?? 0 ) + 1;
		}
		return $out;
	}

	public function upsert(
		int $user_id,
		EntityType $entity_type,
		int $entity_id,
		int $course_id,
		ProgressStatus $status,
		?int $position_seconds,
		?\DateTimeImmutable $completed_at,
		?\DateTimeImmutable $last_seen_at
	): Progress {
		$now      = ( $this->clock_fn )()->format( self::DATETIME_FORMAT );
		$existing = $this->find_existing_id( $user_id, $entity_type, $entity_id );

		$base = [
			'user_id'          => $user_id,
			'entity_type'      => $entity_type->value,
			'entity_id'        => $entity_id,
			'course_id'        => $course_id,
			'status'           => $status->value,
			'position_seconds' => $position_seconds,
			'completed_at'     => null === $completed_at ? null : $completed_at->format( self::DATETIME_FORMAT ),
			'last_seen_at'     => null === $last_seen_at ? null : $last_seen_at->format( self::DATETIME_FORMAT ),
		];

		if ( null === $existing ) {
			$id                = $this->next_id++;
			$this->rows[ $id ] = array_merge(
				$base,
				[
					'id'         => $id,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
			return Progress::from_row( $this->rows[ $id ] );
		}

		$this->rows[ $existing ] = array_merge(
			$this->rows[ $existing ],
			$base,
			[ 'updated_at' => $now ]
		);
		return Progress::from_row( $this->rows[ $existing ] );
	}

	public function mark_completed( int $progress_id, \DateTimeImmutable $completed_at ): Progress {
		if ( ! isset( $this->rows[ $progress_id ] ) ) {
			throw new \RuntimeException( 'Cannot complete unknown progress row.' );
		}

		$now = ( $this->clock_fn )()->format( self::DATETIME_FORMAT );

		$this->rows[ $progress_id ] = array_merge(
			$this->rows[ $progress_id ],
			[
				'status'       => ProgressStatus::COMPLETED->value,
				'completed_at' => $completed_at->format( self::DATETIME_FORMAT ),
				'updated_at'   => $now,
			]
		);

		return Progress::from_row( $this->rows[ $progress_id ] );
	}

	public function delete_for_user( int $user_id ): int {
		$deleted = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['user_id'] === $user_id ) {
				unset( $this->rows[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public function delete_for_user_in_course( int $user_id, int $course_id ): int {
		$deleted = 0;
		foreach ( $this->rows as $id => $row ) {
			if ( (int) $row['user_id'] === $user_id && (int) $row['course_id'] === $course_id ) {
				unset( $this->rows[ $id ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	private function find_existing_id( int $user_id, EntityType $entity_type, int $entity_id ): ?int {
		foreach ( $this->rows as $id => $row ) {
			if (
				(int) $row['user_id'] === $user_id
				&& $row['entity_type'] === $entity_type->value
				&& (int) $row['entity_id'] === $entity_id
			) {
				return $id;
			}
		}
		return null;
	}
}
