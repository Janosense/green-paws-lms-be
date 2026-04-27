<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\LessonSummaryTransformer;
use WP_Post;

final class LessonSummaryTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private LessonSummaryTransformer $transformer;

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

		$this->transformer = new LessonSummaryTransformer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_exactly_four_keys(): void {
		$summary = $this->transformer->transform( $this->lesson( 51, 'Lesson 1' ) );

		self::assertSame(
			[ 'id', 'title', 'duration_seconds', 'is_preview' ],
			array_keys( $summary )
		);
	}

	public function test_does_not_leak_video_url_or_content(): void {
		$this->meta = [
			'_vl_lesson_video_url'        => [ 51 => 'https://leak.test/video.mp4' ],
			'_vl_lesson_duration_seconds' => [ 51 => 600 ],
		];

		$summary = $this->transformer->transform( $this->lesson( 51, 'Lesson 1' ) );

		self::assertArrayNotHasKey( '_vl_lesson_video_url', $summary );
		self::assertArrayNotHasKey( 'video_url', $summary );
		self::assertArrayNotHasKey( 'content', $summary );
		self::assertArrayNotHasKey( 'attachments', $summary );
	}

	public function test_is_preview_reflects_meta(): void {
		$this->meta = [
			'_vl_lesson_is_preview'       => [
				51 => true,
				52 => false,
				53 => '0',
			],
			'_vl_lesson_duration_seconds' => [
				51 => 60,
				52 => 60,
				53 => 60,
			],
		];

		self::assertTrue( $this->transformer->transform( $this->lesson( 51, 'P' ) )['is_preview'] );
		self::assertFalse( $this->transformer->transform( $this->lesson( 52, 'NP' ) )['is_preview'] );
		self::assertFalse( $this->transformer->transform( $this->lesson( 53, 'NP2' ) )['is_preview'] );
	}

	public function test_duration_seconds_coerces_to_int(): void {
		$this->meta = [
			'_vl_lesson_duration_seconds' => [ 51 => '600' ],
		];

		self::assertSame( 600, $this->transformer->transform( $this->lesson( 51, 'L' ) )['duration_seconds'] );
	}

	private function lesson( int $id, string $title ): WP_Post {
		$post              = Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_title  = $title;
		$post->post_type   = 'vl_lesson';
		$post->post_status = 'publish';
		$post->post_parent = 0;
		return $post;
	}
}
