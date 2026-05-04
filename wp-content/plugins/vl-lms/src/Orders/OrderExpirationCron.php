<?php

declare(strict_types=1);

namespace VL\LMS\Orders;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Order\OrderStatus;
use VL\LMS\Repositories\OrderRepository;
use VL\LMS\Support\Logger;

/**
 * Phase 8.2 — hourly cron that flips PENDING / AWAITING_PAYMENT orders past
 * their `expires_at` to EXPIRED.
 *
 * The 24-hour TTL set by {@see OrderService::ORDER_TTL_HOURS} means a
 * worst-case ≤ 60-minute lag between expiry and the EXPIRED transition,
 * which is acceptable — the order is already non-payable by virtue of
 * `expires_at` being in the past, and downstream gates (LiqPay callback
 * arriving for a still-`PENDING` row whose `expires_at` is past) are
 * harmless because `mark_paid` would still succeed at the domain level.
 *
 * Bounded `$limit = 100` per tick to keep cron ticks fast under unusual
 * load. If more than 100 expired orders accumulate per hour, the next
 * tick picks up the tail.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderExpirationCron {

	public const string HOOK_NAME = 'vl_lms_order_expiration_cron';

	public const string SCHEDULE = 'hourly';

	public const int TICK_LIMIT = 100;

	public function __construct(
		private readonly OrderRepository $orders,
		private readonly Logger $logger
	) {
	}

	/**
	 * Schedule the recurring tick. Idempotent — safe to call on every boot
	 * thanks to `wp_next_scheduled`'s false-on-not-scheduled return.
	 */
	public function register(): void {
		if ( false === wp_next_scheduled( self::HOOK_NAME ) ) {
			wp_schedule_event( time(), self::SCHEDULE, self::HOOK_NAME );
		}
	}

	/**
	 * Clear the recurring tick. Called from the plugin deactivation hook.
	 */
	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_NAME );
	}

	public function on_tick(): void {
		$now           = $this->now();
		$expired       = $this->orders->list_expired_open( $now, self::TICK_LIMIT );
		$expired_count = 0;

		foreach ( $expired as $order ) {
			if ( null === $order->id ) {
				continue;
			}
			try {
				$order->mark_expired( $now );
			} catch ( \DomainException $e ) {
				// Defensive: `list_expired_open` already filters to
				// PENDING / AWAITING_PAYMENT, but if the row's status
				// drifted under us between the SELECT and here, skip
				// quietly rather than crash the cron tick.
				$this->logger->warning(
					'OrderExpirationCron: skipping order whose state forbids EXPIRED transition',
					[
						'order_uuid' => $order->uuid,
						'message'    => $e->getMessage(),
					]
				);
				continue;
			}

			$this->orders->update_status( (int) $order->id, OrderStatus::EXPIRED, $now );

			/**
			 * Fires after an order is moved to EXPIRED by the cron tick.
			 * Reserved for future listeners; no consumer in 8.2 / 8.3.
			 *
			 * @param Order $order The post-transition order (in-memory; the
			 *                     listener should re-fetch if it needs the
			 *                     persisted row).
			 */
			do_action( 'vl_lms_order_expired', $order );

			++$expired_count;
		}

		$this->logger->info(
			'OrderExpirationCron tick processed',
			[ 'expired_count' => $expired_count ]
		);
	}

	protected function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
