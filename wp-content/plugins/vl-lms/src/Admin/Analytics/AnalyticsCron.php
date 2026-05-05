<?php

declare(strict_types=1);

namespace VL\LMS\Admin\Analytics;

/**
 * Phase 9.3 — nightly WP-Cron event that rolls up the previous calendar
 * day into `vl_user_activity_daily`.
 *
 * `schedule()` is called from {@see \VL\LMS\Activator::activate()} and
 * `unschedule()` from {@see \VL\LMS\Deactivator::deactivate()}. The hook
 * fires once every 24 hours; the first tick is anchored at 02:00 UTC the
 * day after activation so we don't compete with peak request load.
 *
 * Not declared `final` — unit tests subclass for hook assertions.
 *
 * @author Tymofii Synianskyi
 */
class AnalyticsCron {

	public const string HOOK_NAME = 'vl_lms_analytics_rollup';
	public const string SCHEDULE  = 'daily';

	public function __construct( private readonly AnalyticsRollupService $rollup ) {
	}

	public function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK_NAME ) ) {
			wp_schedule_event(
				(int) strtotime( 'tomorrow 02:00:00 UTC' ),
				self::SCHEDULE,
				self::HOOK_NAME
			);
		}
	}

	public function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK_NAME );
	}

	public function handle(): void {
		$yesterday = gmdate( 'Y-m-d', (int) strtotime( 'yesterday' ) );
		$this->rollup->rollup( $yesterday );
	}
}
