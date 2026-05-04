<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Payment;

/**
 * Whether a {@see Payment} row represents a charge or a refund.
 *
 * Stored amounts are always positive; the sign of the cash flow is
 * indicated by this discriminator. Phase 8.0 only persists the value;
 * 8.2/8.3 derive accounting consequences from it.
 *
 * @author Tymofii Synianskyi
 */
enum PaymentTransactionType: string {

	case CHARGE = 'charge';
	case REFUND = 'refund';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown payment transaction type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}

	public function is_refund(): bool {
		return self::REFUND === $this;
	}
}
