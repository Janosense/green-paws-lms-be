<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

/**
 * Immutable outcome of a {@see SessionAccessGate} check.
 *
 * Same shape as `Services/Webinars/WebinarAccessDecision`. On allow, the
 * `redirect_url` is the canonical Zoom URL the controller will 302 to; on
 * deny, `context` may carry reason-specific timestamps for the frontend.
 *
 * @author Tymofii Synianskyi
 */
final class SessionAccessDecision {

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly SessionAccessReason $reason,
		public readonly ?string $redirect_url,
		public readonly array $context = []
	) {
	}

	public static function allow( string $redirect_url ): self {
		return new self( true, SessionAccessReason::OK, $redirect_url );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function deny( SessionAccessReason $reason, array $context = [] ): self {
		return new self( false, $reason, null, $context );
	}
}
