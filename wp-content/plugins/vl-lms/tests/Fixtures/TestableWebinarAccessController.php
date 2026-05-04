<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures;

use VL\LMS\Api\WebinarAccessController;

/**
 * Subclass of {@see WebinarAccessController} with the redirect seam
 * overridden so PHPUnit can assert on the redirect URL without
 * `exit`-ing the test runner.
 *
 * @author Tymofii Synianskyi
 */
final class TestableWebinarAccessController extends WebinarAccessController {

	public ?string $last_redirect = null;

	protected function send_redirect( string $url ): void {
		$this->last_redirect = $url;
	}
}
