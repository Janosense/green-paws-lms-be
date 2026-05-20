<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Groups;

use VL\LMS\Admin\Groups\GroupsListPage;

/**
 * Test double that swaps `forbidden()` for an in-process flag so PHPUnit
 * can assert the capability-guard branch without `wp_die`.
 */
final class TestableGroupsListPage extends GroupsListPage {

	public bool $forbidden_called = false;

	protected function forbidden(): void {
		$this->forbidden_called = true;
	}
}
