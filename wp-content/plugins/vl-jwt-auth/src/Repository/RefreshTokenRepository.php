<?php

declare(strict_types=1);

namespace VLJwtAuth\Repository;

use VLJwtAuth\Support\Hasher;

/**
 * CRUD on {prefix}vl_refresh_tokens.
 *
 * Stores only SHA-256 hashes of issued refresh tokens — the raw token is
 * never written. IP addresses are kept in the VARBINARY column via
 * INET6_ATON / INET6_NTOA to sidestep binary handling through prepare.
 *
 * @phpstan-type RefreshTokenRow array{
 *     id: int,
 *     user_id: int,
 *     token_hash: string,
 *     token_family: string,
 *     device_name: ?string,
 *     ip_address: ?string,
 *     user_agent: ?string,
 *     expires_at: string,
 *     revoked_at: ?string,
 *     created_at: string,
 *     last_used_at: ?string
 * }
 */
final class RefreshTokenRepository {

	private string $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'vl_refresh_tokens';
	}

	/**
	 * Persist a newly issued refresh token.
	 *
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create(
		int $user_id,
		string $token,
		string $token_family,
		string $expires_at_utc,
		?string $device_name = null,
		?string $ip_address = null,
		?string $user_agent = null
	): int {
		global $wpdb;

		$hash = Hasher::hash( $token );
		$now  = gmdate( 'Y-m-d H:i:s' );

		// Raw query rather than $wpdb->insert so we can use INET6_ATON() for the VARBINARY column.
		$sql = "INSERT INTO {$this->table}
			(user_id, token_hash, token_family, device_name, ip_address, user_agent, expires_at, created_at)
			VALUES (%d, %s, %s, %s, INET6_ATON(%s), %s, %s, %s)";

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$user_id,
				$hash,
				$token_family,
				(string) ( $device_name ?? '' ),
				(string) ( $ip_address ?? '' ),
				(string) ( $user_agent ?? '' ),
				$expires_at_utc,
				$now
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to persist refresh token.' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * @return RefreshTokenRow|null
	 */
	public function find_by_hash( string $hash ): ?array {
		global $wpdb;

		$sql = "SELECT id, user_id, token_hash, token_family, device_name,
			INET6_NTOA(ip_address) AS ip_address, user_agent,
			expires_at, revoked_at, created_at, last_used_at
			FROM {$this->table}
			WHERE token_hash = %s
			LIMIT 1";

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $hash ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Mark a single token as revoked. No-op if already revoked.
	 */
	public function revoke( int $id ): void {
		global $wpdb;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$this->table} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				$id
			)
		);
	}

	/**
	 * Revoke every active token sharing the given token family — the response
	 * to a detected refresh-token replay.
	 *
	 * @return int Number of rows newly revoked.
	 */
	public function revoke_family( string $token_family ): int {
		global $wpdb;

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$this->table} SET revoked_at = %s WHERE token_family = %s AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				$token_family
			)
		);
	}

	/**
	 * Revoke every active token belonging to a user — used on password change.
	 *
	 * @return int Number of rows newly revoked.
	 */
	public function revoke_user( int $user_id ): int {
		global $wpdb;

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$this->table} SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				$user_id
			)
		);
	}

	/**
	 * Update `last_used_at` for observability on the /sessions endpoint.
	 */
	public function touch( int $id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->table,
			[ 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * List active (non-revoked, non-expired) refresh tokens for a user —
	 * the source data for the /sessions endpoint.
	 *
	 * @return list<RefreshTokenRow>
	 */
	public function list_active_for_user( int $user_id ): array {
		global $wpdb;

		$sql = "SELECT id, user_id, token_hash, token_family, device_name,
			INET6_NTOA(ip_address) AS ip_address, user_agent,
			expires_at, revoked_at, created_at, last_used_at
			FROM {$this->table}
			WHERE user_id = %d
			  AND revoked_at IS NULL
			  AND expires_at > %s
			ORDER BY last_used_at IS NULL, last_used_at DESC, created_at DESC";

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $user_id, gmdate( 'Y-m-d H:i:s' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return array_map( fn ( array $row ): array => $this->normalize_row( $row ), $rows );
	}

	/**
	 * Fetch a single row by id (used by /sessions/{id} revocation).
	 *
	 * @return RefreshTokenRow|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$sql = "SELECT id, user_id, token_hash, token_family, device_name,
			INET6_NTOA(ip_address) AS ip_address, user_agent,
			expires_at, revoked_at, created_at, last_used_at
			FROM {$this->table}
			WHERE id = %d
			LIMIT 1";

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return $row ? $this->normalize_row( $row ) : null;
	}

	/**
	 * Hard-delete rows whose tokens expired more than 30 days ago.
	 * Intended for a scheduled cleanup job.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_expired(): int {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$cutoff
			)
		);
	}

	/**
	 * Cast raw DB strings into the typed row shape callers rely on.
	 *
	 * @param array<string, mixed> $row
	 * @return RefreshTokenRow
	 */
	private function normalize_row( array $row ): array {
		return [
			'id'           => (int) $row['id'],
			'user_id'      => (int) $row['user_id'],
			'token_hash'   => (string) $row['token_hash'],
			'token_family' => (string) $row['token_family'],
			'device_name'  => isset( $row['device_name'] ) ? (string) $row['device_name'] : null,
			'ip_address'   => isset( $row['ip_address'] ) && '' !== $row['ip_address']
				? (string) $row['ip_address']
				: null,
			'user_agent'   => isset( $row['user_agent'] ) ? (string) $row['user_agent'] : null,
			'expires_at'   => (string) $row['expires_at'],
			'revoked_at'   => isset( $row['revoked_at'] ) ? (string) $row['revoked_at'] : null,
			'created_at'   => (string) $row['created_at'],
			'last_used_at' => isset( $row['last_used_at'] ) ? (string) $row['last_used_at'] : null,
		];
	}
}
