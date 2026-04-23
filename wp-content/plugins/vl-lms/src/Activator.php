<?php

declare(strict_types=1);

namespace VL\LMS;

use VL\LMS\Roles\RolesInstaller;

/**
 * Runs on plugin activation.
 *
 * Installs the roles and capabilities map. Later phases will install
 * custom tables and seed default settings from here.
 */
final class Activator {

	public static function activate(): void {
		RolesInstaller::install();

		// Phase 2+: schema install, default options seed.
	}
}
