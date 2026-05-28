<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Students;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Enrollment\EnrollmentStatus;
use VL\LMS\Tests\Fixtures\InMemoryCertificateRepository;
use VL\LMS\Tests\Fixtures\InMemoryEnrollmentRepository;
use WP_User;

final class StudentDetailPageTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private InMemoryEnrollmentRepository $enrollments;
	private InMemoryCertificateRepository $certificates;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias( static fn ( string $url ): string => $url . '&_nonce=1' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'number_format_i18n' )->alias( static fn ( $n, $d = 0 ): string => number_format( (float) $n, (int) $d ) );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $v ): string => (string) json_encode( $v ) );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->enrollments  = new InMemoryEnrollmentRepository();
		$this->certificates = new InMemoryCertificateRepository();

		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	private function page(): TestableStudentDetailPage {
		return new TestableStudentDetailPage( $this->enrollments, $this->certificates );
	}

	private function stub_student( int $user_id = 7 ): void {
		Functions\when( 'get_userdata' )->alias(
			static function () use ( $user_id ): WP_User {
				$user        = new WP_User();
				$user->ID    = $user_id;
				$user->roles = [ 'student' ];
				return $user;
			}
		);
		Functions\when( 'get_user_meta' )->alias(
			static function ( $id, string $key ): string {
				return 'first_name' === $key ? 'Olena' : ( 'last_name' === $key ? 'Koval' : '' );
			}
		);
	}

	private function stub_course( string $title = 'CMS 101' ): void {
		Functions\when( 'get_post' )->alias(
			static function () use ( $title ) {
				$post             = Mockery::mock( 'WP_Post' );
				$post->ID         = 321;
				$post->post_title = $title;
				return $post;
			}
		);
		Functions\when( 'get_post_meta' )->justReturn( '' );
	}

	public function test_forbidden_when_cap_missing(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$page = $this->page();
		ob_start();
		$page->render();
		ob_end_clean();

		self::assertTrue( $page->forbidden_called );
	}

	public function test_renders_not_found_for_non_student(): void {
		$_GET = [ 'id' => '7' ];
		Functions\when( 'get_userdata' )->justReturn( false );

		ob_start();
		$this->page()->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Студента не знайдено', $output );
	}

	public function test_analytics_tab_renders_cards_and_course_row(): void {
		$_GET = [ 'id' => '7' ];
		$this->stub_student();
		$this->stub_course( 'CMS 101' );
		$this->enrollments->seed(
			[
				'user_id'      => 7,
				'course_id'    => 321,
				'status'       => EnrollmentStatus::COMPLETED->value,
				'progress_pct' => 100,
			]
		);

		ob_start();
		$this->page()->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'nav-tab-wrapper', $output );
		self::assertStringContainsString( 'Записів усього', $output );
		self::assertStringContainsString( 'Сертифікатів', $output );
		self::assertStringContainsString( 'CMS 101', $output );
	}

	public function test_courses_tab_renders_enrollment_and_grant_form(): void {
		$_GET = [
			'id'  => '7',
			'tab' => 'courses',
		];
		$this->stub_student();
		$this->stub_course( 'CMS 101' );
		$this->enrollments->seed(
			[
				'user_id'   => 7,
				'course_id' => 321,
				'status'    => EnrollmentStatus::ACTIVE->value,
			]
		);

		ob_start();
		$this->page()->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Записи на курси', $output );
		self::assertStringContainsString( 'Надати доступ до курсу', $output );
		self::assertStringContainsString( 'vl-admin-course-search', $output );
		self::assertStringContainsString( 'Відкликати', $output );
		self::assertStringContainsString( 'CMS 101', $output );
	}
}
