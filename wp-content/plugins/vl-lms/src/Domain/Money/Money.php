<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Money;

/**
 * Immutable money value object.
 *
 * Domain code carries amounts as integer minor units paired with an ISO 4217
 * currency code; persistence on the order/payment tables stores the same
 * value as a `DECIMAL(12,2)` major-unit number plus a `CHAR(3)` currency
 * column. The conversion is round-tripped through {@see self::from_major_decimal()}
 * and {@see self::to_major_decimal()} at the repository boundary so float
 * arithmetic never reaches the business layer.
 *
 * Phase 8.0 — Foundations. Consumers land in 8.1+.
 *
 * @author Tymofii Synianskyi
 */
class Money {

	private const string CURRENCY_PATTERN = '/^[A-Z]{3}$/';

	public function __construct(
		private readonly int $amount_minor_units,
		private readonly string $currency
	) {
		if ( $amount_minor_units < 0 ) {
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Money amount must be non-negative; got %d.', $amount_minor_units )
			);
		}
		if ( 1 !== preg_match( self::CURRENCY_PATTERN, $currency ) ) {
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Currency code must be three uppercase ASCII letters; got "%s".', $currency )
			);
		}
	}

	/**
	 * Parses a major-unit decimal string (e.g. `"1500.00"`) into a Money
	 * instance. Accepts at most two fractional digits — anything more is a
	 * precision the schema cannot store and is rejected loudly. No floats
	 * are involved; the conversion is integer math on the digit substrings.
	 *
	 * @throws \InvalidArgumentException When `$major` is not a valid decimal string.
	 */
	public static function from_major_decimal( string $major, string $currency ): self {
		if ( 1 !== preg_match( '/^(\d+)(?:\.(\d{1,2}))?$/', $major, $matches ) ) {
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Invalid major-unit decimal "%s"; expected pattern like "1500" or "1500.00" with at most two fractional digits.', $major )
			);
		}

		$whole    = $matches[1];
		$fraction = isset( $matches[2] ) ? str_pad( $matches[2], 2, '0', STR_PAD_RIGHT ) : '00';

		$amount = (int) $whole * 100 + (int) $fraction;

		return new self( $amount, $currency );
	}

	public function to_major_decimal(): string {
		$whole    = intdiv( $this->amount_minor_units, 100 );
		$fraction = $this->amount_minor_units % 100;
		return sprintf( '%d.%02d', $whole, $fraction );
	}

	public function amount_minor_units(): int {
		return $this->amount_minor_units;
	}

	public function currency(): string {
		return $this->currency;
	}

	public function equals( self $other ): bool {
		return $this->amount_minor_units === $other->amount_minor_units
			&& $this->currency === $other->currency;
	}

	/**
	 * @throws \InvalidArgumentException When the operands carry different currencies.
	 */
	public function add( self $other ): self {
		if ( $this->currency !== $other->currency ) {
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Cannot add money in different currencies: %s vs %s.', $this->currency, $other->currency )
			);
		}
		return new self( $this->amount_minor_units + $other->amount_minor_units, $this->currency );
	}

	public function is_zero(): bool {
		return 0 === $this->amount_minor_units;
	}
}
