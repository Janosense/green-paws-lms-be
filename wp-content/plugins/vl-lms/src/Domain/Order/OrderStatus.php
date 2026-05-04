<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Order;

/**
 * Lifecycle status of a {@see Order} row.
 *
 * `PENDING` is the initial state on order creation; `AWAITING_PAYMENT` is
 * set once 8.1's `OrderService` redirects the user to LiqPay and we are
 * waiting for the callback. `PAID`, `FAILED`, `CANCELLED`, `EXPIRED`, and
 * `REFUNDED` are terminal — no further transitions are legal except the
 * `PAID → REFUNDED` step driven by 8.3's refund flow.
 *
 * Phase 8.0 — Foundations.
 *
 * @author Tymofii Synianskyi
 */
enum OrderStatus: string {

	case PENDING          = 'pending';
	case AWAITING_PAYMENT = 'awaiting_payment';
	case PAID             = 'paid';
	case FAILED           = 'failed';
	case CANCELLED        = 'cancelled';
	case EXPIRED          = 'expired';
	case REFUNDED         = 'refunded';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown order status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}

	public function is_terminal(): bool {
		return match ( $this ) {
			self::PAID, self::FAILED, self::CANCELLED, self::EXPIRED, self::REFUNDED => true,
			self::PENDING, self::AWAITING_PAYMENT => false,
		};
	}

	public function is_open(): bool {
		return self::PENDING === $this || self::AWAITING_PAYMENT === $this;
	}

	public function is_successful(): bool {
		return self::PAID === $this;
	}

	public function is_refundable(): bool {
		return self::PAID === $this;
	}
}
