<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\StudyTime\Domain\StudyKind;
use VL\LMS\StudyTime\Domain\StudyTimeEntry;

/**
 * Primitive data-access layer for `{prefix}vl_study_time` — the only place
 * feature `study-time` runs SQL.
 *
 * Prepared queries, no business rules, no hooks: the heartbeat service
 * computes the capped increment and passes it in, together with its "now".
 * {@see self::add_seconds()} is a single `INSERT … ON DUPLICATE KEY UPDATE`
 * keyed off `uk_user_course_entity_kind`, so concurrent heartbeats for one
 * row never create a second one.
 *
 * Not `final`, like the `core` repositories: service tests mock it, and
 * Mockery cannot double a final class.
 *
 * The `{$table}` interpolation in each prepared statement resolves to
 * {@see SchemaManager::study_time_table()} — no untrusted input ever
 * reaches the SQL string.
 *
 * @author Tymofii Synianskyi
 */
class StudyTimeRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * The learner's most recent signal anywhere in the course — the base of
	 * the next capped increment. `null` when the learner has no row in it.
	 */
	public function last_signal_at_for_user_in_course( int $user_id, int $course_id ): ?\DateTimeImmutable {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT MAX(last_signal_at) FROM {$table} WHERE user_id = %d AND course_id = %d",
			$user_id,
			$course_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( $sql );

		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * Adds `$seconds` to the addressed row, creating it on the first signal.
	 *
	 * On insert both signal columns take `$now` and `active_seconds` takes
	 * `$seconds` (0 for a first signal); on a duplicate key the seconds are
	 * added and only `last_signal_at` moves — `first_signal_at` is never
	 * rewritten. `$now` is stored in UTC whatever its zone.
	 *
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
		$wpdb  = $this->wpdb();
		$table = $this->table();
		$at    = $now->setTimezone( new \DateTimeZone( 'UTC' ) )->format( self::DATETIME_FORMAT );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO {$table} (user_id, course_id, entity_type, entity_id, kind, active_seconds, first_signal_at, last_signal_at) "
			. 'VALUES (%d, %d, %s, %d, %s, %d, %s, %s) '
			. 'ON DUPLICATE KEY UPDATE active_seconds = active_seconds + %d, last_signal_at = %s',
			$user_id,
			$course_id,
			$entity_type,
			$entity_id,
			$kind->value,
			$seconds,
			$at,
			$at,
			$seconds,
			$at
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );
	}

	/**
	 * @return list<StudyTimeEntry>
	 */
	public function rows_for_user_in_course( int $user_id, int $course_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d ORDER BY id ASC",
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
				$out[] = StudyTimeEntry::from_row( $row );
			}
		}
		return $out;
	}

	/**
	 * The learner's ledger seconds per course and kind.
	 *
	 * Returns `course_id => kind => seconds` for the `(course, kind)` pairs
	 * the learner has rows for; callers default missing kinds to 0.
	 *
	 * @return array<int, array<string, int>>
	 */
	public function totals_for_user( int $user_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT course_id, kind, SUM(active_seconds) AS total FROM {$table} WHERE user_id = %d GROUP BY course_id, kind",
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['course_id'], $row['kind'], $row['total'] ) ) {
					continue;
				}
				$out[ (int) $row['course_id'] ][ (string) $row['kind'] ] = (int) $row['total'];
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::study_time_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
