<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Settings;

use VL\LMS\Admin\Settings\SettingsPage;

/**
 * Test double for {@see SettingsPage} that swaps the `defined()` /
 * `wp_redirect` seams for plain in-process flags so PHPUnit can invoke
 * `handle_save()` without exiting the process.
 */
final class TestableSettingsPage extends SettingsPage {

	/** @var list<string> */
	public array $defined_constants = [];

	public bool $redirected = false;

	protected function is_constant_defined( string $name ): bool {
		return in_array( $name, $this->defined_constants, true );
	}

	protected function redirect_back(): void {
		$this->redirected = true;
	}
}
