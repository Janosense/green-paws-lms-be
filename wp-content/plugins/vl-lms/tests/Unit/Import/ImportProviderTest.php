<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Import\ImportFormHandler;
use VL\LMS\Import\ImportProvider;

final class ImportProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_boot_hooks_admin_init_and_builds_nothing(): void {
		// The importer's configuration is read from filters a theme may still
		// add; `boot()` runs at `plugins_loaded`, so it must not read it yet.
		Functions\expect( 'wp_max_upload_size' )->never();
		$provider = new ImportProvider();

		$provider->boot();

		self::assertNotFalse( has_action( 'admin_init', [ $provider, 'register_handlers' ] ) );
		self::assertFalse( has_action( 'admin_post_' . ImportFormHandler::UPLOAD_ACTION ) );
		self::assertFalse( has_action( 'admin_post_' . ImportFormHandler::CONFIRM_ACTION ) );
		self::assertFalse( has_action( 'admin_post_' . ImportFormHandler::DISCARD_ACTION ) );
	}

	public function test_register_handlers_wires_the_three_actions_to_one_handler(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 1048576 );
		$hooked = [];
		foreach ( [ ImportFormHandler::UPLOAD_ACTION, ImportFormHandler::CONFIRM_ACTION, ImportFormHandler::DISCARD_ACTION ] as $action ) {
			Actions\expectAdded( 'admin_post_' . $action )->once()->whenHappen(
				static function ( array $callback ) use ( $action, &$hooked ): void {
					$hooked[ $action ] = $callback;
				}
			);
		}

		( new ImportProvider() )->register_handlers();

		self::assertInstanceOf( ImportFormHandler::class, $hooked[ ImportFormHandler::UPLOAD_ACTION ][0] );
		self::assertSame( $hooked[ ImportFormHandler::UPLOAD_ACTION ][0], $hooked[ ImportFormHandler::CONFIRM_ACTION ][0], 'One handler serves every action.' );
		self::assertSame( $hooked[ ImportFormHandler::UPLOAD_ACTION ][0], $hooked[ ImportFormHandler::DISCARD_ACTION ][0], 'One handler serves every action.' );
		self::assertSame( 'handle_upload', $hooked[ ImportFormHandler::UPLOAD_ACTION ][1] );
		self::assertSame( 'handle_confirm', $hooked[ ImportFormHandler::CONFIRM_ACTION ][1] );
		self::assertSame( 'handle_discard', $hooked[ ImportFormHandler::DISCARD_ACTION ][1] );
	}

	public function test_the_page_is_built_once_and_reused(): void {
		Functions\when( 'wp_max_upload_size' )->justReturn( 1048576 );
		$provider = new ImportProvider();

		$page = $provider->page();

		self::assertSame( $page, $provider->page() );
	}
}
