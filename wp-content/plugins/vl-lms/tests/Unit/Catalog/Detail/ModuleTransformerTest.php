<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\LessonSummaryTransformer;
use VL\LMS\Catalog\Detail\ModuleTransformer;
use WP_Post;

final class ModuleTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private ModuleTransformer $transformer;

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

		$this->transformer = new ModuleTransformer( new LessonSummaryTransformer() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_emits_meta_fields_and_lessons(): void {
		$this->meta = [
			'_vl_module_duration_minutes'  => [ 45 => 180 ],
			'_vl_module_passing_threshold' => [ 45 => 70 ],
			'_vl_module_intro_video_url'   => [ 45 => 'https://intro.test/video' ],
			'_vl_lesson_duration_seconds'  => [
				51 => 600,
				52 => 900,
			],
			'_vl_lesson_is_preview'        => [ 51 => true ],
		];

		$result = $this->transformer->transform(
			$this->module( 45, 'Module 1' ),
			[
				$this->lesson( 51, 'Lesson 1' ),
				$this->lesson( 52, 'Lesson 2' ),
			]
		);

		self::assertSame( 45, $result['id'] );
		self::assertSame( 'Module 1', $result['title'] );
		self::assertSame( 180, $result['duration_minutes'] );
		self::assertSame( 70, $result['passing_threshold'] );
		self::assertSame( 'https://intro.test/video', $result['intro_video_url'] );
		self::assertCount( 2, $result['lessons'] );
		self::assertSame( 51, $result['lessons'][0]['id'] );
		self::assertTrue( $result['lessons'][0]['is_preview'] );
	}

	public function test_zero_passing_threshold_emits_null(): void {
		$this->meta = [
			'_vl_module_passing_threshold' => [ 45 => 0 ],
		];

		$result = $this->transformer->transform( $this->module( 45, 'M' ), [] );

		self::assertNull( $result['passing_threshold'] );
	}

	public function test_positive_passing_threshold_passes_through(): void {
		$this->meta = [
			'_vl_module_passing_threshold' => [ 45 => 80 ],
		];

		$result = $this->transformer->transform( $this->module( 45, 'M' ), [] );

		self::assertSame( 80, $result['passing_threshold'] );
	}

	public function test_empty_lesson_list_emits_empty_array(): void {
		$result = $this->transformer->transform( $this->module( 45, 'M' ), [] );

		self::assertSame( [], $result['lessons'] );
	}

	private function module( int $id, string $title ): WP_Post {
		$post             = Mockery::mock( 'WP_Post' );
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_type  = 'vl_module';
		return $post;
	}

	private function lesson( int $id, string $title ): WP_Post {
		$post             = Mockery::mock( 'WP_Post' );
		$post->ID         = $id;
		$post->post_title = $title;
		$post->post_type  = 'vl_lesson';
		return $post;
	}
}
