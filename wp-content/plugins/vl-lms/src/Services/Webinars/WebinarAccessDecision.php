<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

/**
 * Immutable outcome of a {@see WebinarAccessGate} check.
 *
 * On `allowed = true`, `redirect_url` carries the canonical Zoom URL
 * (`_vl_webinar_zoom_join_url` for `can_join`, `_vl_webinar_recording_url`
 * for `can_view_recording`). On denial, `redirect_url` is null and
 * `context` may carry reason-specific timestamps the controller surfaces
 * to the frontend.
 *
 * @author Tymofii Synianskyi
 */
final class WebinarAccessDecision {

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		public readonly bool $allowed,
		public readonly WebinarAccessReason $reason,
		public readonly ?string $redirect_url,
		public readonly array $context = []
	) {
	}

	public static function allow( string $redirect_url ): self {
		return new self( true, WebinarAccessReason::OK, $redirect_url );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function deny( WebinarAccessReason $reason, array $context = [] ): self {
		return new self( false, $reason, null, $context );
	}
}
