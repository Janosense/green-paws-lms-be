<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Settings;

/**
 * Immutable carrier for the four Zoom S2S OAuth + webhook credentials.
 *
 * `is_configured()` returns true only when every field is non-empty —
 * that is the integrators' "Zoom is wired up" probe used by Phase 7.1+
 * services. Resolution precedence (PHP constant > WP option > empty) is
 * the {@see ZoomSettingsProvider}'s job, not this VO's.
 *
 * @author Tymofii Synianskyi
 */
final class ZoomCredentials {

	public function __construct(
		public readonly string $account_id,
		public readonly string $client_id,
		public readonly string $client_secret,
		public readonly string $webhook_secret
	) {
	}

	public function is_configured(): bool {
		return '' !== $this->account_id
			&& '' !== $this->client_id
			&& '' !== $this->client_secret
			&& '' !== $this->webhook_secret;
	}
}
