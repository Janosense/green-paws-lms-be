<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Quiz\QuizAttempt;
use VL\LMS\Domain\Quiz\QuizAttemptStatus;

/**
 * Primitive data-access layer for `{prefix}vl_quiz_attempts`.
 *
 * Mirrors the {@see ProgressRepository} shape: prepared queries, no
 * business rules, no hooks. Domain orchestration (start / submit / score)
 * lives in the Phase 6.1 service layer; the repository only knows how to
 * read attempts, count them, and write the few atomic state transitions
 * the service needs (status flip, final-scoring write).
 *
 * The `{$table}` interpolation in each prepared statement resolves to
 * {@see SchemaManager::quiz_attempts_table()}, which is `$wpdb->prefix`
 * plus a hardcoded suffix — no untrusted input reaches the SQL string.
 *
 * Reads split into two families since the self-service progress reset:
 * *counting* reads (progression gate, max-attempts ceiling, best-score,
 * final-exam arm) LEFT JOIN `vl_enrollments` and exclude attempts started
 * before the enrollment's `progress_reset_at`; *record* reads (attempt
 * history, admin roll-ups) are deliberately epoch-blind and see every row.
 * The predicate compares two columns, so it adds no placeholders and the
 * positional bind order of every query is unchanged.
 *
 * @author Tymofii Synianskyi
 */
class QuizAttemptRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Epoch predicate for counting reads. Requires the attempts table to be
	 * aliased `a` and {@see self::counting_join()} to be present. `>=` so an
	 * attempt started in the same second as the reset still counts.
	 */
	private const string COUNTING_PREDICATE = 'AND ( e.progress_reset_at IS NULL OR a.started_at >= e.progress_reset_at )';

	/** @var callable():\DateTimeImmutable */
	private $clock;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function find( int $id ): ?QuizAttempt {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return QuizAttempt::from_array( $row );
	}

	/**
	 * Deliberately epoch-blind: a progress reset closes in-flight attempts
	 * by flipping them to ABANDONED ({@see self::abandon_in_progress_for_user_in_course()}),
	 * so an unfiltered read can never resume a pre-reset attempt.
	 */
	public function find_active_for_user_in_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND quiz_id = %d AND status = %s ORDER BY started_at DESC LIMIT 1",
			$user_id,
			$quiz_id,
			QuizAttemptStatus::IN_PROGRESS->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return QuizAttempt::from_array( $row );
	}

	/**
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded.
	 */
	public function count_for_user_in_quiz( int $user_id, int $quiz_id ): int {
		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} a {$join} WHERE a.user_id = %d AND a.quiz_id = %d {$predicate}",
			$user_id,
			$quiz_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Counts attempts in a terminal state (anything that is not IN_PROGRESS).
	 *
	 * Drives the `attempts_remaining` derivation against
	 * `_vl_quiz_max_attempts`. Argument order is `(quiz_id, user_id)` —
	 * the inverse of the older `_for_user_in_quiz` family — because the
	 * call site iterates "quiz first, user second" when rendering an
	 * attempt-state envelope.
	 *
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded.
	 */
	public function count_submitted_for_user( int $quiz_id, int $user_id ): int {
		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} a {$join} WHERE a.quiz_id = %d AND a.user_id = %d AND a.status != %s {$predicate}",
			$quiz_id,
			$user_id,
			QuizAttemptStatus::IN_PROGRESS->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Highest passing-percentage observed on submitted attempts for the
	 * pair, or `null` if there are no submitted attempts. Stored columns
	 * are raw `score` + `max_score`, so the percentage is derived by
	 * `MAX(score / max_score * 100)` SQL-side and rounded to two decimals
	 * for stable wire-format equality (75 vs 75.0 vs 75.00).
	 *
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded.
	 */
	public function best_score_for_user( int $quiz_id, int $user_id ): ?float {
		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT MAX(ROUND(a.score / a.max_score * 100, 2)) FROM {$table} a {$join} WHERE a.quiz_id = %d AND a.user_id = %d AND a.status = %s AND a.max_score > 0 {$predicate}",
			$quiz_id,
			$user_id,
			QuizAttemptStatus::SUBMITTED->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( $sql );

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Deliberately epoch-blind: this is the attempt-history read, and a
	 * progress reset must not hide the learner's earlier sittings from
	 * their own log. Don't "fix" this by adding the counting join.
	 *
	 * @return list<QuizAttempt>
	 */
	public function list_for_user_in_quiz( int $user_id, int $quiz_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND quiz_id = %d ORDER BY started_at DESC",
			$user_id,
			$quiz_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	/**
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded: this is "the best *counting*
	 * attempt", matching gate semantics for any future caller.
	 */
	public function find_best_score_for_user_in_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT a.* FROM {$table} a {$join} WHERE a.user_id = %d AND a.quiz_id = %d AND a.status = %s {$predicate} ORDER BY a.score DESC, a.submitted_at DESC LIMIT 1",
			$user_id,
			$quiz_id,
			QuizAttemptStatus::SUBMITTED->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return QuizAttempt::from_array( $row );
	}

	/**
	 * Per-quiz attempt summary for one user across a whole course, in a
	 * single GROUP BY round trip. Powers the curriculum-rail quiz overlay
	 * ({@see \VL\LMS\Learn\QuizStatusOverlay}) so the tree walk reads each
	 * quiz's status from memory rather than fanning out per quiz.
	 *
	 * Returned rows are keyed by `quiz_id`. `best_pct` is the highest
	 * passing-percentage observed on *submitted* attempts (rounded to two
	 * decimals for stable wire equality), or `null` when none scored.
	 *
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded, so a reset re-engages progression
	 * locks: a quiz whose attempts are all pre-reset drops out of the map
	 * and reads as `not_started`.
	 *
	 * @return array<int, array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null}>
	 */
	public function status_map_for_user_in_course( int $user_id, int $course_id ): array {
		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT a.quiz_id,
				MAX(a.passed) AS passed,
				MAX(CASE WHEN a.status = %s THEN 1 ELSE 0 END) AS in_progress,
				SUM(CASE WHEN a.status != %s THEN 1 ELSE 0 END) AS submitted_count,
				MAX(CASE WHEN a.status = %s AND a.max_score > 0 THEN ROUND(a.score / a.max_score * 100, 2) ELSE NULL END) AS best_pct
			FROM {$table} a
			{$join}
			WHERE a.user_id = %d AND a.course_id = %d {$predicate}
			GROUP BY a.quiz_id",
			QuizAttemptStatus::IN_PROGRESS->value,
			QuizAttemptStatus::IN_PROGRESS->value,
			QuizAttemptStatus::SUBMITTED->value,
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
			if ( ! is_array( $row ) ) {
				continue;
			}
			$quiz_id         = (int) ( $row['quiz_id'] ?? 0 );
			$best            = $row['best_pct'] ?? null;
			$out[ $quiz_id ] = [
				'passed'          => (int) ( $row['passed'] ?? 0 ) > 0,
				'in_progress'     => (int) ( $row['in_progress'] ?? 0 ) > 0,
				'submitted_count' => (int) ( $row['submitted_count'] ?? 0 ),
				'best_pct'        => is_numeric( $best ) ? (float) $best : null,
			];
		}
		return $out;
	}

	/**
	 * Bulk DISTINCT passed-quiz counts for one learner across many courses,
	 * for the dashboard enrollment-stats read.
	 *
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded, so after a reset the dashboard card
	 * reads 0 passed, matching the zeroed `progress_pct` (don't "fix" this
	 * with the epoch-blind {@see self::attempt_summary_for_users()}, which
	 * is an all-time admin record). DISTINCT so a learner who failed a quiz
	 * twice then passed contributes one, not three. Courses with no
	 * counted attempts are absent from the map; callers default to 0.
	 * Empty input short-circuits to an empty array — avoids generating a
	 * malformed `IN ()` clause.
	 *
	 * @param list<int> $course_ids
	 * @return array<int, int>
	 */
	public function passed_quiz_counts_for_user_in_courses( int $user_id, array $course_ids ): array {
		if ( [] === $course_ids ) {
			return [];
		}

		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$placeholders = implode( ', ', array_fill( 0, count( $course_ids ), '%d' ) );
		// The epoch predicate compares two columns and adds no placeholders,
		// so the args stay user id first, then the IN run.
		$args = [ $user_id, ...array_values( $course_ids ) ];

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table resolves to SchemaManager::quiz_attempts_table(), $join / $predicate are class-local SQL fragments, and $placeholders is a locally-built run of %d; every value binds through $args. The interpolations sit mid-string, so a single-line phpcs:ignore cannot reach them.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count varies with the batch size by design.
			"SELECT a.course_id,
				COUNT(DISTINCT CASE WHEN a.passed = 1 THEN a.quiz_id ELSE NULL END) AS quizzes_passed
			FROM {$table} a
			{$join}
			WHERE a.user_id = %d AND a.course_id IN ({$placeholders}) {$predicate}
			GROUP BY a.course_id",
			$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['course_id'] ) ) {
					continue;
				}
				$out[ (int) $row['course_id'] ] = (int) ( $row['quizzes_passed'] ?? 0 );
			}
		}
		return $out;
	}

	/**
	 * Deliberately epoch-blind: no production caller today, and as a
	 * record read ("every pass the learner ever had") it stays complete.
	 *
	 * @return list<QuizAttempt>
	 */
	public function list_passed_for_user_in_course( int $user_id, int $course_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d AND status = %s AND passed = 1 ORDER BY submitted_at DESC",
			$user_id,
			$course_id,
			QuizAttemptStatus::SUBMITTED->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	/**
	 * Per-user attempt roll-up for a batch of users, in one GROUP BY round
	 * trip. Powers the `Admin\Students\StudentsListTable` quiz column, which
	 * pre-batches its joins in `prepare_items()` rather than fanning out per
	 * rendered row.
	 *
	 * `attempts` counts every row including `in_progress` ones — it answers
	 * "how many times has this student sat this test". `graded` excludes
	 * `in_progress`, matching the `count_submitted_for_user` definition that
	 * drives `attempts_remaining`. `quizzes_passed` is a DISTINCT count so a
	 * learner who failed a quiz twice then passed contributes one, not three.
	 *
	 * Returned rows are keyed by `user_id`; users with no attempts are absent
	 * from the map (callers default to zeroes).
	 *
	 * Deliberately epoch-blind: the admin list is an all-time record and a
	 * learner's progress reset must not hide their sittings from staff.
	 * Don't "fix" this by adding the counting join.
	 *
	 * @param list<int> $user_ids
	 * @return array<int, array{attempts: int, graded: int, passed: int, quizzes: int, quizzes_passed: int}>
	 */
	public function attempt_summary_for_users( array $user_ids ): array {
		if ( [] === $user_ids ) {
			return [];
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

		// `$wpdb->prepare()` binds positionally, so `$args` must follow the
		// order the placeholders appear in the SQL — and the `%s` for status
		// sits in the SELECT list, ahead of the `%d` run in the IN clause.
		// Appending the status instead would shift every bind by one: the
		// first user id would be compared against `status`, and the status
		// string would be cast to 0 and matched as a user id.
		$args = array_merge(
			[ QuizAttemptStatus::IN_PROGRESS->value ],
			array_values( $user_ids )
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table resolves to SchemaManager::quiz_attempts_table() and $placeholders is a locally-built run of %d; every user value binds through $args. The interpolations sit mid-string, so a single-line phpcs:ignore cannot reach them.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Placeholder count varies with the batch size by design.
			"SELECT user_id,
				COUNT(*) AS attempts,
				SUM(CASE WHEN status != %s THEN 1 ELSE 0 END) AS graded,
				SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) AS passed,
				COUNT(DISTINCT quiz_id) AS quizzes,
				COUNT(DISTINCT CASE WHEN passed = 1 THEN quiz_id ELSE NULL END) AS quizzes_passed
			FROM {$table}
			WHERE user_id IN ({$placeholders})
			GROUP BY user_id",
			$args
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['user_id'] ) ) {
				continue;
			}
			$out[ (int) $row['user_id'] ] = [
				'attempts'       => (int) ( $row['attempts'] ?? 0 ),
				'graded'         => (int) ( $row['graded'] ?? 0 ),
				'passed'         => (int) ( $row['passed'] ?? 0 ),
				'quizzes'        => (int) ( $row['quizzes'] ?? 0 ),
				'quizzes_passed' => (int) ( $row['quizzes_passed'] ?? 0 ),
			];
		}
		return $out;
	}

	/**
	 * Counting read — attempts started before the enrollment's
	 * `progress_reset_at` are excluded, so the E2 final-exam arm re-arms
	 * after a reset: a pre-reset pass no longer completes the course.
	 */
	public function find_passed_final_exam_for_user_in_course(
		int $user_id,
		int $course_id,
		int $final_exam_quiz_id
	): ?QuizAttempt {
		$wpdb      = $this->wpdb();
		$table     = $this->table();
		$join      = $this->counting_join();
		$predicate = self::COUNTING_PREDICATE;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT a.* FROM {$table} a {$join} WHERE a.user_id = %d AND a.course_id = %d AND a.quiz_id = %d AND a.status = %s AND a.passed = 1 {$predicate} ORDER BY a.submitted_at DESC LIMIT 1",
			$user_id,
			$course_id,
			$final_exam_quiz_id,
			QuizAttemptStatus::SUBMITTED->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return QuizAttempt::from_array( $row );
	}

	/**
	 * Delete every attempt one learner has made on one quiz, returning the
	 * number of rows removed.
	 *
	 * The only destructive method on this repository, and deliberately so —
	 * attempts are otherwise an append-only record. It exists because a quiz
	 * flagged `_vl_quiz_blocks_progression` *and* carrying a finite
	 * `_vl_quiz_max_attempts` can leave a learner unable to advance with no
	 * in-app way back: they have spent their attempts, and everything after
	 * the quiz stays locked. Raising the course-wide limit would change the
	 * rules for everyone, so the escape hatch has to be per-learner. Exposed
	 * through `wp vl-lms quiz reset-attempts`.
	 *
	 * Answer rows in `vl_quiz_answers` are left behind on purpose: they are
	 * keyed by `attempt_id`, so once the attempts are gone they are
	 * unreachable, and keeping them preserves what the learner actually
	 * answered for later inspection.
	 */
	public function delete_for_user_in_quiz( int $user_id, int $quiz_id ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"DELETE FROM {$table} WHERE user_id = %d AND quiz_id = %d",
			$user_id,
			$quiz_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $wpdb->query( $sql );

		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}

	/**
	 * Flip every in-flight attempt one learner has in one course to
	 * ABANDONED, returning the number of rows updated.
	 *
	 * Runs as part of a self-service progress reset, *before* the epoch is
	 * stamped: {@see self::find_active_for_user_in_quiz()} is epoch-blind,
	 * so an in-progress row left open would be resumable across the reset.
	 * Closing it here keeps that read filter-free. The abandoned rows are
	 * pre-reset, so the counting predicate already excludes them from the
	 * max-attempts ceiling.
	 */
	public function abandon_in_progress_for_user_in_course( int $user_id, int $course_id ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();
		$now   = $this->now()->format( self::DATETIME_FORMAT );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table} SET status = %s, updated_at = %s WHERE user_id = %d AND course_id = %d AND status = %s",
			QuizAttemptStatus::ABANDONED->value,
			$now,
			$user_id,
			$course_id,
			QuizAttemptStatus::IN_PROGRESS->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$updated = $wpdb->query( $sql );

		return is_numeric( $updated ) ? (int) $updated : 0;
	}

	/**
	 * Insert a new attempt row. The VO's `id` is ignored — `wpdb->insert_id`
	 * is the source of truth for the assigned PK. `created_at` and
	 * `updated_at` are stamped from the injected clock.
	 */
	public function insert( QuizAttempt $attempt ): int {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		$data               = $attempt->to_array();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		unset( $data['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	public function update_status(
		int $id,
		QuizAttemptStatus $status,
		?\DateTimeImmutable $submitted_at = null
	): bool {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		$data = [
			'status'     => $status->value,
			'updated_at' => $now,
		];
		if ( null !== $submitted_at ) {
			$data['submitted_at'] = $submitted_at->format( self::DATETIME_FORMAT );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $id ] );

		return false !== $result && $result >= 1;
	}

	/**
	 * Atomic write of the submission outcome. Sets score / passed /
	 * time_taken / submitted_at / status in a single UPDATE so the row
	 * never observes a partially-finalized state.
	 */
	public function update_final(
		int $id,
		int $score,
		bool $passed,
		int $time_taken_seconds,
		\DateTimeImmutable $submitted_at,
		QuizAttemptStatus $final_status
	): bool {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		$data = [
			'score'              => $score,
			'passed'             => $passed ? 1 : 0,
			'time_taken_seconds' => $time_taken_seconds,
			'submitted_at'       => $submitted_at->format( self::DATETIME_FORMAT ),
			'status'             => $final_status->value,
			'updated_at'         => $now,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $id ] );

		return false !== $result && $result >= 1;
	}

	/**
	 * @param mixed $rows
	 * @return list<QuizAttempt>
	 */
	private function hydrate_rows( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = QuizAttempt::from_array( $row );
			}
		}
		return $out;
	}

	/**
	 * LEFT JOIN pairing each attempt (aliased `a`) with its enrollment row
	 * so {@see self::COUNTING_PREDICATE} can compare per-(user, course)
	 * columns. 1:0..1 by the `uk_user_course` UNIQUE key, so the join never
	 * multiplies rows; LEFT so attempts without an enrollment row keep
	 * pre-reset behaviour.
	 */
	private function counting_join(): string {
		$enrollments = SchemaManager::enrollments_table();
		return "LEFT JOIN {$enrollments} e ON e.user_id = a.user_id AND e.course_id = a.course_id";
	}

	private function now(): \DateTimeImmutable {
		return ( $this->clock )();
	}

	private function table(): string {
		return SchemaManager::quiz_attempts_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
