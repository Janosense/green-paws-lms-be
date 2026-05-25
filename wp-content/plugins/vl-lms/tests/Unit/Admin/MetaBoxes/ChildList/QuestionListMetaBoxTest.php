<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\MetaBoxes\ChildList;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\MetaBoxes\ChildList\QuestionListMetaBox;
use WP_Post;

final class QuestionListMetaBoxTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
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

		( new QuestionListMetaBox() )->register();

		self::assertCount( 1, $captured );
		self::assertSame( 'vl_lms_question_list', $captured[0][0] );
		self::assertSame( 'Питання', $captured[0][1] );
		self::assertSame( 'vl_quiz', $captured[0][3] );
	}

	public function test_render_outputs_add_question_button_bound_to_parent(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-x' );
		Functions\when( 'admin_url' )->alias(
			static fn ( string $path ): string => 'https://example.test/wp-admin/' . $path
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( array $args, string $url ): string {
				return $url . '?' . http_build_query( $args );
			}
		);

		$box = new class() extends QuestionListMetaBox {
			protected function query_children( int $parent_id ): array {
				unset( $parent_id );
				return [];
			}
		};

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 77;
		assert( $post instanceof WP_Post );

		ob_start();
		$box->render( $post );
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'Додати питання', $out );
		self::assertStringContainsString( 'post_type=vl_quiz_question', $out );
		self::assertStringContainsString( 'vl_parent_id=77', $out );
	}

	public function test_render_links_each_question_to_its_editor(): void {
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce-x' );
		Functions\when( 'admin_url' )->alias( static fn ( string $p ): string => $p );
		Functions\when( 'add_query_arg' )->justReturn( 'post-new.php' );
		Functions\when( 'get_edit_post_link' )->alias(
			static fn ( int $id ): string => 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'
		);

		$child             = Mockery::mock( 'WP_Post' );
		$child->ID         = 9;
		$child->post_title = 'Питання 1';
		assert( $child instanceof WP_Post );

		$box = new class( $child ) extends QuestionListMetaBox {
			public function __construct( private readonly WP_Post $child ) {
				parent::__construct();
			}

			protected function query_children( int $parent_id ): array {
				unset( $parent_id );
				return [ $this->child ];
			}
		};

		$post     = Mockery::mock( 'WP_Post' );
		$post->ID = 77;
		assert( $post instanceof WP_Post );

		ob_start();
		$box->render( $post );
		$out = (string) ob_get_clean();

		self::assertStringContainsString( 'post.php?post=9&action=edit', $out );
		self::assertStringContainsString( 'Редагувати', $out );
	}
}
