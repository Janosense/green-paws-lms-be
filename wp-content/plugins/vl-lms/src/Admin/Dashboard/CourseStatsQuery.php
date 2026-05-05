<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Dashboard;

use VL\LMS\Database\SchemaManager;

/**
 * KPI rollups over `{prefix}vl_enrollments` for the Phase 9.2
 * Instructor Dashboard.
 *
 * Each query produces a `[course_id => count]` map keyed by the input
 * IDs. Courses with zero rows are simply absent from the map; callers
 * should default missing keys to zero.
 *
 * Not declared `final` — unit tests subclass to bypass `$wpdb`.
 *
 * @author Tymofii Synianskyi
 */
class CourseStatsQuery {

	/**
	 * Active + completed enrollments per course.
	 *
	 * @param list<int> $course_ids
	 *
	 * @return array<int, int>
	 */
	public function enrollment_count_by_course( array $course_ids ): array {
		return $this->count_by_status( $course_ids, [ 'active', 'completed' ] );
	}

	/**
	 * Completed enrollments per course.
	 *
	 * @param list<int> $course_ids
	 *
	 * @return array<int, int>
	 */
	public function completion_count_by_course( array $course_ids ): array {
		return $this->count_by_status( $course_ids, [ 'completed' ] );
	}

	/**
	 * @param list<int>    $course_ids
	 * @param list<string> $statuses
	 *
	 * @return array<int, int>
	 */
	protected function count_by_status( array $course_ids, array $statuses ): array {
		$ids = array_values( array_unique( array_filter( $course_ids, static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $ids || [] === $statuses ) {
			return [];
		}

		$wpdb  = $this->wpdb();
		$table = SchemaManager::enrollments_table();

		$id_placeholders     = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder lists are built from counted arrays only.
			"SELECT course_id, COUNT(*) AS total FROM {$table} WHERE course_id IN ({$id_placeholders}) AND status IN ({$status_placeholders}) GROUP BY course_id",
			array_merge( $ids, $statuses )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cid = (int) ( $row['course_id'] ?? 0 );
			if ( $cid > 0 ) {
				$out[ $cid ] = (int) ( $row['total'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * @return \wpdb
	 */
	protected function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
