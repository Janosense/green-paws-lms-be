<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\AdminProvider;

final class AdminProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_boot_registers_hooks(): void {
		$provider = new AdminProvider( [] );

		Actions\expectAdded( 'add_meta_boxes' )->once();
		Actions\expectAdded( 'save_post' )->once();
		Actions\expectAdded( 'admin_init' )->once();
		Actions\expectAdded( 'admin_enqueue_scripts' )->once();
		Actions\expectAdded( 'wp_ajax_vl_lms_search_instructors' )->once();

		$provider->boot();
	}
}
