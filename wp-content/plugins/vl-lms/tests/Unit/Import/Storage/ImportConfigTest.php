<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Import\Storage;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Import\Storage\ImportConfig;

final class ImportConfigTest extends TestCase {

	use MockeryPHPUnitIntegration;

	private const UPLOAD_LIMIT = 8388608;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_max_upload_size' )->justReturn( self::UPLOAD_LIMIT );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_are_the_wordpress_upload_limit_an_hour_five_image_types_and_seventy_percent(): void {
		$config = ImportConfig::from_filters();

		self::assertSame( self::UPLOAD_LIMIT, $config->max_upload_bytes );
		self::assertSame( 3600, $config->temp_ttl );
		self::assertSame( [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ], $config->allowed_image_extensions );
		self::assertSame( 70, $config->default_pass_percent );
	}

	public function test_the_config_filter_replaces_every_value(): void {
		Filters\expectApplied( 'vl_lms/import/config' )
			->once()
			->with(
				[
					'max_upload_bytes'         => self::UPLOAD_LIMIT,
					'temp_ttl'                 => 3600,
					'allowed_image_extensions' => [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ],
					'default_pass_percent'     => 70,
				]
			)
			->andReturn(
				[
					'max_upload_bytes'         => 1048576,
					'temp_ttl'                 => 600,
					'allowed_image_extensions' => [ 'png' ],
					'default_pass_percent'     => 80,
				]
			);

		$config = ImportConfig::from_filters();

		self::assertSame( 1048576, $config->max_upload_bytes );
		self::assertSame( 600, $config->temp_ttl );
		self::assertSame( [ 'png' ], $config->allowed_image_extensions );
		self::assertSame( 80, $config->default_pass_percent );
	}

	public function test_a_key_the_filtered_config_lacks_keeps_its_default(): void {
		Filters\expectApplied( 'vl_lms/import/config' )->once()->andReturn( [ 'temp_ttl' => 600 ] );

		$config = ImportConfig::from_filters();

		self::assertSame( self::UPLOAD_LIMIT, $config->max_upload_bytes );
		self::assertSame( 600, $config->temp_ttl );
		self::assertSame( [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ], $config->allowed_image_extensions );
		self::assertSame( 70, $config->default_pass_percent );
	}

	public function test_the_per_value_filters_get_the_config_value_and_win(): void {
		Filters\expectApplied( 'vl_lms/import/config' )->once()->andReturn(
			[
				'max_upload_bytes'         => 1048576,
				'temp_ttl'                 => 600,
				'allowed_image_extensions' => [ 'png' ],
			]
		);
		Filters\expectApplied( 'vl_lms/import/max_upload_bytes' )->once()->with( 1048576 )->andReturn( 2097152 );
		Filters\expectApplied( 'vl_lms/import/temp_ttl' )->once()->with( 600 )->andReturn( 900 );
		Filters\expectApplied( 'vl_lms/import/allowed_image_extensions' )->once()->with( [ 'png' ] )->andReturn( [ 'png', 'webp' ] );

		$config = ImportConfig::from_filters();

		self::assertSame( 2097152, $config->max_upload_bytes );
		self::assertSame( 900, $config->temp_ttl );
		self::assertSame( [ 'png', 'webp' ], $config->allowed_image_extensions );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function non_positive_limits(): array {
		return [
			'zero'         => [ 0 ],
			'negative'     => [ -5 ],
			'not a number' => [ 'soon' ],
		];
	}

	/**
	 * @dataProvider non_positive_limits
	 */
	public function test_the_upload_limit_and_the_ttl_are_at_least_one( mixed $value ): void {
		Filters\expectApplied( 'vl_lms/import/max_upload_bytes' )->once()->andReturn( $value );
		Filters\expectApplied( 'vl_lms/import/temp_ttl' )->once()->andReturn( $value );

		$config = ImportConfig::from_filters();

		self::assertSame( 1, $config->max_upload_bytes );
		self::assertSame( 1, $config->temp_ttl );
	}

	/**
	 * @return array<string, array{0: int, 1: int}>
	 */
	public static function pass_percents(): array {
		return [
			'above 100' => [ 150, 100 ],
			'below 0'   => [ -5, 0 ],
			'in range'  => [ 55, 55 ],
		];
	}

	/**
	 * @dataProvider pass_percents
	 */
	public function test_the_default_pass_percent_stays_a_percentage( int $filtered, int $expected ): void {
		Filters\expectApplied( 'vl_lms/import/config' )->once()->andReturn( [ 'default_pass_percent' => $filtered ] );

		self::assertSame( $expected, ImportConfig::from_filters()->default_pass_percent );
	}

	public function test_image_extensions_are_lowercase_unique_and_without_a_dot(): void {
		Filters\expectApplied( 'vl_lms/import/allowed_image_extensions' )->once()->andReturn( [ 'PNG', '.jpg', '', 42, 'png' ] );

		self::assertSame( [ 'png', 'jpg' ], ImportConfig::from_filters()->allowed_image_extensions );
	}
}
