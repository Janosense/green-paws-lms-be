<?php

declare(strict_types=1);

namespace VL\LMS\Tests\Unit\Admin\Analytics;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use VL\LMS\Admin\Analytics\AnalyticsCron;
use VL\LMS\Admin\Analytics\AnalyticsRollupService;

final class AnalyticsCronTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var list<array{0: int, 1: string, 2: string}> */
	private array $schedule_calls = [];

	/** @var list<string> */
	private array $clear_calls = [];

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->schedule_calls = [];
		$this->clear_calls    = [];

		$schedule = &$this->schedule_calls;
		$clear    = &$this->clear_calls;

		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( int $timestamp, string $recurrence, string $hook ) use ( &$schedule ): bool {
				$schedule[] = [ $timestamp, $recurrence, $hook ];
				return true;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( string $hook ) use ( &$clear ): int {
				$clear[] = $hook;
				return 1;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_schedule_registers_daily_event(): void {
		$cron = new AnalyticsCron( Mockery::mock( AnalyticsRollupService::class ) );

		$cron->schedule();

		self::assertCount( 1, $this->schedule_calls );
		self::assertSame( 'daily', $this->schedule_calls[0][1] );
		self::assertSame( 'vl_lms_analytics_rollup', $this->schedule_calls[0][2] );
	}

	public function test_unschedule_clears_hook(): void {
		$cron = new AnalyticsCron( Mockery::mock( AnalyticsRollupService::class ) );

		$cron->unschedule();

		self::assertSame( [ 'vl_lms_analytics_rollup' ], $this->clear_calls );
	}

	public function test_schedule_is_idempotent_when_already_scheduled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );

		$cron = new AnalyticsCron( Mockery::mock( AnalyticsRollupService::class ) );
		$cron->schedule();

		self::assertSame( [], $this->schedule_calls );
	}
}
