<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Catalog\Transformers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Catalog\Transformers\CoverImageTransformer;

final class CoverImageTransformerTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private CoverImageTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transformer = new CoverImageTransformer();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_null_for_zero_attachment_id(): void {
		self::assertNull( $this->transformer->transform( 0 ) );
	}

	public function test_returns_null_when_attachment_does_not_exist(): void {
		Functions\when( 'get_post' )->justReturn( null );

		self::assertNull( $this->transformer->transform( 99 ) );
	}

	public function test_emits_all_four_sizes_when_available(): void {
		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );

		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array {
				return match ( $size ) {
					'thumbnail'    => [ 'https://t/150.jpg', 150, 150, true ],
					'medium_large' => [ 'https://t/768.jpg', 768, 432, true ],
					'vl_hero'      => [ 'https://t/hero.jpg', 1920, 720, true ],
					'full'         => [ 'https://t/full.jpg', 1920, 1080, false ],
					default        => [],
				};
			}
		);

		$cover = $this->transformer->transform( 7 );

		self::assertIsArray( $cover );
		self::assertArrayHasKey( 'thumbnail', $cover );
		self::assertArrayHasKey( 'card', $cover );
		self::assertArrayHasKey( 'hero', $cover );
		self::assertArrayHasKey( 'full', $cover );
		self::assertSame( 'https://t/768.jpg', $cover['card']['url'] );
		self::assertSame( 768, $cover['card']['width'] );
		self::assertSame( 432, $cover['card']['height'] );
		self::assertSame( 'https://t/hero.jpg', $cover['hero']['url'] );
		self::assertSame( 1920, $cover['hero']['width'] );
		self::assertSame( 720, $cover['hero']['height'] );
	}

	public function test_omits_specific_size_when_unavailable(): void {
		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );

		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array|false {
				return match ( $size ) {
					'thumbnail' => [ 'https://t/150.jpg', 150, 150, true ],
					'full'      => [ 'https://t/full.jpg', 1920, 1080, false ],
					default     => false,
				};
			}
		);

		$cover = $this->transformer->transform( 7 );

		self::assertIsArray( $cover );
		self::assertArrayHasKey( 'thumbnail', $cover );
		self::assertArrayHasKey( 'full', $cover );
		self::assertArrayNotHasKey( 'card', $cover );
		self::assertArrayNotHasKey( 'hero', $cover );
	}

	public function test_omits_hero_size_when_only_classic_sizes_exist(): void {
		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );

		Functions\when( 'wp_get_attachment_image_src' )->alias(
			static function ( int $id, string $size ): array|false {
				return match ( $size ) {
					'thumbnail'    => [ 'https://t/150.jpg', 150, 150, true ],
					'medium_large' => [ 'https://t/768.jpg', 768, 432, true ],
					'full'         => [ 'https://t/full.jpg', 1920, 1080, false ],
					default        => false,
				};
			}
		);

		$cover = $this->transformer->transform( 7 );

		self::assertIsArray( $cover );
		self::assertArrayHasKey( 'thumbnail', $cover );
		self::assertArrayHasKey( 'card', $cover );
		self::assertArrayHasKey( 'full', $cover );
		self::assertArrayNotHasKey( 'hero', $cover );
	}

	public function test_returns_null_when_no_size_resolves(): void {
		Functions\when( 'get_post' )->justReturn( Mockery::mock( 'WP_Post' ) );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );

		self::assertNull( $this->transformer->transform( 7 ) );
	}
}
