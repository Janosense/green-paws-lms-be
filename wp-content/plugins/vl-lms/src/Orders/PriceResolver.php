<?php

declare(strict_types=1);

namespace VL\LMS\Orders;

use VL\LMS\Domain\Money\Money;
use VL\LMS\Domain\Order\PurchasableEntityType;

/**
 * Resolves the purchase price for a course or webinar.
 *
 * Reads `_vl_course_price` (course) or `_vl_webinar_price` (webinar) post
 * meta as a major-unit decimal string (e.g. `'1500.00'`) and returns a
 * {@see Money} instance hard-coded to the `'UAH'` currency. Phase 8.1 ships
 * single-currency only; multi-currency support is a future-phase concern.
 *
 * Free-tier rows (missing meta, empty string, `'0'`, `'0.00'`) return
 * `null` so callers can map them to "not purchasable" instead of silently
 * inserting a zero-amount order.
 *
 * @author Tymofii Synianskyi
 */
class PriceResolver {

	private const string CURRENCY = 'UAH';

	public function resolve( int $entity_id, PurchasableEntityType $type ): ?Money {
		$meta_key = match ( $type ) {
			PurchasableEntityType::COURSE  => '_vl_course_price',
			PurchasableEntityType::WEBINAR => '_vl_webinar_price',
		};

		$raw = $this->read_meta( $entity_id, $meta_key );

		if ( '' === $raw ) {
			return null;
		}

		try {
			$money = Money::from_major_decimal( $raw, self::CURRENCY );
		} catch ( \InvalidArgumentException ) {
			return null;
		}

		if ( $money->is_zero() ) {
			return null;
		}

		return $money;
	}

	/**
	 * Indirected so tests can subclass and override without round-tripping
	 * through `get_post_meta()`.
	 */
	protected function read_meta( int $entity_id, string $meta_key ): string {
		$value = get_post_meta( $entity_id, $meta_key, true );
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		return '';
	}
}
