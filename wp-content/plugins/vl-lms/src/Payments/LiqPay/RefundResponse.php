<?php

declare(strict_types=1);

namespace VL\LMS\Payments\LiqPay;

/**
 * Phase 8.3 — value object for a parsed LiqPay refund response.
 *
 * `status` is the raw provider string (`reversed`, `failure`, `error`, …).
 * `payment_id` is LiqPay's refund payment identifier — present on
 * `reversed`, often null on error paths. `err_code` and `err_description`
 * carry the provider's machine-readable and human-readable rejection
 * details. `raw` is the full decoded payload for audit-row payload storage.
 *
 * Concrete (not final) for DI / testability — Phase 8 convention.
 *
 * @author Tymofii Synianskyi
 */
class RefundResponse {

	/**
	 * @param array<string, mixed> $raw
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?string $payment_id,
		public readonly ?string $err_code,
		public readonly ?string $err_description,
		public readonly array $raw
	) {
	}

	public function is_reversed(): bool {
		return 'reversed' === $this->status;
	}

	public function is_rejected(): bool {
		return 'failure' === $this->status || 'error' === $this->status;
	}
}
