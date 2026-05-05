<?php

declare(strict_types=1);

namespace VL\LMS\Refunds\Exception;

use VL\LMS\Domain\Order\OrderStatus;

/**
 * Phase 8.3 — raised when an order is not in PAID and therefore cannot be
 * refunded. Carries the current status so the admin controller can echo it
 * to the caller (e.g. "this order is already CANCELLED, not refundable").
 *
 * Mapped to `409 order_not_refundable` with `data.current_status`.
 *
 * @author Tymofii Synianskyi
 */
class OrderNotRefundableException extends \RuntimeException {

	public function __construct( private readonly OrderStatus $current_status ) {
		parent::__construct( 'Order is not in a refundable state.' );
	}

	public function current_status(): OrderStatus {
		return $this->current_status;
	}
}
