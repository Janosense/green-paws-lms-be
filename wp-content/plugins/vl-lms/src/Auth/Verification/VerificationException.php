<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Verification;

/**
 * Carries a stable error code + HTTP status for verification failures.
 *
 * Codes:
 * - `vl_lms_verification_invalid`          — token not recognised
 * - `vl_lms_verification_expired`          — token matched but past expiry
 * - `vl_lms_verification_already_verified` — token matched but user is already verified
 *
 * @author Tymofii Synianskyi
 */
final class VerificationException extends \RuntimeException {

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
		return new self( 'vl_lms_verification_invalid', 'Verification token is invalid.', 400 );
	}

	public static function expired(): self {
		return new self( 'vl_lms_verification_expired', 'Verification token has expired.', 400 );
	}

	public static function already_verified(): self {
		return new self( 'vl_lms_verification_already_verified', 'Email has already been verified.', 409 );
	}
}
