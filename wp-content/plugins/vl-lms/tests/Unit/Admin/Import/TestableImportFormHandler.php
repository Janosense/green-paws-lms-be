<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Import;

use VL\LMS\Admin\Import\ImportFormHandler;

/**
 * Test double for {@see ImportFormHandler} that records the redirect target
 * instead of calling `wp_safe_redirect()` and `exit`, and the time limit
 * instead of setting it — `set_time_limit()` is a PHP internal Brain Monkey
 * cannot replace (`docs/TESTING.md`).
 */
final class TestableImportFormHandler extends ImportFormHandler {

	public ?string $redirected_to = null;

	/**
	 * The seconds the handler asked for, or null while it never asked.
	 */
	public ?int $time_limit = null;

	protected function redirect( string $url ): void {
		$this->redirected_to = $url;
	}

	protected function limit_time( int $seconds ): void {
		$this->time_limit = $seconds;
	}
}
