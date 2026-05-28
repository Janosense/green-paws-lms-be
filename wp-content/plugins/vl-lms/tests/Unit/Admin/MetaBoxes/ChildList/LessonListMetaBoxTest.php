<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes\ChildList;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ChildList\LessonListMetaBox;
use WP_Post;

final class LessonListMetaBoxTest extends TestCase {

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
		Functions\when( '__' )->returnArg();
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

		( new LessonListMetaBox() )->register();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl_lms_lesson_list', $captured[0][0] );
		self::assertSame( 'Уроки', $captured[0][1] );
		self::assertSame( 'vl_module', $captured[0][3] );
	}

	public function test_render_shows_add_button_and_picker_even_when_empty(): void {
		Functions\when( 'get_posts' )->justReturn( [] );

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 77;
		assert( $post instanceof WP_Post );

		ob_start();
		( new LessonListMetaBox() )->render( $post );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'vl-sortable-list', $html );
		self::assertStringContainsString( 'Немає елементів', $html );
		self::assertStringContainsString( 'Додати урок', $html );
		self::assertStringContainsString( 'post-new.php?post_type=vl_lesson', $html );
		self::assertStringContainsString( 'vl_parent_id=77', $html );
		self::assertStringContainsString( 'vl-lms-entity-search', $html );
		self::assertStringContainsString( 'data-entity="lesson"', $html );
		self::assertStringContainsString( 'data-parent-id="77"', $html );
	}

	public function test_render_links_each_lesson_row_to_edit_and_unlink(): void {
		$lesson     = Mockery::mock( 'WP_Post' );
		$lesson->ID = 9;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$lesson->post_title = 'Sample lesson';
		assert( $lesson instanceof WP_Post );

		Functions\when( 'get_posts' )->justReturn( [ $lesson ] );

		$module     = Mockery::mock( 'WP_Post' );
		$module->ID = 77;
		assert( $module instanceof WP_Post );

		ob_start();
		( new LessonListMetaBox() )->render( $module );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Sample lesson', $html );
		self::assertStringContainsString( 'post.php?post=9&action=edit', $html );
		self::assertStringContainsString( 'vl-lms-entity-unlink', $html );
		self::assertStringContainsString( 'Відкріпити', $html );
		self::assertStringContainsString( 'data-id="9"', $html );
	}
}
