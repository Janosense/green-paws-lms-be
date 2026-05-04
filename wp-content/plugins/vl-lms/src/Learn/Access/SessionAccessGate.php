<?php

declare(strict_types=1);

namespace VL\LMS\Learn\Access;

use Closure;
use VL\LMS\Services\Enrollment\EnrollmentService;
use VL\LMS\Services\JoinWindowPolicy;
use WP_Post;

/**
 * Best-effort gate over a cohort session's `join` and `recording`
 * redirect URLs.
 *
 * Differs from `Services/Webinars/WebinarAccessGate` (Phase 7.3) in two
 * ways:
 *
 * 1. Access is keyed on **course enrollment** (`EnrollmentService::has_active_access`)
 *    rather than per-resource registration.
 * 2. The recording window uses an **instructor-controlled**
 *    `_vl_session_recording_available_until` meta — when empty, the
 *    recording stays available indefinitely while course access lasts.
 *    (Webinars use an auto-managed expiry computed by the recording
 *    webhook handler.)
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class SessionAccessGate {

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly EnrollmentService $enrollments,
		private readonly JoinWindowPolicy $window_policy,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	public function can_join( WP_Post $session, int $user_id ): SessionAccessDecision {
		$course_id = $this->resolve_course_id( $session );
		if ( null === $course_id ) {
			return SessionAccessDecision::deny( SessionAccessReason::COURSE_NOT_FOUND );
		}
		if ( ! $this->enrollments->has_active_access( $user_id, $course_id ) ) {
			return SessionAccessDecision::deny( SessionAccessReason::NOT_ENROLLED );
		}

		$status = (string) get_post_meta( (int) $session->ID, '_vl_session_status', true );
		if ( 'cancelled' === $status ) {
			return SessionAccessDecision::deny( SessionAccessReason::SESSION_CANCELLED );
		}

		$join_url = (string) get_post_meta( (int) $session->ID, '_vl_session_zoom_join_url', true );
		if ( '' === $join_url ) {
			return SessionAccessDecision::deny( SessionAccessReason::MEETING_NOT_PROVISIONED );
		}

		$start = $this->parse_iso8601( (string) get_post_meta( (int) $session->ID, '_vl_session_scheduled_start', true ) );
		$end   = $this->parse_iso8601( (string) get_post_meta( (int) $session->ID, '_vl_session_scheduled_end', true ) );
		if ( null === $start || null === $end ) {
			return SessionAccessDecision::deny( SessionAccessReason::MEETING_NOT_PROVISIONED );
		}

		[ $opens_at, $closes_at ] = $this->window_policy->compute_window( $start, $end );
		$now                      = ( $this->clock )();

		if ( $now < $opens_at ) {
			return SessionAccessDecision::deny(
				SessionAccessReason::JOIN_WINDOW_NOT_OPEN,
				[ 'opens_at' => $opens_at->format( \DateTimeInterface::ATOM ) ]
			);
		}
		if ( $now > $closes_at ) {
			return SessionAccessDecision::deny(
				SessionAccessReason::JOIN_WINDOW_CLOSED,
				[ 'closed_at' => $closes_at->format( \DateTimeInterface::ATOM ) ]
			);
		}
		return SessionAccessDecision::allow( $join_url );
	}

	public function can_view_recording( WP_Post $session, int $user_id ): SessionAccessDecision {
		$course_id = $this->resolve_course_id( $session );
		if ( null === $course_id ) {
			return SessionAccessDecision::deny( SessionAccessReason::COURSE_NOT_FOUND );
		}
		if ( ! $this->enrollments->has_active_access( $user_id, $course_id ) ) {
			return SessionAccessDecision::deny( SessionAccessReason::NOT_ENROLLED );
		}

		$recording_url = (string) get_post_meta( (int) $session->ID, '_vl_session_recording_url', true );
		if ( '' === $recording_url ) {
			return SessionAccessDecision::deny( SessionAccessReason::RECORDING_NOT_AVAILABLE );
		}

		// Empty until-date = no expiry (cohort recordings are part of the
		// course, not a 30-day rental). Only enforce when the instructor has
		// explicitly set a window.
		$until_raw = (string) get_post_meta( (int) $session->ID, '_vl_session_recording_available_until', true );
		if ( '' !== $until_raw ) {
			$until = $this->parse_iso8601( $until_raw );
			if ( null !== $until && ( $this->clock )() > $until ) {
				return SessionAccessDecision::deny(
					SessionAccessReason::RECORDING_WINDOW_EXPIRED,
					[ 'expired_at' => $until->format( \DateTimeInterface::ATOM ) ]
				);
			}
		}

		return SessionAccessDecision::allow( $recording_url );
	}

	/**
	 * Walks `post_parent` to the cohort course. Returns the course id only
	 * when the parent post is a published `vl_course`.
	 */
	private function resolve_course_id( WP_Post $session ): ?int {
		$parent_id = (int) $session->post_parent;
		if ( $parent_id <= 0 ) {
			return null;
		}
		$parent = get_post( $parent_id );
		if ( ! $parent instanceof WP_Post ) {
			return null;
		}
		if ( 'vl_course' !== $parent->post_type ) {
			return null;
		}
		if ( 'publish' !== $parent->post_status ) {
			return null;
		}
		return $parent_id;
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
