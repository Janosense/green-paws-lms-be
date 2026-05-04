<?php

declare(strict_types=1);

namespace VL\LMS\Services\Notifications;

use Closure;
use VL\LMS\Services\Zoom\Sync\PostKind;
use VL\LMS\Support\Logger;
use WP_Post;

/**
 * Phase 7.6 — schedules WP-Cron events at `t-24h` and `t-1h` for every
 * `vl_session` and `vl_webinar` post on save.
 *
 * Hooks `save_post_vl_session` and `save_post_vl_webinar` at priority 30
 * (after `MeetingSynchronizer`'s priority 20) so a fresh meeting ID is
 * already in post-meta by the time we look at the row. The scheduler
 * itself never reads Zoom state — it only consumes the
 * `_vl_*_scheduled_start` and status meta fields.
 *
 * The cron action name is `vl_lms_send_reminder`, with a single positional
 * argument vector `[post_id, kind, variant]`. WP-Cron uses the args
 * vector for natural deduplication: re-scheduling the same triple is a
 * no-op as long as the planned timestamp matches. When the scheduled
 * start moves, we unschedule the previous events keyed by their old args
 * and reschedule fresh ones for the new times.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class ReminderScheduler {

	public const string CRON_HOOK = 'vl_lms_send_reminder';

	// Inline literals rather than `HOUR_IN_SECONDS` so this file is safe to
	// `require_once` from PHPUnit fixtures that don't preload WP constants.
	private const int OFFSET_24H = 86400; // 24 * 60 * 60
	private const int OFFSET_1H  = 3600;

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly Logger $logger,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	public function register(): void {
		add_action( 'save_post_vl_session', [ $this, 'on_save_session' ], 30, 2 );
		add_action( 'save_post_vl_webinar', [ $this, 'on_save_webinar' ], 30, 2 );
	}

	public function on_save_session( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			$this->unschedule_all( $post_id, PostKind::SESSION );
			return;
		}
		$this->schedule_for_post( $post_id, PostKind::SESSION );
	}

	public function on_save_webinar( int $post_id, WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			$this->unschedule_all( $post_id, PostKind::WEBINAR );
			return;
		}
		$this->schedule_for_post( $post_id, PostKind::WEBINAR );
	}

	public function schedule_for_post( int $post_id, PostKind $kind ): void {
		$start_raw = (string) get_post_meta( $post_id, $kind->meta_key_scheduled_start(), true );
		$status    = (string) get_post_meta( $post_id, $kind->meta_key_status(), true );

		if ( '' === $start_raw || 'cancelled' === $status ) {
			$this->unschedule_all( $post_id, $kind );
			return;
		}

		try {
			$start = new \DateTimeImmutable( $start_raw );
		} catch ( \Throwable ) {
			$this->logger->warning(
				'ReminderScheduler: unparseable scheduled_start; skipping.',
				[
					'post_id'   => $post_id,
					'kind'      => $kind->value,
					'start_raw' => $start_raw,
				]
			);
			$this->unschedule_all( $post_id, $kind );
			return;
		}

		$now = ( $this->clock )();
		$this->reschedule_variant( $post_id, $kind, '24h', $start->getTimestamp() - self::OFFSET_24H, $now );
		$this->reschedule_variant( $post_id, $kind, '1h', $start->getTimestamp() - self::OFFSET_1H, $now );
	}

	private function reschedule_variant(
		int $post_id,
		PostKind $kind,
		string $variant,
		int $target_ts,
		\DateTimeImmutable $now
	): void {
		$args = [ $post_id, $kind->value, $variant ];

		// Always remove the existing single-shot for this triple before
		// re-planning. WP_Cron keys event identity on (hook, args), so
		// any prior scheduled-start is matched by the same args triple.
		$prior = wp_next_scheduled( self::CRON_HOOK, $args );
		if ( false !== $prior ) {
			wp_unschedule_event( $prior, self::CRON_HOOK, $args );
		}

		if ( $target_ts <= $now->getTimestamp() ) {
			return;
		}

		$result = wp_schedule_single_event( $target_ts, self::CRON_HOOK, $args );
		if ( false === $result ) {
			$this->logger->warning(
				'ReminderScheduler: wp_schedule_single_event returned false.',
				[
					'post_id'   => $post_id,
					'kind'      => $kind->value,
					'variant'   => $variant,
					'target_ts' => $target_ts,
				]
			);
		}
	}

	private function unschedule_all( int $post_id, PostKind $kind ): void {
		foreach ( [ '24h', '1h' ] as $variant ) {
			$args  = [ $post_id, $kind->value, $variant ];
			$prior = wp_next_scheduled( self::CRON_HOOK, $args );
			if ( false !== $prior ) {
				wp_unschedule_event( $prior, self::CRON_HOOK, $args );
			}
		}
	}
}
