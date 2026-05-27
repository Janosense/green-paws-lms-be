<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes\ChildList;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ChildList\ModuleListMetaBox;
use WP_Post;

final class ModuleListMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'admin_url' )->alias(
			static fn ( string $path ): string => 'https://admin.test/wp-admin/' . ltrim( $path, '/' )
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-x' );
		Functions\when( 'get_edit_post_link' )->alias(
			static fn ( int $id ): string => 'https://admin.test/wp-admin/post.php?post=' . $id . '&action=edit'
		);
		Functions\when( 'get_posts' )->justReturn( [] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_calls_add_meta_box_with_correct_post_type(): void {
		$captured = [];
		Functions\when( 'add_meta_box' )->alias(
			static function ( ...$args ) use ( &$captured ): void {
				$captured[] = $args;
			}
		);

		( new ModuleListMetaBox() )->register();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl_lms_module_list', $captured[0][0] );
		self::assertSame( 'Модулі', $captured[0][1] );
		self::assertSame( 'vl_course', $captured[0][3] );
	}

	public function test_render_shows_add_button_and_picker_even_when_empty(): void {
		Functions\when( 'get_posts' )->justReturn( [] );

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 42;
		assert( $post instanceof WP_Post );

		ob_start();
		( new ModuleListMetaBox() )->render( $post );
		$html = (string) ob_get_clean();

		// The container is always rendered (hidden) so the JS picker has
		// a stable target to append attached modules into.
		self::assertStringContainsString( 'vl-sortable-list', $html );
		self::assertStringContainsString( 'Немає елементів', $html );
		self::assertStringContainsString( 'Додати модуль', $html );
		self::assertStringContainsString( 'post-new.php?post_type=vl_module', $html );
		self::assertStringContainsString( 'vl_parent_id=42', $html );
		self::assertStringContainsString( 'vl-lms-module-search', $html );
		self::assertStringContainsString( 'data-course-id="42"', $html );
	}

	public function test_render_links_each_module_row_to_edit_and_unlink(): void {
		$module     = Mockery::mock( 'WP_Post' );
		$module->ID = 9;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$module->post_title = 'Sample module';
		assert( $module instanceof WP_Post );

		Functions\when( 'get_posts' )->justReturn( [ $module ] );

		$course     = Mockery::mock( 'WP_Post' );
		$course->ID = 42;
		assert( $course instanceof WP_Post );

		ob_start();
		( new ModuleListMetaBox() )->render( $course );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Sample module', $html );
		self::assertStringContainsString( 'post.php?post=9&action=edit', $html );
		self::assertStringContainsString( 'Редагувати', $html );
		self::assertStringContainsString( 'vl-lms-module-unlink', $html );
		self::assertStringContainsString( 'Відкріпити', $html );
		self::assertStringContainsString( 'data-id="9"', $html );
	}
}
