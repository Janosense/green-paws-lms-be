<?php

declare(strict_types=1);

namespace VL\LMS\Payments\Exception;

/**
 * Raised when a {@see \VL\LMS\Payments\RefundCapableProvider} fails at the
 * transport / HTTP layer — network error, timeout, non-2xx status, or a
 * malformed response body. Distinct from
 * {@see PaymentProviderRejectedException}, which carries a successful HTTP
 * round-trip but a provider-side rejection (LiqPay status=failure/error).
 *
 * Mapped to `502 payment_provider_error` (with `data.reason='http'`) by the
 * REST controller.
 *
 * @author Tymofii Synianskyi
 */
class PaymentProviderHttpException extends \RuntimeException {

	public function __construct(
		string $message,
		private readonly ?int $http_status = null,
		private readonly ?string $response_body = null,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function http_status(): ?int {
		return $this->http_status;
	}

	public function response_body(): ?string {
		return $this->response_body;
	}
}
