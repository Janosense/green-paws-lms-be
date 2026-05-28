<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use VL\LMS\Admin\Students\StudentDetailPage;

/**
 * Test double that swaps `forbidden()` for an in-process flag so PHPUnit
 * can assert the capability guard without `wp_die`.
 */
final class TestableStudentDetailPage extends StudentDetailPage {

	public bool $forbidden_called = false;

	protected function forbidden(): void {
		$this->forbidden_called = true;
	}
}
