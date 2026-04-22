<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use VL\LMS\Plugin;

final class PluginTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_plugin_state();
	}

	protected function tearDown(): void {
		$this->reset_plugin_state();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_boot_short_circuits_when_dependencies_are_missing(): void {
		Plugin::set_dependency_checker( static fn (): bool => false );

		Actions\expectAdded( 'admin_notices' )->once();
		Actions\expectAdded( 'init' )->never();
		Actions\expectAdded( 'rest_api_init' )->never();
		Actions\expectDone( 'vl_lms/booted' )->never();

		Plugin::instance()->boot();
	}

	public function test_boot_registers_hooks_and_fires_action_when_dependencies_present(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Actions\expectAdded( 'init' )->once();
		Actions\expectAdded( 'rest_api_init' )->once();
		Actions\expectDone( 'vl_lms/booted' )->once();

		Plugin::instance()->boot();
	}

	public function test_boot_is_idempotent(): void {
		Plugin::set_dependency_checker( static fn (): bool => true );

		Actions\expectAdded( 'init' )->once();
		Actions\expectAdded( 'rest_api_init' )->once();
		Actions\expectDone( 'vl_lms/booted' )->once();

		$plugin = Plugin::instance();
		$plugin->boot();
		$plugin->boot();
		$plugin->boot();
	}

	public function test_default_dependency_check_uses_class_exists(): void {
		// Override cleared in setUp; the default path relies on the real
		// facade class, which is not loaded in the unit suite.
		self::assertFalse( Plugin::instance()->dependencies_met() );
	}

	private function reset_plugin_state(): void {
		Plugin::set_dependency_checker( null );

		$reflection = new ReflectionClass( Plugin::class );
		$reflection->getProperty( 'instance' )->setValue( null, null );
	}
}
