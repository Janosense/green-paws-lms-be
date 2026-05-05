<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Orders;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Orders\OrdersListPage;
use VL\LMS\Tests\Fixtures\InMemoryOrderRepository;

final class OrdersListPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_date' )->alias( static fn ( string $f, int $ts ): string => gmdate( $f, $ts ) );
		Functions\when( 'add_query_arg' )->alias( static fn ( $args, string $url ): string => $url );
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'get_userdata' )->justReturn( false );
		$_REQUEST = [];
	}

	protected function tearDown(): void {
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_outputs_wrap_with_title_and_form(): void {
		$page = new OrdersListPage( new InMemoryOrderRepository() );

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( '<h1>Замовлення</h1>', $output );
		self::assertStringContainsString( '<form method="get">', $output );
		self::assertStringContainsString( 'name="page" value="vl-lms-orders"', $output );
	}
}
