<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Analytics;

use VL\LMS\Database\SchemaManager;

/**
 * Phase 9.3 — populates `vl_user_activity_daily` for a given calendar day.
 *
 * `rollup()` runs three aggregate queries (new enrollments, active users via
 * the lesson-views → module → course join chain, and completions) and folds
 * the results into a single upsert per course. Idempotent — the UNIQUE key
 * on `(course_id, activity_date)` lets `INSERT … ON DUPLICATE KEY UPDATE`
 * overwrite a stale row instead of duplicating.
 *
 * Not declared `final` — unit tests subclass to inject `$wpdb` fakes.
 *
 * @author Tymofii Synianskyi
 */
class AnalyticsRollupService {

	public function rollup( string $date ): void {
		$wpdb = $this->wpdb();

		$enrollments_table = SchemaManager::enrollments_table();
		$lesson_views      = SchemaManager::lesson_views_table();
		$activity_table    = SchemaManager::user_activity_daily_table();
		$posts_table       = $wpdb->posts;

		// 1. New enrollments per course.
		$enrollments_sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT course_id, COUNT(*) AS cnt FROM {$enrollments_table} WHERE DATE(enrolled_at) = %s GROUP BY course_id",
			$date
		);
		$new_enrollments = $this->fetch_counts( $enrollments_sql );

		// 2. Active users per course (lesson-view join chain).
		$active_sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- All interpolated names come from $wpdb / SchemaManager.
			"SELECT p_course.ID AS course_id, COUNT(DISTINCT lv.user_id) AS cnt
			FROM {$lesson_views} lv
			INNER JOIN {$posts_table} p_lesson ON p_lesson.ID = lv.lesson_id
			INNER JOIN {$posts_table} p_module ON p_module.ID = p_lesson.post_parent AND p_module.post_type = 'vl_module'
			INNER JOIN {$posts_table} p_course ON p_course.ID = p_module.post_parent AND p_course.post_type = 'vl_course'
			WHERE DATE(lv.created_at) = %s
			GROUP BY p_course.ID",
			$date
		);
		$active_users = $this->fetch_counts( $active_sql );

		// 3. Completions per course.
		$completions_sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT course_id, COUNT(*) AS cnt FROM {$enrollments_table} WHERE status = %s AND DATE(updated_at) = %s GROUP BY course_id",
			'completed',
			$date
		);
		$completions = $this->fetch_counts( $completions_sql );

		// 4. Merge keys and upsert one row per course.
		$course_ids = array_unique( array_merge(
			array_keys( $new_enrollments ),
			array_keys( $active_users ),
			array_keys( $completions )
		) );

		foreach ( $course_ids as $course_id ) {
			$cid       = (int) $course_id;
			$new_count = (int) ( $new_enrollments[ $cid ] ?? 0 );
			$active    = (int) ( $active_users[ $cid ] ?? 0 );
			$done      = (int) ( $completions[ $cid ] ?? 0 );

			$upsert = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$activity_table} (course_id, activity_date, new_enrollments, active_users, completions)
				VALUES (%d, %s, %d, %d, %d)
				ON DUPLICATE KEY UPDATE new_enrollments = VALUES(new_enrollments), active_users = VALUES(active_users), completions = VALUES(completions)",
				$cid,
				$date,
				$new_count,
				$active,
				$done
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $upsert );
		}
	}

	/**
	 * @return array<int, int>
	 */
	protected function fetch_counts( string $sql ): array {
		$wpdb = $this->wpdb();
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
				$out[ $cid ] = (int) ( $row['cnt'] ?? 0 );
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
