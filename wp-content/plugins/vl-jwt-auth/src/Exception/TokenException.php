<?php

declare(strict_types=1);

namespace VLJwtAuth\Exception;

/**
 * Carries a stable error code + HTTP status alongside the exception message.
 *
 * The REST layer maps these directly to the error-response envelope documented
 * in readme.txt (codes: token_expired, token_invalid, refresh_token_invalid,
 * refresh_token_reused, user_not_found).
 */
class TokenException extends \RuntimeException {

	public function __construct(
		private string $error_code,
		string $message,
		private int $status_code = 401,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function error_code(): string {
		return $this->error_code;
	}

	public function status_code(): int {
		return $this->status_code;
	}
}
