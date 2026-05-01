<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom\Sync;

use VL\LMS\Services\Zoom\Settings\ZoomCredentials;
use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

/**
 * Test double for {@see ZoomSettingsProvider}: returns whatever
 * credentials it was constructed with, no constants / options
 * round-trip.
 */
final class StubZoomSettingsProvider extends ZoomSettingsProvider {

	private ZoomCredentials $credentials;

	public function __construct( ZoomCredentials $credentials ) {
		$this->credentials = $credentials;
	}

	public function get_credentials(): ZoomCredentials {
		return $this->credentials;
	}
}
