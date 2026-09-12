<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Import;

use VL\LMS\Admin\Import\ImportFormHandler;

/**
 * Test double for {@see ImportFormHandler} that records the redirect target
 * instead of calling `wp_safe_redirect()` and `exit`.
 */
final class TestableImportFormHandler extends ImportFormHandler {

	public ?string $redirected_to = null;

	protected function redirect( string $url ): void {
		$this->redirected_to = $url;
	}
}
