<?php

declare(strict_types=1);

namespace VL\LMS\Repositories;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Domain\Payment\Payment;

/**
 * Primitive data-access layer for `{prefix}vl_payments`.
 *
 * Append-only audit trail of provider callbacks per order. The UNIQUE
 * `idempotency_key` column is the safety backstop against duplicate
 * insertion — Phase 8.2's `CallbackHandler` lookups via
 * {@see self::find_by_idempotency_key()} first, but a concurrent race
 * past the lookup is caught by the constraint and surfaces as
 * {@see PaymentAlreadyRecordedException}.
 *
 * Phase 8.0 — Foundations.
 *
 * @author Tymofii Synianskyi
 */
class PaymentRepository {

	/**
	 * @throws \DomainException When `$payment` already carries an id.
	 * @throws PaymentAlreadyRecordedException When the idempotency key collides.
	 */
	public function insert( Payment $payment ): int {
		if ( null !== $payment->id ) {
			throw new \DomainException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Cannot insert payment %d — it already has an id.', $payment->id )
			);
		}

		$existing = $this->find_by_idempotency_key( $payment->idempotency_key );
		if ( null !== $existing ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new PaymentAlreadyRecordedException( $payment->idempotency_key );
		}

		$wpdb = $this->wpdb();
		$data = $payment->to_row();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert( $this->table(), $data );

		if ( false === $result ) {
			// `$wpdb->insert` returns false on UNIQUE-violation. The pre-check
			// above almost always catches the duplicate first; this branch
			// covers the lookup-then-insert race window.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			throw new PaymentAlreadyRecordedException( $payment->idempotency_key );
		}

		return (int) $wpdb->insert_id;
	}

	public function find_by_id( int $id ): ?Payment {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Payment::from_row( $row );
	}

	public function find_by_idempotency_key( string $key ): ?Payment {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $sql, ARRAY_A );

		if ( ! is_array( $row ) ) {
			return null;
		}
		return Payment::from_row( $row );
	}

	/**
	 * @return list<Payment>
	 */
	public function list_for_order( int $order_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE order_id = %d ORDER BY received_at ASC, id ASC",
			$order_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	/**
	 * @return list<Payment>
	 */
	public function list_by_provider_payment_id( string $provider, string $provider_payment_id ): array {
		$wpdb  = $this->wpdb();
		$table = $this->table();

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$table} WHERE provider = %s AND provider_payment_id = %s ORDER BY received_at ASC, id ASC",
			$provider,
			$provider_payment_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $this->hydrate_rows( $rows );
	}

	/**
	 * @param mixed $rows
	 *
	 * @return list<Payment>
	 */
	private function hydrate_rows( $rows ): array {
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$out[] = Payment::from_row( $row );
			}
		}
		return $out;
	}

	private function table(): string {
		return SchemaManager::payments_table();
	}

	/**
	 * @return \wpdb
	 */
	private function wpdb() {
		return $GLOBALS['wpdb'];
	}
}
