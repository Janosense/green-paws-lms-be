<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Support\HeroImageSize;

final class HeroImageSizeTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_calls_add_image_size_with_documented_args(): void {
		$captured = null;

		Functions\expect( 'add_image_size' )
			->once()
			->andReturnUsing(
				static function (
					string $name,
					int $width,
					int $height,
					bool $crop
				) use ( &$captured ): void {
					$captured = compact( 'name', 'width', 'height', 'crop' );
				}
			);

		( new HeroImageSize() )->register();

		self::assertSame(
			[
				'name'   => 'vl_hero',
				'width'  => 1920,
				'height' => 720,
				'crop'   => true,
			],
			$captured
		);
	}

	public function test_constants_match_documented_dimensions(): void {
		self::assertSame( 'vl_hero', HeroImageSize::SIZE_NAME );
		self::assertSame( 1920, HeroImageSize::WIDTH );
		self::assertSame( 720, HeroImageSize::HEIGHT );
	}
}
