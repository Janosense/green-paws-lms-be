<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\LessonNodeTransformer;
use VL\LMS\Learn\ModuleNodeTransformer;
use VL\LMS\Learn\ProgressOverlay;
use VL\LMS\Learn\TopicNodeTransformer;
use WP_Post;

final class ModuleNodeTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta = [];
		$meta_ref   = &$this->meta;

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ) use ( &$meta_ref ): mixed {
				return $meta_ref[ $key ][ $post_id ] ?? '';
			}
		);
		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function module( int $id, string $slug, string $title, int $menu_order = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_module';
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->menu_order  = $menu_order;
		assert( $post instanceof WP_Post );
		return $post;
	}

	private function lesson( int $id, string $slug, string $title, int $menu_order = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_lesson';
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->menu_order  = $menu_order;
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * @param array<int, list<WP_Post>> $lessons_by_module_id
	 */
	private function makeTransformer( array $lessons_by_module_id = [] ): ModuleNodeTransformer {
		// Lesson transformer with no topics — the module-level test only
		// cares that the module composes lessons in order.
		$lesson_transformer = new class( new TopicNodeTransformer() ) extends LessonNodeTransformer {

			protected function query_child_topics( int $lesson_id ): array {
				return [];
			}
		};

		return new class( $lesson_transformer, $lessons_by_module_id ) extends ModuleNodeTransformer {

			/** @param array<int, list<WP_Post>> $lessons_by_module_id */
			public function __construct(
				LessonNodeTransformer $lesson_transformer,
				private array $lessons_by_module_id
			) {
				parent::__construct( $lesson_transformer );
			}

			protected function query_child_lessons( int $parent_id ): array {
				return $this->lessons_by_module_id[ $parent_id ] ?? [];
			}
		};
	}

	public function test_module_with_multiple_lessons_in_order(): void {
		$module = $this->module( 110, 'module-1-basics', 'Module 1: Basics', 1 );
		$first  = $this->lesson( 121, 'welcome', 'Welcome', 1 );
		$second = $this->lesson( 122, 'next', 'Next', 2 );

		$transformer = $this->makeTransformer( [ 110 => [ $first, $second ] ] );

		$node = $transformer->transform( $module, ProgressOverlay::fromList( [] ) );

		self::assertSame( 110, $node['id'] );
		self::assertSame( 'module-1-basics', $node['slug'] );
		self::assertSame( 'Module 1: Basics', $node['title'] );
		self::assertSame( 1, $node['menu_order'] );
		self::assertCount( 2, $node['lessons'] );
		self::assertSame( [ 121, 122 ], array_column( $node['lessons'], 'id' ) );
	}

	public function test_empty_module_has_empty_lessons_array(): void {
		$module = $this->module( 110, 'empty', 'Empty', 1 );

		$transformer = $this->makeTransformer();
		$node        = $transformer->transform( $module, ProgressOverlay::fromList( [] ) );

		self::assertSame( [], $node['lessons'] );
	}

	public function test_module_node_has_no_progress_field(): void {
		$module = $this->module( 110, 'm', 'M' );

		$transformer = $this->makeTransformer();
		$node        = $transformer->transform( $module, ProgressOverlay::fromList( [] ) );

		self::assertArrayNotHasKey( 'progress', $node );
	}
}
