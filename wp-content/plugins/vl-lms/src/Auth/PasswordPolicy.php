<?php

declare(strict_types=1);

namespace VL\LMS\Auth;

/**
 * Shared password-policy helper.
 *
 * Centralizes the minimum-length check used at both registration
 * ({@see \VL\LMS\Auth\Registration\RegistrationService}) and
 * password-reset confirmation
 * ({@see \VL\LMS\Auth\PasswordReset\PasswordResetService}), so the
 * `vl_lms_min_password_length` filter is guaranteed to apply
 * identically to both use-sites.
 *
 * Intentionally does NOT throw — callers surface the violation through
 * their own domain exceptions (`RegistrationException::weak_password()`,
 * `PasswordResetException::weak_password()`) so the REST error codes
 * stay distinct per flow.
 *
 * @author Tymofii Synianskyi
 */
final class PasswordPolicy {

	public const int DEFAULT_MIN_LENGTH = 8;

	/**
	 * Effective minimum password length after the
	 * `vl_lms_min_password_length` filter.
	 *
	 * Floored at 1 so a filter returning 0 or a negative never disables
	 * the check entirely.
	 */
	public function min_length(): int {
		/**
		 * Filter the minimum password length enforced across the auth
		 * subsystem (registration + password reset).
		 *
		 * @param int $length Default minimum length.
		 */
		$raw = apply_filters( 'vl_lms_min_password_length', self::DEFAULT_MIN_LENGTH );
		return max( 1, (int) $raw );
	}

	/**
	 * Whether `$password` satisfies the current minimum-length policy.
	 */
	public function is_acceptable( string $password ): bool {
		return strlen( $password ) >= $this->min_length();
	}
}
