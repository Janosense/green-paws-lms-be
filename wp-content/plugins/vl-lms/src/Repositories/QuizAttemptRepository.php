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
	 * Insert a new attempt row. The VO's `id` is ignored — `wpdb->insert_id`
	 * is the source of truth for the assigned PK. `created_at` and
	 * `updated_at` are stamped from the injected clock.
	 */
	public function insert( QuizAttempt $attempt ): int {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		$data                = $attempt->to_array();
		$data['created_at']  = $now;
		$data['updated_at']  = $now;
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
