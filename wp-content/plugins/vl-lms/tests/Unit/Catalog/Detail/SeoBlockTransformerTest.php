<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Detail;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Detail\SeoBlockTransformer;
use VL\LMS\Catalog\PostType;
use WP_Post;

final class SeoBlockTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private SeoBlockTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'get_the_title' )->alias(
			static fn ( WP_Post $p ): string => (string) $p->post_title
		);
		Functions\when( 'wp_strip_all_tags' )->returnArg();

		$this->transformer = new SeoBlockTransformer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_title_appends_site_suffix(): void {
		$result = $this->transformer->transform(
			$this->post( 'cardiology', 'Cardiology Fundamentals' ),
			PostType::COURSE,
			'short excerpt',
			null
		);

		self::assertSame( 'Cardiology Fundamentals | Green Paws LMS', $result['title'] );
	}

	public function test_canonical_path_for_course(): void {
		$result = $this->transformer->transform(
			$this->post( 'cardiology', 'Cardiology' ),
			PostType::COURSE,
			'',
			null
		);

		self::assertSame( '/courses/cardiology', $result['canonical_path'] );
	}

	public function test_canonical_path_for_webinar(): void {
		$result = $this->transformer->transform(
			$this->post( 'spring-q-and-a', 'Spring Q&A' ),
			PostType::WEBINAR,
			'',
			null
		);

		self::assertSame( '/webinars/spring-q-and-a', $result['canonical_path'] );
	}

	public function test_short_description_passes_through_unchanged(): void {
		$result = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'A short description.',
			null
		);

		self::assertSame( 'A short description.', $result['description'] );
	}

	public function test_long_description_truncates_at_word_boundary_under_160_chars(): void {
		$excerpt = str_repeat( 'word ', 60 ); // 300 chars of "word "
		$result  = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			$excerpt,
			null
		);

		// The truncated string must be <= 160 chars (excluding the ellipsis)
		// and must end at a word boundary, not mid-word.
		self::assertLessThanOrEqual( 161, mb_strlen( $result['description'] ) );
		self::assertStringEndsWith( '…', $result['description'] );
		self::assertStringStartsWith( 'word ', $result['description'] );
	}

	public function test_empty_excerpt_returns_empty_description(): void {
		$result = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'',
			null
		);

		self::assertSame( '', $result['description'] );
	}

	public function test_og_image_prefers_hero_size(): void {
		$cover = [
			'thumbnail' => [
				'url'    => 'https://t/150.jpg',
				'width'  => 150,
				'height' => 150,
			],
			'card'      => [
				'url'    => 'https://t/768.jpg',
				'width'  => 768,
				'height' => 432,
			],
			'hero'      => [
				'url'    => 'https://t/hero.jpg',
				'width'  => 1920,
				'height' => 720,
			],
			'full'      => [
				'url'    => 'https://t/full.jpg',
				'width'  => 1920,
				'height' => 1080,
			],
		];

		$result = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'',
			$cover
		);

		self::assertSame( 'https://t/hero.jpg', $result['og_image'] );
	}

	public function test_og_image_falls_back_from_hero_to_full_to_card(): void {
		$cover_full_only = [
			'card' => [
				'url'    => 'https://t/768.jpg',
				'width'  => 768,
				'height' => 432,
			],
			'full' => [
				'url'    => 'https://t/full.jpg',
				'width'  => 1920,
				'height' => 1080,
			],
		];
		$result          = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'',
			$cover_full_only
		);
		self::assertSame( 'https://t/full.jpg', $result['og_image'] );

		$cover_card_only = [
			'thumbnail' => [
				'url'    => 'https://t/150.jpg',
				'width'  => 150,
				'height' => 150,
			],
			'card'      => [
				'url'    => 'https://t/768.jpg',
				'width'  => 768,
				'height' => 432,
			],
		];
		$result          = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'',
			$cover_card_only
		);
		self::assertSame( 'https://t/768.jpg', $result['og_image'] );
	}

	public function test_og_image_returns_null_when_no_cover(): void {
		$result = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'',
			null
		);

		self::assertNull( $result['og_image'] );
	}

	public function test_og_image_returns_null_when_only_thumbnail_present(): void {
		$cover = [
			'thumbnail' => [
				'url'    => 'https://t/150.jpg',
				'width'  => 150,
				'height' => 150,
			],
		];

		$result = $this->transformer->transform(
			$this->post( 'a', 'A' ),
			PostType::COURSE,
			'',
			$cover
		);

		self::assertNull( $result['og_image'] );
	}

	private function post( string $slug, string $title ): WP_Post {
		$post             = Mockery::mock( 'WP_Post' );
		$post->ID         = 1;
		$post->post_name  = $slug;
		$post->post_title = $title;
		return $post;
	}
}
