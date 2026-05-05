<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Mail\OrderPaidMailer;
use VL\LMS\Support\Logger;

/**
 * Phase 8.5 — listens to `vl_lms_order_paid` (Phase 8.2) and dispatches the
 * order-paid email. Mirrors {@see CertificateIssuedListener}: priority 20,
 * after the Phase 8.2 fan-out at priority 10 — emails reflect the persisted
 * post-fan-out state.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class OrderPaidListener {

	public function __construct(
		private readonly OrderPaidMailer $mailer,
		private readonly Logger $logger
	) {
	}

	public function register(): void {
		add_action( 'vl_lms_order_paid', [ $this, 'on_order_paid' ], 20, 2 );
	}

	public function on_order_paid( Order $order, Payment $payment ): void {
		$ok = $this->mailer->send( $order, $payment );
		$this->logger->info(
			'OrderPaidListener: dispatched order-paid email.',
			[
				'order_uuid' => $order->uuid,
				'user_id'    => $order->user_id,
				'sent'       => $ok,
			]
		);
	}
}
