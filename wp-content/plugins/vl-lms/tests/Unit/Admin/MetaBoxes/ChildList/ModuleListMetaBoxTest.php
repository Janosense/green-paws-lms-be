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
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-x' );
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

	public function test_render_outputs_no_children_message_when_empty(): void {
		$box = new class() extends ModuleListMetaBox {
			protected function query_children( int $parent_id ): array {
				unset( $parent_id );
				return [];
			}
		};

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 42;
		assert( $post instanceof WP_Post );

		ob_start();
		$box->render( $post );
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Немає елементів', $out );
		self::assertStringNotContainsString( 'vl-sortable-list', $out );
	}
}
