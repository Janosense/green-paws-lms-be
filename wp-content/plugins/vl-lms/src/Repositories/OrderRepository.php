<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Domain\Order\PurchasableEntityType;

/**
 * Primitive data-access layer for `{prefix}vl_orders`.
 *
 * Mirrors the shape of {@see WebinarRegistrationRepository} and
 * {@see QuizAttemptRepository} — prepared queries, no business rules,
 * no hooks. Domain orchestration (create / cancel / mark-paid /
 * idempotent re-create) lives in Phase 8.1's `OrderService`; the
 * repository only knows how to read and write rows.
 *
 * Money round-trips through {@see \VL\LMS\Domain\Money\Money} at the
 * VO's `from_row` / `to_row` boundary so the SQL parameters always carry
 * the major-unit decimal string MySQL expects for the `DECIMAL(12,2)`
 * column.
 *
 * Phase 8.0 — Foundations. Consumers land in 8.1+.
 *
 * @author Tymofii Synianskyi
 */
class OrderRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/**
	 * @throws \DomainException When `$order` already carries an id.
	 */
	public function insert( Order $order ): int {
		if ( null !== $order->id ) {
			throw new \DomainException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Cannot insert order %d — it already has an id.', $order->id )
			);
		}

		$wpdb = $this->wpdb();
		$data = $order->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Replace every column on the matching `id` row with the values from
	 * `$order`. Used by callers that have a fully-hydrated VO and want a
	 * single round-trip — narrow updates have dedicated methods below.
	 */
	public function update( Order $order ): bool {
		if ( null === $order->id ) {
			throw new \DomainException( 'Cannot update an unsaved order — id is null.' );
		}

		$wpdb = $this->wpdb();
		$data = $order->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $order->id ] );

		return false !== $result;
	}

	public function update_provider_reference( int $order_id, string $reference ): bool {
		$wpdb = $this->wpdb();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$this->table(),
			[ 'liqpay_order_id' => $reference ],
			[ 'id' => $order_id ]
		);

		return false !== $result;
	}

	/**
	 * Narrow status-change updater.
	 *
	 * Derives the right timestamp column from `$new_status`:
	 * `PAID` → `paid_at`, `CANCELLED` → `cancelled_at`, `REFUNDED` →
	 * `refunded_at`. NULL `$timestamp` means "use the current UTC instant".
	 * For terminal-but-no-timestamp transitions (`FAILED`, `EXPIRED`,
	 * `AWAITING_PAYMENT`) the optional argument is ignored at the column
	 * level — only `status` changes.
	 */
	public function update_status(
		int $order_id,
		OrderStatus $new_status,
		?\DateTimeImmutable $timestamp = null
	): bool {
		$wpdb = $this->wpdb();

		$data = [ 'status' => $new_status->value ];

		$timestamp_column = match ( $new_status ) {
			OrderStatus::PAID      => 'paid_at',
			OrderStatus::CANCELLED => 'cancelled_at',
			OrderStatus::REFUNDED  => 'refunded_at',
			default                => null,
		};

		if ( null !== $timestamp_column ) {
			$value                     = ( $timestamp ?? new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) )->format( self::DATETIME_FORMAT );
			$data[ $timestamp_column ] = $value;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $order_id ] );

		return false !== $result;
	}

	public function find_by_id( int $id ): ?Order {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Order::from_row( $row );
	}

	public function find_by_uuid( string $uuid ): ?Order {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Order::from_row( $row );
	}

	public function find_by_provider_reference( string $provider, string $reference ): ?Order {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE payment_provider = %s AND liqpay_order_id = %s",
			$provider,
			$reference
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Order::from_row( $row );
	}

	/**
	 * Page through a user's orders, ordered `created_at DESC`.
	 *
	 * `$statuses === null` means "any status". An empty list returns an
	 * empty page rather than emitting `WHERE status IN ()` (which is a
	 * SQL syntax error).
	 *
	 * @param list<OrderStatus>|null $statuses
	 *
	 * @return array{items: list<Order>, total: int}
	 */
	public function list_for_user(
		int $user_id,
		?array $statuses,
		int $page,
		int $per_page
	): array {
		$page     = max( 1, $page );
		$per_page = max( 1, $per_page );

		$wpdb  = $this->wpdb();
		$table = $this->table();

		if ( null !== $statuses && [] === $statuses ) {
			return [
				'items' => [],
				'total' => 0,
			];
		}

		if ( null === $statuses ) {
			$count_sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d",
				$user_id
			);
			$page_sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				( $page - 1 ) * $per_page
			);
		} else {
			$values       = array_map( static fn ( OrderStatus $s ): string => $s->value, $statuses );
			$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

			$count_sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status IN ({$placeholders})",
				$user_id,
				...$values
			);
			$page_sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE user_id = %d AND status IN ({$placeholders}) ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$user_id,
				...array_merge( $values, [ $per_page, ( $page - 1 ) * $per_page ] )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = $wpdb->get_var( $count_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $page_sql, ARRAY_A );

		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$items[] = Order::from_row( $row );
				}
			}
		}

		return [
			'items' => $items,
			'total' => is_numeric( $total ) ? (int) $total : 0,
		];
	}

	/**
	 * Look up the most-recent open order for the
	 * `(user_id, entity_type, entity_id)` triple. 8.1's `OrderService`
	 * uses this to short-circuit `POST /vl/v1/orders` into idempotent
	 * "return existing pending order" behaviour.
	 */
	public function find_open_for_user_and_entity(
		int $user_id,
		PurchasableEntityType $entity_type,
		int $entity_id
	): ?Order {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table}
			 WHERE user_id = %d AND entity_type = %s AND entity_id = %d AND status IN (%s, %s)
			 ORDER BY created_at DESC
			 LIMIT 1",
			$user_id,
			$entity_type->value,
			$entity_id,
			OrderStatus::PENDING->value,
			OrderStatus::AWAITING_PAYMENT->value
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Order::from_row( $row );
	}

	/**
	 * Admin-side query: search by UUID / user_email / title; filter by
	 * status / entity_type; sort by an allowed column; paginate.
	 *
	 * Phase 8.6 — `Admin/Orders/OrdersListTable` consumer. The user_email
	 * branch JOINs against `wp_users` so a learner's mailbox matches even
	 * when their display name doesn't appear in the order snapshot.
	 *
	 * `$filters` accepts:
	 *   - `'search'`      => string|null (UUID exact / email exact / title LIKE — OR'd)
	 *   - `'status'`      => string|null single OrderStatus value
	 *   - `'entity_type'` => string|null `'course'` / `'webinar'`
	 *
	 * `$sort_by` whitelist: `created_at`, `amount`, `status`, `user_email`.
	 * Anything else falls back to `created_at`. `$sort_dir`: `ASC` / `DESC`,
	 * else `DESC`. `$per_page` is clamped to `[1, 100]` and `$page` to `>= 1`.
	 *
	 * @param array{search?: string|null, status?: string|null, entity_type?: string|null} $filters
	 *
	 * @return array{items: list<Order>, total: int}
	 */
	public function list_for_admin(
		array $filters,
		int $page,
		int $per_page,
		string $sort_by,
		string $sort_dir
	): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );

		$sort_columns = [
			'created_at' => 'o.created_at',
			'amount'     => 'o.amount',
			'status'     => 'o.status',
			'user_email' => 'u.user_email',
		];
		$sort_column  = $sort_columns[ $sort_by ] ?? $sort_columns['created_at'];
		$sort_dir     = 'ASC' === strtoupper( $sort_dir ) ? 'ASC' : 'DESC';

		$wpdb        = $this->wpdb();
		$orders      = $this->table();
		$users_table = $wpdb->prefix . 'users';

		$where  = [];
		$params = [];

		$status = $filters['status'] ?? null;
		if ( null !== $status && '' !== $status ) {
			$where[]  = 'o.status = %s';
			$params[] = (string) $status;
		}

		$entity_type = $filters['entity_type'] ?? null;
		if ( null !== $entity_type && '' !== $entity_type ) {
			$where[]  = 'o.entity_type = %s';
			$params[] = (string) $entity_type;
		}

		$search = $filters['search'] ?? null;
		if ( null !== $search && '' !== trim( (string) $search ) ) {
			$search   = trim( (string) $search );
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( o.uuid = %s OR u.user_email = %s OR o.entity_title_snapshot LIKE %s )';
			$params[] = $search;
			$params[] = $search;
			$params[] = $like;
		}

		$where_sql = [] === $where ? '' : ( ' WHERE ' . implode( ' AND ', $where ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql_template = "SELECT COUNT(*) FROM {$orders} o LEFT JOIN {$users_table} u ON u.ID = o.user_id" . $where_sql;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$page_sql_template = "SELECT o.* FROM {$orders} o LEFT JOIN {$users_table} u ON u.ID = o.user_id" . $where_sql . " ORDER BY {$sort_column} {$sort_dir} LIMIT %d OFFSET %d";

		if ( [] === $params ) {
			$count_sql = $count_sql_template;
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = $wpdb->prepare( $count_sql_template, ...$params );
		}

		$page_params = array_merge( $params, [ $per_page, ( $page - 1 ) * $per_page ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$page_sql = $wpdb->prepare( $page_sql_template, ...$page_params );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = $wpdb->get_var( $count_sql );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $page_sql, ARRAY_A );

		$items = [];
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) ) {
					$items[] = Order::from_row( $row );
				}
			}
		}

		return [
			'items' => $items,
			'total' => is_numeric( $total ) ? (int) $total : 0,
		];
	}

	/**
	 * Phase 8.2's expiration cron reads up to `$limit` open orders past
	 * their `expires_at`, ordered by oldest expiry first.
	 *
	 * @return list<Order>
	 */
	public function list_expired_open( \DateTimeImmutable $now, int $limit = 100 ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table}
			 WHERE status IN (%s, %s) AND expires_at <= %s
			 ORDER BY expires_at ASC
			 LIMIT %d",
			OrderStatus::PENDING->value,
			OrderStatus::AWAITING_PAYMENT->value,
			$now->format( self::DATETIME_FORMAT ),
			max( 1, $limit )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Order::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::orders_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
