<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Groups;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Groups\GroupDetailPage;
use VL\LMS\Tests\Fixtures\InMemoryGroupAccessRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;

final class GroupsListPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $s ): void {
				echo esc_html( $s );
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( '_e' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_date' )->alias( static fn ( string $f, int $ts ): string => gmdate( $f, $ts ) );
		$_GET     = [];
		$_REQUEST = [];
	}

	protected function tearDown(): void {
		$_GET     = [];
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_calls_forbidden_when_cap_missing(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$page = new TestableGroupsListPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryGroupAccessRepository(),
			Mockery::mock( GroupDetailPage::class )
		);

		ob_start();
		$page->render();
		ob_end_clean();

		self::assertTrue( $page->forbidden_called );
	}

	public function test_render_dispatches_to_detail_when_action_edit(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET['action'] = 'edit';

		$detail = Mockery::mock( GroupDetailPage::class );
		$detail->shouldReceive( 'render' )->once();

		$page = new TestableGroupsListPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryGroupAccessRepository(),
			$detail
		);

		ob_start();
		$page->render();
		ob_end_clean();
	}

	public function test_render_outputs_list_layout_with_create_form(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$page = new TestableGroupsListPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryGroupAccessRepository(),
			Mockery::mock( GroupDetailPage::class )
		);

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Групи', $output );
		self::assertStringContainsString( 'name="page" value="vl-lms-groups"', $output );
		self::assertStringContainsString( 'value="vl_lms_group_create"', $output );
		self::assertStringContainsString( 'name="name"', $output );
		self::assertStringContainsString( 'name="max_members"', $output );
	}
}
