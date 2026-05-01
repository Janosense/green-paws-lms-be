<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\SessionAttendance\SessionAttendance;

/**
 * Primitive data-access layer for `{prefix}vl_session_attendance`.
 *
 * `record_join` is idempotent on `(session_id, zoom_participant_uuid)`:
 * if an open row (`left_at IS NULL`) already exists, it is returned
 * unchanged. `record_leave` finds the open row and stamps `left_at` /
 * `duration_seconds`. Phase 7.2 webhook handlers are the primary callers.
 *
 * @author Tymofii Synianskyi
 */
class SessionAttendanceRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Idempotent on `(session_id, zoom_participant_uuid)`. If an open row
	 * exists for the same `(session, participant)` pair, returns it
	 * unchanged. Otherwise INSERTs a new row.
	 */
	public function record_join(
		int $session_id,
		?int $user_id,
		string $zoom_participant_uuid,
		?string $participant_email,
		?string $participant_name,
		\DateTimeImmutable $joined_at
	): SessionAttendance {
		$existing = $this->find_open( $session_id, $zoom_participant_uuid );
		if ( null !== $existing ) {
			return $existing;
		}

		$wpdb = $this->wpdb();

		$data = [
			'session_id'            => $session_id,
			'user_id'               => $user_id,
			'zoom_participant_uuid' => $zoom_participant_uuid,
			'participant_email'     => $participant_email,
			'participant_name'      => $participant_name,
			'joined_at'             => $joined_at->format( self::DATETIME_FORMAT ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		$id = (int) $wpdb->insert_id;

		$row = $this->find_row( $id );
		if ( null === $row ) {
			throw new \RuntimeException( 'Failed to read back inserted session_attendance row.' );
		}
		return SessionAttendance::from_row( $row );
	}

	/**
	 * Closes the open `(session, participant)` row by stamping `left_at`
	 * and `duration_seconds`. Returns `null` if no open row was found —
	 * the caller logs the orphan leave (Phase 7.2 dispatcher).
	 */
	public function record_leave(
		int $session_id,
		string $zoom_participant_uuid,
		\DateTimeImmutable $left_at
	): ?SessionAttendance {
		$open = $this->find_open( $session_id, $zoom_participant_uuid );
		if ( null === $open ) {
			return null;
		}

		$joined   = new \DateTimeImmutable( $open->joined_at, new \DateTimeZone( 'UTC' ) );
		$duration = $left_at->getTimestamp() - $joined->getTimestamp();
		if ( $duration < 0 ) {
			$duration = 0;
		}

		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->table(),
			[
				'left_at'          => $left_at->format( self::DATETIME_FORMAT ),
				'duration_seconds' => $duration,
			],
			[ 'id' => $open->id ]
		);

		$row = $this->find_row( $open->id );
		if ( null === $row ) {
			return null;
		}
		return SessionAttendance::from_row( $row );
	}

	public function find_open( int $session_id, string $zoom_participant_uuid ): ?SessionAttendance {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE session_id = %d AND zoom_participant_uuid = %s AND left_at IS NULL ORDER BY id DESC LIMIT 1",
			$session_id,
			$zoom_participant_uuid
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return SessionAttendance::from_row( $row );
	}

	/**
	 * @return list<SessionAttendance>
	 */
	public function list_for_session( int $session_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE session_id = %d ORDER BY joined_at ASC",
			$session_id
		);
		return $this->hydrate_list( $sql );
	}

	/**
	 * @return list<SessionAttendance>
	 */
	public function list_for_user( int $user_id, ?int $session_id = null ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $session_id ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY joined_at DESC",
				$user_id
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d AND session_id = %d ORDER BY joined_at DESC",
				$user_id,
				$session_id
			);
		}

		return $this->hydrate_list( $sql );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_row( int $id ): ?array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE id = %d",
			$id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<SessionAttendance>
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
				$out[] = SessionAttendance::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::session_attendance_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
