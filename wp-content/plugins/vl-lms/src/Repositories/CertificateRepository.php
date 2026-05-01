<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Certificate\Certificate;

/**
 * Primitive data-access layer for `{prefix}vl_certificates`.
 *
 * Issuing certificates is the service layer's job (Phase 6.3) — the
 * repository only knows how to read by id / uuid, list, insert, and write
 * the two narrow update paths the service needs (revocation flip, lazy
 * PDF-path stamp). Active-per-(user, course) uniqueness is enforced in
 * the service layer because MySQL has no partial unique index over a
 * nullable column.
 *
 * The `{$table}` interpolation in each prepared statement resolves to
 * {@see SchemaManager::certificates_table()}, which is `$wpdb->prefix`
 * plus a hardcoded suffix — no untrusted input reaches the SQL string.
 *
 * @author Tymofii Synianskyi
 */
class CertificateRepository {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	/** @var callable():\DateTimeImmutable */
	private $clock;

	/**
	 * @param (callable():\DateTimeImmutable)|null $clock UTC clock; defaults to wall-clock UTC.
	 */
	public function __construct( ?callable $clock = null ) {
		$this->clock = $clock ?? static fn (): \DateTimeImmutable =>
			new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	public function find( int $id ): ?Certificate {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Certificate::from_array( $row );
	}

	public function find_by_uuid( string $uuid ): ?Certificate {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Certificate::from_array( $row );
	}

	public function find_active_for_user_in_course( int $user_id, int $course_id ): ?Certificate {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d AND course_id = %d AND revoked_at IS NULL ORDER BY issued_at DESC LIMIT 1",
			$user_id,
			$course_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Certificate::from_array( $row );
	}

	/**
	 * @return list<Certificate>
	 */
	public function list_for_enrollment( int $enrollment_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE enrollment_id = %d ORDER BY issued_at DESC",
			$enrollment_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Certificate::from_array( $row );
			}
		}
		return $out;
	}

	/**
	 * @return list<Certificate>
	 */
	public function list_for_user( int $user_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE user_id = %d ORDER BY issued_at DESC",
			$user_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Certificate::from_array( $row );
			}
		}
		return $out;
	}

	/**
	 * Insert a new certificate row. The VO must already carry a UUID (the
	 * service layer mints it before calling here). `created_at` and
	 * `updated_at` are stamped from the injected clock; the VO's `id` is
	 * ignored — `wpdb->insert_id` is authoritative.
	 */
	public function insert( Certificate $cert ): int {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		$data               = $cert->to_array();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		unset( $data['id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $this->table(), $data );

		return (int) $wpdb->insert_id;
	}

	/**
	 * Set or clear `revoked_at` on a certificate.
	 *
	 * Passing `null` clears the timestamp (admin un-revoke); passing a
	 * `DateTimeImmutable` sets it.
	 */
	public function update_revocation( int $id, ?\DateTimeImmutable $revoked_at ): bool {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		$data = [
			'revoked_at' => null === $revoked_at ? null : $revoked_at->format( self::DATETIME_FORMAT ),
			'updated_at' => $now,
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update( $this->table(), $data, [ 'id' => $id ] );

		return false !== $result && $result >= 1;
	}

	public function update_pdf_path( int $id, string $path ): bool {
		$wpdb = $this->wpdb();
		$now  = $this->now()->format( self::DATETIME_FORMAT );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->update(
			$this->table(),
			[
				'pdf_path'   => $path,
				'updated_at' => $now,
			],
			[ 'id' => $id ]
		);

		return false !== $result && $result >= 1;
	}

	private function now(): \DateTimeImmutable {
		return ( $this->clock )();
	}

	private function table(): string {
		return SchemaManager::certificates_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
