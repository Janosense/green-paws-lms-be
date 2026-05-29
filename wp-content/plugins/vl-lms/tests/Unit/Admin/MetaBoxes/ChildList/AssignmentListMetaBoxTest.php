<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes\ChildList;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ChildList\AssignmentListMetaBox;
use WP_Post;

final class AssignmentListMetaBoxTest extends TestCase {

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

	/**
	 * @dataProvider parentTypeProvider
	 */
	public function test_register_targets_the_constructed_parent_type( string $parent_type ): void {
		$captured = [];
		Functions\when( 'add_meta_box' )->alias(
			static function ( ...$args ) use ( &$captured ): void {
				$captured[] = $args;
			}
		);

		( new AssignmentListMetaBox( $parent_type ) )->register();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl_lms_assignment_list', $captured[0][0] );
		self::assertSame( 'Завдання', $captured[0][1] );
		self::assertSame( $parent_type, $captured[0][3] );
	}

	/**
	 * @return list<list<string>>
	 */
	public static function parentTypeProvider(): array {
		return [
			[ 'vl_course' ],
			[ 'vl_module' ],
			[ 'vl_lesson' ],
			[ 'vl_session' ],
		];
	}

	public function test_render_shows_add_button_and_picker_even_when_empty(): void {
		Functions\when( 'get_posts' )->justReturn( [] );

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 88;
		assert( $post instanceof WP_Post );

		ob_start();
		( new AssignmentListMetaBox( 'vl_module' ) )->render( $post );
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'Немає елементів', $html );
		self::assertStringContainsString( 'Додати завдання', $html );
		self::assertStringContainsString( 'post-new.php?post_type=vl_assignment', $html );
		self::assertStringContainsString( 'vl_parent_id=88', $html );
		self::assertStringContainsString( 'data-entity="assignment"', $html );
		self::assertStringContainsString( 'data-parent-id="88"', $html );
	}
}
