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
		// Unschedule the daily refresh-token cleanup. `wp_clear_scheduled_hook`
		// is a no-op when no event is registered, so this is idempotent.
		wp_clear_scheduled_hook( Activator::CLEANUP_HOOK );
	}
}
