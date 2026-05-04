<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

use VL\LMS\Domain\WebinarRegistration\WebinarRegistration;

/**
 * Immutable outcome of a {@see WebinarRegistrationService} call.
 *
 * - `decision === FAILED`  → `error` is set, `registration` is null,
 *                            `context` carries reason-specific fields the
 *                            controller surfaces in the error body
 *                            (e.g. `opens_at`, `price`, `capacity`).
 * - any other decision     → `error` is null, `registration` carries the
 *                            persisted row.
 *
 * @author Tymofii Synianskyi
 */
final class WebinarRegistrationDecision {

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		public readonly WebinarRegistrationDecisionType $decision,
		public readonly ?WebinarRegistration $registration,
		public readonly ?WebinarRegistrationError $error,
		public readonly array $context = []
	) {
	}

	public static function success(
		WebinarRegistrationDecisionType $decision,
		WebinarRegistration $registration
	): self {
		return new self( $decision, $registration, null );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function failure( WebinarRegistrationError $error, array $context = [] ): self {
		return new self( WebinarRegistrationDecisionType::FAILED, null, $error, $context );
	}
}
