<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Progress\LessonView;
use VL\LMS\Domain\Progress\ViewEventType;

/**
 * Primitive data-access layer for `{prefix}vl_lesson_views`.
 *
 * Append-only event log — only `insert`, `find_by_id`, `list_for_session`,
 * and `delete_for_user` make sense. No `update`. JSON encoding/decoding for
 * the `payload` column lives at this seam so consumers always work with PHP
 * arrays.
 *
 * The `{$table}` interpolation in each prepared statement resolves to
 * {@see SchemaManager::lesson_views_table()}, which is `$wpdb->prefix` plus
 * a hardcoded suffix — no untrusted input reaches the SQL string.
 *
 * @author Tymofii Synianskyi
 */
class LessonViewRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * @param array<string, mixed>|null $payload Will be `wp_json_encode`-d on write.
	 */
	public function insert(
		int $user_id,
		int $lesson_id,
		?int $topic_id,
		string $session_uuid,
		ViewEventType $event_type,
		?int $position_seconds,
		?array $payload,
		\DateTimeImmutable $created_at
	): LessonView {
		$wpdb = $this->wpdb();

		$encoded_payload = null;
		if ( null !== $payload ) {
			$encoded         = wp_json_encode( $payload );
			$encoded_payload = false === $encoded ? null : $encoded;
		}

		$data = [
			'user_id'          => $user_id,
			'lesson_id'        => $lesson_id,
			'topic_id'         => $topic_id,
			'session_uuid'     => $session_uuid,
			'event_type'       => $event_type->value,
			'position_seconds' => $position_seconds,
			'payload'          => $encoded_payload,
			'created_at'       => $created_at->format( self::DATETIME_FORMAT ),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		$row = $this->find_by_id( (int) $wpdb->insert_id );
		if ( null === $row ) {
			throw new \RuntimeException( 'Failed to read back newly inserted lesson view row.' );
		}
		return $row;
	}

	public function find_by_id( int $id ): ?LessonView {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return $this->hydrate( $row );
	}

	/**
	 * @return list<LessonView>
	 */
	public function list_for_session( string $session_uuid ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE session_uuid = %s ORDER BY id ASC",
			$session_uuid
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = $this->hydrate( $row );
			}
		}
		return $out;
	}

	public function delete_for_user( int $user_id ): int {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $this->table(), [ 'user_id' => $user_id ] );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate( array $row ): LessonView {
		return new LessonView(
			(int) $row['id'],
			(int) $row['user_id'],
			(int) $row['lesson_id'],
			self::nullable_int( $row['topic_id'] ?? null ),
			(string) $row['session_uuid'],
			ViewEventType::from_string( (string) $row['event_type'] ),
			self::nullable_int( $row['position_seconds'] ?? null ),
			self::decode_payload( $row['payload'] ?? null ),
			new \DateTimeImmutable( (string) $row['created_at'], new \DateTimeZone( 'UTC' ) )
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decode_payload( mixed $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	private static function nullable_int( mixed $value ): ?int {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (int) $value;
	}

	private function table(): string {
		return SchemaManager::lesson_views_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
