<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Menu;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Dashboard\InstructorDashboardPage;
use VL\LMS\Admin\Groups\GroupsListPage;
use VL\LMS\Admin\Menu\AdminMenuProvider;
use VL\LMS\Admin\Orders\OrdersListPage;
use VL\LMS\Admin\Students\StudentsListPage;

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

	public function test_register_skips_groups_submenu_when_page_not_injected(): void {
		$provider = $this->makeProvider();

		$provider->register();

		foreach ( $this->submenu_calls as $call ) {
			self::assertNotSame( AdminMenuProvider::GROUPS_SLUG, $call['menu_slug'] );
		}
	}

	public function test_register_adds_groups_submenu_when_page_injected(): void {
		$dashboard = Mockery::mock( InstructorDashboardPage::class );
		$orders    = Mockery::mock( OrdersListPage::class );
		$groups    = Mockery::mock( GroupsListPage::class );

		$provider = new AdminMenuProvider( $dashboard, $orders, null, null, null, $groups );

		$provider->register();

		$groups_submenu = null;
		foreach ( $this->submenu_calls as $call ) {
			if ( AdminMenuProvider::GROUPS_SLUG === $call['menu_slug'] ) {
				$groups_submenu = $call;
				break;
			}
		}

		self::assertNotNull( $groups_submenu, 'Expected a Groups submenu registration.' );
		self::assertSame( 'vl-lms', $groups_submenu['parent_slug'] );
		self::assertSame( 'vl_manage_groups', $groups_submenu['capability'] );
		self::assertSame( 'Групи', $groups_submenu['menu_title'] );
	}

	public function test_register_skips_students_submenu_when_page_not_injected(): void {
		$provider = $this->makeProvider();

		$provider->register();

		foreach ( $this->submenu_calls as $call ) {
			self::assertNotSame( AdminMenuProvider::STUDENTS_SLUG, $call['menu_slug'] );
		}
	}

	public function test_register_adds_students_submenu_when_page_injected(): void {
		$dashboard = Mockery::mock( InstructorDashboardPage::class );
		$orders    = Mockery::mock( OrdersListPage::class );
		$students  = Mockery::mock( StudentsListPage::class );

		$provider = new AdminMenuProvider( $dashboard, $orders, null, null, null, null, $students );

		$provider->register();

		$students_submenu = null;
		foreach ( $this->submenu_calls as $call ) {
			if ( AdminMenuProvider::STUDENTS_SLUG === $call['menu_slug'] ) {
				$students_submenu = $call;
				break;
			}
		}

		self::assertNotNull( $students_submenu, 'Expected a Students submenu registration.' );
		self::assertSame( 'vl-lms', $students_submenu['parent_slug'] );
		self::assertSame( 'vl_view_all_enrollments', $students_submenu['capability'] );
		self::assertSame( 'Студенти', $students_submenu['menu_title'] );
	}

	private function makeProvider(): AdminMenuProvider {
		$dashboard = Mockery::mock( InstructorDashboardPage::class );
		$orders    = Mockery::mock( OrdersListPage::class );
		return new AdminMenuProvider( $dashboard, $orders );
	}
}
