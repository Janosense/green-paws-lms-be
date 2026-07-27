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
 * @author Tymofii Synianskyi
 */
class QuizAttemptRepository {

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

	public function count_for_user_in_quiz( int $user_id, int $quiz_id ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND quiz_id = %d",
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
	 */
	public function count_submitted_for_user( int $quiz_id, int $user_id ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE quiz_id = %d AND user_id = %d AND status != %s",
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
	 */
	public function best_score_for_user( int $quiz_id, int $user_id ): ?float {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT MAX(ROUND(score / max_score * 100, 2)) FROM {$table} WHERE quiz_id = %d AND user_id = %d AND status = %s AND max_score > 0",
			$quiz_id,
			$user_id,
			QuizAttemptStatus::SUBMITTED->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( $sql );

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
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

	public function find_best_score_for_user_in_quiz( int $user_id, int $quiz_id ): ?QuizAttempt {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND quiz_id = %d AND status = %s ORDER BY score DESC, submitted_at DESC LIMIT 1",
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
	 * @return array<int, array{passed: bool, in_progress: bool, submitted_count: int, best_pct: float|null}>
	 */
	public function status_map_for_user_in_course( int $user_id, int $course_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT quiz_id,
				MAX(passed) AS passed,
				MAX(CASE WHEN status = %s THEN 1 ELSE 0 END) AS in_progress,
				SUM(CASE WHEN status != %s THEN 1 ELSE 0 END) AS submitted_count,
				MAX(CASE WHEN status = %s AND max_score > 0 THEN ROUND(score / max_score * 100, 2) ELSE NULL END) AS best_pct
			FROM {$table}
			WHERE user_id = %d AND course_id = %d
			GROUP BY quiz_id",
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

	public function find_passed_final_exam_for_user_in_course(
		int $user_id,
		int $course_id,
		int $final_exam_quiz_id
	): ?QuizAttempt {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d AND quiz_id = %d AND status = %s AND passed = 1 ORDER BY submitted_at DESC LIMIT 1",
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
