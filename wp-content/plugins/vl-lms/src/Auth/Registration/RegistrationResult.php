<?php

declare(strict_types=1);

namespace VL\LMS\Auth\Registration;

/**
 * Value object returned by {@see RegistrationService::register()}.
 *
 * `plain_verification_token` is populated whenever a verification email
 * has just been enqueued (outcomes {@see RegistrationOutcome::CREATED}
 * and {@see RegistrationOutcome::RESENT}); for
 * {@see RegistrationOutcome::ALREADY_VERIFIED} it is `null`.
 *
 * @author Tymofii Synianskyi
 */
final class RegistrationResult {

	public function __construct(
		public readonly ?int $user_id,
		public readonly ?string $plain_verification_token,
		public readonly RegistrationOutcome $outcome
	) {
	}

	public function has_token(): bool {
		return null !== $this->plain_verification_token;
	}
}
