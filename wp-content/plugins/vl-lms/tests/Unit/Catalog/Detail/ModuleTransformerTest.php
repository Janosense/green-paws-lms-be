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
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $hook, mixed $value ): string => '<p>' . $value . '</p>'
		);

		$this->transformer = new ModuleTransformer( new LessonSummaryTransformer() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_emits_id_title_and_lessons(): void {
		$this->meta = [
			'_vl_lesson_duration_seconds' => [
				51 => 600,
				52 => 900,
			],
			'_vl_lesson_is_preview'       => [ 51 => true ],
		];

		$result = $this->transformer->transform(
			$this->module( 45, 'Module 1', 'Intro text' ),
			[
				$this->lesson( 51, 'Lesson 1' ),
				$this->lesson( 52, 'Lesson 2' ),
			]
		);

		self::assertSame( 45, $result['id'] );
		self::assertSame( 'Module 1', $result['title'] );
		self::assertSame( '<p>Intro text</p>', $result['content'] );
		self::assertCount( 2, $result['lessons'] );
		self::assertSame( 51, $result['lessons'][0]['id'] );
		self::assertTrue( $result['lessons'][0]['is_preview'] );
	}

	public function test_empty_lesson_list_emits_empty_array(): void {
		$result = $this->transformer->transform( $this->module( 45, 'M' ), [] );

		self::assertSame( [], $result['lessons'] );
	}

	public function test_blank_content_emits_empty_string_without_rendering(): void {
		$result = $this->transformer->transform( $this->module( 45, 'M', '   ' ), [] );

		self::assertSame( '', $result['content'] );
	}

	private function module( int $id, string $title, string $content = '' ): WP_Post {
		$post               = Mockery::mock( 'WP_Post' );
		$post->ID           = $id;
		$post->post_title   = $title;
		$post->post_content = $content;
		$post->post_type    = 'vl_module';
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
