<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Mail\OrderRefundedMailer;
use VL\LMS\Support\Logger;

/**
 * Phase 8.5 — listens to `vl_lms_order_refunded` (Phase 8.3) and dispatches
 * the order-refunded email. Priority 20, after the Phase 8.3 enrollment
 * revoker at priority 10.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderRefundedListener {

	public function __construct(
		private readonly OrderRefundedMailer $mailer,
		private readonly Logger $logger
	) {
	}

	public function register(): void {
		add_action( 'vl_lms_order_refunded', [ $this, 'on_order_refunded' ], 20, 2 );
	}

	public function on_order_refunded( Order $order, Payment $refund_payment ): void {
		$ok = $this->mailer->send( $order, $refund_payment );
		$this->logger->info(
			'OrderRefundedListener: dispatched order-refunded email.',
			[
				'order_uuid' => $order->uuid,
				'user_id'    => $order->user_id,
				'sent'       => $ok,
			]
		);
	}
}
