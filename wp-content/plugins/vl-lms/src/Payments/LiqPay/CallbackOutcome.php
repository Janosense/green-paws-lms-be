<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Outcome surface for {@see CallbackHandler::handle()}.
 *
 * Phase 8.2 — every case maps to HTTP `200 OK` at the controller layer
 * (LiqPay's retry policy hammers non-2xx aggressively, so we absorb every
 * error class and let ops monitor warning rates). The enum exists for
 * logging granularity and for unit-test assertions over which branch of
 * the state machine fired.
 *
 * @author Tymofii Synianskyi
 */
enum CallbackOutcome: string {

	/** Payment row recorded; order transition (if any) applied. */
	case OK_PROCESSED = 'processed';

	/** Idempotency-key collision — LiqPay retry of an already-recorded callback. */
	case OK_DUPLICATE = 'duplicate';

	/** Recognized but non-actionable (`reversed` deferred to 8.3, missing payment_id, etc.). */
	case OK_NO_OP = 'no_op';

	/** No order row matches the provider's `order_id`. */
	case OK_UNKNOWN_ORDER = 'unknown_order';

	/** Callback amount disagrees with the persisted order amount. */
	case OK_AMOUNT_MISMATCH = 'amount_mismatch';

	/** Callback currency disagrees with the persisted order currency. */
	case OK_CURRENCY_MISMATCH = 'currency_mismatch';
}
