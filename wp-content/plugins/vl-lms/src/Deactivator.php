<?php

declare(strict_types=1);

namespace VL\LMS;

/**
 * Runs on plugin deactivation.
 *
 * Deactivation is reversible — destructive cleanup belongs in
 * uninstall.php, not here. Use this hook to unschedule cron events and
 * flush caches only.
 */
final class Deactivator {

	public static function deactivate(): void {
		// Phase 1+: wp_unschedule_event, transient cleanup.
	}
}
