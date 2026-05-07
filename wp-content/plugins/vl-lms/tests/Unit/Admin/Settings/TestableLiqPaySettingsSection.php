<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Settings;

use VL\LMS\Admin\Settings\LiqPaySettingsSection;

/**
 * Test double for {@see LiqPaySettingsSection} that overrides the
 * `defined()` seam so the rendered HTML can be asserted under both
 * "constant defined" and "constant absent" branches without touching
 * the live PHP constant table.
 */
final class TestableLiqPaySettingsSection extends LiqPaySettingsSection {

	/** @var list<string> */
	public array $defined_constants = [];

	protected function is_constant_defined( string $name ): bool {
		return in_array( $name, $this->defined_constants, true );
	}
}
