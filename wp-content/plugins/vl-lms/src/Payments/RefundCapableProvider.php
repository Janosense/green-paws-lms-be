<?php

declare(strict_types=1);

namespace VL\LMS\Payments;

use VL\LMS\Domain\Order\Order;
use VL\LMS\Domain\Payment\Payment;
use VL\LMS\Payments\Exception\PaymentProviderHttpException;
use VL\LMS\Payments\Exception\PaymentProviderRejectedException;
use VL\LMS\Payments\Exception\PaymentProviderUnavailableException;

/**
 * Phase 8.3 — refund-capable extension of {@see PaymentProvider}.
 *
 * The split mirrors ISP: 8.1 / 8.2 only depended on the prepare-side and
 * never reached for refund. Only providers that support refund implement
 * this sub-interface; future providers that don't can stay on the base.
 *
 * Implementations return a fully-formed {@see Payment} VO with
 * `transaction_type=REFUND` and `status=REVERSED`. The caller (typically
 * {@see \VL\LMS\Refunds\RefundService}) is responsible for persisting the
 * row, transitioning the order, and firing the domain action.
 *
 * @author Tymofii Synianskyi
 */
interface RefundCapableProvider extends PaymentProvider {

	/**
	 * Issue a full refund for an already-paid order.
	 *
	 * @throws PaymentProviderUnavailableException When credentials are missing.
	 * @throws PaymentProviderHttpException        On HTTP / transport failure
	 *                                             (network, timeout, non-2xx).
	 * @throws PaymentProviderRejectedException    When the provider's response
	 *                                             carries a failure / error status.
	 */
	public function refund_payment( Order $order ): Payment;
}
