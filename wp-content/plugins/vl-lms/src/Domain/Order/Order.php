<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Order;

use VL\LMS\Domain\Money\Money;

/**
 * Immutable data carrier for one row of `{prefix}vl_orders`.
 *
 * State transitions are exposed as `mark_*` / `with_*` methods that return
 * a new instance — mirroring {@see \VL\LMS\Domain\Quiz\QuizAttempt} and
 * {@see \VL\LMS\Domain\Enrollment\Enrollment}. Each transition validates
 * the source state and throws `\DomainException` with a message naming
 * both the current and requested states for diagnosability.
 *
 * Phase 8.0 — Foundations. State-transition orchestration lands in 8.1+.
 *
 * @author Tymofii Synianskyi
 */
class Order {

	/**
	 * @param array<string, mixed>|null $metadata Free-form JSON metadata; consumers in 8.1+.
	 */
	private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

	public function __construct(
		public readonly ?int $id,
		public readonly string $uuid,
		public readonly int $user_id,
		public readonly OrderStatus $status,
		public readonly string $payment_provider,
		public readonly ?string $liqpay_order_id,
		public readonly PurchasableEntityType $entity_type,
		public readonly int $entity_id,
		public readonly string $entity_slug,
		public readonly string $entity_title_snapshot,
		public readonly Money $amount,
		public readonly \DateTimeImmutable $created_at,
		public readonly \DateTimeImmutable $expires_at,
		public readonly ?\DateTimeImmutable $paid_at = null,
		public readonly ?\DateTimeImmutable $cancelled_at = null,
		public readonly ?\DateTimeImmutable $refunded_at = null,
		/** @var array<string, mixed>|null */
		public readonly ?array $metadata = null
	) {
	}

	/**
	 * Hydrate from the associative array produced by
	 * `$wpdb->get_row( ..., ARRAY_A )`.
	 *
	 * Money columns (`amount`, `currency`) round-trip through
	 * {@see Money::from_major_decimal()}; datetimes are interpreted as UTC.
	 *
	 * @param array<string, mixed> $row
	 *
	 * @throws \InvalidArgumentException When `status` or `entity_type` carry an unrecognized value.
	 */
	public static function from_row( array $row ): self {
		return new self(
			isset( $row['id'] ) ? (int) $row['id'] : null,
			(string) $row['uuid'],
			(int) $row['user_id'],
			OrderStatus::from_string( (string) $row['status'] ),
			(string) $row['payment_provider'],
			self::nullable_string( $row['liqpay_order_id'] ?? null ),
			PurchasableEntityType::from_string( (string) $row['entity_type'] ),
			(int) $row['entity_id'],
			(string) $row['entity_slug'],
			(string) $row['entity_title_snapshot'],
			Money::from_major_decimal( (string) $row['amount'], (string) $row['currency'] ),
			self::datetime( (string) $row['created_at'] ),
			self::datetime( (string) $row['expires_at'] ),
			self::nullable_datetime( $row['paid_at'] ?? null ),
			self::nullable_datetime( $row['cancelled_at'] ?? null ),
			self::nullable_datetime( $row['refunded_at'] ?? null ),
			self::decode_metadata( $row['metadata'] ?? null )
		);
	}

	/**
	 * Serialize to the associative-array shape the repository writes back.
	 *
	 * @return array<string, mixed>
	 */
	public function to_row(): array {
		$encoded_metadata = null;
		if ( null !== $this->metadata ) {
			$encoded          = wp_json_encode( $this->metadata );
			$encoded_metadata = false === $encoded ? null : $encoded;
		}

		return [
			'uuid'                  => $this->uuid,
			'user_id'               => $this->user_id,
			'status'                => $this->status->value,
			'payment_provider'      => $this->payment_provider,
			'liqpay_order_id'       => $this->liqpay_order_id,
			'entity_type'           => $this->entity_type->value,
			'entity_id'             => $this->entity_id,
			'entity_slug'           => $this->entity_slug,
			'entity_title_snapshot' => $this->entity_title_snapshot,
			'amount'                => $this->amount->to_major_decimal(),
			'currency'              => $this->amount->currency(),
			'created_at'            => $this->created_at->format( self::DATETIME_FORMAT ),
			'expires_at'            => $this->expires_at->format( self::DATETIME_FORMAT ),
			'paid_at'               => null === $this->paid_at ? null : $this->paid_at->format( self::DATETIME_FORMAT ),
			'cancelled_at'          => null === $this->cancelled_at ? null : $this->cancelled_at->format( self::DATETIME_FORMAT ),
			'refunded_at'           => null === $this->refunded_at ? null : $this->refunded_at->format( self::DATETIME_FORMAT ),
			'metadata'              => $encoded_metadata,
		];
	}

