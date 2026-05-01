<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Sync;

use WP_Post;

/**
 * Builds the Zoom REST request body for a `vl_session` /
 * `vl_webinar` post on `POST /users/me/meetings` (CREATE) or
 * `PATCH /meetings/{id}` (UPDATE).
 *
 * Contract:
 *   - `topic` = post_title, truncated to 200 chars (Zoom limit).
 *   - `type` = 2 (scheduled meeting).
 *   - `start_time` = `_vl_*_scheduled_start` normalised to UTC
 *     `YYYY-MM-DDTHH:MM:SSZ`. Omitted entirely when the meta is absent or
 *     unparseable — {@see MeetingSynchronizer} treats that as
 *     {@see SyncReason::MISSING_REQUIRED_META}.
 *   - `duration` = `(scheduled_end - scheduled_start) / 60` minutes,
 *     defaulting to 60 when either bound is missing.
 *   - `timezone` = `wp_timezone_string()`.
 *   - `password` = `$existing_password ?? generator->generate()`. Reusing
 *     on UPDATE keeps existing join URLs valid.
 *   - `agenda` = `wp_strip_all_tags(post_content)` truncated to 2000.
 *   - `settings.*` = the documented fixed set (host_video, mute_upon_entry,
 *     audio=both, auto_recording=cloud, etc.).
 *
 * Concrete (not final) so unit tests can subclass.
 *
 * @author Tymofii Synianskyi
 */
class MeetingPayloadBuilder {

	private const int TOPIC_MAX_LENGTH = 200;

	private const int AGENDA_MAX_LENGTH = 2000;

	private const int DEFAULT_DURATION_MINUTES = 60;

	private PasswordGenerator $passwords;

	public function __construct( PasswordGenerator $passwords ) {
		$this->passwords = $passwords;
	}

	/**
	 * @param array{
	 *     scheduled_start: ?string,
	 *     scheduled_end:   ?string,
	 * } $schedule
	 *
	 * @return array<string, mixed>
	 */
	public function build( WP_Post $post, PostKind $kind, array $schedule, ?string $existing_password ): array {
		unset( $kind );

		$payload = [
			'type'     => 2,
			'topic'    => $this->truncate( (string) $post->post_title, self::TOPIC_MAX_LENGTH ),
			'duration' => $this->compute_duration_minutes( $schedule['scheduled_start'], $schedule['scheduled_end'] ),
			'timezone' => wp_timezone_string(),
			'password' => null !== $existing_password && '' !== $existing_password
				? $existing_password
				: $this->passwords->generate(),
			'agenda'   => $this->truncate(
				trim( wp_strip_all_tags( (string) $post->post_content ) ),
				self::AGENDA_MAX_LENGTH
			),
			'settings' => [
				'host_video'        => true,
				'participant_video' => false,
				'join_before_host'  => false,
				'mute_upon_entry'   => true,
				'waiting_room'      => false,
				'audio'             => 'both',
				'auto_recording'    => 'cloud',
			],
		];

		$start_iso = $this->normalise_start_time( $schedule['scheduled_start'] );
		if ( null !== $start_iso ) {
			$payload['start_time'] = $start_iso;
		}

		return $payload;
	}

	/**
	 * Deterministic fingerprint of a payload for diff detection. The same
	 * input produces the same hash — even when array key order differs —
	 * so {@see DiffDetector} can compare against the persisted hash with
	 * a single string equality check.
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function fingerprint( array $payload ): string {
		return hash( 'sha256', self::canonical_encode( $payload ) );
	}

	/**
	 * @param array<int|string, mixed> $value
	 */
	private static function canonical_encode( array $value ): string {
		$normalised = self::canonicalise( $value );
		$encoded    = wp_json_encode( $normalised );
		return false === $encoded ? '' : $encoded;
	}

	/**
	 * Recursively sort array keys so encoding is deterministic.
	 *
	 * @param array<int|string, mixed> $value
	 *
	 * @return array<int|string, mixed>
	 */
	private static function canonicalise( array $value ): array {
		ksort( $value );
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = self::canonicalise( $v );
			}
		}
		return $value;
	}

	private function truncate( string $value, int $max ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max );
		}
		return substr( $value, 0, $max );
	}

	private function normalise_start_time( ?string $raw ): ?string {
		if ( null === $raw || '' === $raw ) {
			return null;
		}
		try {
			$dt = new \DateTimeImmutable( $raw );
		} catch ( \Exception ) {
			return null;
		}
		$utc = $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $utc->format( 'Y-m-d\\TH:i:s\\Z' );
	}

	private function compute_duration_minutes( ?string $start_raw, ?string $end_raw ): int {
		if ( null === $start_raw || null === $end_raw || '' === $start_raw || '' === $end_raw ) {
			return self::DEFAULT_DURATION_MINUTES;
		}
		try {
			$start = new \DateTimeImmutable( $start_raw );
			$end   = new \DateTimeImmutable( $end_raw );
		} catch ( \Exception ) {
			return self::DEFAULT_DURATION_MINUTES;
		}

		$delta = $end->getTimestamp() - $start->getTimestamp();
		if ( $delta <= 0 ) {
			return self::DEFAULT_DURATION_MINUTES;
		}
		return (int) floor( $delta / 60 );
	}
}
