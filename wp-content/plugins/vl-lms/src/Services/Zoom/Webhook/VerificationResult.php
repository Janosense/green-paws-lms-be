<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Outcome of {@see WebhookSignatureVerifier::verify()}. The `reason`
 * field is one of `'ok'`, `'missing_secret'`, `'missing_headers'`,
 * `'replay_window'`, `'mismatch'` — the controller logs it but never
 * surfaces it in the HTTP response (leak surface).
 *
 * @author Tymofii Synianskyi
 */
final class VerificationResult {

	public function __construct(
		public readonly bool $valid,
		public readonly string $reason
	) {
	}

	public static function ok(): self {
		return new self( true, 'ok' );
	}

	public static function failed( string $reason ): self {
		return new self( false, $reason );
	}
}
