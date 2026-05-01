<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Zoom;

use VL\LMS\Services\Zoom\Settings\ZoomSettingsProvider;

/**
 * Test seam: a {@see ZoomSettingsProvider} subclass that lets us inject
 * constants and options without touching the PHP runtime or the WP
 * options table. Mirrors the production resolution rules.
 */
final class TestZoomSettingsProvider extends ZoomSettingsProvider {

	/** @var array<string, string|null> */
	private array $constants;

	/** @var array<string, string|null> */
	private array $options;

	/**
	 * @param array<string, string|null> $constants
	 * @param array<string, string|null> $options
	 */
	public function __construct( array $constants = [], array $options = [] ) {
		$this->constants = $constants;
		$this->options   = $options;
	}

	protected function read_constant( string $name ): ?string {
		return array_key_exists( $name, $this->constants ) ? $this->constants[ $name ] : null;
	}

	protected function read_option( string $name ): ?string {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : null;
	}
}
