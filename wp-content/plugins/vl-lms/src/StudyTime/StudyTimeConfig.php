<?php

declare(strict_types=1);

namespace VL\LMS\StudyTime;

/**
 * The study-time feature's business values — how often the client sends a
 * heartbeat, how long a learner may stay idle before reading stops counting,
 * and the ceiling one signal may add. They are configuration, read from the
 * `vl_lms/study_time/*` filters, never literals in the controller or the
 * composable (`docs/DECISIONS.md` 2026-09-15 — filters with defaults; root
 * `CLAUDE.md` core rule 3).
 *
 * The server is the source of these values: `GET /vl/v1/study-time/config`
 * serves them to the client, and the cap is enforced server-side whatever the
 * client believes.
 *
 * @author Tymofii Synianskyi
 */
final readonly class StudyTimeConfig {

	public const int DEFAULT_IDLE_SECONDS = 120;

	public const int DEFAULT_HEARTBEAT_SECONDS = 30;

	public const int DEFAULT_CAP_SECONDS = 45;

	/**
	 * @param int $idle_seconds      Reading accrues only while the last interaction is no older than this.
	 * @param int $heartbeat_seconds How often the client decides whether to send a signal.
	 * @param int $cap_seconds       The most one signal may add, whatever the elapsed time says.
	 */
	public function __construct(
		public int $idle_seconds,
		public int $heartbeat_seconds,
		public int $cap_seconds
	) {
	}

	/**
	 * Reads the three filters and normalises them, so a theme cannot produce
	 * a configuration the heartbeat rules contradict.
	 *
	 * A value that is not numeric falls back to its default — every one of
	 * the three is a duration, so there is no meaningful zero to fall back
	 * to. Then each value is at least one second, the cap stays above the
	 * heartbeat interval (the cap exists so a regular beat is never clipped,
	 * so it gives way rather than the cadence someone chose), and the idle
	 * window is never shorter than that interval.
	 */
	public static function from_filters(): self {
		/**
		 * Filters how long a learner may go without interacting before
		 * reading stops accruing, in seconds.
		 *
		 * @param mixed $seconds Default two minutes.
		 */
		$idle = apply_filters( 'vl_lms/study_time/idle_seconds', self::DEFAULT_IDLE_SECONDS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The study-time filter names are fixed in FEATURE.md → Interfaces.

		/**
		 * Filters how often the client decides whether to send a heartbeat,
		 * in seconds.
		 *
		 * @param mixed $seconds Default thirty seconds.
		 */
		$heartbeat = apply_filters( 'vl_lms/study_time/heartbeat_seconds', self::DEFAULT_HEARTBEAT_SECONDS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The study-time filter names are fixed in FEATURE.md → Interfaces.

		/**
		 * Filters the most one heartbeat may add to the ledger, in seconds.
		 *
		 * @param mixed $seconds Default forty-five seconds.
		 */
		$cap = apply_filters( 'vl_lms/study_time/cap_seconds', self::DEFAULT_CAP_SECONDS ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- The study-time filter names are fixed in FEATURE.md → Interfaces.

		$heartbeat_seconds = max( 1, self::to_int( $heartbeat, self::DEFAULT_HEARTBEAT_SECONDS ) );
		$cap_seconds       = max( 1, self::to_int( $cap, self::DEFAULT_CAP_SECONDS ) );
		$idle_seconds      = max( 1, self::to_int( $idle, self::DEFAULT_IDLE_SECONDS ) );

		return new self(
			max( $idle_seconds, $heartbeat_seconds ),
			$heartbeat_seconds,
			max( $cap_seconds, $heartbeat_seconds + 1 )
		);
	}

	private static function to_int( mixed $value, int $fallback ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}
}
