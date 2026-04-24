<?php

declare(strict_types=1);

namespace VL\LMS;

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
	}
}
