<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Payment;

/**
 * Payment status mapped from LiqPay's callback vocabulary.
 *
 * The case list mirrors LiqPay's documented values
 * (https://www.liqpay.ua/en/documentation/api/callback). `OTHER` is the
 * sentinel for unrecognized strings — production callback parsing MUST
 * route through {@see self::try_from_liqpay()} (not `from()`) so an
 * unforeseen new status never crashes the dispatcher. The raw provider
 * string is also persisted on the payment row so `OTHER` cases remain
 * auditable verbatim.
 *
 * @author Tymofii Synianskyi
 */
enum PaymentStatus: string {

	case SUCCESS           = 'success';
	case SANDBOX           = 'sandbox';
	case REVERSED          = 'reversed';
	case FAILURE           = 'failure';
	case ERROR             = 'error';
	case WAIT_SECURE       = 'wait_secure';
	case WAIT_ACCEPT       = 'wait_accept';
	case WAIT_COMPENSATION = 'wait_compensation';
	case PROCESSING        = 'processing';
	case OTHER             = 'other';

	/**
	 * Forgiving parser used at the callback boundary. Returns
	 * {@see self::OTHER} for any value the enum does not recognize.
	 */
	public static function try_from_liqpay( string $raw ): self {
		$case = self::tryFrom( $raw );
		return $case ?? self::OTHER;
	}

	/**
	 * Strict parser. Used internally where we know the value must already
	 * be one of our enum cases (e.g. hydrating from our own schema column).
	 *
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown payment status "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}

	public function is_terminal(): bool {
		return match ( $this ) {
			self::SUCCESS, self::SANDBOX, self::REVERSED, self::FAILURE, self::ERROR => true,
			self::WAIT_SECURE, self::WAIT_ACCEPT, self::WAIT_COMPENSATION, self::PROCESSING, self::OTHER => false,
		};
	}

	public function is_terminal_success(): bool {
		return self::SUCCESS === $this || self::SANDBOX === $this;
	}

	public function is_terminal_failure(): bool {
		return self::FAILURE === $this || self::ERROR === $this;
	}

	public function is_pending(): bool {
		return match ( $this ) {
			self::WAIT_SECURE, self::WAIT_ACCEPT, self::WAIT_COMPENSATION, self::PROCESSING => true,
			default => false,
		};
	}
}
