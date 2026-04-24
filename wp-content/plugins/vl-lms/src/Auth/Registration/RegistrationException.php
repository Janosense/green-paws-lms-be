<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Registration;

use VL\LMS\Auth\AccountKind;

/**
 * Carries a stable error code + HTTP status for registration failures.
 *
 * The REST layer maps these to the standard WP error envelope
 * (`{ code, message, data: { status } }`), so the codes must stay
 * stable — they're part of the frontend contract.
 *
 * Codes currently emitted:
 * - `vl_lms_invalid_email`
 * - `vl_lms_invalid_password`
 * - `vl_lms_invalid_first_name`
 * - `vl_lms_invalid_last_name`
 * - `vl_lms_invalid_account_kind`
 * - `vl_lms_weak_password`
 * - `vl_lms_registration_failed` (generic fallback for `wp_insert_user` errors)
 *
 * @author Tymofii Synianskyi
 */
final class RegistrationException extends \RuntimeException {

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

	public static function invalid_field( string $field, string $message ): self {
		return new self( 'vl_lms_invalid_' . $field, $message, 400 );
	}

	public static function invalid_account_kind( string $value ): self {
		$allowed = implode( ', ', AccountKind::ALLOWED );
		return new self(
			'vl_lms_invalid_account_kind',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message; REST layer surfaces only the code + a generic message.
			sprintf( 'Unknown account_kind "%s". Allowed: %s.', $value, $allowed ),
			400
		);
	}

	public static function weak_password( int $min_length ): self {
		return new self(
			'vl_lms_weak_password',
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing message; no HTML context.
			sprintf( 'Password must be at least %d characters long.', $min_length ),
			400
		);
	}

	public static function invalid_email(): self {
		return new self( 'vl_lms_invalid_email', 'Email address is invalid.', 400 );
	}

	public static function insertion_failed( string $reason ): self {
		return new self( 'vl_lms_registration_failed', $reason, 500 );
	}
}
