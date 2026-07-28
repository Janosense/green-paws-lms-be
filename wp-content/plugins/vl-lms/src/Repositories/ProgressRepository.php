<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;

/**
 * Primitive data-access layer for `{prefix}vl_progress`.
 *
 * Mirrors the `EnrollmentRepository` shape: prepared queries, no business
 * rules, no hooks. The "now" timestamp used for `created_at` / `updated_at`
 * audit columns comes from a constructor-injected clock so unit tests can
 * advance time deterministically. Domain columns (`completed_at`,
 * `last_seen_at`) are always supplied by the caller.
 *
 * The `{$table}` interpolation in each prepared statement resolves to
 * {@see SchemaManager::progress_table()}, which is `$wpdb->prefix` plus a
 * hardcoded suffix — no untrusted input ever reaches the SQL string.
 *
 * @author Tymofii Synianskyi
 */
class ProgressRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/** @var callable():\DateTimeImmutable */
	private $clock;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function find( int $user_id, EntityType $entity_type, int $entity_id ): ?Progress {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND entity_type = %s AND entity_id = %d",
			$user_id,
			$entity_type->value,
			$entity_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return Progress::from_row( $row );
	}

	public function find_by_id( int $id ): ?Progress {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return Progress::from_row( $row );
	}

	/**
	 * @return list<Progress>
	 */
	public function list_for_user_in_course( int $user_id, int $course_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d",
			$user_id,
			$course_id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Progress::from_row( $row );
			}
		}
		return $out;
	}

	/**
	 * Bulk COMPLETED-row counts for one learner across many courses, for
	 * the dashboard enrollment-stats read.
	 *
	 * Returns `course_id => entity_type => count` for `(course, type)`
	 * pairs with at least one completed row; callers default missing keys
	 * to 0. Covered by `idx_user_course_status`. No reset-epoch filtering
	 * is needed here — the self-service progress reset hard-deletes this
	 * table's rows. Empty input short-circuits to an empty array — avoids
	 * generating a malformed `IN ()` clause.
	 *
	 * @param list<int> $course_ids
	 * @return array<int, array<string, int>>
	 */
	public function completed_counts_for_user_in_courses( int $user_id, array $course_ids ): array {
		if ( [] === $course_ids ) {
			return [];
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );
		// Positional binds in SQL order: user id, the IN run, then status.
		$args = array_values( $course_ids );
		array_unshift( $args, $user_id );
		$args[] = ProgressStatus::COMPLETED->value;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table and $placeholders are SQL fragments built locally; %d / %s placeholders for $args are valid.
			"SELECT course_id, entity_type, COUNT(*) AS total FROM {$table} WHERE user_id = %d AND course_id IN ({$placeholders}) AND status = %s GROUP BY course_id, entity_type",
			$args
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['course_id'], $row['entity_type'], $row['total'] ) ) {
					continue;
				}
				$out[ (int) $row['course_id'] ][ (string) $row['entity_type'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	/**
	 * Insert-or-update on the `(user_id, entity_type, entity_id)` triplet.
	 *
	 * On insert, both audit columns are stamped with the clock's "now"; on
	 * update, `created_at` is preserved and `updated_at` is refreshed. The
	 * unique key `uniq_user_entity` guarantees at most one row per triplet
	 * even under concurrent writers — the SELECT-then-{INSERT|UPDATE} dance
	 * here is correct under MySQL's default REPEATABLE READ because the
	 * unique key would reject a racing duplicate insert.
	 */
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
		$existing = $this->find( $user_id, $entity_type, $entity_id );
		$now      = $this->now();

		$base = [
			'user_id'          => $user_id,
			'entity_type'      => $entity_type->value,
			'entity_id'        => $entity_id,
			'course_id'        => $course_id,
			'status'           => $status->value,
			'position_seconds' => $position_seconds,
			'completed_at'     => $this->datetime_or_null( $completed_at ),
			'last_seen_at'     => $this->datetime_or_null( $last_seen_at ),
		];

		if ( null === $existing ) {
			$wpdb               = $this->wpdb();
			$base['created_at'] = $now->format( self::DATETIME_FORMAT );
			$base['updated_at'] = $now->format( self::DATETIME_FORMAT );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( $this->table(), $base );

			$row = $this->find_by_id( (int) $wpdb->insert_id );
			if ( null === $row ) {
				throw new \RuntimeException( 'Failed to read back newly inserted progress row.' );
			}
			return $row;
		}

		$wpdb               = $this->wpdb();
		$base['updated_at'] = $now->format( self::DATETIME_FORMAT );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( $this->table(), $base, [ 'id' => $existing->id ] );

		$row = $this->find_by_id( $existing->id );
		if ( null === $row ) {
			throw new \RuntimeException( 'Failed to read back updated progress row.' );
		}
		return $row;
	}

	public function mark_completed( int $progress_id, \DateTimeImmutable $completed_at ): Progress {
		$wpdb = $this->wpdb();
		$now  = $this->now();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->table(),
			[
				'status'       => ProgressStatus::COMPLETED->value,
				'completed_at' => $completed_at->format( self::DATETIME_FORMAT ),
				'updated_at'   => $now->format( self::DATETIME_FORMAT ),
			],
			[ 'id' => $progress_id ]
		);

		$row = $this->find_by_id( $progress_id );
		if ( null === $row ) {
			throw new \RuntimeException( 'Failed to read back completed progress row.' );
		}
		return $row;
	}

	public function delete_for_user( int $user_id ): int {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $this->table(), [ 'user_id' => $user_id ] );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * Course-scoped sibling of {@see self::delete_for_user()}, used by the
	 * self-service progress reset: wipes one learner's per-entity progress
	 * rows for one course while leaving their other courses untouched.
	 */
	public function delete_for_user_in_course( int $user_id, int $course_id ): int {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			$this->table(),
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);

		return is_numeric( $result ) ? (int) $result : 0;
	}

	private function datetime_or_null( ?\DateTimeImmutable $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		return $value->format( self::DATETIME_FORMAT );
	}

	private function now(): \DateTimeImmutable {
		return ( $this->clock )();
	}

	private function table(): string {
		return SchemaManager::progress_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
