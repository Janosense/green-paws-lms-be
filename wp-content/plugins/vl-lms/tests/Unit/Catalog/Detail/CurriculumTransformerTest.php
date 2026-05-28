<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\CurriculumTransformer;
use VL\LMS\Catalog\Detail\LessonSummaryTransformer;
use VL\LMS\Catalog\Detail\ModuleTransformer;
use VL\LMS\Catalog\Detail\PostFinder;
use VL\LMS\Tests\Unit\Catalog\Detail\Support\FakePostFinder;
use WP_Post;

final class CurriculumTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private FakePostFinder $finder;

	private CurriculumTransformer $transformer;

	/** @var array<string, array<int, mixed>> */
	private array $meta = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'get_post_meta' )->alias(
			fn ( int $post_id, string $key ): mixed => $this->meta[ $key ][ $post_id ] ?? ''
		);

		$this->finder = new FakePostFinder();

		$lesson_summary    = new LessonSummaryTransformer();
		$this->transformer = new CurriculumTransformer(
			new ModuleTransformer( $lesson_summary ),
			$lesson_summary,
			$this->finder,
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_course_with_modules_and_lessons_inside_modules(): void {
		$module1 = $this->module( 45, 'Module 1', 100 );
		$module2 = $this->module( 46, 'Module 2', 100 );

		$lesson_a = $this->lesson( 51, 'Lesson A', 45 );
		$lesson_b = $this->lesson( 52, 'Lesson B', 45 );
		$lesson_c = $this->lesson( 53, 'Lesson C', 46 );

		$this->finder
			->stub(
				static fn ( array $args ): bool => 'vl_module' === $args['post_type'] && 100 === $args['post_parent'],
				[ $module1, $module2 ]
			)
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent__in'] ),
				[ $lesson_a, $lesson_b, $lesson_c ]
			)
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent'] ) && 100 === $args['post_parent'],
				[]
			);

		$result = $this->transformer->transform( 100 );

		self::assertCount( 2, $result['modules'] );
		self::assertSame( 45, $result['modules'][0]['id'] );
		self::assertSame( 46, $result['modules'][1]['id'] );
		self::assertCount( 2, $result['modules'][0]['lessons'] );
		self::assertCount( 1, $result['modules'][1]['lessons'] );
		self::assertSame( [], $result['orphan_lessons'] );
	}

	public function test_module_less_course_returns_orphan_lessons_only(): void {
		$lesson_a = $this->lesson( 51, 'Orphan A', 100 );
		$lesson_b = $this->lesson( 52, 'Orphan B', 100 );

		$this->finder
			->stub( static fn ( array $args ): bool => 'vl_module' === $args['post_type'], [] )
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent'] ),
				[ $lesson_a, $lesson_b ]
			);

		$result = $this->transformer->transform( 100 );

		self::assertSame( [], $result['modules'] );
		self::assertCount( 2, $result['orphan_lessons'] );
		self::assertSame( 51, $result['orphan_lessons'][0]['id'] );
	}

	public function test_mixed_course_with_modules_and_orphan_lessons(): void {
		$module1  = $this->module( 45, 'Module 1', 100 );
		$lesson_a = $this->lesson( 51, 'A', 45 );
		$orphan   = $this->lesson( 60, 'Orphan', 100 );

		$this->finder
			->stub( static fn ( array $args ): bool => 'vl_module' === $args['post_type'], [ $module1 ] )
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent__in'] ),
				[ $lesson_a ]
			)
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent'] ),
				[ $orphan ]
			);

		$result = $this->transformer->transform( 100 );

		self::assertCount( 1, $result['modules'] );
		self::assertCount( 1, $result['modules'][0]['lessons'] );
		self::assertCount( 1, $result['orphan_lessons'] );
		self::assertSame( 60, $result['orphan_lessons'][0]['id'] );
	}

	public function test_empty_course_emits_both_arrays_empty(): void {
		$this->finder
			->stub( static fn ( array $args ): bool => true, [] );

		$result = $this->transformer->transform( 100 );

		self::assertSame( [], $result['modules'] );
		self::assertSame( [], $result['orphan_lessons'] );
	}

	public function test_lessons_in_modules_query_skipped_when_no_modules(): void {
		// If no modules exist, the curriculum transformer must NOT issue
		// a `post_parent__in` lessons query — that protects against an
		// unintentional N+1 regression.
		$this->finder
			->stub( static fn ( array $args ): bool => 'vl_module' === $args['post_type'], [] )
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent'] ),
				[]
			);

		$this->transformer->transform( 100 );

		self::assertSame( 0, $this->finder->call_count_matching( static fn ( array $a ): bool => isset( $a['post_parent__in'] ) ) );
	}

	public function test_module_query_orders_by_menu_order_then_id(): void {
		$this->finder
			->stub( static fn ( array $args ): bool => true, [] );

		$this->transformer->transform( 100 );

		$calls = $this->finder->calls();
		self::assertSame(
			[
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
			],
			$calls[0]['args']['orderby']
		);
	}

	public function test_lessons_return_only_four_keys_no_content_leak(): void {
		$lesson = $this->lesson( 51, 'L', 45 );
		$module = $this->module( 45, 'M', 100 );

		$this->meta = [
			'_vl_lesson_video_url'        => [ 51 => 'https://leak.test/video.mp4' ],
			'_vl_lesson_duration_seconds' => [ 51 => 600 ],
		];

		$this->finder
			->stub( static fn ( array $args ): bool => 'vl_module' === $args['post_type'], [ $module ] )
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent__in'] ),
				[ $lesson ]
			)
			->stub(
				static fn ( array $args ): bool => 'vl_lesson' === $args['post_type'] && isset( $args['post_parent'] ),
				[]
			);

		$result = $this->transformer->transform( 100 );

		self::assertSame(
			[ 'id', 'title', 'duration_seconds', 'is_preview' ],
			array_keys( $result['modules'][0]['lessons'][0] )
		);
	}

	private function module( int $id, string $title, int $parent ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_title   = $title;
		$post->post_content = '';
		$post->post_type    = 'vl_module';
		$post->post_status  = 'publish';
		$post->post_parent  = $parent;
		$post->menu_order   = 0;
		return $post;
	}

	private function lesson( int $id, string $title, int $parent ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_title  = $title;
		$post->post_type   = 'vl_lesson';
		$post->post_status = 'publish';
		$post->post_parent = $parent;
		$post->menu_order  = 0;
		return $post;
	}
}
