<?php

declare(strict_types=1);

namespace VL\LMS\Domain\Order;

/**
 * The CPT a paid order represents.
 *
 * Phase 8.0 supports exactly two purchasable shapes — a single course or a
 * single webinar. The single-item-per-order constraint means the snapshot
 * columns on `vl_orders` (`entity_type`, `entity_id`, `entity_slug`,
 * `entity_title_snapshot`) are sufficient — there is no `vl_order_items`
 * join table.
 *
 * @author Tymofii Synianskyi
 */
enum PurchasableEntityType: string {

	case COURSE  = 'course';
	case WEBINAR = 'webinar';

	/**
	 * @throws \InvalidArgumentException When `$value` is not a recognized case.
	 */
	public static function from_string( string $value ): self {
		$case = self::tryFrom( $value );
		if ( null === $case ) {
			$options = implode( ', ', array_map( static fn ( self $c ): string => $c->value, self::cases() ) );
			throw new \InvalidArgumentException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception.
				sprintf( 'Unknown purchasable entity type "%s". Valid options: %s.', $value, $options )
			);
		}
		return $case;
	}

	public function wp_post_type(): string {
		return match ( $this ) {
			self::COURSE  => 'vl_course',
			self::WEBINAR => 'vl_webinar',
		};
	}
}
