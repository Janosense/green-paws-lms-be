<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Database\SchemaManager;
use VL\LMS\Roles\RolesInstaller;

/**
 * Runs on plugin activation.
 *
 * Installs the roles and capabilities map, then runs the schema installer
 * to create or migrate custom tables. Later phases will seed default
 * settings from here.
 */
final class Activator {

	public static function activate(): void {
		RolesInstaller::install();
		SchemaManager::install();

		// Phase 2+: default options seed.
	}
}
