<?php

declare(strict_types=1);

namespace VL\LMS\Payments\Exception;

/**
 * Raised when a {@see \VL\LMS\Payments\RefundCapableProvider}'s HTTP call
 * succeeds but the provider rejects the operation — LiqPay returns
 * `status=failure` or `status=error` with an `err_code`.
 *
 * Distinct from {@see PaymentProviderHttpException} so callers can
 * distinguish "retry might work" (transport / network) from "the provider
 * has decided the operation is bad" (rejection). Mapped to
 * `502 payment_provider_error` with `data.reason='rejected'` and the
 * `provider_err_code` echoed.
 *
 * @author Tymofii Synianskyi
 */
class PaymentProviderRejectedException extends \RuntimeException {

	public function __construct(
		string $message,
		private readonly string $provider_status,
		private readonly ?string $provider_err_code = null,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function provider_status(): string {
		return $this->provider_status;
	}

	public function provider_err_code(): ?string {
		return $this->provider_err_code;
	}
}
