<?php

declare(strict_types=1);

namespace VL\LMS\Services\Zoom\Webhook;

/**
 * Transient-backed cache of open webinar joins, used to compute
 * `(participant_left - participant_joined)` duration so the
 * `participant_left` handler can call
 * {@see \VL\LMS\Repositories\WebinarRegistrationRepository::mark_attended()}.
 *
 * Webinars (unlike sessions) don't carry a per-join row — only an
 * aggregate `attended_duration_seconds` counter on the registration
 * row, so we keep the open join in a 24-hour transient keyed on
 * `(webinar_id, participant_uuid)`.
 *
 * Caveat: object-cache backends (Redis / Memcached) may evict transients
 * before TTL under memory pressure, in which case the matching
 * `participant_left` becomes a no-op. Acceptable for Phase 7.
 *
 * Concrete (not final) so unit tests can subclass and override the
 * three transient seams without touching the WP transient API.
 *
 * @author Tymofii Synianskyi
 */
class WebinarJoinTracker {

	private const string KEY_PREFIX = 'vl_lms_zoom_webinar_join_';

	private const int TTL_SECONDS = 86400;

	public function record_open( int $webinar_id, string $participant_uuid, \DateTimeImmutable $joined_at ): void {
		$this->write_transient(
			$this->key( $webinar_id, $participant_uuid ),
			$joined_at->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			self::TTL_SECONDS
		);
	}

	/**
	 * Returns duration in seconds, or `null` if no matching open join was
	 * found (transient missing, expired, or malformed).
	 */
	public function consume( int $webinar_id, string $participant_uuid, \DateTimeImmutable $left_at ): ?int {
		$key = $this->key( $webinar_id, $participant_uuid );

		$raw = $this->read_transient( $key );
		if ( null === $raw ) {
			return null;
		}

		$this->clear_transient( $key );

		try {
			$joined_at = new \DateTimeImmutable( $raw );
		} catch ( \Throwable ) {
			return null;
		}

		$delta = $left_at->getTimestamp() - $joined_at->getTimestamp();
		if ( $delta < 0 ) {
			return 0;
		}
		return $delta;
	}

	private function key( int $webinar_id, string $participant_uuid ): string {
		return self::KEY_PREFIX . $webinar_id . '_' . $participant_uuid;
	}

	protected function read_transient( string $key ): ?string {
		$value = get_transient( $key );
		if ( false === $value ) {
			return null;
		}
		return is_string( $value ) ? $value : null;
	}

	protected function write_transient( string $key, string $value, int $ttl_seconds ): void {
		set_transient( $key, $value, $ttl_seconds );
	}

	protected function clear_transient( string $key ): void {
		delete_transient( $key );
	}
}
