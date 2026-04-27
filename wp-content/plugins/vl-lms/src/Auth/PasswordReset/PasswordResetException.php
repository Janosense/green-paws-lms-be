<?php

declare(strict_types=1);

namespace VL\LMS\Auth\PasswordReset;

/**
 * Carries a stable error code + HTTP status for password-reset failures.
 *
 * Codes:
 * - `vl_lms_password_reset_invalid_token`  — token not recognised
 * - `vl_lms_password_reset_token_expired`  — token matched but past expiry
 * - `vl_lms_password_reset_weak_password`  — supplied password violates policy
 *
 * The `request` phase does not surface exceptions — its response is
 * always generic to prevent email enumeration; only the `confirm` phase
 * throws.
 *
 * @author Tymofii Synianskyi
 */
final class PasswordResetException extends \RuntimeException {

	public function __construct(
		private readonly string $error_code,
		string $message,
		private readonly int $status_code = 400,
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

	public static function invalid(): self {
		return new self(
			'vl_lms_password_reset_invalid_token',
			'Password reset token is invalid.',
			400
		);
	}

	public static function expired(): self {
		return new self(
			'vl_lms_password_reset_token_expired',
			'Password reset token has expired.',
			400
		);
	}

	public static function weak_password( int $min_length ): self {
		return new self(
			'vl_lms_password_reset_weak_password',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message; no HTML context.
			sprintf( 'Password must be at least %d characters long.', $min_length ),
			400
		);
	}
}
