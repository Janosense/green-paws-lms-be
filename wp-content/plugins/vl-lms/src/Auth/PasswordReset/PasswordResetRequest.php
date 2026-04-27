<?php

declare(strict_types=1);

namespace VL\LMS\Auth\PasswordReset;

/**
 * Immutable value object for the "start password reset" request.
 *
 * Validates only structural invariants (non-empty email). Policy-level
 * concerns — email syntax, existence of a matching account, rate limits —
 * belong to {@see PasswordResetService}, which operates on values that
 * have at least been shaped correctly by the time they reach it.
 *
 * The controller is expected to have already sanitized the raw REST
 * input (`sanitize_email`, `sanitize_text_field`) before constructing
 * this VO.
 *
 * @author Tymofii Synianskyi
 */
final class PasswordResetRequest {

	public function __construct(
		public readonly string $email,
		public readonly string $ip = ''
	) {
	}
}
