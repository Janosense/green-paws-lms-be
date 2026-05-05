<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Admin\Analytics\AnalyticsCron;
use VL\LMS\Orders\OrderExpirationCron;

/**
 * Runs on plugin deactivation.
 *
 * Deactivation is reversible — destructive cleanup (roles, tables,
 * options) belongs in `uninstall.php`, not here. This hook only flushes
 * transient state that WordPress caches on our behalf.
 */
final class Deactivator {

	public static function deactivate(): void {
		// Drop the rewrite rules this plugin contributed to while active.
		// `false` skips the .htaccess hard-flush; the next request will
		// lazily regenerate the in-DB ruleset.
		flush_rewrite_rules( false );

		// Phase 8.2 — clear the recurring order-expiration cron so a
		// deactivated plugin doesn't keep firing ghost ticks against an
		// uninstalled handler.
		wp_clear_scheduled_hook( OrderExpirationCron::HOOK_NAME );

		// Phase 9.3 — clear the nightly analytics rollup hook for the
		// same reason.
		wp_clear_scheduled_hook( AnalyticsCron::HOOK_NAME );
	}
}
