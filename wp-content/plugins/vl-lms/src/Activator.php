<?php

declare(strict_types=1);

namespace VL\LMS;

/**
 * Runs on plugin activation.
 *
 * Phase 0 does not own any schema or options. Later phases will install
 * custom tables, register roles, and seed default settings here.
 */
final class Activator {

	public static function activate(): void {
		// Phase 1+: schema install, role registration, default options seed.
	}
}
