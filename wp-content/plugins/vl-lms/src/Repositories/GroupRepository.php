<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Group\Group;
use VL\LMS\Domain\Group\GroupStatus;

/**
 * Primitive data-access layer for `{prefix}vl_groups`.
 *
 * Mirrors {@see EnrollmentRepository}: prepared queries, no business
 * rules, no hooks. Returns `Group` objects or primitives for counts.
 * The `{$table}` interpolation in each prepared statement resolves to
 * {@see SchemaManager::groups_table()}, which is `$wpdb->prefix` plus a
 * hardcoded suffix — no untrusted input reaches the SQL string.
 *
 * @author Tymofii Synianskyi
 */
class GroupRepository {

	public function find_by_id( int $id ): ?Group {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return Group::from_row( $row );
	}

	public function find_by_slug( string $slug ): ?Group {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}

		return Group::from_row( $row );
	}

	/**
	 * @return list<Group>
	 */
	public function list_by_owner( int $owner_id, ?GroupStatus $status = null ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE owner_id = %d", $owner_id );
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE owner_id = %d AND status = %s",
				$owner_id,
				$status->value
			);
		}

		return $this->hydrate_list( $sql );
	}

	/**
	 * @return list<Group>
	 */
	public function list_by_status( GroupStatus $status ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s", $status->value );

		return $this->hydrate_list( $sql );
	}

	public function count_by_owner( int $owner_id, ?GroupStatus $status = null ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null === $status ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE owner_id = %d", $owner_id );
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE owner_id = %d AND status = %s",
				$owner_id,
				$status->value
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var( $sql );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Paginated listing for the wp-admin list table.
	 *
	 * Both filters are optional; passing `null` for both returns every row
	 * ordered by `created_at DESC`. `$search` matches `name` OR `slug` via
	 * a `LIKE` with `$wpdb->esc_like()`-escaped wildcards. `$limit` /
	 * `$offset` go through `%d` placeholders to avoid the
	 * `WordPress.DB.PreparedSQL.InterpolatedNotPrepared` sniff.
	 *
	 * @return list<Group>
	 */
	public function paginate( ?GroupStatus $status, ?string $search, int $limit, int $offset ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		[ $where, $args ] = $this->build_filter( $status, $search );

		$args[] = $limit;
		$args[] = $offset;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $table and $where are SQL fragments built locally; %d / %s placeholders for $args are valid.
			"SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$args
		);

		return $this->hydrate_list( $sql );
	}

	/**
	 * Total row count matching the same filters as {@see self::paginate()}.
	 * Used to populate `WP_List_Table::set_pagination_args()`.
	 */
	public function count( ?GroupStatus $status, ?string $search ): int {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		[ $where, $args ] = $this->build_filter( $status, $search );

		if ( [] === $args ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		} else {
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table and $where are SQL fragments built locally; %s placeholders for $args are valid.
				"SELECT COUNT(*) FROM {$table} {$where}",
				$args
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
			$count = $wpdb->get_var( $sql );
		}

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Build the shared WHERE clause + positional args used by paginate/count.
	 * Returns an empty clause + empty args when both filters are unset.
	 *
	 * @return array{0:string,1:list<scalar>}
	 */
	private function build_filter( ?GroupStatus $status, ?string $search ): array {
		$wpdb        = $this->wpdb();
		$conditions  = [];
		$args        = [];
		$search_trim = null === $search ? '' : trim( $search );

		if ( null !== $status ) {
			$conditions[] = 'status = %s';
			$args[]       = $status->value;
		}

		if ( '' !== $search_trim ) {
			$like         = '%' . $wpdb->esc_like( $search_trim ) . '%';
			$conditions[] = '(name LIKE %s OR slug LIKE %s)';
			$args[]       = $like;
			$args[]       = $like;
		}

		$where = [] === $conditions ? '' : 'WHERE ' . implode( ' AND ', $conditions );

		return [ $where, $args ];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( array $data ): int {
		$wpdb = $this->wpdb();

		$now                = gmdate( 'Y-m-d H:i:s' );
		$data['created_at'] = $data['created_at'] ?? $now;
		$data['updated_at'] = $data['updated_at'] ?? $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function update( int $id, array $data ): bool {
		$wpdb = $this->wpdb();

		$data['updated_at'] = gmdate( 'Y-m-d H:i:s' );

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
	 * @return list<Group>
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
				$out[] = Group::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::groups_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
