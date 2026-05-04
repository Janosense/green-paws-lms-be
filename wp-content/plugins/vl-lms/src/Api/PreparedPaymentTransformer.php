<?php

declare(strict_types=1);

namespace VL\LMS\Api;

use VL\LMS\Domain\Payment\PreparedPayment;

/**
 * Phase 8.1 — REST projection of {@see PreparedPayment}. The frontend
 * uses this to build an auto-submit form that POSTs directly to LiqPay.
 *
 * @author Tymofii Synianskyi
 */
class PreparedPaymentTransformer {

	/**
	 * @return array{action_url: string, http_method: string, fields: array<string, string>}
	 */
	public function transform( PreparedPayment $payment ): array {
		return [
			'action_url'  => $payment->action_url,
			'http_method' => $payment->http_method,
			'fields'      => $payment->fields,
		];
	}
}
