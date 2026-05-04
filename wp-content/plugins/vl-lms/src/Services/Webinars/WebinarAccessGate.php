<?php

declare(strict_types=1);

namespace VL\LMS\Services\Webinars;

use Closure;
use VL\LMS\Repositories\WebinarRegistrationRepository;
use WP_Post;

/**
 * Best-effort gate over a webinar's `join` and `recording` redirect URLs.
 *
 * Each rule is a guard clause; the first failing rule denies. Time-window
 * boundaries use named constants on this class so the test suite can pin
 * them — `JOIN_EARLY_GRACE_SECONDS` mirrors Zoom's "join up to 15 minutes
 * early" convention; `JOIN_LATE_GRACE_SECONDS` covers run-overs.
 *
 * The gate consults the {@see WebinarRegistrationRepository} for the
 * "active registration" predicate; it does NOT consult the post's
 * Zoom-side state — the Phase 7.1 synchronizer is the source of truth for
 * `_vl_webinar_zoom_join_url` and the Phase 7.2 recording handler for
 * `_vl_webinar_recording_url`.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class WebinarAccessGate {

	public const int JOIN_EARLY_GRACE_SECONDS = 15 * 60;
	public const int JOIN_LATE_GRACE_SECONDS  = 60 * 60;

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly WebinarRegistrationRepository $registrations,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	public function can_join( WP_Post $webinar, int $user_id ): WebinarAccessDecision {
		$registration = $this->registrations->find_active( (int) $webinar->ID, $user_id );
		if ( null === $registration ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED );
		}

		$join_url = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_zoom_join_url', true );
		if ( '' === $join_url ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::MEETING_NOT_PROVISIONED );
		}

		$start_raw = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_scheduled_start', true );
		$end_raw   = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_scheduled_end', true );
		$start     = $this->parse_iso8601( $start_raw );
		$end       = $this->parse_iso8601( $end_raw );
		if ( null === $start || null === $end ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::MEETING_NOT_PROVISIONED );
		}

		$opens_at  = $start->modify( '-' . self::JOIN_EARLY_GRACE_SECONDS . ' seconds' );
		$closes_at = $end->modify( '+' . self::JOIN_LATE_GRACE_SECONDS . ' seconds' );
		$now       = ( $this->clock )();

		if ( $now < $opens_at ) {
			return WebinarAccessDecision::deny(
				WebinarAccessReason::JOIN_WINDOW_NOT_OPEN,
				[ 'opens_at' => $opens_at->format( \DateTimeInterface::ATOM ) ]
			);
		}
		if ( $now > $closes_at ) {
			return WebinarAccessDecision::deny(
				WebinarAccessReason::JOIN_WINDOW_CLOSED,
				[ 'closed_at' => $closes_at->format( \DateTimeInterface::ATOM ) ]
			);
		}

		return WebinarAccessDecision::allow( $join_url );
	}

	public function can_view_recording( WP_Post $webinar, int $user_id ): WebinarAccessDecision {
		$registration = $this->registrations->find_active( (int) $webinar->ID, $user_id );
		if ( null === $registration ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::NOT_REGISTERED );
		}

		$recording_url = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_recording_url', true );
		if ( '' === $recording_url ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::RECORDING_NOT_AVAILABLE );
		}

		$until_raw = (string) get_post_meta( (int) $webinar->ID, '_vl_webinar_recording_available_until', true );
		if ( '' === $until_raw ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::RECORDING_NOT_AVAILABLE );
		}

		$until = $this->parse_iso8601( $until_raw );
		if ( null === $until ) {
			return WebinarAccessDecision::deny( WebinarAccessReason::RECORDING_NOT_AVAILABLE );
		}

		$now = ( $this->clock )();
		if ( $now > $until ) {
			return WebinarAccessDecision::deny(
				WebinarAccessReason::RECORDING_WINDOW_EXPIRED,
				[ 'expired_at' => $until->format( \DateTimeInterface::ATOM ) ]
			);
		}

		return WebinarAccessDecision::allow( $recording_url );
	}

	private function parse_iso8601( string $value ): ?\DateTimeImmutable {
		if ( '' === $value ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $value );
		} catch ( \Throwable ) {
			return null;
		}
	}
}
