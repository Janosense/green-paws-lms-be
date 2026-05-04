<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Repositories\PaymentAlreadyRecordedException;
use VL\LMS\Repositories\PaymentRepository;

/**
 * In-memory double of {@see PaymentRepository}.
 *
 * Mirrors the production path's idempotency-key UNIQUE behaviour by
 * raising {@see PaymentAlreadyRecordedException} on duplicate insert.
 */
final class InMemoryPaymentRepository extends PaymentRepository {

	/** @var array<int, array<string, mixed>> */
	private array $rows = [];

	private int $next_id = 1;

	public function insert( Payment $payment ): int {
		if ( null !== $payment->id ) {
			throw new \DomainException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Cannot insert payment %d — it already has an id.', $payment->id )
			);
		}

		foreach ( $this->rows as $row ) {
			if ( (string) $row['idempotency_key'] === $payment->idempotency_key ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				throw new PaymentAlreadyRecordedException( $payment->idempotency_key );
			}
		}

		$id                = $this->next_id++;
		$row               = $payment->to_row();
		$row['id']         = $id;
		$this->rows[ $id ] = $row;

		return $id;
	}

	public function find_by_id( int $id ): ?Payment {
		if ( ! isset( $this->rows[ $id ] ) ) {
			return null;
		}
		return Payment::from_row( $this->rows[ $id ] );
	}

	public function find_by_idempotency_key( string $key ): ?Payment {
		foreach ( $this->rows as $row ) {
			if ( (string) $row['idempotency_key'] === $key ) {
				return Payment::from_row( $row );
			}
		}
		return null;
	}

	/**
	 * @return list<Payment>
	 */
	public function list_for_order( int $order_id ): array {
		$matched = [];
		foreach ( $this->rows as $row ) {
			if ( (int) $row['order_id'] === $order_id ) {
				$matched[] = $row;
			}
		}

		usort(
			$matched,
			static fn ( array $a, array $b ): int =>
				( (string) $a['received_at'] ) <=> ( (string) $b['received_at'] )
		);

		$out = [];
		foreach ( $matched as $row ) {
			$out[] = Payment::from_row( $row );
		}
		return $out;
	}

	/**
	 * @return list<Payment>
	 */
	public function list_by_provider_payment_id( string $provider, string $provider_payment_id ): array {
		$matched = [];
		foreach ( $this->rows as $row ) {
			if ( (string) $row['provider'] !== $provider ) {
				continue;
			}
			if ( null === ( $row['provider_payment_id'] ?? null ) ) {
				continue;
			}
			if ( (string) $row['provider_payment_id'] !== $provider_payment_id ) {
				continue;
			}
			$matched[] = $row;
		}

		usort(
			$matched,
			static fn ( array $a, array $b ): int =>
				( (string) $a['received_at'] ) <=> ( (string) $b['received_at'] )
		);

		$out = [];
		foreach ( $matched as $row ) {
			$out[] = Payment::from_row( $row );
		}
		return $out;
	}
}
