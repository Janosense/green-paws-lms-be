<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Groups;

use VL\LMS\Admin\Groups\GroupFormHandler;

/**
 * Test double for {@see GroupFormHandler} that swaps `require_cap()`
 * and `redirect()` for in-process flags so PHPUnit can invoke any
 * handler without `wp_die` / `exit`.
 */
final class TestableGroupFormHandler extends GroupFormHandler {

	public bool $forbidden = false;

	public ?string $redirected_to = null;

	protected function require_cap(): void {
		if ( ! current_user_can( 'vl_manage_groups' ) ) {
			$this->forbidden = true;
		}
	}

	protected function redirect( string $url ): void {
		$this->redirected_to = $url;
	}
}
