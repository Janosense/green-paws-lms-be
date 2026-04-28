<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Learn;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Domain\Progress\EntityType;
use VL\LMS\Domain\Progress\Progress;
use VL\LMS\Domain\Progress\ProgressStatus;
use VL\LMS\Learn\LessonNodeTransformer;
use VL\LMS\Learn\ProgressOverlay;
use VL\LMS\Learn\TopicNodeTransformer;
use WP_Post;

final class LessonNodeTransformerTest extends TestCase {

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

	private function topic( int $id, string $slug, string $title, int $menu_order = 1 ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = 'vl_topic';
		$post->post_name   = $slug;
		$post->post_title  = $title;
		$post->post_status = 'publish';
		$post->menu_order  = $menu_order;
		assert( $post instanceof WP_Post );
		return $post;
	}

	/**
	 * @param array<int, list<WP_Post>> $topics_by_lesson_id
	 */
	private function makeTransformer( array $topics_by_lesson_id = [] ): LessonNodeTransformer {
		return new class( new TopicNodeTransformer(), $topics_by_lesson_id ) extends LessonNodeTransformer {

			/** @param array<int, list<WP_Post>> $topics_by_lesson_id */
			public function __construct(
				TopicNodeTransformer $topic_transformer,
				private array $topics_by_lesson_id
			) {
				parent::__construct( $topic_transformer );
			}

			protected function query_child_topics( int $lesson_id ): array {
				return $this->topics_by_lesson_id[ $lesson_id ] ?? [];
			}
		};
	}

	private function lesson_progress( int $entity_id, ProgressStatus $status, ?int $position = null ): Progress {
		$now = new \DateTimeImmutable( '2026-04-28 10:00:00', new \DateTimeZone( 'UTC' ) );
		return new Progress(
			id: $entity_id * 10,
			user_id: 5,
			entity_type: EntityType::LESSON,
			entity_id: $entity_id,
			course_id: 100,
			status: $status,
			position_seconds: $position,
			completed_at: ProgressStatus::COMPLETED === $status ? $now : null,
			last_seen_at: $now,
			created_at: $now,
			updated_at: $now,
		);
	}

	public function test_lesson_with_topics_has_topics_true(): void {
		$lesson = $this->lesson( 123, 'intro', 'Intro', 2 );
		$this->meta['_vl_lesson_duration_seconds'][123]    = 1800;
		$this->meta['_vl_lesson_is_preview'][123]          = '';
		$this->meta['_vl_lesson_requires_completion'][123] = '1';
		$this->meta['_vl_topic_duration_seconds'][201]     = 100;
		$this->meta['_vl_topic_duration_seconds'][202]     = 200;

		$first  = $this->topic( 201, 'a', 'A', 1 );
		$second = $this->topic( 202, 'b', 'B', 2 );

		$transformer = $this->makeTransformer( [ 123 => [ $first, $second ] ] );
		$overlay     = ProgressOverlay::fromList(
			[ $this->lesson_progress( 123, ProgressStatus::IN_PROGRESS, 240 ) ]
		);

		$node = $transformer->transform( $lesson, $overlay );

		self::assertSame( 123, $node['id'] );
		self::assertSame( 'intro', $node['slug'] );
		self::assertSame( 'Intro', $node['title'] );
		self::assertSame( 2, $node['menu_order'] );
		self::assertSame( 1800, $node['duration_seconds'] );
		self::assertFalse( $node['is_preview'] );
		self::assertTrue( $node['requires_completion'] );
		self::assertTrue( $node['has_topics'] );
		self::assertCount( 2, $node['topics'] );
		self::assertSame( [ 201, 202 ], array_column( $node['topics'], 'id' ) );
		self::assertSame( 'in_progress', $node['progress']['status'] );
		self::assertSame( 240, $node['progress']['position_seconds'] );
	}

	public function test_lesson_without_topics_has_empty_array(): void {
		$lesson = $this->lesson( 123, 'solo', 'Solo' );
		$this->meta['_vl_lesson_duration_seconds'][123] = 300;

		$transformer = $this->makeTransformer();
		$node        = $transformer->transform( $lesson, ProgressOverlay::fromList( [] ) );

		self::assertFalse( $node['has_topics'] );
		self::assertSame( [], $node['topics'] );
	}

	public function test_is_preview_reflects_meta(): void {
		$lesson                                   = $this->lesson( 123, 'preview', 'P' );
		$this->meta['_vl_lesson_is_preview'][123] = '1';

		$transformer = $this->makeTransformer();
		$node        = $transformer->transform( $lesson, ProgressOverlay::fromList( [] ) );

		self::assertTrue( $node['is_preview'] );
	}

	public function test_requires_completion_reflects_meta(): void {
		$lesson = $this->lesson( 123, 'gated', 'G' );
		$this->meta['_vl_lesson_requires_completion'][123] = '1';

		$transformer = $this->makeTransformer();
		$node        = $transformer->transform( $lesson, ProgressOverlay::fromList( [] ) );

		self::assertTrue( $node['requires_completion'] );
	}

	public function test_progress_defaults_to_not_started_when_overlay_misses(): void {
		$lesson      = $this->lesson( 123, 'l', 'L' );
		$transformer = $this->makeTransformer();

		$node = $transformer->transform( $lesson, ProgressOverlay::fromList( [] ) );

		self::assertSame(
			[
				'status'           => 'not_started',
				'position_seconds' => null,
				'completed_at'     => null,
			],
			$node['progress']
		);
	}
}
