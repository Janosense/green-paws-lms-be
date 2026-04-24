<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Registration;

use VL\LMS\Auth\AccountKind;

/**
 * Immutable value object carrying a validated registration payload.
 *
 * Validates structural invariants only (non-empty fields, `account_kind`
 * membership in {@see AccountKind::ALLOWED}). Policy-level concerns —
 * email syntax, password strength, whether the email already exists —
 * belong to {@see RegistrationService}, which operates on values that
 * are *shaped* correctly by the time they reach it.
 *
 * The controller is expected to have already sanitized the raw REST
 * input (`sanitize_email`, `sanitize_text_field`) before constructing
 * this VO.
 *
 * @author Tymofii Synianskyi
 */
final class RegistrationRequest {

	public function __construct(
		public readonly string $email,
		public readonly string $password,
		public readonly string $first_name,
		public readonly string $last_name,
		public readonly string $account_kind = AccountKind::STUDENT
	) {
		if ( '' === trim( $email ) ) {
			throw RegistrationException::invalid_field( 'email', 'Email is required.' );
		}
		if ( '' === $password ) {
			throw RegistrationException::invalid_field( 'password', 'Password is required.' );
		}
		if ( '' === trim( $first_name ) ) {
			throw RegistrationException::invalid_field( 'first_name', 'First name is required.' );
		}
		if ( '' === trim( $last_name ) ) {
			throw RegistrationException::invalid_field( 'last_name', 'Last name is required.' );
		}
		if ( ! in_array( $account_kind, AccountKind::ALLOWED, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception, value is a sanitized string identifier.
			throw RegistrationException::invalid_account_kind( $account_kind );
		}
	}
}
