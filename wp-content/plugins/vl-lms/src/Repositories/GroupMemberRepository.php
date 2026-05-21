<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Group\GroupMember;

/**
 * Primitive data-access layer for `{prefix}vl_group_members`.
 *
 * "Active" means `left_at IS NULL` — the uniqueness index
 * `uk_group_user_active (group_id, user_id, left_at)` permits exactly one
 * such row per `(group, user)` pair, so list methods return membership
 * state as of now and historical rows sit quietly as audit trail.
 *
 * @author Tymofii Synianskyi
 */
class GroupMemberRepository {

	public function find_by_id( int $id ): ?GroupMember {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return GroupMember::from_row( $row );
	}

	public function find_active( int $group_id, int $user_id ): ?GroupMember {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE group_id = %d AND user_id = %d AND left_at IS NULL",
			$group_id,
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return GroupMember::from_row( $row );
	}

	/**
	 * @return list<GroupMember>
	 */
	public function list_active_members( int $group_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE group_id = %d AND left_at IS NULL", $group_id );

		return $this->hydrate_list( $sql );
	}

	/**
	 * @return list<GroupMember>
	 */
	public function list_active_memberships_for_user( int $user_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d AND left_at IS NULL", $user_id );

		return $this->hydrate_list( $sql );
	}

	/**
	 * Bulk active-membership lookup for the Students admin list. Returns
	 * a `user_id => list<GroupMember>` map; users with no active rows are
	 * omitted from the result so callers can default to `[]`. Empty input
	 * short-circuits to an empty array — avoids generating a malformed
	 * `IN ()` clause.
	 *
	 * @param list<int> $user_ids
	 * @return array<int, list<GroupMember>>
	 */
	public function list_active_for_users( array $user_ids ): array {
		if ( [] === $user_ids ) {
			return [];
		}

		$wpdb  = $this->wpdb();
		$table = $this->table();

		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $table and $placeholders are SQL fragments built locally; %d placeholders for $user_ids are valid.
			"SELECT * FROM {$table} WHERE user_id IN ({$placeholders}) AND left_at IS NULL",
			array_values( $user_ids )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$out = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['user_id'] ) ) {
					continue;
				}
				$user_id           = (int) $row['user_id'];
				$out[ $user_id ] ??= [];
				$out[ $user_id ][] = GroupMember::from_row( $row );
			}
		}
		return $out;
	}

	public function count_active_members( int $group_id ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE group_id = %d AND left_at IS NULL", $group_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$wpdb = $this->wpdb();

		$data['joined_at'] = $data['joined_at'] ?? gmdate( 'Y-m-d H:i:s' );

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

	/**
	 * Sets `left_at` to `$left_at` (or `now()` when `null`).
	 *
	 * Returns `true` iff exactly one row was updated — useful for callers
	 * that want to know whether the member was actually active.
	 */
	public function mark_left( int $id, ?string $left_at = null ): bool {
		$wpdb = $this->wpdb();

		$timestamp = $left_at ?? gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$this->table(),
			[ 'left_at' => $timestamp ],
			[ 'id' => $id ]
		);

		return is_int( $result ) && $result > 0;
	}

	/**
	 * @return list<GroupMember>
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
				$out[] = GroupMember::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::group_members_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
