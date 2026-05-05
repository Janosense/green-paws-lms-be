<?php

declare(strict_types=1);

namespace VL\LMS\Refunds\Exception;

/**
 * Phase 8.3 — raised in the rare case where a duplicate refund is detected
 * past the idempotent-return short-circuit (e.g. an explicit refund
 * already-recorded escalation in a non-default code path).
 *
 * Reserved for retry / reconciliation logic that needs to distinguish
 * "we found a fresh REFUND row already" from the standard idempotent
 * "order is already REFUNDED, return it" success path. The mainline
 * `RefundService::refund_order` does not throw this — it short-circuits
 * silently — but the symbol exists so callers that *do* need to treat
 * a duplicate as an error can.
 *
 * @author Tymofii Synianskyi
 */
class RefundAlreadyExistsException extends \RuntimeException {
}
