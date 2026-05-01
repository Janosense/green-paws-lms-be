<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationSource;
use VL\LMS\Domain\WebinarRegistration\WebinarRegistrationStatus;

/**
 * Primitive data-access layer for `{prefix}vl_webinar_registrations`.
 *
 * `register` upserts on `(webinar_id, user_id)` — a re-registration
 * after cancellation flips the existing row's status back to `active`
 * rather than INSERTing a duplicate. `mark_attended` accumulates
 * live-attendance duration recorded by the Phase 7.2 webinar attendance
 * handler.
 *
 * @author Tymofii Synianskyi
 */
class WebinarRegistrationRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * Idempotent upsert keyed on `(webinar_id, user_id)`. If a row exists
	 * (regardless of status), flips it back to `active`, clears
	 * `cancelled_at`, and updates `source`. Otherwise INSERTs a new row.
	 */
	public function register(
		int $webinar_id,
		int $user_id,
		WebinarRegistrationSource $source,
		\DateTimeImmutable $now
	): WebinarRegistration {
		$wpdb = $this->wpdb();

		$existing = $this->find( $webinar_id, $user_id );
		if ( null !== $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->update(
				$this->table(),
				[
					'status'        => WebinarRegistrationStatus::ACTIVE->value,
					'source'        => $source->value,
					'cancelled_at'  => null,
					'registered_at' => $existing->registered_at,
				],
				[ 'id' => $existing->id ]
			);

			$row = $this->find_row_by_id( $existing->id );
			if ( null === $row ) {
				throw new \RuntimeException( 'Failed to read back webinar registration row.' );
			}
			return WebinarRegistration::from_row( $row );
		}

		$data = [
			'webinar_id'    => $webinar_id,
			'user_id'       => $user_id,
			'status'        => WebinarRegistrationStatus::ACTIVE->value,
			'source'        => $source->value,
			'registered_at' => $now->format( self::DATETIME_FORMAT ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		$row = $this->find_row_by_id( (int) $wpdb->insert_id );
		if ( null === $row ) {
			throw new \RuntimeException( 'Failed to read back webinar registration row.' );
		}
		return WebinarRegistration::from_row( $row );
	}

	public function cancel( int $webinar_id, int $user_id, \DateTimeImmutable $now ): ?WebinarRegistration {
		$existing = $this->find( $webinar_id, $user_id );
		if ( null === $existing ) {
			return null;
		}

		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->table(),
			[
				'status'       => WebinarRegistrationStatus::CANCELLED->value,
				'cancelled_at' => $now->format( self::DATETIME_FORMAT ),
			],
			[ 'id' => $existing->id ]
		);

		$row = $this->find_row_by_id( $existing->id );
		if ( null === $row ) {
			return null;
		}
		return WebinarRegistration::from_row( $row );
	}

	public function find( int $webinar_id, int $user_id ): ?WebinarRegistration {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE webinar_id = %d AND user_id = %d",
			$webinar_id,
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return WebinarRegistration::from_row( $row );
	}

	public function find_active( int $webinar_id, int $user_id ): ?WebinarRegistration {
		$existing = $this->find( $webinar_id, $user_id );
		if ( null === $existing ) {
			return null;
		}
		if ( WebinarRegistrationStatus::ACTIVE !== $existing->status ) {
			return null;
		}
		return $existing;
	}

	/**
	 * @return list<WebinarRegistration>
	 */
	public function list_for_user( int $user_id, ?WebinarRegistrationStatus $status = null ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $status ) {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY registered_at DESC",
				$user_id
			);
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d AND status = %s ORDER BY registered_at DESC",
				$user_id,
				$status->value
			);
		}

		return $this->hydrate_list( $sql );
	}

	public function count_active_for_webinar( int $webinar_id ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE webinar_id = %d AND status = %s",
			$webinar_id,
			WebinarRegistrationStatus::ACTIVE->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Sets `attended = 1` and accumulates `attended_duration_seconds`
	 * by the supplied delta. Phase 7.2 webinar attendance handler calls
	 * this on each `participant_left` event.
	 */
	public function mark_attended( int $webinar_id, int $user_id, int $duration_increment_seconds ): void {
		$existing = $this->find( $webinar_id, $user_id );
		if ( null === $existing ) {
			return;
		}

		$wpdb = $this->wpdb();

		$new_total = $existing->attended_duration_seconds + max( 0, $duration_increment_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$this->table(),
			[
				'attended'                  => 1,
				'attended_duration_seconds' => $new_total,
			],
			[ 'id' => $existing->id ]
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function find_row_by_id( int $id ): ?array {
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
	 * @return list<WebinarRegistration>
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
				$out[] = WebinarRegistration::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::webinar_registrations_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
