<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Assignment\Submission;
use VL\LMS\Domain\Assignment\SubmissionStatus;

/**
 * Phase 9.4 — primitive data-access layer for `{prefix}vl_assignment_submissions`.
 *
 * Pure persistence — no business rules, no hooks. Service-layer
 * orchestration (validation, exception mapping, action dispatch) lives in
 * {@see \VL\LMS\Services\Assignments\AssignmentSubmissionService}.
 *
 * Not declared `final` — the in-memory test fixture extends to swap the
 * `$wpdb` calls for an array-backed implementation.
 *
 * @author Tymofii Synianskyi
 */
class AssignmentSubmissionRepository {

	public function find( int $id ): ?Submission {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Submission::from_array( $row );
	}

	public function find_by_assignment_user( int $assignment_id, int $user_id ): ?Submission {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE assignment_id = %d AND user_id = %d LIMIT 1",
			$assignment_id,
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Submission::from_array( $row );
	}

	/**
	 * @return list<Submission>
	 */
	public function list_pending( int $page = 1, int $per_page = 20 ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE status = %s ORDER BY submitted_at ASC LIMIT %d OFFSET %d",
			SubmissionStatus::PENDING->value,
			$per_page,
			$offset
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	public function count_pending(): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			SubmissionStatus::PENDING->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * @return list<Submission>
	 */
	public function list_by_status( string $status, int $page = 1, int $per_page = 20 ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );
		$offset   = ( $page - 1 ) * $per_page;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE status = %s ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
			$status,
			$per_page,
			$offset
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	public function count_by_status( string $status ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE status = %s",
			$status
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	public function insert( Submission $submission ): int {
		$wpdb = $this->wpdb();

		$data = [
			'assignment_id'        => $submission->assignment_id,
			'user_id'              => $submission->user_id,
			'status'               => $submission->status->value,
			'submission_text'      => $submission->submission_text,
			'submission_file_url'  => $submission->submission_file_url,
			'submission_file_name' => $submission->submission_file_name,
			'score'                => $submission->score,
			'feedback'             => $submission->feedback,
			'graded_by'            => $submission->graded_by,
			'submitted_at'         => $submission->submitted_at,
			'graded_at'            => $submission->graded_at,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	public function update( Submission $submission ): void {
		$wpdb = $this->wpdb();

		$data = [
			'status'               => $submission->status->value,
			'submission_text'      => $submission->submission_text,
			'submission_file_url'  => $submission->submission_file_url,
			'submission_file_name' => $submission->submission_file_name,
			'score'                => $submission->score,
			'feedback'             => $submission->feedback,
			'graded_by'            => $submission->graded_by,
			'submitted_at'         => $submission->submitted_at,
			'graded_at'            => $submission->graded_at,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update( $this->table(), $data, [ 'id' => $submission->id ] );
	}

	/**
	 * @param mixed $rows
	 * @return list<Submission>
	 */
	private function hydrate_rows( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Submission::from_array( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::assignment_submissions_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
