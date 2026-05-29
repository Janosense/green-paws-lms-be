<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Learn\QuizNodeTransformer;
use VL\LMS\Learn\QuizStatusOverlay;
use WP_Post;

final class QuizNodeTransformerTest extends TestCase {

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

	private function quiz( int $id, string $slug, string $title, int $menu_order = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_quiz';
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->menu_order  = $menu_order;
		assert( $post instanceof WP_Post );
		return $post;
	}

	public function test_transform_shapes_a_single_quiz_node(): void {
		$this->meta['_vl_quiz_is_final_exam'][9]     = '1';
		$this->meta['_vl_quiz_passing_threshold'][9] = 80;

		$overlay = QuizStatusOverlay::fromMap(
			[
				9 => [
					'passed'          => false,
					'in_progress'     => false,
					'submitted_count' => 1,
					'best_pct'        => 60.0,
				],
			]
		);

		$node = ( new QuizNodeTransformer() )->transform( $this->quiz( 9, 'final', 'Final Exam', 3 ), $overlay );

		self::assertSame(
			[
				'type'              => 'quiz',
				'id'                => 9,
				'slug'              => 'final',
				'title'             => 'Final Exam',
				'menu_order'        => 3,
				'is_final_exam'     => true,
				'passing_threshold' => 80,
				'status'            => 'failed',
				'best_score_pct'    => 60.0,
			],
			$node
		);
	}

	public function test_transform_children_maps_each_child_in_query_order(): void {
		$first  = $this->quiz( 10, 'a', 'A', 1 );
		$second = $this->quiz( 11, 'b', 'B', 2 );

		$transformer = new class( [ 500 => [ $first, $second ] ] ) extends QuizNodeTransformer {

			/** @param array<int, list<WP_Post>> $by_parent */
			public function __construct( private array $by_parent ) {
			}

			protected function query_child_quizzes( int $parent_id ): array {
				return $this->by_parent[ $parent_id ] ?? [];
			}
		};

		$nodes = $transformer->transform_children( 500, QuizStatusOverlay::fromMap( [] ) );

		self::assertCount( 2, $nodes );
		self::assertSame( [ 10, 11 ], array_column( $nodes, 'id' ) );
		self::assertSame( 'not_started', $nodes[0]['status'] );
	}

	public function test_transform_children_is_empty_for_parent_with_no_quizzes(): void {
		$transformer = new class() extends QuizNodeTransformer {
			protected function query_child_quizzes( int $parent_id ): array {
				return [];
			}
		};

		self::assertSame( [], $transformer->transform_children( 1, QuizStatusOverlay::fromMap( [] ) ) );
	}
}