	private static function datetime( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	private static function nullable_datetime( mixed $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::datetime( (string) $value );
	}

	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function decode_metadata( mixed $value ): ?array {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$decoded = json_decode( (string) $value, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		/** @var array<string, mixed> $decoded */
		return $decoded;
	}

	/**
	 * Used by the repository's `insert()` path to attach the assigned PK.
	 *
	 * @throws \DomainException When the order already has an id.
	 */
	public function with_id( int $id ): self {
		if ( null !== $this->id ) {
			throw new \DomainException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Order %d already has an id assigned.', $this->id )
			);
		}
		return new self(
			$id,
			$this->uuid,
			$this->user_id,
			$this->status,
			$this->payment_provider,
			$this->liqpay_order_id,
			$this->entity_type,
			$this->entity_id,
			$this->entity_slug,
			$this->entity_title_snapshot,
			$this->amount,
			$this->created_at,
			$this->expires_at,
			$this->paid_at,
			$this->cancelled_at,
			$this->refunded_at,
			$this->metadata
		);
	}

	/**
	 * Sets `liqpay_order_id`. Legal only on a `PENDING` order — once we have
	 * redirected the learner the order is in `AWAITING_PAYMENT` and the
	 * provider reference is fixed.
	 *
	 * @throws \DomainException When the order is not `PENDING`.
	 */
	public function with_provider_reference( string $reference ): self {
		$this->guard_transition( [ OrderStatus::PENDING ], 'set provider reference' );
		return new self(
			$this->id,
			$this->uuid,
			$this->user_id,
			$this->status,
			$this->payment_provider,
			$reference,
			$this->entity_type,
			$this->entity_id,
			$this->entity_slug,
			$this->entity_title_snapshot,
			$this->amount,
			$this->created_at,
			$this->expires_at,
			$this->paid_at,
			$this->cancelled_at,
			$this->refunded_at,
			$this->metadata
		);
	}

	/**
	 * @throws \DomainException When the source state forbids the transition.
	 */
	public function mark_awaiting_payment( \DateTimeImmutable $now ): self {
		$this->guard_transition( [ OrderStatus::PENDING ], 'mark_awaiting_payment' );
		unset( $now );
		return $this->with_status( OrderStatus::AWAITING_PAYMENT );
	}

	/**
	 * @throws \DomainException When the source state forbids the transition.
	 */
	public function mark_paid( \DateTimeImmutable $paid_at ): self {
		$this->guard_transition(
			[ OrderStatus::PENDING, OrderStatus::AWAITING_PAYMENT ],
			'mark_paid'
		);
		return new self(
			$this->id,
			$this->uuid,
			$this->user_id,
			OrderStatus::PAID,
			$this->payment_provider,
			$this->liqpay_order_id,
			$this->entity_type,
			$this->entity_id,
			$this->entity_slug,
			$this->entity_title_snapshot,
			$this->amount,
			$this->created_at,
			$this->expires_at,
			$paid_at,
			$this->cancelled_at,
			$this->refunded_at,
			$this->metadata
		);
	}

	/**
	 * @throws \DomainException When the source state forbids the transition.
	 */
	public function mark_failed( \DateTimeImmutable $now ): self {
		$this->guard_transition(
			[ OrderStatus::PENDING, OrderStatus::AWAITING_PAYMENT ],
			'mark_failed'
		);
		unset( $now );
		return $this->with_status( OrderStatus::FAILED );
	}

	/**
	 * @throws \DomainException When the source state forbids the transition.
	 */
	public function mark_cancelled( \DateTimeImmutable $cancelled_at ): self {
		$this->guard_transition(
			[ OrderStatus::PENDING, OrderStatus::AWAITING_PAYMENT ],
			'mark_cancelled'
		);
		return new self(
			$this->id,
			$this->uuid,
			$this->user_id,
			OrderStatus::CANCELLED,
			$this->payment_provider,
			$this->liqpay_order_id,
			$this->entity_type,
			$this->entity_id,
			$this->entity_slug,
			$this->entity_title_snapshot,
			$this->amount,
			$this->created_at,
			$this->expires_at,
			$this->paid_at,
			$cancelled_at,
			$this->refunded_at,
			$this->metadata
		);
	}

	/**
	 * @throws \DomainException When the source state forbids the transition.
	 */
	public function mark_expired( \DateTimeImmutable $now ): self {
		$this->guard_transition(
			[ OrderStatus::PENDING, OrderStatus::AWAITING_PAYMENT ],
			'mark_expired'
		);
		unset( $now );
		return $this->with_status( OrderStatus::EXPIRED );
	}

	/**
	 * @throws \DomainException When the source state forbids the transition.
	 */
	public function mark_refunded( \DateTimeImmutable $refunded_at ): self {
		$this->guard_transition( [ OrderStatus::PAID ], 'mark_refunded' );
		return new self(
			$this->id,
			$this->uuid,
			$this->user_id,
			OrderStatus::REFUNDED,
			$this->payment_provider,
			$this->liqpay_order_id,
			$this->entity_type,
			$this->entity_id,
			$this->entity_slug,
			$this->entity_title_snapshot,
			$this->amount,
			$this->created_at,
			$this->expires_at,
			$this->paid_at,
			$this->cancelled_at,
			$refunded_at,
			$this->metadata
		);
	}

	private function with_status( OrderStatus $status ): self {
		return new self(
			$this->id,
			$this->uuid,
			$this->user_id,
			$status,
			$this->payment_provider,
			$this->liqpay_order_id,
			$this->entity_type,
			$this->entity_id,
			$this->entity_slug,
			$this->entity_title_snapshot,
			$this->amount,
			$this->created_at,
			$this->expires_at,
			$this->paid_at,
			$this->cancelled_at,
			$this->refunded_at,
			$this->metadata
		);
	}

	/**
	 * @param list<OrderStatus> $allowed
	 */
	private function guard_transition( array $allowed, string $transition ): void {
		foreach ( $allowed as $status ) {
			if ( $status === $this->status ) {
				return;
			}
		}
		$allowed_values = implode( ', ', array_map( static fn ( OrderStatus $s ): string => $s->value, $allowed ) );
		$current_status = $this->status->value;
		throw new \DomainException(
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
			sprintf( 'Cannot perform transition "%s" on order in status "%s"; allowed source states: %s.', $transition, $current_status, $allowed_values )
		);
	}
}
