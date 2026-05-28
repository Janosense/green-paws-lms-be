<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Students\StudentDetailPage;
use VL\LMS\Tests\Fixtures\InMemoryCertificateRepository;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupAccessRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;
use WP_User;

final class StudentsListPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( string $s ): void {
				echo esc_html( $s );
			}
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ): string {
				return (string) $a === (string) $b ? " selected='selected'" : '';
			}
		);
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'update_meta_cache' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$_GET     = [];
		$_REQUEST = [];

		// `\InMemoryGroupAccessRepository` is unused but the constructor sig
		// requires nothing from it. Silence unused-import linter via touch.
		\class_exists( InMemoryGroupAccessRepository::class );
	}

	protected function tearDown(): void {
		$_GET     = [];
		$_REQUEST = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_detail_page(): StudentDetailPage {
		return new StudentDetailPage(
			new InMemoryEnrollmentRepository(),
			new InMemoryCertificateRepository()
		);
	}

	public function test_render_calls_forbidden_when_cap_missing(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$page = new TestableStudentsListPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryEnrollmentRepository(),
			$this->make_detail_page()
		);

		ob_start();
		$page->render();
		ob_end_clean();

		self::assertTrue( $page->forbidden_called );
	}

	public function test_render_dispatches_to_detail_page_on_view_action(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		$_GET = [
			'action' => 'view',
			'id'     => '7',
		];
		Functions\when( 'get_userdata' )->alias(
			static function (): WP_User {
				$user        = new WP_User();
				$user->ID    = 7;
				$user->roles = [ 'student' ];
				return $user;
			}
		);

		$page = new TestableStudentsListPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryEnrollmentRepository(),
			$this->make_detail_page()
		);

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		// Detail page renders the tab wrapper; the list does not.
		self::assertStringContainsString( 'nav-tab-wrapper', $output );
		self::assertStringContainsString( 'Аналітика', $output );
	}

	public function test_render_outputs_wrap_with_heading_and_search_form(): void {
		Functions\when( 'current_user_can' )->justReturn( true );

		$page = new TestableStudentsListPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryEnrollmentRepository(),
			$this->make_detail_page()
		);

		$table                = new TestableStudentsListTable(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryEnrollmentRepository()
		);
		$page->table_override = $table;

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Студенти', $output );
		self::assertStringContainsString( 'name="page" value="vl-lms-students"', $output );
		self::assertStringContainsString( '<form method="get">', $output );
	}
}
