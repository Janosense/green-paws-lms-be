<?php

declare(strict_types=1);

namespace VL\LMS\Payments;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\PreparedPayment;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;

/**
 * Prepare-only contract for an outbound payment provider.
 *
 * Phase 8.1 ships only this prepare-side surface — implementations build a
 * provider-specific payload (e.g. signed LiqPay form fields) for the
 * frontend to submit. Phase 8.3 will introduce a separate
 * `RefundCapableProvider` sub-interface adding `refund_payment()`; keeping
 * the two split avoids stub methods on providers that don't support refunds.
 *
 * Implementations must NOT mutate the order. Persisting a provider
 * reference (e.g. `liqpay_order_id`) is the caller's responsibility before
 * invoking {@see prepare_payment()}.
 *
 * @author Tymofii Synianskyi
 */
interface PaymentProvider {

	/**
	 * Build the provider-specific payment payload for an order.
	 *
	 * @throws PaymentProviderUnavailableException When the provider is
	 *                                             not configured (missing
	 *                                             credentials, etc.).
	 */
	public function prepare_payment( Order $order ): PreparedPayment;
}
