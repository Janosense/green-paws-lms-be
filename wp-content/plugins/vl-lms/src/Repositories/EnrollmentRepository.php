<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Enrollment\Enrollment;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;

/**
 * Primitive data-access layer for `{prefix}vl_enrollments`.
 *
 * Every query goes through `$wpdb->prepare()` — callers pass primitives,
 * the repository returns `Enrollment` objects (or primitives for counts).
 * No business rules, no hooks, no WP side effects. Business operations
 * (enroll / revoke / complete) live in
 * {@see \VL\LMS\Services\Enrollment\EnrollmentService}.
 *
 * Constructor takes no arguments in Phase 1 — the global `$wpdb` is pulled
 * on demand via {@see self::wpdb()}. When a DI container is wired up later,
 * the dependency can move to the constructor without touching callers.
 *
 * The `{$table}` interpolation inside each prepared statement is the
 * `SchemaManager::enrollments_table()` return value, which is assembled
 * from `$wpdb->prefix` plus a hardcoded suffix — no untrusted input ever
 * reaches the SQL string, so the `InterpolatedNotPrepared` / `NotPrepared`
 * sniffs below are acknowledged and suppressed at each query site.
 *
 * @author Tymofii Synianskyi
 */
class EnrollmentRepository {

	public function find_by_id( int $id ): ?Enrollment {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return Enrollment::from_row( $row );
	}

	public function find_for_user_and_course( int $user_id, int $course_id ): ?Enrollment {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d",
			$user_id,
			$course_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return Enrollment::from_row( $row );
	}

	/**
	 * @return list<Enrollment>
	 */
	public function list_for_user( int $user_id, ?EnrollmentStatus $status = null ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id );
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d AND status = %s",
				$user_id,
				$status->value
			);
		}

		return $this->hydrate_list( $sql );
	}

	/**
	 * Sibling of {@see self::list_for_user()} that filters by an explicit set
	 * of statuses and orders by `enrolled_at DESC` (newest first).
	 *
	 * Empty `$statuses` returns an empty list — callers asking for "no
	 * statuses" almost certainly have a bug; surface that as a no-op rather
	 * than emitting `WHERE status IN ()` (which is a SQL syntax error).
	 *
	 * @param list<EnrollmentStatus> $statuses
	 *
	 * @return list<Enrollment>
	 */
	public function list_for_user_in_statuses( int $user_id, array $statuses ): array {
		if ( [] === $statuses ) {
			return [];
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		$values       = array_map( static fn ( EnrollmentStatus $s ): string => $s->value, $statuses );
		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND status IN ({$placeholders}) ORDER BY enrolled_at DESC",
			$user_id,
			...$values
		);

		return $this->hydrate_list( $sql );
	}

	/**
	 * @return list<Enrollment>
	 */
	public function list_for_course( int $course_id, ?EnrollmentStatus $status = null ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE course_id = %d", $course_id );
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE course_id = %d AND status = %s",
				$course_id,
				$status->value
			);
		}

		return $this->hydrate_list( $sql );
	}

	public function count_for_course( int $course_id, ?EnrollmentStatus $status = null ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE course_id = %d", $course_id );
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE course_id = %d AND status = %s",
				$course_id,
				$status->value
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$wpdb = $this->wpdb();

		$now                = gmdate( 'Y-m-d H:i:s' );
		$data['created_at'] = $data['created_at'] ?? $now;
		$data['updated_at'] = $data['updated_at'] ?? $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		$wpdb = $this->wpdb();

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $id ] );

		return false !== $result;
	}

	public function delete( int $id ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $this->table(), [ 'id' => $id ] );

		return false !== $result;
	}

	/**
	 * @return list<Enrollment>
	 */
	private function hydrate_list( string $sql ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb()->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Enrollment::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::enrollments_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
