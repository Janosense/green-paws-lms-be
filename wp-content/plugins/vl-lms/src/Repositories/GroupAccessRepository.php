<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Group\AccessEntityType;
use VL\LMS\Domain\Group\GroupAccess;

/**
 * Primitive data-access layer for `{prefix}vl_group_access`.
 *
 * "Active" rows are the ones where access has not yet expired — the
 * `list_active_for_group()` filter matches `expires_at IS NULL OR
 * expires_at > NOW()`, with `NOW()` evaluated by MySQL in the server
 * timezone. The per-row timestamps this table stores are UTC, so on
 * servers configured to UTC the comparison is direct; on non-UTC servers
 * the caller should additionally verify individual rows if they care
 * about sub-second precision.
 *
 * @author Tymofii Synianskyi
 */
class GroupAccessRepository {

	public function find_by_id( int $id ): ?GroupAccess {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return GroupAccess::from_row( $row );
	}

	public function find_by_group_entity( int $group_id, AccessEntityType $entity_type, int $entity_id ): ?GroupAccess {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE group_id = %d AND entity_type = %s AND entity_id = %d",
			$group_id,
			$entity_type->value,
			$entity_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return GroupAccess::from_row( $row );
	}

	/**
	 * @return list<GroupAccess>
	 */
	public function list_by_group( int $group_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE group_id = %d", $group_id );

		return $this->hydrate_list( $sql );
	}

	/**
	 * Rows whose grant is still in force — either no expiry or expiry in the future.
	 *
	 * @return list<GroupAccess>
	 */
	public function list_active_for_group( int $group_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE group_id = %d AND (expires_at IS NULL OR expires_at > NOW())",
			$group_id
		);

		return $this->hydrate_list( $sql );
	}

	/**
	 * @return list<GroupAccess>
	 */
	public function list_by_entity( AccessEntityType $entity_type, int $entity_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE entity_type = %s AND entity_id = %d",
			$entity_type->value,
			$entity_id
		);

		return $this->hydrate_list( $sql );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$wpdb = $this->wpdb();

		$data['granted_at'] = $data['granted_at'] ?? gmdate( 'Y-m-d H:i:s' );

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
	 * @return list<GroupAccess>
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
				$out[] = GroupAccess::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::group_access_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
