<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Mail\OrderFailedMailer;
use VL\LMS\Support\Logger;

/**
 * Phase 8.5 — listens to `vl_lms_order_failed` (introduced in 8.5,
 * fired by {@see \VL\LMS\Payments\LiqPay\CallbackHandler} after a successful
 * FAILED transition) and dispatches the order-failed email at priority 20.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderFailedListener {

	public function __construct(
		private readonly OrderFailedMailer $mailer,
		private readonly Logger $logger
	) {
	}

	public function register(): void {
		add_action( 'vl_lms_order_failed', [ $this, 'on_order_failed' ], 20, 2 );
	}

	public function on_order_failed( Order $order, Payment $payment ): void {
		$ok = $this->mailer->send( $order, $payment );
		$this->logger->info(
			'OrderFailedListener: dispatched order-failed email.',
			[
				'order_uuid' => $order->uuid,
				'user_id'    => $order->user_id,
				'sent'       => $ok,
			]
		);
	}
}
