<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes\ChildList;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ChildList\TopicListMetaBox;
use WP_Post;

final class TopicListMetaBoxTest extends TestCase {

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
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-stub' );
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

		( new TopicListMetaBox() )->register();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl_lms_topic_list', $captured[0][0] );
		self::assertSame( 'Теми', $captured[0][1] );
		self::assertSame( 'vl_lesson', $captured[0][3] );
	}

	public function test_render_shows_add_new_button_even_when_no_topics(): void {
		Functions\when( 'get_posts' )->justReturn( [] );

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 42;
		assert( $post instanceof WP_Post );

		ob_start();
		( new TopicListMetaBox() )->render( $post );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Немає елементів', $html );
		self::assertStringContainsString( 'Додати тему', $html );
		self::assertStringContainsString( 'post-new.php?post_type=vl_topic', $html );
		self::assertStringContainsString( 'vl_parent_id=42', $html );
	}

	public function test_render_links_each_topic_row_to_edit_screen(): void {
		$topic     = Mockery::mock( 'WP_Post' );
		$topic->ID = 9;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$topic->post_title = 'Sample topic';
		assert( $topic instanceof WP_Post );

		Functions\when( 'get_posts' )->justReturn( [ $topic ] );

		$lesson     = Mockery::mock( 'WP_Post' );
		$lesson->ID = 42;
		assert( $lesson instanceof WP_Post );

		ob_start();
		( new TopicListMetaBox() )->render( $lesson );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Sample topic', $html );
		self::assertStringContainsString( 'post.php?post=9&action=edit', $html );
		self::assertStringContainsString( 'Редагувати', $html );
		self::assertStringContainsString( 'Додати тему', $html );
	}
}
