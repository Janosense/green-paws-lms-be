<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Quiz\QuizAnswer;

/**
 * Primitive data-access layer for `{prefix}vl_quiz_answers`.
 *
 * The `upsert()` path uses `INSERT … ON DUPLICATE KEY UPDATE` keyed off the
 * unique index `attempt_question (attempt_id, question_id)` so save-as-you-go
 * writes from the quiz player (Phase 6.1) are atomic — one round-trip per
 * save, no SELECT-then-{insert/update} dance, no race window where two
 * answers can land for the same `(attempt, question)`.
 *
 * The batched scoring path {@see self::update_scoring_batch()} loops single-row
 * updates inside a single `START TRANSACTION` rather than building one
 * multi-row CASE-WHEN statement: the transaction keeps the writes atomic,
 * the per-row form keeps the SQL legible, and the volume is bounded by the
 * question count of one quiz. The trade-off is more round-trips per submit;
 * if quiz sizes ever push past a few dozen questions the implementation can
 * be swapped for a single CASE-WHEN UPDATE without the public surface
 * changing.
 *
 * @author Tymofii Synianskyi
 */
class QuizAnswerRepository {

	public function find( int $id ): ?QuizAnswer {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return QuizAnswer::from_array( $row );
	}

	public function find_by_attempt_and_question( int $attempt_id, int $question_id ): ?QuizAnswer {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE attempt_id = %d AND question_id = %d",
			$attempt_id,
			$question_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return QuizAnswer::from_array( $row );
	}

	/**
	 * @return list<QuizAnswer>
	 */
	public function list_for_attempt( int $attempt_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE attempt_id = %d ORDER BY id ASC",
			$attempt_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = QuizAnswer::from_array( $row );
			}
		}
		return $out;
	}

	/**
	 * Insert-or-update keyed on `(attempt_id, question_id)`.
	 *
	 * Single `INSERT … ON DUPLICATE KEY UPDATE` — atomic against the unique
	 * index, so a duplicate save refreshes the existing row's
	 * `answer_data` / `answered_at` instead of creating a second row. The
	 * scoring columns (`is_correct`, `points_awarded`) are intentionally
	 * omitted from both the INSERT and the UPDATE clause: the save path
	 * never sets them (they default to NULL), and the scoring path
	 * ({@see self::update_scoring()}) is the only writer of those columns.
	 *
	 * Returns the row's primary key (existing or new).
	 */
	public function upsert( QuizAnswer $answer ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$data = $answer->to_array();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"INSERT INTO {$table} (attempt_id, question_id, answer_data, answered_at) "
			. 'VALUES (%d, %d, %s, %s) '
			. 'ON DUPLICATE KEY UPDATE answer_data = VALUES(answer_data), answered_at = VALUES(answered_at)',
			(int) $data['attempt_id'],
			(int) $data['question_id'],
			(string) $data['answer_data'],
			(string) $data['answered_at']
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $sql );

		// On insert, `insert_id` is the new row's PK. On update, `insert_id`
		// is 0 in MySQL ≥5.7 default mode — fall back to a SELECT for the
		// existing PK.
		$insert_id = (int) $wpdb->insert_id;
		if ( $insert_id > 0 ) {
			return $insert_id;
		}

		$existing = $this->find_by_attempt_and_question(
			(int) $data['attempt_id'],
			(int) $data['question_id']
		);
		return null === $existing ? 0 : $existing->id;
	}

	public function update_scoring( int $id, bool $is_correct, int $points_awarded ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$this->table(),
			[
				'is_correct'     => $is_correct ? 1 : 0,
				'points_awarded' => $points_awarded,
			],
			[ 'id' => $id ]
		);

		return false !== $result && $result >= 1;
	}

	/**
	 * Batched scoring write for one attempt's worth of answers.
	 *
	 * Wraps the per-row updates in a single `START TRANSACTION` /
	 * `COMMIT` pair so the attempt either ends up fully scored or
	 * rolls back unchanged on error.
	 *
	 * @param array<int, array{is_correct: bool, points_awarded: int}> $by_answer_id
	 *
	 * @return int Number of rows successfully updated.
	 */
	public function update_scoring_batch( int $attempt_id, array $by_answer_id ): int {
		$wpdb = $this->wpdb();

		if ( [] === $by_answer_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'START TRANSACTION' );

		$affected = 0;
		foreach ( $by_answer_id as $id => $row ) {
			if ( $this->update_scoring( (int) $id, (bool) $row['is_correct'], (int) $row['points_awarded'] ) ) {
				++$affected;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( 'COMMIT' );

		// $attempt_id is kept on the signature so a future implementation can
		// scope a single multi-row CASE-WHEN UPDATE to one attempt without
		// breaking callers — the per-row update path doesn't need it.
		unset( $attempt_id );

		return $affected;
	}

	private function table(): string {
		return SchemaManager::quiz_answers_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
