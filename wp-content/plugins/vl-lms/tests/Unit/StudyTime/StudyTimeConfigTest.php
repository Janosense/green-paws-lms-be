<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\StudyTime;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\StudyTime\StudyTimeConfig;

final class StudyTimeConfigTest extends TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_defaults_are_two_minutes_idle_thirty_second_beats_and_a_forty_five_second_cap(): void {
		$config = StudyTimeConfig::from_filters();

		self::assertSame( 120, $config->idle_seconds );
		self::assertSame( 30, $config->heartbeat_seconds );
		self::assertSame( 45, $config->cap_seconds );
	}

	public function test_each_filter_carries_its_default_and_overrides_its_own_value(): void {
		// The names are the feature's public surface
		// (`docs/features/study-time/FEATURE.md` → Interfaces): renaming one
		// silently drops a site's configuration.
		Filters\expectApplied( 'vl_lms/study_time/idle_seconds' )->once()->with( 120 )->andReturn( 300 );
		Filters\expectApplied( 'vl_lms/study_time/heartbeat_seconds' )->once()->with( 30 )->andReturn( 20 );
		Filters\expectApplied( 'vl_lms/study_time/cap_seconds' )->once()->with( 45 )->andReturn( 60 );

		$config = StudyTimeConfig::from_filters();

		self::assertSame( 300, $config->idle_seconds );
		self::assertSame( 20, $config->heartbeat_seconds );
		self::assertSame( 60, $config->cap_seconds );
	}

	public function test_numeric_strings_become_integers(): void {
		Filters\expectApplied( 'vl_lms/study_time/idle_seconds' )->once()->andReturn( '90' );
		Filters\expectApplied( 'vl_lms/study_time/heartbeat_seconds' )->once()->andReturn( '15' );
		Filters\expectApplied( 'vl_lms/study_time/cap_seconds' )->once()->andReturn( '25.7' );

		$config = StudyTimeConfig::from_filters();

		self::assertSame( 90, $config->idle_seconds );
		self::assertSame( 15, $config->heartbeat_seconds );
		self::assertSame( 25, $config->cap_seconds );
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function non_numeric_values(): array {
		return [
			'string'  => [ 'soon' ],
			'null'    => [ null ],
			'array'   => [ [ 30 ] ],
			'boolean' => [ true ],
		];
	}

	/**
	 * A duration has no meaningful zero, so a value the site cannot mean
	 * falls back to the documented default rather than to 0.
	 *
	 * @dataProvider non_numeric_values
	 */
	public function test_a_non_numeric_value_falls_back_to_its_default( mixed $value ): void {
		Filters\expectApplied( 'vl_lms/study_time/idle_seconds' )->once()->andReturn( $value );
		Filters\expectApplied( 'vl_lms/study_time/heartbeat_seconds' )->once()->andReturn( $value );
		Filters\expectApplied( 'vl_lms/study_time/cap_seconds' )->once()->andReturn( $value );

		$config = StudyTimeConfig::from_filters();

		self::assertSame( 120, $config->idle_seconds );
		self::assertSame( 30, $config->heartbeat_seconds );
		self::assertSame( 45, $config->cap_seconds );
	}

	public function test_zero_and_negative_values_floor_at_one_second(): void {
		Filters\expectApplied( 'vl_lms/study_time/idle_seconds' )->once()->andReturn( -5 );
		Filters\expectApplied( 'vl_lms/study_time/heartbeat_seconds' )->once()->andReturn( 0 );
		Filters\expectApplied( 'vl_lms/study_time/cap_seconds' )->once()->andReturn( 0 );

		$config = StudyTimeConfig::from_filters();

		self::assertSame( 1, $config->heartbeat_seconds );
		// One second of beat needs a cap above it, and the idle window is
		// never shorter than the beat.
		self::assertSame( 2, $config->cap_seconds );
		self::assertSame( 1, $config->idle_seconds );
	}

	public function test_a_heartbeat_at_or_above_the_cap_raises_the_cap(): void {
		// The cap exists so a regular beat is never clipped, so it gives way
		// — not the cadence the site deliberately set.
		Filters\expectApplied( 'vl_lms/study_time/heartbeat_seconds' )->once()->andReturn( 60 );
		Filters\expectApplied( 'vl_lms/study_time/cap_seconds' )->once()->andReturn( 45 );

		$config = StudyTimeConfig::from_filters();

		self::assertSame( 60, $config->heartbeat_seconds );
		self::assertSame( 61, $config->cap_seconds );
	}

	public function test_an_idle_window_below_the_heartbeat_is_raised_to_it(): void {
		// Otherwise a reading beat could never arrive inside the window.
		Filters\expectApplied( 'vl_lms/study_time/idle_seconds' )->once()->andReturn( 10 );

		$config = StudyTimeConfig::from_filters();

		self::assertSame( 30, $config->idle_seconds );
		self::assertSame( 30, $config->heartbeat_seconds );
	}
}
