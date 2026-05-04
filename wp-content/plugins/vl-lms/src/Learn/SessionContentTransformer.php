<?php

declare(strict_types=1);

namespace VL\LMS\Learn;

use Closure;
use VL\LMS\Learn\Access\SessionAccessGate;
use VL\LMS\Learn\Access\SessionAccessReason;
use VL\LMS\Repositories\SessionAttendanceRepository;
use VL\LMS\Services\JoinWindowPolicy;
use WP_Post;

/**
 * Detail-endpoint payload composer for `GET /vl/v1/learn/sessions/{slug}`.
 *
 * Mirrors the per-row shape Phase 7.3 produces for webinars: a `session.*`
 * summary block plus a `computed.*` block (`join_window_open`,
 * `join_opens_at`, `join_closes_at`, `recording_available`, `is_past`,
 * `user_attended`) so the frontend doesn't re-implement window math.
 *
 * The transformer never leaks `_vl_session_zoom_join_url`,
 * `_vl_session_zoom_start_url`, or `_vl_session_zoom_password` — those
 * become concrete URLs only via the 302 redirect endpoints.
 *
 * Concrete (not final) for DI / testability.
 *
 * @author Tymofii Synianskyi
 */
class SessionContentTransformer {

	/** @var Closure(): \DateTimeImmutable */
	private readonly Closure $clock;

	/**
	 * @param Closure(): \DateTimeImmutable $clock
	 */
	public function __construct(
		private readonly SessionAccessGate $gate,
		private readonly SessionAttendanceRepository $attendance,
		private readonly JoinWindowPolicy $window_policy,
		Closure $clock
	) {
		$this->clock = $clock;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function transform( WP_Post $session, int $user_id ): array {
		$session_id = (int) $session->ID;
		$now        = ( $this->clock )();

		$start_raw = (string) get_post_meta( $session_id, '_vl_session_scheduled_start', true );
		$end_raw   = (string) get_post_meta( $session_id, '_vl_session_scheduled_end', true );

		$start_dt = $this->parse_iso8601( $start_raw );
		$end_dt   = $this->parse_iso8601( $end_raw );

		$opens_at_iso  = null;
		$closes_at_iso = null;
		if ( null !== $start_dt && null !== $end_dt ) {
			[ $opens_at, $closes_at ] = $this->window_policy->compute_window( $start_dt, $end_dt );
			$opens_at_iso             = $opens_at->format( \DateTimeInterface::ATOM );
			$closes_at_iso            = $closes_at->format( \DateTimeInterface::ATOM );
		}

		$join_decision      = $this->gate->can_join( $session, $user_id );
		$recording_decision = $this->gate->can_view_recording( $session, $user_id );

		$course_id   = (int) $session->post_parent;
		$course_post = $course_id > 0 ? get_post( $course_id ) : null;
		$course_slug = $course_post instanceof WP_Post ? (string) $course_post->post_name : '';

		$attendance_rows = $this->attendance->list_for_user( $user_id, $session_id );

		$status                    = (string) get_post_meta( $session_id, '_vl_session_status', true );
		$session_number            = (int) get_post_meta( $session_id, '_vl_session_number', true );
		$materials_raw             = get_post_meta( $session_id, '_vl_session_materials', true );
		$recording_available_until = (string) get_post_meta( $session_id, '_vl_session_recording_available_until', true );

		return [
			'session'  => [
				'id'                        => $session_id,
				'slug'                      => (string) $session->post_name,
				'title'                     => $this->title( $session ),
				'course_id'                 => $course_id,
				'course_slug'               => $course_slug,
				'session_number'            => $session_number,
				'scheduled_start'           => '' === $start_raw ? null : $start_raw,
				'scheduled_end'             => '' === $end_raw ? null : $end_raw,
				'status'                    => '' === $status ? 'scheduled' : $status,
				'materials'                 => $this->normalize_materials( $materials_raw ),
				'recording_available_until' => '' === $recording_available_until ? null : $recording_available_until,
			],
			'computed' => [
				'join_window_open'    => SessionAccessReason::OK === $join_decision->reason,
				'join_opens_at'       => $opens_at_iso,
				'join_closes_at'      => $closes_at_iso,
				'recording_available' => $recording_decision->allowed,
				'is_past'             => null !== $end_dt && $now > $end_dt,
				'user_attended'       => count( $attendance_rows ) > 0,
			],
		];
	}

	private function title( WP_Post $session ): string {
		$title = get_the_title( $session );
		return wp_strip_all_tags( $title );
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

	/**
	 * @return list<array{url: string, name: string, size: int}>
	 */
	private function normalize_materials( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = isset( $item['url'] ) && is_string( $item['url'] ) ? $item['url'] : '';
			if ( '' === $url ) {
				continue;
			}
			$out[] = [
				'url'  => $url,
				'name' => isset( $item['name'] ) && is_string( $item['name'] ) ? $item['name'] : '',
				'size' => isset( $item['size'] ) ? (int) $item['size'] : 0,
			];
		}
		return $out;
	}
}
