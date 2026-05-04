<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Fixtures\Payments\LiqPay;

use VL\LMS\Payments\LiqPay\Settings;

/**
 * Test seam: a {@see Settings} subclass that injects constants, options,
 * and a `wp_get_environment_type()` value without touching the PHP
 * runtime or the WordPress options table.
 */
final class TestLiqPaySettings extends Settings {

	/** @var array<string, mixed> */
	private array $constants;

	/** @var array<string, string|null> */
	private array $options;

	private string $environment;

	/**
	 * @param array<string, mixed>        $constants
	 * @param array<string, string|null> $options
	 */
	public function __construct(
		array $constants = [],
		array $options = [],
		string $environment = 'local'
	) {
		$this->constants   = $constants;
		$this->options     = $options;
		$this->environment = $environment;
	}

	protected function read_constant( string $name ): mixed {
		return array_key_exists( $name, $this->constants ) ? $this->constants[ $name ] : null;
	}

	protected function read_option( string $name ): ?string {
		return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : null;
	}

	protected function environment_type(): string {
		return $this->environment;
	}
}
