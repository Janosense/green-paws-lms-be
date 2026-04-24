<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Roles\RolesInstaller;
use VL\LMS\Taxonomy\DifficultyTermsInstaller;

/**
 * Runs on plugin activation.
 *
 * Orchestrates the one-time setup sequence. Order of the steps matters:
 * roles come before schema (so future defaults that depend on caps work),
 * schema before term seeding (so any term-owner / table dependency resolves),
 * default terms before the plugin-version marker (the marker records that
 * setup completed), and the rewrite flush is always last because it's a
 * whole-system side effect.
 *
 * Every step is idempotent — re-activation is a common dev workflow and
 * must be a no-op at steady state.
 */
final class Activator {

	public const string PLUGIN_VERSION_OPTION = 'vl_lms_plugin_version';

	public static function activate(): void {
		// 1. Roles and capabilities.
		RolesInstaller::install();

		// 2. Custom tables.
		SchemaManager::install();

		// 3. Seed default taxonomy terms.
		DifficultyTermsInstaller::install();

		// 4. Record the current plugin version. Distinct from
		// `vl_lms_db_version` (schema generation) so code-only releases
		// can bump one without touching the other.
		update_option( self::PLUGIN_VERSION_OPTION, VL_LMS_VERSION );

		// 5. Flush rewrite rules once. Cron and core-install contexts
		// skip it because any flush here would be discarded or wasted.
		// Passing `false` avoids the .htaccess hard-flush — only the
		// in-DB rules need refreshing.
		if ( ! wp_doing_cron() && ! wp_installing() ) {
			flush_rewrite_rules( false );
		}
	}
}
