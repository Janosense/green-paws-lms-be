<?php

declare(strict_types=1);

namespace VL\LMS\Orders\Exception;

use VL\LMS\Domain\Order\OrderStatus;

/**
 * Raised when a cancel call lands on an order in a status from which
 * cancellation is illegal — i.e. any terminal state other than CANCELLED
 * itself (already-cancelled is treated as idempotent and returns the
 * order rather than throwing). Mapped to `409 order_not_cancellable` by
 * the REST controller; the controller surfaces `current_status()` in the
 * error `data` payload.
 *
 * @author Tymofii Synianskyi
 */
class OrderNotCancellableException extends \RuntimeException {

	public function __construct(
		private readonly OrderStatus $current_status,
		string $message = 'Order is in a terminal state and cannot be cancelled.'
	) {
		parent::__construct( $message );
	}

	public function current_status(): OrderStatus {
		return $this->current_status;
	}
}
