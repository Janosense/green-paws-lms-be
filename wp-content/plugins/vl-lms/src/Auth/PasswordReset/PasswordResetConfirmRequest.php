<?php

declare(strict_types=1);

namespace VL\LMS\Auth\PasswordReset;

/**
 * Immutable value object for the "confirm password reset" request.
 *
 * Carries the plain token and the new password. Password-policy checks
 * are applied by {@see PasswordResetService::confirm()} against the
 * shared {@see \VL\LMS\Auth\PasswordPolicy}, so the filter override
 * behaves identically to registration.
 *
 * @author Tymofii Synianskyi
 */
final class PasswordResetConfirmRequest {

	public function __construct(
		public readonly string $token,
		public readonly string $password
	) {
	}
}
