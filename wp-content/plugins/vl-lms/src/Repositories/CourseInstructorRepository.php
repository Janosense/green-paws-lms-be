<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\CourseInstructor\CourseInstructor;
use VL\LMS\Domain\CourseInstructor\InstructorEntityType;

/**
 * Primitive data-access layer for `{prefix}vl_course_instructors`.
 *
 * {@see self::list_for_entity()} is the one place in Phase 1 where this
 * plugin emits an `ORDER BY` clause — `display_order` is part of the
 * instructor list's data contract (authors can sort their team) rather
 * than a presentation concern, so ordering lives with the storage layer.
 *
 * @author Tymofii Synianskyi
 */
class CourseInstructorRepository {

	public function find_by_id( int $id ): ?CourseInstructor {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return CourseInstructor::from_row( $row );
	}

	public function find_assignment(
		InstructorEntityType $entity_type,
		int $entity_id,
		int $user_id
	): ?CourseInstructor {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d AND user_id = %d",
			$entity_type->value,
			$entity_id,
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return CourseInstructor::from_row( $row );
	}

	/**
	 * @return list<CourseInstructor>
	 */
	public function list_for_entity( InstructorEntityType $entity_type, int $entity_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d ORDER BY display_order ASC, id ASC",
			$entity_type->value,
			$entity_id
		);

		return $this->hydrate_list( $sql );
	}

	/**
	 * @return list<CourseInstructor>
	 */
	public function list_for_user( int $user_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id );

		return $this->hydrate_list( $sql );
	}

	/**
	 * Batch lookup of one lead row per `entity_id` for the given type.
	 *
	 * Returned map is keyed by `entity_id`; entities without a lead row
	 * are simply absent from the map. The catalog list endpoints use this
	 * to keep lead resolution at one round-trip per page (no N+1) — see
	 * {@see \VL\LMS\Catalog\CatalogController}'s class docblock.
	 *
	 * @param list<int> $entity_ids
	 *
	 * @return array<int, CourseInstructor>
	 */
	public function find_leads_for_entities( InstructorEntityType $entity_type, array $entity_ids ): array {
		$entity_ids = array_values( array_unique( array_filter( $entity_ids, static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $entity_ids ) {
			return [];
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		$placeholders = implode( ', ', array_fill( 0, count( $entity_ids ), '%d' ) );
		$params       = array_merge( [ $entity_type->value ], $entity_ids );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholder list is built from a counted array of integers.
			"SELECT * FROM {$table} WHERE entity_type = %s AND role_in_course = 'lead' AND entity_id IN ({$placeholders}) ORDER BY entity_id ASC, id ASC",
			$params
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
			$instructor = CourseInstructor::from_row( $row );
			// First row per entity_id wins because of `ORDER BY entity_id ASC, id ASC`.
			if ( ! isset( $out[ $instructor->entity_id ] ) ) {
				$out[ $instructor->entity_id ] = $instructor;
			}
		}
		return $out;
	}

	/**
	 * Returns the first lead row (by `id ASC`) for the entity. During an
	 * in-flight save_post transition more than one row may briefly carry
	 * `role_in_course = 'lead'`; the sync service is expected to
	 * immediately reduce that to one.
	 */
	public function find_lead( InstructorEntityType $entity_type, int $entity_id ): ?CourseInstructor {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d AND role_in_course = 'lead' ORDER BY id ASC LIMIT 1",
			$entity_type->value,
			$entity_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return CourseInstructor::from_row( $row );
	}

	public function is_assigned( InstructorEntityType $entity_type, int $entity_id, int $user_id ): bool {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT 1 FROM {$table} WHERE entity_type = %s AND entity_id = %d AND user_id = %d LIMIT 1",
			$entity_type->value,
			$entity_id,
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var( $sql );

		return null !== $value;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$wpdb = $this->wpdb();

		$data['assigned_at'] = $data['assigned_at'] ?? gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $id ] );

		return false !== $result;
	}

	public function delete( int $id ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete( $this->table(), [ 'id' => $id ] );

		return false !== $result;
	}

	/**
	 * Deletes every assignment for the given entity and returns the number
	 * of rows deleted. Used when a course or webinar is permanently
	 * deleted from WordPress.
	 */
	public function delete_all_for_entity( InstructorEntityType $entity_type, int $entity_id ): int {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->delete(
			$this->table(),
			[
				'entity_type' => $entity_type->value,
				'entity_id'   => $entity_id,
			]
		);

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * @return list<CourseInstructor>
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
				$out[] = CourseInstructor::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::course_instructors_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
