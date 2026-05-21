<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use VL\LMS\Admin\Students\StudentsListPage;
use VL\LMS\Admin\Students\StudentsListTable;

/**
 * Test double that swaps `forbidden()` for an in-process flag and lets
 * the caller substitute a fake table — so PHPUnit can render the page
 * without a real `WP_User_Query`.
 */
final class TestableStudentsListPage extends StudentsListPage {

	public bool $forbidden_called = false;

	public ?StudentsListTable $table_override = null;

	protected function forbidden(): void {
		$this->forbidden_called = true;
	}

	protected function build_table(): StudentsListTable {
		if ( null !== $this->table_override ) {
			return $this->table_override;
		}
		return parent::build_table();
	}
}
