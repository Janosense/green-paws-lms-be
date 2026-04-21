<?php

declare(strict_types=1);

namespace VLJwtAuth;

/**
 * Runs on plugin deactivation.
 *
 * Soft cleanup only. Do not drop tables or delete options here — a user
 * may deactivate temporarily. Destructive teardown belongs in uninstall.php.
 */
final class Deactivator {

	public static function deactivate(): void {
		// Intentionally empty. Kept as an extension point for future
		// cleanup (scheduled events, transients) without changing the
		// plugin entry file.
	}
}
