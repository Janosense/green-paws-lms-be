<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Payment;

/**
 * Identifier of the payment processor backing a {@see Payment} row.
 *
 * Phase 8 launches with a single provider — LiqPay. The enum exists so a
 * future provider can be added by extending the case list rather than by
 * touching every consumer's signature.
 *
 * @author Tymofii Synianskyi
 */
enum PaymentProvider: string {

	case LIQPAY = 'liqpay';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown payment provider "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}
}
