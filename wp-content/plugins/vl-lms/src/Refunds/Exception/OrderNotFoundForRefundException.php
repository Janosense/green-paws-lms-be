<?php

declare(strict_types=1);

namespace VL\LMS\Refunds\Exception;

/**
 * Phase 8.3 — raised when {@see \VL\LMS\Refunds\RefundService::refund_order()}
 * is called with a uuid that does not match any row in `vl_orders`.
 *
 * Distinct from 8.1's {@see \VL\LMS\Orders\Exception\OrderNotFoundException}
 * so the refund-side controller can map this to its own error code without
 * coupling to the user-facing orders flow's exception surface.
 *
 * Mapped to `404 order_not_found` by the admin REST controller.
 *
 * @author Tymofii Synianskyi
 */
class OrderNotFoundForRefundException extends \RuntimeException {
}
