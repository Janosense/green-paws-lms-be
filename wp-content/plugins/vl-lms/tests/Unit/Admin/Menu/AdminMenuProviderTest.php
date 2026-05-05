<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Menu;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use VL\LMS\Admin\Menu\AdminMenuProvider;
use VL\LMS\Admin\Orders\OrdersListPage;

final class AdminMenuProviderTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array<string, mixed>> */
	private array $menu_calls = [];

	/** @var list<array<string, mixed>> */
	private array $submenu_calls = [];

	/** @var list<string> */
	private array $remove_calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->menu_calls    = [];
		$this->submenu_calls = [];
		$this->remove_calls  = [];

		$menu    = &$this->menu_calls;
		$submenu = &$this->submenu_calls;
		$remove  = &$this->remove_calls;

		Functions\when( 'add_menu_page' )->alias(
			static function (
				string $page_title,
				string $menu_title,
				string $capability,
				string $menu_slug,
				$callback = '',
				string $icon = '',
				$position = null
			) use ( &$menu ): string {
				$menu[] = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback', 'icon', 'position' );
				unset( $callback, $icon, $position );
				return 'hook_' . $menu_slug;
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function (
				string $parent_slug,
				string $page_title,
				string $menu_title,
				string $capability,
				string $menu_slug,
				$callback = ''
			) use ( &$submenu ): string {
				$submenu[] = compact( 'parent_slug', 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
				unset( $callback );
				return 'hook_' . $menu_slug;
			}
		);
		Functions\when( 'remove_menu_page' )->alias(
			static function ( string $slug ) use ( &$remove ): bool {
				$remove[] = $slug;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_adds_top_level_menu(): void {
		$provider = $this->makeProvider();

		$provider->register();

		self::assertCount( 1, $this->menu_calls );
		self::assertSame( 'vl-lms', $this->menu_calls[0]['menu_slug'] );
		self::assertSame( 'edit_posts', $this->menu_calls[0]['capability'] );
		self::assertSame( 'dashicons-welcome-learn-more', $this->menu_calls[0]['icon'] );
		self::assertSame( 3, $this->menu_calls[0]['position'] );
		self::assertContains( 'vl-lms-orders', $this->remove_calls );
	}

	public function test_register_adds_orders_submenu(): void {
		$provider = $this->makeProvider();

		$provider->register();

		$orders_submenu = null;
		foreach ( $this->submenu_calls as $call ) {
			if ( 'vl-lms-orders' === $call['menu_slug'] ) {
				$orders_submenu = $call;
				break;
			}
		}

		self::assertNotNull( $orders_submenu, 'Expected an Orders submenu registration.' );
		self::assertSame( 'vl-lms', $orders_submenu['parent_slug'] );
		self::assertSame( 'vl_refund_orders', $orders_submenu['capability'] );
	}

	private function makeProvider(): AdminMenuProvider {
		$dashboard = Mockery::mock( InstructorDashboardPage::class );
		$orders    = Mockery::mock( OrdersListPage::class );
		return new AdminMenuProvider( $dashboard, $orders );
	}
}
