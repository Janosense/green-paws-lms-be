<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Payment;

use VL\LMS\Domain\Money\Money;

/**
 * Immutable data carrier for one row of `{prefix}vl_payments`.
 *
 * Each row is one provider callback recorded for an order. The
 * `idempotency_key` is the duplicate-callback safety backstop — Phase 8.2's
 * `CallbackHandler` constructs it as `liqpay:{payment_id}:{action}:{status}`
 * and re-checks before inserting; concurrent inserts of the same key
 * surface as {@see \VL\LMS\Repositories\PaymentAlreadyRecordedException}
 * from the repository.
 *
 * `provider_action` is a free string at this layer; LiqPay's vocabulary is
 * `'pay'` for charge callbacks and `'refund'` for refund callbacks.
 * `raw_provider_status` is the verbatim callback value — kept alongside
 * the parsed {@see PaymentStatus} so `OTHER` cases retain their original
 * label for audit.
 *
 * Phase 8.0 — Foundations.
 *
 * @author Tymofii Synianskyi
 */
class Payment {

	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		public readonly ?int $id,
		public readonly int $order_id,
		public readonly PaymentProvider $provider,
		public readonly ?string $provider_payment_id,
		public readonly string $provider_action,
		public readonly PaymentStatus $status,
		public readonly string $raw_provider_status,
		public readonly PaymentTransactionType $transaction_type,
		public readonly Money $amount,
		public readonly string $raw_payload,
		public readonly \DateTimeImmutable $received_at,
		public readonly string $idempotency_key
	) {
		if ( '' === $idempotency_key ) {
			throw new \InvalidArgumentException( 'Payment idempotency_key must not be empty.' );
		}
		if ( '' === $provider_action ) {
			throw new \InvalidArgumentException( 'Payment provider_action must not be empty.' );
		}
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`. Money columns round-trip through
	 * {@see Money::from_major_decimal()}; `received_at` is interpreted as UTC.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When enum-backed columns carry an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		return new self(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(int) $row['order_id'],
			PaymentProvider::from_string( (string) $row['provider'] ),
			self::nullable_string( $row['provider_payment_id'] ?? null ),
			(string) $row['provider_action'],
			PaymentStatus::try_from_liqpay( (string) $row['provider_status'] ),
			(string) $row['provider_status'],
			PaymentTransactionType::from_string( (string) $row['transaction_type'] ),
			Money::from_major_decimal( (string) $row['amount'], (string) $row['currency'] ),
			(string) $row['raw_payload'],
			new \DateTimeImmutable( (string) $row['received_at'], new \DateTimeZone( 'UTC' ) ),
			(string) $row['idempotency_key']
		);
	}

	/**
	 * Serialize to the associative-array shape the repository writes back.
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		return [
			'order_id'            => $this->order_id,
			'provider'            => $this->provider->value,
			'provider_payment_id' => $this->provider_payment_id,
			'provider_action'     => $this->provider_action,
			'provider_status'     => $this->raw_provider_status,
			'transaction_type'    => $this->transaction_type->value,
			'amount'              => $this->amount->to_major_decimal(),
			'currency'            => $this->amount->currency(),
			'raw_payload'         => $this->raw_payload,
			'received_at'         => $this->received_at->format( self::DATETIME_FORMAT ),
			'idempotency_key'     => $this->idempotency_key,
		];
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * @throws \DomainException When the payment already has an id.
	 */
	public function with_id( int $id ): self {
		if ( null !== $this->id ) {
			throw new \DomainException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Payment %d already has an id assigned.', $this->id )
			);
		}
		return new self(
			$id,
			$this->order_id,
			$this->provider,
			$this->provider_payment_id,
			$this->provider_action,
			$this->status,
			$this->raw_provider_status,
			$this->transaction_type,
			$this->amount,
			$this->raw_payload,
			$this->received_at,
			$this->idempotency_key
		);
	}
}
