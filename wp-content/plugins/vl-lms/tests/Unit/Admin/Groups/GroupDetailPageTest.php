<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Groups;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Groups\GroupDetailPage;
use VL\LMS\Domain\Group\GroupStatus;
use VL\LMS\Tests\Fixtures\InMemoryGroupAccessRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupMemberRepository;
use VL\LMS\Tests\Fixtures\InMemoryGroupRepository;

final class GroupDetailPageTest extends TestCase {

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
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'admin_url' )->alias( static fn ( string $p = '' ): string => '/wp-admin/' . $p );
		Functions\when( 'wp_nonce_url' )->alias( static fn ( string $url ): string => $url . '&_wpnonce=test' );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $v ): string {
				$out = json_encode( $v, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Stand-in for wp_json_encode under unit tests.
				return false === $out ? '""' : $out;
			}
		);
		// Mirror WP's selected(): (string)$a === (string)$b. Doing the cast
		// here ensures callers never pass values that the real WP function
		// would TypeError on (e.g. a BackedEnum that can't be string-cast).
		Functions\when( 'selected' )->alias(
			static function ( $a, $b ): string {
				return (string) $a === (string) $b ? " selected='selected'" : '';
			}
		);
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'get_userdata' )->justReturn( false );
		Functions\when( 'get_post' )->justReturn( null );
		$_GET = [];
	}

	protected function tearDown(): void {
		$_GET = [];
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_render_shows_not_found_when_id_missing(): void {
		$page = $this->makePage();

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Групу не знайдено', $output );
	}

	public function test_render_shows_not_found_when_group_missing(): void {
		$_GET['id'] = '999';

		$page = $this->makePage();

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Групу не знайдено', $output );
	}

	public function test_render_outputs_three_section_layout(): void {
		$groups     = new InMemoryGroupRepository();
		$id         = $groups->insert(
			[
				'name'     => 'QA Cohort',
				'slug'     => 'qa-cohort',
				'type'     => 'ad_hoc',
				'owner_id' => 1,
				'status'   => GroupStatus::ACTIVE->value,
			]
		);
		$_GET['id'] = (string) $id;

		$page = new GroupDetailPage(
			$groups,
			new InMemoryGroupMemberRepository(),
			new InMemoryGroupAccessRepository()
		);

		ob_start();
		$page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Параметри групи', $output );
		self::assertStringContainsString( 'Учасники', $output );
		self::assertStringContainsString( 'Безкоштовний доступ до курсів', $output );
		self::assertStringContainsString( 'value="vl_lms_group_update"', $output );
		self::assertStringContainsString( 'value="vl_lms_group_member_add"', $output );
		self::assertStringContainsString( 'value="vl_lms_group_course_grant"', $output );
		self::assertStringContainsString( 'qa-cohort', $output );
	}

	private function makePage(): GroupDetailPage {
		return new GroupDetailPage(
			new InMemoryGroupRepository(),
			new InMemoryGroupMemberRepository(),
			new InMemoryGroupAccessRepository()
		);
	}
}
