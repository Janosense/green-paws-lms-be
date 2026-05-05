<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Admin\Analytics\AnalyticsCron;
use VL\LMS\Admin\Analytics\AnalyticsRollupService;
use VL\LMS\Database\SchemaManager;
use VL\LMS\Roles\RolesInstaller;

/**
 * Runs on plugin activation.
 *
 * Does the two pieces of work that do NOT need post types or taxonomies
 * to be registered — roles/capabilities and custom tables — and then
 * raises a `first-run pending` flag. Everything that needs CPTs or
 * taxonomies (default term seeding, rewrite flush) is handled on the
 * next `init` by {@see Plugin::run_first_run_tasks()}.
 *
 * Why split: `register_activation_hook` fires inside the admin/CLI
 * request that activates the plugin, and the plugin file is included
 * AFTER `plugins_loaded` has already fired in that request. That means
 * the `init` listeners installed by {@see Plugin::boot()} never get a
 * chance to run in the activation request, so CPTs and taxonomies are
 * NOT registered when the activator executes. Deferring the
 * registration-dependent work keeps a single authoritative taxonomy /
 * CPT registration path (the `init` hook, via the registrars), and
 * avoids duplicating that logic inside the activator.
 *
 * Every step is idempotent — re-activation is a common dev workflow and
 * must be a no-op at steady state.
 */
final class Activator {

	public const string PLUGIN_VERSION_OPTION    = 'vl_lms_plugin_version';
	public const string FIRST_RUN_PENDING_OPTION = 'vl_lms_first_run_pending';

	public static function activate(): void {
		// 1. Roles and capabilities.
		RolesInstaller::install();

		// 2. Custom tables.
		SchemaManager::install();

		// 3. Phase 9.3 — schedule the nightly analytics rollup. Idempotent
		// (wp_next_scheduled gate), safe under reactivation.
		( new AnalyticsCron( new AnalyticsRollupService() ) )->schedule();

		// 4. Record the current plugin version. Distinct from
		// `vl_lms_db_version` (schema generation) so code-only releases
		// can bump one without touching the other.
		update_option( self::PLUGIN_VERSION_OPTION, VL_LMS_VERSION );

		// 5. Queue the tasks that require CPTs / taxonomies to be
		// registered. Picked up on the next request's `init` hook, at
		// priority 20 — after the CPT / taxonomy registrars (priority
		// 10) have done their work.
		update_option( self::FIRST_RUN_PENDING_OPTION, '1' );
	}
}
