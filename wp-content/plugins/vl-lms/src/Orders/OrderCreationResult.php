<?php

declare(strict_types=1);

namespace VL\LMS\Orders;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\PreparedPayment;

/**
 * Two-tuple returned by {@see OrderService::create_for_purchase}: the
 * persisted (or re-fetched-idempotent) order, plus the provider-specific
 * form payload the frontend needs to launch checkout.
 *
 * @author Tymofii Synianskyi
 */
class OrderCreationResult {

	public function __construct(
		public readonly Order $order,
		public readonly PreparedPayment $prepared_payment
	) {
	}
}
